from __future__ import annotations

import io

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.app import create_app
from app.config import Settings


def make_settings(**overrides) -> Settings:
    defaults = dict(
        DEVICE="cpu",
        VISION_MODEL="Qwen/Qwen3-VL-4B-Instruct",
        EMBEDDING_MODEL="google/siglip2-so400m-patch14-384",
        PROMPT_VERSION="image-analysis-v2",
        AI_SERVICE_API_KEY="",
        AI_CONCURRENCY=1,
        AI_MAX_IMAGE_BYTES=20 * 1024 * 1024,
        AI_MODEL_PROVIDER="mock",
        AI_ENVIRONMENT="development",
    )
    defaults.update(overrides)
    return Settings(**defaults)


@pytest.fixture
def app_settings() -> Settings:
    return make_settings()


@pytest.fixture
def client(app_settings: Settings):
    app = create_app(app_settings)
    with TestClient(app) as test_client:
        yield test_client


def make_jpeg_bytes(size=(32, 32), color=(200, 50, 50)) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", size, color=color).save(buffer, format="JPEG")
    return buffer.getvalue()


def make_png_bytes(size=(16, 16), color=(10, 200, 10)) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", size, color=color).save(buffer, format="PNG")
    return buffer.getvalue()
