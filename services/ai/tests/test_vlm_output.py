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
    ["", "no json here", "[1, 2, 3]", "{}", '{"summary": "a dog"}', '{"caption": "unterminated', '{"caption": 42}', '{"concepts": "x"}', '{"ocr": {}}'],
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
    result = parse_vision_output('{"caption": "A gray square."}')
    assert result.caption == "A gray square."
    assert result.ocr == []
    assert result.concepts == []

    result = parse_vision_output('{"caption": null, "concepts": [{"type": "color", "label": "gray", "confidence": 0.9}]}')
    assert result.caption == ""
    assert result.ocr == []


@pytest.mark.parametrize(
    "raw",
    [
        '{"caption": null}',
        '{"caption": "   ", "ocr": [], "concepts": []}',
        '{"caption": "", "ocr": [{"text": "EXIT", "confidence": 0.9}], "concepts": []}',
        # every concept dropped as invalid -> nothing left
        '{"caption": "", "concepts": [{"type": "object", "label": "", "confidence": 0.5}]}',
    ],
)
def test_empty_analysis_is_invalid(raw):
    with pytest.raises(VisionOutputInvalid, match="neither caption nor concepts"):
        parse_vision_output(raw)


# Real Qwen3-VL output cut off by AI_VLM_MAX_NEW_TOKENS: the root object never
# closes, so the old parser fell through to the first nested concept.
TRUNCATED = (
    '```json\n{\n  "caption": "Two engineers wearing helmets near industrial equipment.",\n'
    '  "ocr": [{"text": "ЛУКОЙЛ", "confidence": 0.91}],\n'
    '  "concepts": [\n'
    '    {"type": "person", "label": "engineer", "confidence": 0.96},\n'
    '    {"type": "object", "label": "protective helmet", "confidence": 0.97},\n'
    '    {"type": "scene", "label": "industr'
)


def test_truncated_output_keeps_caption_and_complete_concepts():
    result = parse_vision_output(TRUNCATED)
    assert result.caption == VALID["caption"]
    assert [o.text for o in result.ocr] == ["ЛУКОЙЛ"]
    assert [(c.type, c.label) for c in result.concepts] == [
        (TagType.PERSON, "engineer"),
        (TagType.OBJECT, "protective helmet"),
    ]


ENGINEER = '{"type": "person", "label": "engineer", "confidence": 0.96}'


@pytest.mark.parametrize(
    ("cut", "labels"),
    [
        ("", []),
        ('"conc', []),
        ('"concepts": [', []),
        ('"concepts": [\n    {"type": "pers', []),
        ('"concepts": [\n    ' + ENGINEER, ["engineer"]),
        ('"concepts": [\n    ' + ENGINEER + ",", ["engineer"]),
        ('"concepts": [\n    ' + ENGINEER + ',\n    {"type": "object", ', ["engineer"]),
        ('"concepts": [\n    ' + ENGINEER + ',\n    {"type": "object", "label": "x", "confidence": 0.', ["engineer"]),
        ('"concepts": [\n    ' + ENGINEER + "\n  ]\n", ["engineer"]),
        ('"concepts": [\n    ' + ENGINEER + '\n  ],\n  "ext', ["engineer"]),
    ],
)
def test_truncation_at_any_point_after_caption(cut, labels):
    raw = '{\n  "caption": "A gray square.",\n  "ocr": [],\n  ' + cut
    result = parse_vision_output(raw)
    assert result.caption == "A gray square."
    assert [c.label for c in result.concepts] == labels


def test_truncated_output_without_caption_is_invalid():
    # Caption itself cut off: nothing usable -> retry / MODEL_OUTPUT_INVALID.
    with pytest.raises(VisionOutputInvalid):
        parse_vision_output('{"caption": "Two engineers wear')
    with pytest.raises(VisionOutputInvalid):
        parse_vision_output('```json\n{\n  "capt')


def test_truncated_ocr_keeps_caption():
    result = parse_vision_output('{"caption": "A sign.", "ocr": [{"text": "EXIT", "confidence": 0.9}, {"text": "ST')
    assert result.caption == "A sign."
    assert [o.text for o in result.ocr] == ["EXIT"]
    assert result.concepts == []


@pytest.mark.parametrize(
    "raw",
    [
        # only nested objects decode; none of them is an analysis root
        '[{"type": "object", "label": "helmet", "confidence": 0.9}]',
        '[{"type": "object", "label": "helmet", "confidence": 0.9}, {"type": "sce',
        'Concepts: {"type": "object", "label": "helmet", "confidence": 0.9}',
        '{"type": "object", "label": "helmet", "confidence": 0.9, "extra": {"caption": "x"',
    ],
)
def test_nested_objects_are_never_taken_for_the_root(raw):
    with pytest.raises(VisionOutputInvalid):
        parse_vision_output(raw)


def test_malformed_but_closed_root_is_not_salvaged():
    # Closed object with broken JSON inside is not a cut-off; leave it to a retry.
    raw = '{"caption": "A.", "concepts": [{"type": "object", "label": "helmet", "confidence": 0.9},]}'
    with pytest.raises(VisionOutputInvalid):
        parse_vision_output(raw)


def test_complete_root_preferred_over_leading_fragments():
    raw = 'Example: {"type": "object", "label": "x", "confidence": 1} ' + json.dumps(VALID)
    assert parse_vision_output(raw).caption == VALID["caption"]


def test_braces_inside_truncated_strings_do_not_close_root():
    raw = '{"caption": "A sign reading \\"{OPEN}\\" on a door.", "concepts": [{"type": "object", "label": "sign", "confidence": 0.8}, {"ty'
    result = parse_vision_output(raw)
    assert result.caption == 'A sign reading "{OPEN}" on a door.'
    assert [c.label for c in result.concepts] == ["sign"]


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
    raw = json.dumps({"caption": "Signs.", "ocr": [{"text": " ", "confidence": 0.9}, {"text": "EXIT"}, {"text": "STOP", "confidence": 0.8}]})
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
