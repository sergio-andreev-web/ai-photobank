"""Shared schema test (§71, §73): our Pydantic models must accept the exact
fixtures backend/QA use (contracts/examples/ai/*.json), and must reject
additional properties per the contract's additionalProperties: false.
"""
from __future__ import annotations

import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.schemas.analysis import ImageAnalysisResponse
from app.schemas.embeddings import TextEmbeddingsRequest, TextEmbeddingsResponse

CONTRACTS_EXAMPLES = Path(__file__).resolve().parents[3] / "contracts" / "examples" / "ai"


def _load(name: str) -> dict:
    return json.loads((CONTRACTS_EXAMPLES / name).read_text())


@pytest.mark.skipif(not CONTRACTS_EXAMPLES.exists(), reason="contracts/examples/ai not found")
def test_analyze_response_example_matches_schema():
    example = _load("analyze-response.json")
    model = ImageAnalysisResponse(**example)
    assert model.embedding_dimensions == 1152
    assert len(model.embedding) == 1152


@pytest.mark.skipif(not CONTRACTS_EXAMPLES.exists(), reason="contracts/examples/ai not found")
def test_text_embeddings_request_example_matches_schema():
    example = _load("text-embeddings-request.json")
    TextEmbeddingsRequest(**example)


@pytest.mark.skipif(not CONTRACTS_EXAMPLES.exists(), reason="contracts/examples/ai not found")
def test_text_embeddings_response_example_matches_schema():
    example = _load("text-embeddings-response.json")
    model = TextEmbeddingsResponse(**example)
    assert model.dimensions == 1152
    assert all(len(vector) == 1152 for vector in model.embeddings)


def test_image_analysis_response_rejects_additional_properties():
    example = json.loads((CONTRACTS_EXAMPLES / "analyze-response.json").read_text())
    example["unexpected_field"] = True
    with pytest.raises(ValidationError):
        ImageAnalysisResponse(**example)
