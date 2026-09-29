"""Bounded inference concurrency (AI_CONCURRENCY, §58) -> 429 SERVICE_BUSY.

Two independent pools so a short search query is not stuck behind a long
image analysis:

- ``vision``: whole image analyses (Qwen generation + image embedding),
  capacity AI_CONCURRENCY. Non-blocking: a request that cannot get a slot
  immediately gets a fast, retryable SERVICE_BUSY instead of piling up
  behind a slow model.
- ``text``: text embeddings for search, capacity AI_TEXT_EMBEDDING_CONCURRENCY.
  Calls are short, so a request waits up to AI_TEXT_EMBEDDING_WAIT_SECONDS
  before SERVICE_BUSY.

The pools may run at the same time only because they never share an MPS
device: PyTorch MPS is not thread-safe (concurrent use corrupts results or
aborts the process), so on MPS the text tower runs on CPU
(AI_TEXT_EMBEDDING_DEVICE) and AI_CONCURRENCY must stay 1.
"""
from __future__ import annotations

import threading
from contextlib import contextmanager
from dataclasses import dataclass

from .schemas.errors import ServiceBusyError


class InferenceLimiter:
    def __init__(self, capacity: int):
        if capacity < 1:
            raise ValueError("capacity must be >= 1")
        self._semaphore = threading.Semaphore(capacity)

    @contextmanager
    def slot(self, *, request_id: str | None = None, wait_seconds: float = 0.0):
        if wait_seconds > 0:
            acquired = self._semaphore.acquire(timeout=wait_seconds)
        else:
            acquired = self._semaphore.acquire(blocking=False)
        if not acquired:
            raise ServiceBusyError(request_id=request_id)
        try:
            yield
        finally:
            self._semaphore.release()


@dataclass(frozen=True)
class InferenceLimiters:
    vision: InferenceLimiter
    text: InferenceLimiter
    # Face detection/embeddings (ADR-0015) run on CPU (onnxruntime), never on MPS.
    faces: InferenceLimiter
