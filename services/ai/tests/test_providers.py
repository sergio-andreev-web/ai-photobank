"""Transformers provider wiring without torch: weight-loading environment on MPS."""
from __future__ import annotations

import os
import sys
import types
from pathlib import Path

import pytest

from app.models import providers
from app.models.providers import ASYNC_LOAD_ENV, build_transformers_models, configure_weight_loading
from app.models.weights import ResolvedWeights
from app.schemas.common import AiDevice
from tests.conftest import make_settings


def test_mps_disables_async_weight_loading():
    environ: dict[str, str] = {}
    configure_weight_loading(AiDevice.MPS, environ)
    assert environ == {ASYNC_LOAD_ENV: "1"}


@pytest.mark.parametrize("explicit", ["0", "false", "1"])
def test_explicit_value_is_not_overridden(explicit):
    environ = {ASYNC_LOAD_ENV: explicit}
    configure_weight_loading(AiDevice.MPS, environ)
    assert environ == {ASYNC_LOAD_ENV: explicit}


def test_empty_value_counts_as_unset():
    # Compose passes `VAR=` for an unset `${VAR:-}`.
    environ = {ASYNC_LOAD_ENV: ""}
    configure_weight_loading(AiDevice.MPS, environ)
    assert environ == {ASYNC_LOAD_ENV: "1"}


@pytest.mark.parametrize("device", [AiDevice.CUDA, AiDevice.CPU])
def test_other_devices_keep_async_loading(device):
    environ: dict[str, str] = {}
    configure_weight_loading(device, environ)
    assert environ == {}


class _Loaded:
    def __init__(self, env_at_load):
        self.env_at_load = env_at_load
        self.dimensions = 1152

    def close(self):
        pass


def _fake_backend() -> types.ModuleType:
    backend = types.ModuleType("app.models.transformers_backend")

    class Encoder:
        @classmethod
        def load(cls, path, *, device, dtype, text_device):
            loaded = _Loaded(os.environ.get(ASYNC_LOAD_ENV))
            loaded.text_device = text_device
            return loaded

    class Generator:
        @classmethod
        def load(cls, path, *, device, dtype, max_new_tokens):
            return _Loaded(os.environ.get(ASYNC_LOAD_ENV))

    backend.TransformersEmbeddingEncoder = Encoder
    backend.TransformersVisionGenerator = Generator
    return backend


@pytest.mark.parametrize(("device", "expected"), [(AiDevice.MPS, "1"), (AiDevice.CUDA, "")])
def test_environment_is_set_before_weights_load(monkeypatch, device, expected):
    # setenv (not delenv) so monkeypatch restores whatever the build writes.
    monkeypatch.setenv(ASYNC_LOAD_ENV, "")
    monkeypatch.setitem(sys.modules, "app.models.transformers_backend", _fake_backend())
    monkeypatch.setattr(
        providers,
        "resolve_weights",
        lambda name, revision, hf_home: ResolvedWeights(path=Path("/weights"), revision="sha"),
    )
    settings = make_settings(AI_MODEL_PROVIDER="transformers", DTYPE="float32")

    vision, embedding = build_transformers_models(settings, device, "PROMPT")

    assert embedding._encoder.env_at_load == expected
    assert vision._generator.env_at_load == expected


@pytest.mark.parametrize(
    ("device", "configured", "expected"),
    [
        (AiDevice.MPS, "auto", AiDevice.CPU),
        (AiDevice.CUDA, "auto", AiDevice.CUDA),
        (AiDevice.CPU, "auto", AiDevice.CPU),
        (AiDevice.CUDA, "cpu", AiDevice.CPU),
    ],
)
def test_text_embedding_device(device, configured, expected):
    from app.models.providers import text_embedding_device

    settings = make_settings(AI_TEXT_EMBEDDING_DEVICE=configured)
    assert text_embedding_device(settings, device) is expected


def test_mps_text_tower_is_built_on_cpu(monkeypatch):
    monkeypatch.setitem(sys.modules, "app.models.transformers_backend", _fake_backend())
    monkeypatch.setattr(
        providers,
        "resolve_weights",
        lambda name, revision, hf_home: ResolvedWeights(path=Path("/weights"), revision="sha"),
    )
    settings = make_settings(AI_MODEL_PROVIDER="transformers", DTYPE="float32")

    _, embedding = build_transformers_models(settings, AiDevice.MPS, "PROMPT")

    assert embedding._encoder.text_device == "cpu"
