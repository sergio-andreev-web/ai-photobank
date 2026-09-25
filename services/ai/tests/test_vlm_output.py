"""§73 'invalid model output': VLM text is untrusted and must be reduced to
something that satisfies schemas/image-analysis.json, or rejected."""
from __future__ import annotations

import json

import pytest

from app.models.errors import VisionOutputInvalid
from app.models.vlm_output import parse_vision_output
from app.schemas.common import TagType

VALID = {
    "caption": "Two engineers wearing helmets near industrial equipment.",
    "ocr": [{"text": "ЛУКОЙЛ", "confidence": 0.91}],
    "concepts": [
        {"type": "person", "label": "engineer", "confidence": 0.96},
        {"type": "object", "label": "protective helmet", "confidence": 0.97},
    ],
}


def test_parses_plain_json():
    result = parse_vision_output(json.dumps(VALID, ensure_ascii=False))
    assert result.caption == VALID["caption"]
    assert [(o.text, o.confidence) for o in result.ocr] == [("ЛУКОЙЛ", 0.91)]
    assert [(c.type, c.label) for c in result.concepts] == [
        (TagType.PERSON, "engineer"),
        (TagType.OBJECT, "protective helmet"),
    ]


@pytest.mark.parametrize(
    "wrapper",
    [
        "```json\nPAYLOAD\n```",
        "Here is the analysis:\n```\nPAYLOAD\n```\nDone.",
        "Sure! PAYLOAD Hope this helps.",
        '<think>maybe {"caption": 1}</think>\nPAYLOAD',
        "Result {not json} then PAYLOAD",
    ],
)
def test_extracts_json_from_common_wrappers(wrapper):
    result = parse_vision_output(wrapper.replace("PAYLOAD", json.dumps(VALID)))
    assert result.caption == VALID["caption"]
    assert len(result.concepts) == 2


@pytest.mark.parametrize(
    "raw",
    ["", "no json here", "[1, 2, 3]", '{"caption": "unterminated', '{"caption": 42}', '{"concepts": "x"}', '{"ocr": {}}'],
)
def test_rejects_unusable_output(raw):
    with pytest.raises(VisionOutputInvalid):
        parse_vision_output(raw)


def test_unknown_concept_type_maps_to_other():
    raw = json.dumps({"caption": "", "concepts": [{"type": "Animal", "label": "dog", "confidence": 0.8}]})
    assert parse_vision_output(raw).concepts[0].type is TagType.OTHER


def test_concept_type_is_case_insensitive():
    raw = json.dumps({"concepts": [{"type": " Scene ", "label": "office", "confidence": 0.5}]})
    assert parse_vision_output(raw).concepts[0].type is TagType.SCENE


@pytest.mark.parametrize(
    "concept",
    [
        {"type": "object", "label": "", "confidence": 0.5},
        {"type": "object", "label": "   ", "confidence": 0.5},
        {"type": "object", "label": "x" * 256, "confidence": 0.5},
        {"type": "object", "label": 5, "confidence": 0.5},
        {"type": "object", "label": "helmet", "confidence": 1.5},
        {"type": "object", "label": "helmet", "confidence": -0.1},
        {"type": "object", "label": "helmet", "confidence": True},
        {"type": "object", "label": "helmet", "confidence": "high"},
        {"type": "object", "label": "helmet"},
        "helmet",
    ],
)
def test_invalid_concepts_are_dropped_not_fatal(concept):
    raw = json.dumps({"caption": "c", "concepts": [concept, {"type": "color", "label": "orange", "confidence": 0.7}]})
    result = parse_vision_output(raw)
    assert [c.label for c in result.concepts] == ["orange"]


def test_missing_optional_sections_default_to_empty():
    result = parse_vision_output('{"caption": null}')
    assert result.caption == ""
    assert result.ocr == []
    assert result.concepts == []


def test_limits_and_duplicates():
    concepts = [{"type": "object", "label": f"thing {i}", "confidence": 0.5} for i in range(80)]
    concepts.insert(1, dict(concepts[0]))
    ocr = [{"text": f"t{i}", "confidence": 0.9} for i in range(150)] + [{"text": "t0", "confidence": 0.9}]
    raw = json.dumps({"caption": "x" * 5000, "ocr": ocr, "concepts": concepts})
    result = parse_vision_output(raw)
    assert len(result.caption) == 2000
    assert len(result.ocr) == 100
    assert len(result.concepts) == 50
    assert len({c.label for c in result.concepts}) == 50


def test_ocr_items_without_valid_text_or_confidence_are_dropped():
    raw = json.dumps({"ocr": [{"text": " ", "confidence": 0.9}, {"text": "EXIT"}, {"text": "STOP", "confidence": 0.8}]})
    assert [o.text for o in parse_vision_output(raw).ocr] == ["STOP"]


def test_parsed_result_passes_contract_schema():
    from app.schemas.analysis import AnalysisModels, ImageAnalysisResponse

    result = parse_vision_output(json.dumps({"caption": "y" * 3000, "concepts": [{"type": "?", "label": "a", "confidence": 0}]}))
    ImageAnalysisResponse(
        request_id="r-1",
        caption=result.caption,
        ocr=result.ocr,
        concepts=result.concepts,
        embedding=[0.0] * 1151 + [1.0],
        models=AnalysisModels(vision="v", embedding="e", vision_revision="1", embedding_revision="2"),
        prompt_version="image-analysis-v1",
        processing_ms=0,
    )
