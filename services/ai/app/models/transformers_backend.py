"""PyTorch/Transformers backends for SigLIP2 and Qwen3-VL.

The only module that touches torch/transformers (§59: the FastAPI layer never
calls Transformers). Imported lazily by app/models/providers.py when
AI_MODEL_PROVIDER=transformers, so the mock/CI profile needs neither package.

Memory control:
- weights load straight onto the target device (device_map) in DTYPE;
- inference runs under torch.inference_mode();
- VLM input area is capped upstream (AI_VLM_MAX_PIXELS) and generation is
  bounded by AI_VLM_MAX_NEW_TOKENS and a cooperative wall-clock deadline;
- on OOM accelerator caches are released and InferenceOutOfMemory is raised,
  leaving the process healthy for the next request.
"""
from __future__ import annotations

import gc
import logging
import time
from contextlib import contextmanager
from pathlib import Path

import torch
from PIL.Image import Image

from .errors import InferenceOutOfMemory, InferenceTimeout, ModelError

logger = logging.getLogger("ai_service.transformers")

_DTYPES = {
    "float32": torch.float32,
    "float16": torch.float16,
    "bfloat16": torch.bfloat16,
}

# SigLIP2 text tower was trained with fixed-length padding (model card).
SIGLIP_TEXT_MAX_LENGTH = 64

# Qwen3-VL recommended sampling for retries after a failed greedy attempt.
_RETRY_SAMPLING = {"do_sample": True, "temperature": 0.7, "top_p": 0.8, "top_k": 20}
_GREEDY = {"do_sample": False, "temperature": None, "top_p": None, "top_k": None}


def torch_dtype(name: str) -> torch.dtype:
    return _DTYPES[name]


def is_out_of_memory(exc: BaseException) -> bool:
    oom_type = getattr(torch, "OutOfMemoryError", None) or torch.cuda.OutOfMemoryError
    if isinstance(exc, oom_type):
        return True
    # MPS reports OOM as a plain RuntimeError ("MPS backend out of memory").
    return isinstance(exc, RuntimeError) and "out of memory" in str(exc).lower()


def release_accelerator_memory(device: str) -> None:
    gc.collect()
    try:
        if device == "cuda" and torch.cuda.is_available():
            torch.cuda.empty_cache()
        elif device == "mps" and torch.backends.mps.is_available():
            torch.mps.empty_cache()
    except Exception:  # cache release is best effort
        logger.warning("ai_service.empty_cache_failed", exc_info=True)


@contextmanager
def inference_guard(device: str):
    try:
        with torch.inference_mode():
            yield
    except ModelError:
        raise
    except Exception as exc:
        if is_out_of_memory(exc):
            release_accelerator_memory(device)
            raise InferenceOutOfMemory(f"{device} out of memory") from exc
        raise


def _load_kwargs(device: str, dtype: str) -> dict:
    return {"dtype": torch_dtype(dtype), "device_map": {"": device}}


class TransformersEmbeddingEncoder:
    """google/siglip2-* (fixed-resolution) image/text towers."""

    def __init__(self, model, processor, *, device: str, dtype: str) -> None:
        self._model = model
        self._processor = processor
        self._device = device
        self._dtype = torch_dtype(dtype)
        self.dimensions = int(model.config.vision_config.hidden_size)

    @classmethod
    def load(cls, path: Path, *, device: str, dtype: str) -> "TransformersEmbeddingEncoder":
        from transformers import AutoModel, AutoProcessor

        processor = AutoProcessor.from_pretrained(path)
        model = AutoModel.from_pretrained(path, **_load_kwargs(device, dtype))
        model.eval()
        return cls(model, processor, device=device, dtype=dtype)

    @staticmethod
    def _as_tensor(features) -> torch.Tensor:
        # Newer transformers may return a ModelOutput instead of a tensor.
        return features if isinstance(features, torch.Tensor) else features.pooler_output

    def encode_images(self, images: list[Image]) -> list[list[float]]:
        with inference_guard(self._device):
            inputs = self._processor(images=images, return_tensors="pt").to(self._device)
            pixel_values = inputs["pixel_values"].to(self._dtype)
            features = self._as_tensor(self._model.get_image_features(pixel_values=pixel_values))
            return features.float().cpu().tolist()

    def encode_texts(self, texts: list[str]) -> list[list[float]]:
        with inference_guard(self._device):
            inputs = self._processor(
                text=texts,
                padding="max_length",
                max_length=SIGLIP_TEXT_MAX_LENGTH,
                truncation=True,
                return_tensors="pt",
            ).to(self._device)
            features = self._as_tensor(self._model.get_text_features(**inputs))
            return features.float().cpu().tolist()

    def close(self) -> None:
        self._model = None
        self._processor = None
        release_accelerator_memory(self._device)


def _deadline_criteria(deadline: float):
    from transformers import StoppingCriteria

    class DeadlineCriteria(StoppingCriteria):
        """Stops generation cooperatively at the wall-clock deadline, so a
        timeout actually frees the device instead of orphaning a thread."""

        def __init__(self) -> None:
            self.expired = False

        def __call__(self, input_ids, scores, **kwargs):
            self.expired = time.monotonic() >= deadline
            return torch.full((input_ids.shape[0],), self.expired, dtype=torch.bool, device=input_ids.device)

    return DeadlineCriteria()


class TransformersVisionGenerator:
    """Qwen/Qwen3-VL-*-Instruct chat generation (single image, single turn)."""

    def __init__(self, model, processor, *, device: str, max_new_tokens: int) -> None:
        self._model = model
        self._processor = processor
        self._device = device
        self._max_new_tokens = max_new_tokens

    @classmethod
    def load(cls, path: Path, *, device: str, dtype: str, max_new_tokens: int) -> "TransformersVisionGenerator":
        from transformers import AutoModelForImageTextToText, AutoProcessor

        processor = AutoProcessor.from_pretrained(path)
        model = AutoModelForImageTextToText.from_pretrained(path, **_load_kwargs(device, dtype))
        model.eval()
        return cls(model, processor, device=device, max_new_tokens=max_new_tokens)

    def generate(self, image: Image, prompt: str, *, sample: bool, deadline: float) -> str:
        from transformers import StoppingCriteriaList

        messages = [{"role": "user", "content": [{"type": "image"}, {"type": "text", "text": prompt}]}]
        chat_text = self._processor.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
        deadline_criteria = _deadline_criteria(deadline)

        with inference_guard(self._device):
            inputs = self._processor(text=[chat_text], images=[image], return_tensors="pt").to(self._device)
            prompt_length = inputs["input_ids"].shape[1]
            output_ids = self._model.generate(
                **inputs,
                max_new_tokens=self._max_new_tokens,
                stopping_criteria=StoppingCriteriaList([deadline_criteria]),
                **(_RETRY_SAMPLING if sample else _GREEDY),
            )
            raw = self._processor.batch_decode(output_ids[:, prompt_length:], skip_special_tokens=True)[0]
            del inputs, output_ids

        if deadline_criteria.expired:
            raise InferenceTimeout("VLM generation exceeded the inference deadline")
        return raw

    def close(self) -> None:
        self._model = None
        self._processor = None
        release_accelerator_memory(self._device)
