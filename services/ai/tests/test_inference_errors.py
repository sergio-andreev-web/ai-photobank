"""Model failures surface as contract errors (ai-error.json), never as bare
500s with stack traces, and never leave the inference slot held."""
from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.app import create_app
from app.models.errors import InferenceFailed, InferenceOutOfMemory, InferenceTimeout, VisionOutputInvalid
from app.models.mock import MockEmbeddingModel, MockVisionModel
from tests.conftest import make_jpeg_bytes

VALID_REQUEST_ID = "0b9f6c1e-3d2a-4c55-9a51-6f1f3c0a7e42"


class RaisingVision(MockVisionModel):
    def __init__(self, exc):
        self.exc = exc

    def analyze(self, image):
        raise self.exc


class RaisingEmbedding(MockEmbeddingModel):
    def __init__(self, exc):
        self.exc = exc

    def text_embeddings(self, texts):
        raise self.exc


def analyze(client):
    return client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
    )


@pytest.mark.parametrize(
    "exc, status, code",
    [
        (VisionOutputInvalid("no JSON object"), 502, "MODEL_OUTPUT_INVALID"),
        (InferenceTimeout("deadline"), 500, "INFERENCE_FAILED"),
        (InferenceOutOfMemory("mps out of memory"), 500, "INFERENCE_FAILED"),
        (InferenceFailed("nan"), 500, "INFERENCE_FAILED"),
        (RuntimeError("/srv/secret/path exploded"), 500, "INFERENCE_FAILED"),
    ],
)
def test_analyze_maps_model_failures(app_settings, exc, status, code):
    app = create_app(app_settings)
    with TestClient(app, raise_server_exceptions=False) as client:
        app.state.registry.vision_model = RaisingVision(exc)
        response = analyze(client)
        assert response.status_code == status
        error = response.json()["error"]
        assert error["code"] == code
        assert error["request_id"] == VALID_REQUEST_ID
        assert "/srv/secret" not in response.text
        if code == "MODEL_OUTPUT_INVALID":
            assert error["details"] == {"prompt_version": "image-analysis-v1"}

        # The single inference slot (AI_CONCURRENCY=1) was released.
        app.state.registry.vision_model = MockVisionModel()
        assert analyze(client).status_code == 200


@pytest.mark.parametrize(
    "exc",
    [InferenceTimeout("deadline"), InferenceOutOfMemory("cuda out of memory"), InferenceFailed("nan"), ValueError("x")],
)
def test_text_embeddings_map_model_failures(app_settings, exc):
    app = create_app(app_settings)
    with TestClient(app, raise_server_exceptions=False) as client:
        app.state.registry.embedding_model = RaisingEmbedding(exc)
        response = client.post("/v1/embeddings/text", json={"texts": ["a"]}, headers={"X-Request-ID": "req-7"})
        assert response.status_code == 500
        assert response.json()["error"]["code"] == "INFERENCE_FAILED"
        assert response.json()["error"]["request_id"] == "req-7"

        app.state.registry.embedding_model = MockEmbeddingModel()
        assert client.post("/v1/embeddings/text", json={"texts": ["a"]}).status_code == 200
