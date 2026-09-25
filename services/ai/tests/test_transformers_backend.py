"""Torch-level helpers of the real backend (OOM handling, dtype, feature
extraction) with tiny fake modules — no transformers install or weights.
Skipped when torch is not installed (default CI profile)."""
from __future__ import annotations

import types

import pytest

torch = pytest.importorskip("torch")

from PIL import Image  # noqa: E402

from app.models import transformers_backend as backend  # noqa: E402
from app.models.errors import InferenceOutOfMemory, InferenceTimeout  # noqa: E402


def test_torch_dtype_names():
    assert backend.torch_dtype("bfloat16") is torch.bfloat16
    assert backend.torch_dtype("float16") is torch.float16
    assert backend.torch_dtype("float32") is torch.float32


@pytest.mark.parametrize(
    "exc, expected",
    [
        (torch.cuda.OutOfMemoryError("CUDA out of memory. Tried to allocate 2.00 GiB"), True),
        (RuntimeError("MPS backend out of memory (MPS allocated: 17.9 GB)"), True),
        (RuntimeError("shape mismatch"), False),
        (ValueError("out of memory"), False),
    ],
)
def test_is_out_of_memory(exc, expected):
    assert backend.is_out_of_memory(exc) is expected


def test_inference_guard_converts_oom_and_releases_cache(monkeypatch):
    released = []
    monkeypatch.setattr(backend, "release_accelerator_memory", released.append)
    with pytest.raises(InferenceOutOfMemory):
        with backend.inference_guard("mps"):
            raise RuntimeError("MPS backend out of memory")
    assert released == ["mps"]


def test_inference_guard_passes_through_other_errors(monkeypatch):
    monkeypatch.setattr(backend, "release_accelerator_memory", lambda device: pytest.fail("must not release"))
    with pytest.raises(InferenceTimeout):
        with backend.inference_guard("cuda"):
            raise InferenceTimeout("deadline")
    with pytest.raises(KeyError):
        with backend.inference_guard("cuda"):
            raise KeyError("x")


def test_inference_guard_disables_autograd():
    with backend.inference_guard("cpu"):
        assert torch.is_inference_mode_enabled()


class _FakeBatch(dict):
    def to(self, device):
        return _FakeBatch({key: value.to(device) for key, value in self.items()})


class _FakeProcessor:
    def __init__(self):
        self.text_kwargs = None

    def __call__(self, images=None, text=None, **kwargs):
        if images is not None:
            return _FakeBatch(pixel_values=torch.ones((len(images), 3, 4, 4)))
        self.text_kwargs = kwargs
        return _FakeBatch(input_ids=torch.ones((len(text), 64), dtype=torch.long))


class _FakeSiglip:
    config = types.SimpleNamespace(vision_config=types.SimpleNamespace(hidden_size=1152))

    def __init__(self, wrap_output=False):
        self.wrap_output = wrap_output
        self.pixel_dtype = None

    def _out(self, tensor):
        return types.SimpleNamespace(pooler_output=tensor) if self.wrap_output else tensor

    def get_image_features(self, pixel_values):
        self.pixel_dtype = pixel_values.dtype
        return self._out(torch.full((pixel_values.shape[0], 1152), 0.5, dtype=pixel_values.dtype))

    def get_text_features(self, input_ids):
        return self._out(torch.arange(input_ids.shape[0], dtype=torch.float32).unsqueeze(1).repeat(1, 1152))


@pytest.mark.parametrize("wrap_output", [False, True])
def test_embedding_encoder_extracts_features(wrap_output):
    processor = _FakeProcessor()
    model = _FakeSiglip(wrap_output=wrap_output)
    encoder = backend.TransformersEmbeddingEncoder(model, processor, device="cpu", dtype="bfloat16")
    assert encoder.dimensions == 1152

    image_vectors = encoder.encode_images([Image.new("RGB", (8, 8))])
    assert model.pixel_dtype is torch.bfloat16
    assert len(image_vectors) == 1 and len(image_vectors[0]) == 1152
    assert isinstance(image_vectors[0][0], float)

    text_vectors = encoder.encode_texts(["a", "b", "c"])
    assert [v[0] for v in text_vectors] == [0.0, 1.0, 2.0]
    assert processor.text_kwargs == {
        "padding": "max_length",
        "max_length": 64,
        "truncation": True,
        "return_tensors": "pt",
    }


def test_encoder_close_drops_weights(monkeypatch):
    released = []
    monkeypatch.setattr(backend, "release_accelerator_memory", released.append)
    encoder = backend.TransformersEmbeddingEncoder(_FakeSiglip(), _FakeProcessor(), device="cpu", dtype="float32")
    encoder.close()
    assert encoder._model is None and released == ["cpu"]
