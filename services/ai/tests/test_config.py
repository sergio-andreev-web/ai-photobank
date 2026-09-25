from __future__ import annotations

import pytest
from pydantic import ValidationError

from tests.conftest import make_settings


@pytest.mark.parametrize(
    "override",
    [
        {"DTYPE": "int8"},
        {"AI_MODEL_PROVIDER": "real"},
        {"VISION_MODEL_REVISION": " "},
        {"AI_INFERENCE_TIMEOUT_SECONDS": 0},
        {"AI_VLM_MAX_NEW_TOKENS": 1},
        {"AI_VLM_MAX_PIXELS": 10},
        {"AI_VLM_OUTPUT_ATTEMPTS": 0},
    ],
)
def test_invalid_model_settings_are_rejected(override):
    with pytest.raises(ValidationError):
        make_settings(**override)


def test_mac_and_runpod_profiles_are_valid():
    mac = make_settings(DEVICE="mps", AI_MODEL_PROVIDER="transformers", VISION_MODEL="Qwen/Qwen3-VL-4B-Instruct")
    runpod = make_settings(DEVICE="cuda", AI_MODEL_PROVIDER="transformers", VISION_MODEL="Qwen/Qwen3-VL-8B-Instruct")
    assert mac.dtype == runpod.dtype == "auto"
    assert mac.embedding_model == runpod.embedding_model == "google/siglip2-so400m-patch14-384"
