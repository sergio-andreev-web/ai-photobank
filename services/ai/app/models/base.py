"""Model abstractions per docs/technical-specification.md §59.

The FastAPI layer must never call Transformers directly; it only talks to
these interfaces. Implementations: mock.py (deterministic, CI) and
vision.py/embedding.py over transformers_backend.py (SigLIP2, Qwen3-VL).
Implementations may also define close() to release weights on shutdown.
Failures are raised as app/models/errors.py exceptions.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

from PIL.Image import Image

from ..schemas.analysis import Concept, OcrItem


@dataclass(frozen=True)
class VisionResult:
    caption: str
    ocr: list[OcrItem]
    concepts: list[Concept]


class VisionModel(Protocol):
    name: str
    revision: str

    def analyze(self, image: Image) -> VisionResult: ...


class EmbeddingModel(Protocol):
    name: str
    revision: str
    dimensions: int

    def image_embedding(self, image: Image) -> list[float]: ...

    def text_embeddings(self, texts: list[str]) -> list[list[float]]: ...
