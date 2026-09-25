from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.app import create_app
from app.config import get_settings
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


def test_empty_api_key_is_allowed_in_development():
    settings = make_settings(AI_ENVIRONMENT="development", AI_SERVICE_API_KEY="")
    assert settings.ai_service_api_key == ""
    assert settings.is_production is False


def test_empty_api_key_is_rejected_in_production():
    with pytest.raises(ValidationError):
        make_settings(AI_ENVIRONMENT="production", AI_SERVICE_API_KEY="")


def test_non_empty_api_key_is_accepted_in_production():
    settings = make_settings(AI_ENVIRONMENT="production", AI_SERVICE_API_KEY="s3cr3t")
    assert settings.is_production is True
    assert settings.ai_service_api_key == "s3cr3t"


@pytest.fixture
def startup_env(monkeypatch):
    # The real entrypoint (`uvicorn app.main:app`) builds Settings from the
    # environment through the cached get_settings().
    monkeypatch.setenv("DEVICE", "cpu")
    monkeypatch.setenv("AI_MODEL_PROVIDER", "mock")
    get_settings.cache_clear()
    yield monkeypatch
    get_settings.cache_clear()


def test_startup_fails_in_production_without_api_key(startup_env):
    startup_env.setenv("AI_ENVIRONMENT", "production")
    startup_env.setenv("AI_SERVICE_API_KEY", "")
    with pytest.raises(ValidationError, match="AI_SERVICE_API_KEY"):
        create_app()


def test_startup_allows_empty_api_key_in_development(startup_env):
    startup_env.setenv("AI_ENVIRONMENT", "development")
    startup_env.setenv("AI_SERVICE_API_KEY", "")
    app = create_app()
    assert app.state.settings.ai_service_api_key == ""
