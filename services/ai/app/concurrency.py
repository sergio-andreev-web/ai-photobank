"""Bounded inference concurrency (AI_CONCURRENCY, §58) -> 429 SERVICE_BUSY.

Non-blocking: a request that cannot acquire a slot immediately is rejected
rather than queued, so callers get a fast, retryable SERVICE_BUSY instead of
piling up behind a slow model.
"""
from __future__ import annotations

import threading
from contextlib import contextmanager

from .schemas.errors import ServiceBusyError


class InferenceLimiter:
    def __init__(self, capacity: int):
        if capacity < 1:
            raise ValueError("capacity must be >= 1")
        self._semaphore = threading.Semaphore(capacity)

    @contextmanager
    def slot(self, *, request_id: str | None = None):
        acquired = self._semaphore.acquire(blocking=False)
        if not acquired:
            raise ServiceBusyError(request_id=request_id)
        try:
            yield
        finally:
            self._semaphore.release()
