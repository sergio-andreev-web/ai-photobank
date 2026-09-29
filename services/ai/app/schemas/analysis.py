"""contracts/schemas/image-analysis.json and contracts/schemas/concepts.json."""
from __future__ import annotations

import re
from typing import Literal

from pydantic import Field, field_validator

from .common import REQUEST_ID_PATTERN, StrictModel, TagType

EMBEDDING_DIMENSIONS = 1152
PROMPT_VERSION_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]*$")
PROMPT_VERSION_MAX_LENGTH = 64


class OcrItem(StrictModel):
    text: str = Field(min_length=1, max_length=2000)
    confidence: float = Field(ge=0, le=1)


class Concept(StrictModel):
    type: TagType
    label: str = Field(min_length=1, max_length=255)
    confidence: float = Field(ge=0, le=1)

    @field_validator("label")
    @classmethod
    def _label_has_non_whitespace(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("label must contain non-whitespace characters")
        return value


class AnalysisModels(StrictModel):
    """image-analysis.json#/$defs/Models (includes weight revisions, ADR-0009)."""

    vision: str = Field(min_length=1, max_length=255)
    embedding: str = Field(min_length=1, max_length=255)
    vision_revision: str = Field(min_length=1, max_length=255)
    embedding_revision: str = Field(min_length=1, max_length=255)


class ImageAnalysisResponse(StrictModel):
    request_id: str = Field(min_length=1, max_length=128)
    caption: str = Field(max_length=2000)
    ocr: list[OcrItem] = Field(max_length=100)
    concepts: list[Concept] = Field(max_length=50)
    embedding: list[float] = Field(min_length=EMBEDDING_DIMENSIONS, max_length=EMBEDDING_DIMENSIONS)
    embedding_dimensions: Literal[1152] = EMBEDDING_DIMENSIONS
    models: AnalysisModels
    prompt_version: str = Field(min_length=1, max_length=PROMPT_VERSION_MAX_LENGTH)
    processing_ms: int = Field(ge=0)

    @field_validator("request_id")
    @classmethod
    def _validate_request_id(cls, value: str) -> str:
        if not REQUEST_ID_PATTERN.match(value):
            raise ValueError("request_id has invalid format")
        return value

    @field_validator("prompt_version")
    @classmethod
    def _validate_prompt_version(cls, value: str) -> str:
        if not PROMPT_VERSION_PATTERN.match(value):
            raise ValueError("prompt_version has invalid format")
        return value

    @field_validator("embedding")
    @classmethod
    def _validate_embedding_finite(cls, value: list[float]) -> list[float]:
        for component in value:
            if component != component or component in (float("inf"), float("-inf")):
                raise ValueError("embedding must contain only finite numbers")
        return value
