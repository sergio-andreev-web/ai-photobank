"""§73: 'invalid model output' — the service must never return 200 with a body
that fails schemas/image-analysis.json (contract: ModelOutputInvalid, 502).
"""
from __future__ import annotations

from fastapi.testclient import TestClient

from app.app import create_app
from app.models.base import VisionResult
from tests.conftest import make_jpeg_bytes, make_settings

VALID_REQUEST_ID = "0b9f6c1e-3d2a-4c55-9a51-6f1f3c0a7e42"


class BrokenEmbeddingModel:
    name = "broken-embedding"
    revision = "broken-v1"
    dimensions = 1152

    def image_embedding(self, image):
        return [0.1, 0.2]  # wrong length -> fails image-analysis.json

    def text_embeddings(self, texts):
        return [[0.1, 0.2] for _ in texts]


class BrokenVisionModel:
    name = "broken-vision"
    revision = "broken-v1"

    def analyze(self, image):
        return VisionResult(caption="x" * 2001, ocr=[], concepts=[])  # exceeds maxLength


def test_analyze_returns_502_when_embedding_model_output_is_malformed(app_settings):
    app = create_app(app_settings)
    with TestClient(app) as client:
        app.state.registry.embedding_model = BrokenEmbeddingModel()
        response = client.post(
            "/v1/images/analyze",
            data={"request_id": VALID_REQUEST_ID},
            files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
        )
    assert response.status_code == 502
    body = response.json()
    assert body["error"]["code"] == "MODEL_OUTPUT_INVALID"
    assert body["error"]["details"]["prompt_version"] == "image-analysis-v1"


def test_analyze_returns_502_when_vision_model_output_is_malformed(app_settings):
    app = create_app(app_settings)
    with TestClient(app) as client:
        app.state.registry.vision_model = BrokenVisionModel()
        response = client.post(
            "/v1/images/analyze",
            data={"request_id": VALID_REQUEST_ID},
            files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
        )
    assert response.status_code == 502
    assert response.json()["error"]["code"] == "MODEL_OUTPUT_INVALID"


def test_text_embeddings_returns_500_when_embedding_model_output_is_malformed(app_settings):
    app = create_app(app_settings)
    with TestClient(app) as client:
        app.state.registry.embedding_model = BrokenEmbeddingModel()
        response = client.post("/v1/embeddings/text", json={"texts": ["a"]})
    assert response.status_code == 500
    assert response.json()["error"]["code"] == "INTERNAL_ERROR"
