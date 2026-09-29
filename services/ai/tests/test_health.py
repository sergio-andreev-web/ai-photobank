from __future__ import annotations


def test_health_returns_ok_without_auth(client):
    response = client.get("/health")
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["device"] == "cpu"
    assert body["models"] == {
        "vision": "Qwen/Qwen3-VL-4B-Instruct",
        "embedding": "google/siglip2-so400m-patch14-384",
    }
    assert "X-Request-ID" in response.headers


def test_health_echoes_request_id_header(client):
    response = client.get("/health", headers={"X-Request-ID": "req-echo-1"})
    assert response.headers["X-Request-ID"] == "req-echo-1"


def test_ready_returns_ready_once_started(client):
    response = client.get("/ready")
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ready"
    assert body["embedding_dimensions"] == 1152
    assert body["prompt_version"] == "image-analysis-v2"
    assert body["models"] == {"vision": "mock-vision", "embedding": "mock-embedding"}


def test_health_and_ready_stay_unauthenticated_when_api_key_is_configured():
    from fastapi.testclient import TestClient

    from app.app import create_app
    from tests.conftest import make_settings

    # Contract `security: []` for probes; inference routes still require the key.
    secured_settings = make_settings(AI_ENVIRONMENT="production", AI_SERVICE_API_KEY="s3cret")
    with TestClient(create_app(secured_settings)) as secured_client:
        assert secured_client.get("/health").status_code == 200
        assert secured_client.get("/ready").status_code == 200

        protected = secured_client.post("/v1/embeddings/text", json={"texts": ["cat"]})
        assert protected.status_code == 401
        assert protected.json()["error"]["code"] == "UNAUTHORIZED"


def test_ready_returns_503_model_not_ready_when_startup_failed(app_settings):
    from fastapi.testclient import TestClient

    from app.app import create_app

    broken_settings = app_settings.model_copy(update={"prompt_version": "does-not-exist"})
    app = create_app(broken_settings)
    with TestClient(app) as broken_client:
        response = broken_client.get("/ready")
        assert response.status_code == 503
        body = response.json()
        assert body["error"]["code"] == "MODEL_NOT_READY"
        assert body["error"]["request_id"] is None

        analyze_response = broken_client.post(
            "/v1/images/analyze",
            data={"request_id": "11111111-1111-1111-1111-111111111111"},
            files={"image": ("a.jpg", b"not-checked-because-not-ready", "image/jpeg")},
        )
        assert analyze_response.status_code == 503
        assert analyze_response.json()["error"]["code"] == "MODEL_NOT_READY"
