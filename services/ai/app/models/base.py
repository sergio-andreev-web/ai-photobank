"""Model abstractions per docs/technical-specification.md §59.

The FastAPI layer must never call Transformers directly; it only talks to
these interfaces. Real SigLIP2/Qwen3-VL implementations are a follow-up task
(services/ai owner, "ai-mock" task brief) — this module only defines the
contract mock and future real providers must satisfy.
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
