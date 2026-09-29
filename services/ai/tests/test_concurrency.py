"""Split inference pools: search queries must not wait for image analysis."""
from __future__ import annotations

import threading
import time

import pytest
from fastapi.testclient import TestClient

from app.app import create_app
from app.concurrency import InferenceLimiter
from app.schemas.errors import ServiceBusyError

from .conftest import make_jpeg_bytes, make_settings

VALID_REQUEST_ID = "018f4f3e-8f5a-7b3c-9c62-3d4a7c9e1b20"


def test_limiter_rejects_immediately_without_wait():
    limiter = InferenceLimiter(1)
    with limiter.slot():
        started = time.monotonic()
        with pytest.raises(ServiceBusyError):
            with limiter.slot():
                pass
        assert time.monotonic() - started < 0.05


def test_limiter_waits_for_slot_released_in_time():
    limiter = InferenceLimiter(1)
    release = threading.Event()

    def hold():
        with limiter.slot():
            release.wait(1)

    holder = threading.Thread(target=hold)
    holder.start()
    time.sleep(0.02)
    threading.Timer(0.05, release.set).start()
    with limiter.slot(wait_seconds=1.0):
        pass
    holder.join()


def test_limiter_times_out_while_waiting():
    limiter = InferenceLimiter(1)
    with limiter.slot():
        started = time.monotonic()
        with pytest.raises(ServiceBusyError):
            with limiter.slot(wait_seconds=0.05):
                pass
        assert time.monotonic() - started >= 0.05


def test_text_embeddings_served_while_analysis_pool_is_full():
    app = create_app(make_settings())
    with TestClient(app) as client:
        with app.state.limiters.vision.slot():
            busy = client.post(
                "/v1/images/analyze",
                data={"request_id": VALID_REQUEST_ID},
                files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
            )
            search = client.post("/v1/embeddings/text", json={"texts": ["red car"]})
    assert busy.status_code == 429
    assert search.status_code == 200
    assert len(search.json()["embeddings"]) == 1


def test_text_embeddings_busy_after_wait_when_text_pool_is_full():
    app = create_app(make_settings(AI_TEXT_EMBEDDING_WAIT_SECONDS=0.05))
    with TestClient(app) as client:
        with app.state.limiters.text.slot():
            response = client.post("/v1/embeddings/text", json={"texts": ["red car"]})
    assert response.status_code == 429
    assert response.json()["error"]["code"] == "SERVICE_BUSY"
    assert response.headers["Retry-After"]


def test_analysis_does_not_use_text_pool():
    app = create_app(make_settings())
    with TestClient(app) as client:
        with app.state.limiters.text.slot():
            response = client.post(
                "/v1/images/analyze",
                data={"request_id": VALID_REQUEST_ID},
                files={"image": ("a.jpg", make_jpeg_bytes(), "image/jpeg")},
            )
    assert response.status_code == 200


def test_concurrency_settings_reject_zero():
    with pytest.raises(ValueError):
        make_settings(AI_TEXT_EMBEDDING_CONCURRENCY=0)
    with pytest.raises(ValueError):
        make_settings(AI_CONCURRENCY=0)


def test_text_embedding_device_rejects_unknown_value():
    with pytest.raises(ValueError):
        make_settings(AI_TEXT_EMBEDDING_DEVICE="mps")
