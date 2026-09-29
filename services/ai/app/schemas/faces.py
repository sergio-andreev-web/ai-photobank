"""POST /v1/faces/detect response — contracts/schemas/face-detection.json (ADR-0015)."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from .common import StrictModel


class FaceBox(StrictModel):
    x: float = Field(ge=0, le=1)
    y: float = Field(ge=0, le=1)
    width: float = Field(ge=0, le=1)
    height: float = Field(ge=0, le=1)


class Face(StrictModel):
    box: FaceBox
    score: float = Field(ge=0, le=1)
    landmarks: list[list[float]] = Field(min_length=5, max_length=5)
    embedding: list[float] = Field(min_length=512, max_length=512)


class FaceModelInfo(StrictModel):
    name: str = Field(min_length=1, max_length=255)
    revision: str = Field(min_length=1, max_length=255)


class ImageSize(StrictModel):
    width: int = Field(ge=1)
    height: int = Field(ge=1)


class FaceDetectionResponse(StrictModel):
    request_id: str = Field(min_length=1, max_length=128)
    faces: list[Face] = Field(max_length=100)
    embedding_dimensions: Literal[512] = 512
    model: FaceModelInfo
    image: ImageSize
    processing_ms: int = Field(ge=0)
