"""SigLIP2 EmbeddingModel: text preprocessing and output verification.

Raw vectors come from an EmbeddingEncoder (app/models/transformers_backend.py);
this class enforces the contract invariants on them — count, 1152 dimensions,
finite values, L2 norm 1 (ADR-0009) — so a half-precision overflow can never
leak into pgvector as NaN.
"""
from __future__ import annotations

import math
from typing import Protocol

from PIL.Image import Image

from .errors import InferenceFailed


class EmbeddingEncoder(Protocol):
    dimensions: int

    def encode_images(self, images: list[Image]) -> list[list[float]]: ...

    def encode_texts(self, texts: list[str]) -> list[list[float]]: ...

    def close(self) -> None: ...


def l2_normalize(vector: list[float]) -> list[float]:
    if not all(math.isfinite(component) for component in vector):
        raise InferenceFailed("embedding contains non-finite values")
    norm = math.sqrt(math.fsum(component * component for component in vector))
    if norm == 0:
        raise InferenceFailed("embedding has zero norm")
    return [component / norm for component in vector]


class SiglipEmbeddingModel:
    def __init__(self, *, name: str, revision: str, encoder: EmbeddingEncoder) -> None:
        self.name = name
        self.revision = revision
        self.dimensions = encoder.dimensions
        self._encoder = encoder

    def _finalize(self, vectors: list[list[float]], expected_count: int) -> list[list[float]]:
        if len(vectors) != expected_count:
            raise InferenceFailed(f"encoder returned {len(vectors)} vectors for {expected_count} inputs")
        for vector in vectors:
            if len(vector) != self.dimensions:
                raise InferenceFailed(f"encoder returned {len(vector)} dimensions, expected {self.dimensions}")
        return [l2_normalize(vector) for vector in vectors]

    def image_embedding(self, image: Image) -> list[float]:
        return self._finalize(self._encoder.encode_images([image]), 1)[0]

    def text_embeddings(self, texts: list[str]) -> list[list[float]]:
        if not texts:
            return []
        # SigLIP2 was trained on lowercased text; the model card asks callers to
        # apply the same preprocessing to queries/labels.
        return self._finalize(self._encoder.encode_texts([text.lower() for text in texts]), len(texts))

    def close(self) -> None:
        self._encoder.close()
