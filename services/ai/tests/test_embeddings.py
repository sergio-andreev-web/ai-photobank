from __future__ import annotations

import math


def test_text_embeddings_happy_path(client):
    response = client.post(
        "/v1/embeddings/text",
        json={"texts": ["инженер", "защитная каска", "нефтяная установка"]},
    )
    assert response.status_code == 200
    body = response.json()
    assert body["model"] == "google/siglip2-so400m-patch14-384"
    assert body["revision"] == "mock-embedding-v1"
    assert body["dimensions"] == 1152
    assert len(body["embeddings"]) == 3
    for vector in body["embeddings"]:
        assert len(vector) == 1152
        norm = math.sqrt(sum(c * c for c in vector))
        assert abs(norm - 1.0) < 1e-6


def test_text_embeddings_preserves_order_and_is_deterministic(client):
    first = client.post("/v1/embeddings/text", json={"texts": ["a", "b"]}).json()
    second = client.post("/v1/embeddings/text", json={"texts": ["a", "b"]}).json()
    assert first["embeddings"] == second["embeddings"]
    assert first["embeddings"][0] != first["embeddings"][1]

    swapped = client.post("/v1/embeddings/text", json={"texts": ["b", "a"]}).json()
    assert swapped["embeddings"][0] == first["embeddings"][1]
    assert swapped["embeddings"][1] == first["embeddings"][0]


def test_text_embeddings_rejects_empty_list(client):
    response = client.post("/v1/embeddings/text", json={"texts": []})
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_text_embeddings_rejects_too_many_texts(client):
    response = client.post("/v1/embeddings/text", json={"texts": ["x"] * 65})
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_text_embeddings_rejects_blank_text(client):
    response = client.post("/v1/embeddings/text", json={"texts": ["   "]})
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_text_embeddings_rejects_unknown_fields(client):
    response = client.post("/v1/embeddings/text", json={"texts": ["a"], "extra": 1})
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_REQUEST"


def test_text_embeddings_error_body_is_not_fastapi_default_shape(client):
    response = client.post("/v1/embeddings/text", json={"texts": []})
    body = response.json()
    assert "detail" not in body
    assert set(body.keys()) == {"error"}
    assert set(body["error"].keys()) == {"code", "message", "details", "request_id"}
