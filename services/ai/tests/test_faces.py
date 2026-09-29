"""POST /v1/faces/detect (ADR-0015) with the mock face model, plus the pure geometry helpers."""
from __future__ import annotations

import json
from pathlib import Path

import numpy as np
from fastapi.testclient import TestClient
from PIL import Image

from app.app import create_app
from app.models.faces import _nms, align_face, similarity_transform
from app.schemas.faces import FaceDetectionResponse

from .conftest import make_jpeg_bytes, make_settings

REQUEST_ID = "0b9f6c1e-3d2a-4c55-9a51-6f1f3c0a7e42"
SCHEMA = Path(__file__).resolve().parents[3] / "contracts" / "schemas" / "face-detection.json"


def post(client, data: bytes, request_id: str = REQUEST_ID):
    return client.post("/v1/faces/detect", data={"request_id": request_id}, files={"image": ("a.jpg", data, "image/jpeg")})


def test_detect_returns_contract_shaped_faces():
    with TestClient(create_app(make_settings())) as client:
        response = post(client, make_jpeg_bytes(size=(200, 100)))

    assert response.status_code == 200
    body = response.json()
    # The Pydantic model mirrors the contract; its keys must be exactly the schema's.
    FaceDetectionResponse(**body)
    schema = json.loads(SCHEMA.read_text())
    assert sorted(body) == sorted(schema["required"])
    assert sorted(body["faces"][0]) == sorted(schema["properties"]["faces"]["items"]["required"])
    assert body["request_id"] == REQUEST_ID
    assert body["image"] == {"width": 200, "height": 100}
    assert body["model"] == {"name": "mock-faces", "revision": "mock-faces-v1"}
    face = body["faces"][0]
    assert [round(face["box"][k], 6) for k in ("x", "y", "width", "height")] == [0.3, 0.2, 0.4, 0.6]
    assert abs(sum(v * v for v in face["embedding"]) - 1) < 1e-6


def test_same_image_same_vector_other_image_other_vector():
    with TestClient(create_app(make_settings())) as client:
        a1 = post(client, make_jpeg_bytes(color=(200, 50, 50))).json()["faces"][0]["embedding"]
        a2 = post(client, make_jpeg_bytes(color=(200, 50, 50))).json()["faces"][0]["embedding"]
        b = post(client, make_jpeg_bytes(color=(10, 200, 10))).json()["faces"][0]["embedding"]
    assert a1 == a2
    assert a1 != b


def test_disabled_faces_are_not_ready():
    with TestClient(create_app(make_settings(FACES_ENABLED=False))) as client:
        response = post(client, make_jpeg_bytes())
    assert response.status_code == 503
    assert response.json()["error"]["code"] == "MODEL_NOT_READY"
    assert response.json()["error"]["details"] == {"stage": "faces_unavailable"}


def test_request_is_validated():
    with TestClient(create_app(make_settings())) as client:
        missing = client.post("/v1/faces/detect", data={"request_id": REQUEST_ID})
        bad_id = post(client, make_jpeg_bytes(), request_id="bad id!")
        not_image = client.post("/v1/faces/detect", data={"request_id": REQUEST_ID}, files={"image": ("a.txt", b"hello", "text/plain")})
    assert missing.status_code == 400
    assert bad_id.status_code == 400
    assert not_image.status_code == 415


def test_similarity_transform_recovers_rotation_scale_and_shift():
    src = np.array([[0, 0], [10, 0], [0, 10], [5, 5], [10, 10]], dtype=float)
    angle = np.deg2rad(30)
    rotation = np.array([[np.cos(angle), -np.sin(angle)], [np.sin(angle), np.cos(angle)]])
    dst = 2.0 * src @ rotation.T + np.array([3.0, -4.0])
    matrix = similarity_transform(src, dst)
    mapped = src @ matrix[:, :2].T + matrix[:, 2]
    assert np.allclose(mapped, dst, atol=1e-6)


def test_align_face_crops_to_112():
    image = Image.new("RGB", (300, 300), (120, 80, 40))
    landmarks = np.array([[120, 130], [180, 130], [150, 160], [125, 190], [175, 190]], dtype=float)
    assert align_face(image, landmarks).size == (112, 112)


def test_nms_drops_overlapping_boxes():
    boxes = np.array([[0, 0, 10, 10], [1, 1, 11, 11], [50, 50, 60, 60]], dtype=float)
    scores = np.array([0.9, 0.8, 0.7])
    assert _nms(boxes, scores, 0.4) == [0, 2]
