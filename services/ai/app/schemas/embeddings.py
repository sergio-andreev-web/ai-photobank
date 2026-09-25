"""contracts/schemas/text-embeddings.json"""
from __future__ import annotations

from typing import Literal

from pydantic import Field, field_validator

from .common import StrictModel

EMBEDDING_DIMENSIONS = 1152
MAX_BATCH = 64


class TextEmbeddingsRequest(StrictModel):
    texts: list[str] = Field(min_length=1, max_length=MAX_BATCH)

    @field_validator("texts")
    @classmethod
    def _validate_texts(cls, value: list[str]) -> list[str]:
        for text in value:
            if not (1 <= len(text) <= 1000):
                raise ValueError("each text must be between 1 and 1000 characters")
            if not text.strip():
                raise ValueError("each text must contain a non-whitespace character")
        return value


class TextEmbeddingsResponse(StrictModel):
    model: str = Field(min_length=1, max_length=255)
    revision: str = Field(min_length=1, max_length=255)
    dimensions: Literal[1152] = EMBEDDING_DIMENSIONS
    embeddings: list[list[float]] = Field(min_length=1, max_length=MAX_BATCH)

    @field_validator("embeddings")
    @classmethod
    def _validate_embeddings_shape(cls, value: list[list[float]]) -> list[list[float]]:
        for vector in value:
            if len(vector) != EMBEDDING_DIMENSIONS:
                raise ValueError(f"each embedding must have exactly {EMBEDDING_DIMENSIONS} dimensions")
        return value
