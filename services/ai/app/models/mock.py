"""Deterministic mock models (docs/technical-specification.md §72, task brief
"ai-mock"). Same image bytes / same texts always produce the same output, so
backend and QA fixtures (contracts/examples/ai/*.json) stay stable across runs
without any GPU (§73).
"""
from __future__ import annotations

import hashlib
import math
import random

from PIL.Image import Image

from ..schemas.analysis import Concept, OcrItem
from ..schemas.common import TagType
from .base import VisionResult

EMBEDDING_DIMENSIONS = 1152

_CAPTION_POOL = [
    "Two engineers wearing helmets near industrial equipment.",
    "A person standing outdoors near a building.",
    "Close-up of a piece of machinery on a work site.",
    "A group of people gathered in an office setting.",
    "A vehicle parked near a warehouse entrance.",
]

_CONCEPT_POOL: list[tuple[TagType, str]] = [
    (TagType.PERSON, "engineer"),
    (TagType.OBJECT, "protective helmet"),
    (TagType.SCENE, "industrial facility"),
    (TagType.ACTIVITY, "equipment inspection"),
    (TagType.OBJECT, "safety vest"),
    (TagType.LOCATION, "construction site"),
    (TagType.SEASON, "summer"),
    (TagType.WEATHER, "clear sky"),
    (TagType.COLOR, "orange"),
    (TagType.SCENE, "office interior"),
]

_OCR_POOL = ["ЛУКОЙЛ", "EXIT", "STOP", "12-04"]


def _digest_to_int(data: bytes) -> int:
    return int.from_bytes(hashlib.sha256(data).digest(), byteorder="big")


def _deterministic_unit_vector(seed_bytes: bytes, dimensions: int) -> list[float]:
    rng = random.Random(_digest_to_int(seed_bytes))
    raw = [rng.uniform(-1.0, 1.0) for _ in range(dimensions)]
    norm = math.sqrt(sum(component * component for component in raw))
    if norm == 0:
        raw[0] = 1.0
        norm = 1.0
    return [component / norm for component in raw]


class MockVisionModel:
    name = "mock-vision"
    revision = "mock-vision-v1"

    def analyze(self, image: Image) -> VisionResult:
        seed = image.tobytes()
        digest = _digest_to_int(seed)

        caption = _CAPTION_POOL[digest % len(_CAPTION_POOL)]

        concept_count = 2 + (digest % 4)  # 2..5 concepts, deterministic
        concepts: list[Concept] = []
        for offset in range(concept_count):
            tag_type, label = _CONCEPT_POOL[(digest // (offset + 1)) % len(_CONCEPT_POOL)]
            confidence = 0.5 + ((digest >> (offset * 3)) % 500) / 1000  # in [0.5, 0.999]
            concepts.append(Concept(type=tag_type, label=label, confidence=round(min(confidence, 0.99), 3)))

        ocr: list[OcrItem] = []
        if digest % 3 == 0:
            text = _OCR_POOL[digest % len(_OCR_POOL)]
            ocr.append(OcrItem(text=text, confidence=round(0.8 + (digest % 200) / 1000, 3)))

        return VisionResult(caption=caption, ocr=ocr, concepts=concepts)


class MockEmbeddingModel:
    name = "mock-embedding"
    revision = "mock-embedding-v1"
    dimensions = EMBEDDING_DIMENSIONS

    def image_embedding(self, image: Image) -> list[float]:
        return _deterministic_unit_vector(image.tobytes(), self.dimensions)

    def text_embeddings(self, texts: list[str]) -> list[list[float]]:
        return [_deterministic_unit_vector(text.encode("utf-8"), self.dimensions) for text in texts]
