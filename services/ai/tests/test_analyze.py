from __future__ import annotations

from tests.conftest import make_jpeg_bytes, make_png_bytes

VALID_REQUEST_ID = "0b9f6c1e-3d2a-4c55-9a51-6f1f3c0a7e42"


def test_analyze_happy_path_matches_schema_shape(client):
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("photo.jpg", make_jpeg_bytes(), "image/jpeg")},
        headers={"X-Request-ID": VALID_REQUEST_ID},
    )
    assert response.status_code == 200
    body = response.json()

    assert body["request_id"] == VALID_REQUEST_ID
    assert isinstance(body["caption"], str)
    assert isinstance(body["ocr"], list)
    assert isinstance(body["concepts"], list) and len(body["concepts"]) >= 1
    for concept in body["concepts"]:
        assert set(concept.keys()) == {"type", "label", "confidence"}
    assert len(body["embedding"]) == 1152
    assert body["embedding_dimensions"] == 1152
    assert body["models"] == {
        "vision": "mock-vision",
        "embedding": "mock-embedding",
        "vision_revision": "mock-vision-v1",
        "embedding_revision": "mock-embedding-v1",
    }
    assert body["prompt_version"] == "image-analysis-v2"
    assert body["processing_ms"] >= 0
    assert response.headers["X-Request-ID"] == VALID_REQUEST_ID


def test_analyze_is_deterministic_for_same_bytes(client):
    image_bytes = make_png_bytes()
    first = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("a.png", image_bytes, "image/png")},
    ).json()
    second = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("b.png", image_bytes, "image/png")},
    ).json()
    assert first["caption"] == second["caption"]
    assert first["concepts"] == second["concepts"]
    assert first["embedding"] == second["embedding"]


def test_analyze_embedding_is_l2_normalized(client):
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("a.png", make_png_bytes(), "image/png")},
    )
    embedding = response.json()["embedding"]
    norm_sq = sum(component * component for component in embedding)
    assert abs(norm_sq - 1.0) < 1e-6


def test_analyze_missing_image_returns_400(client):
    response = client.post("/v1/images/analyze", data={"request_id": VALID_REQUEST_ID})
    assert response.status_code == 400
    body = response.json()
    assert body["error"]["code"] == "INVALID_REQUEST"
    assert "image" in body["error"]["details"]["fields"]


def test_analyze_missing_request_id_returns_400(client):
    response = client.post(
        "/v1/images/analyze",
        files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
    )
    assert response.status_code == 400
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_analyze_image_too_large_returns_413(app_settings):
    from fastapi.testclient import TestClient

    from app.app import create_app

    small_limit_settings = app_settings.model_copy(update={"ai_max_image_bytes": 10})
    app = create_app(small_limit_settings)
    with TestClient(app) as small_client:
        response = small_client.post(
            "/v1/images/analyze",
            data={"request_id": VALID_REQUEST_ID},
            files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
        )
    assert response.status_code == 413
    body = response.json()
    assert body["error"]["code"] == "IMAGE_TOO_LARGE"
    assert body["error"]["details"]["max_bytes"] == 10


def test_analyze_unsupported_media_type_returns_415(client):
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("a.pdf", b"%PDF-1.4 not a real pdf but has the header", "application/pdf")},
    )
    assert response.status_code == 415
    body = response.json()
    assert body["error"]["code"] == "UNSUPPORTED_MEDIA_TYPE"
    assert body["error"]["details"]["detected_mime"] == "application/pdf"


def test_analyze_ignores_client_supplied_content_type(client):
    # MIME must be validated by content, not by the multipart Content-Type header.
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("a.png", make_png_bytes(), "image/jpeg")},
    )
    assert response.status_code == 200


def test_analyze_decode_failed_returns_422(client):
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": VALID_REQUEST_ID},
        files={"image": ("a.jpg", b"\xff\xd8\xff" + b"garbage-not-a-real-jpeg" * 5, "image/jpeg")},
    )
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "IMAGE_DECODE_FAILED"


def test_analyze_invalid_request_id_format_returns_400(client):
    response = client.post(
        "/v1/images/analyze",
        data={"request_id": "not a valid id!"},
        files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
    )
    assert response.status_code == 400
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_analyze_requires_api_key_when_configured(app_settings):
    from fastapi.testclient import TestClient

    from app.app import create_app

    secured_settings = app_settings.model_copy(update={"ai_service_api_key": "s3cret"})
    app = create_app(secured_settings)
    with TestClient(app) as secured_client:
        unauthenticated = secured_client.post(
            "/v1/images/analyze",
            data={"request_id": VALID_REQUEST_ID},
            files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
        )
        assert unauthenticated.status_code == 401
        body = unauthenticated.json()
        assert body["error"]["code"] == "UNAUTHORIZED"
        assert body["error"]["request_id"] is None

        authenticated = secured_client.post(
            "/v1/images/analyze",
            data={"request_id": VALID_REQUEST_ID},
            files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
            headers={"Authorization": "Bearer s3cret"},
        )
        assert authenticated.status_code == 200

        wrong_key = secured_client.post(
            "/v1/images/analyze",
            data={"request_id": VALID_REQUEST_ID},
            files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
            headers={"Authorization": "Bearer wrong"},
        )
        assert wrong_key.status_code == 401


def test_analyze_service_busy_when_concurrency_exhausted(app_settings, monkeypatch):
    from fastapi.testclient import TestClient

    from app.app import create_app
    app = create_app(app_settings)
    with TestClient(app) as busy_client:
        # Simulate every slot already taken by holding the single permit open.
        with app.state.limiters.vision.slot():
            response = busy_client.post(
                "/v1/images/analyze",
                data={"request_id": VALID_REQUEST_ID},
                files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
            )
    assert response.status_code == 429
    body = response.json()
    assert body["error"]["code"] == "SERVICE_BUSY"
    assert response.headers["Retry-After"]
