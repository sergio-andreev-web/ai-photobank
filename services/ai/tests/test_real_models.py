"""Real SigLIP2 + Qwen3-VL inference (`pytest -m model`, §73).

Not collected by default. Needs requirements-model.txt and weights in HF_HOME
(downloaded on first run unless HF_HUB_OFFLINE=1). Profile comes from the
environment, e.g.:

    DEVICE=mps VISION_MODEL=Qwen/Qwen3-VL-4B-Instruct pytest -m model   # Mac
    DEVICE=cuda VISION_MODEL=Qwen/Qwen3-VL-8B-Instruct pytest -m model  # CUDA

Both profiles must produce the same contract shape (§5, §58).
"""
from __future__ import annotations

import io
import math
import os
import re

import pytest

pytestmark = pytest.mark.model

pytest.importorskip("torch", reason="requires requirements-model.txt")
pytest.importorskip("transformers", reason="requires requirements-model.txt")

from fastapi.testclient import TestClient  # noqa: E402
from PIL import Image, ImageDraw  # noqa: E402

from app.app import create_app  # noqa: E402
from app.config import Settings  # noqa: E402
from app.schemas.analysis import ImageAnalysisResponse  # noqa: E402
from app.schemas.embeddings import TextEmbeddingsResponse  # noqa: E402

COMMIT_OR_DIGEST = re.compile(r"^([0-9a-f]{40}|sha256:[0-9a-f]{64})$")
LOAD_TIMEOUT_SECONDS = float(os.environ.get("AI_MODEL_TEST_LOAD_TIMEOUT", "3600"))
REQUEST_ID = "model-test-0001"


def _scene_png() -> bytes:
    image = Image.new("RGB", (640, 480), color=(235, 235, 235))
    draw = ImageDraw.Draw(image)
    draw.rectangle((60, 60, 300, 300), fill=(220, 30, 30))
    draw.ellipse((360, 120, 580, 340), fill=(30, 60, 220))
    draw.text((80, 380), "STOP", fill=(0, 0, 0))
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return buffer.getvalue()


def _cosine(a: list[float], b: list[float]) -> float:
    return sum(x * y for x, y in zip(a, b))


@pytest.fixture(scope="module")
def app():
    settings = Settings(AI_MODEL_PROVIDER="transformers", AI_SERVICE_API_KEY="", AI_ENVIRONMENT="development")
    application = create_app(settings)
    with TestClient(application) as client:
        application.state.registry._startup_thread.join(timeout=LOAD_TIMEOUT_SECONDS)
        if not application.state.registry.ready:
            pytest.fail("real models did not become ready; see ai_service.startup_failed log")
        application.state.client = client
        yield application


@pytest.fixture(scope="module")
def client(app):
    return app.state.client


def test_ready_reports_resolved_device_and_models(app, client):
    body = client.get("/ready").json()
    assert body["status"] == "ready"
    assert body["embedding_dimensions"] == 1152
    configured = app.state.settings.device
    if configured != "auto":
        assert body["device"] == configured


def test_analyze_returns_contract_shape(client):
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": REQUEST_ID},
        files={"image": ("scene.png", _scene_png(), "image/png")},
        headers={"X-Request-ID": REQUEST_ID},
    )
    assert response.status_code == 200, response.text
    body = ImageAnalysisResponse(**response.json())
    assert body.caption.strip()
    assert COMMIT_OR_DIGEST.match(body.models.vision_revision)
    assert COMMIT_OR_DIGEST.match(body.models.embedding_revision)
    assert abs(math.sqrt(sum(c * c for c in body.embedding)) - 1.0) < 1e-3


def test_text_embeddings_share_revision_and_semantics(client):
    image_body = client.post(
        "/v1/images/analyze",
        data={"request_id": REQUEST_ID},
        files={"image": ("scene.png", _scene_png(), "image/png")},
    ).json()
    texts = ["a red square and a blue circle", "a dog running on grass"]
    response = client.post("/v1/embeddings/text", json={"texts": texts})
    assert response.status_code == 200, response.text
    body = TextEmbeddingsResponse(**response.json())
    assert body.revision == image_body["models"]["embedding_revision"]
    assert len(body.embeddings) == 2

    image_vector = image_body["embedding"]
    assert _cosine(image_vector, body.embeddings[0]) > _cosine(image_vector, body.embeddings[1])

    # Fixed-length padding makes a text's vector independent of its batch.
    single = client.post("/v1/embeddings/text", json={"texts": [texts[1]]}).json()["embeddings"][0]
    assert _cosine(single, body.embeddings[1]) > 0.999


def test_vlm_deadline_stops_generation_with_inference_failed(app, client):
    vision = app.state.registry.vision_model
    original = vision._timeout_seconds
    vision._timeout_seconds = 0.2
    try:
        response = client.post(
            "/v1/images/analyze",
            data={"request_id": REQUEST_ID},
            files={"image": ("scene.png", _scene_png(), "image/png")},
        )
    finally:
        vision._timeout_seconds = original
    assert response.status_code == 500
    assert response.json()["error"]["code"] == "INFERENCE_FAILED"
    # Service remains usable after the timeout.
    assert client.post("/v1/embeddings/text", json={"texts": ["ok"]}).status_code == 200
