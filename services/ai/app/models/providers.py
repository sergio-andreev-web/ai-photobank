"""Build the configured VisionModel/EmbeddingModel pair (AI_MODEL_PROVIDER)."""
from __future__ import annotations

import logging

from ..config import Settings
from ..device import resolve_dtype
from ..schemas.common import AiDevice
from .base import EmbeddingModel, VisionModel
from .embedding import SiglipEmbeddingModel
from .mock import MockEmbeddingModel, MockVisionModel
from .vision import QwenVisionModel
from .weights import resolve_weights

logger = logging.getLogger("ai_service.providers")


def close_model(model: object | None) -> None:
    close = getattr(model, "close", None)
    if close is not None:
        close()


def build_transformers_models(settings: Settings, device: AiDevice, prompt: str) -> tuple[VisionModel, EmbeddingModel]:
    from .transformers_backend import TransformersEmbeddingEncoder, TransformersVisionGenerator

    dtype = resolve_dtype(settings.dtype, device)
    logger.info("ai_service.loading_models", extra={"device": device.value, "dtype": dtype})

    # Smaller embedding model first; if the VLM then fails to load, free it.
    embedding_weights = resolve_weights(
        settings.embedding_model, settings.embedding_model_revision, hf_home=settings.hf_home
    )
    embedding = SiglipEmbeddingModel(
        name=settings.embedding_model,
        revision=embedding_weights.revision,
        encoder=TransformersEmbeddingEncoder.load(embedding_weights.path, device=device.value, dtype=dtype),
    )
    try:
        vision_weights = resolve_weights(
            settings.vision_model, settings.vision_model_revision, hf_home=settings.hf_home
        )
        vision = QwenVisionModel(
            name=settings.vision_model,
            revision=vision_weights.revision,
            generator=TransformersVisionGenerator.load(
                vision_weights.path,
                device=device.value,
                dtype=dtype,
                max_new_tokens=settings.ai_vlm_max_new_tokens,
            ),
            prompt=prompt,
            max_pixels=settings.ai_vlm_max_pixels,
            timeout_seconds=settings.ai_inference_timeout_seconds,
            attempts=settings.ai_vlm_output_attempts,
        )
    except BaseException:
        close_model(embedding)
        raise
    return vision, embedding


def build_models(settings: Settings, device: AiDevice, prompt: str) -> tuple[VisionModel, EmbeddingModel]:
    if settings.ai_model_provider == "transformers":
        return build_transformers_models(settings, device, prompt)
    return MockVisionModel(), MockEmbeddingModel()
