from __future__ import annotations

import math

from PIL import Image

from app.models.mock import MockEmbeddingModel, MockVisionModel


def test_mock_vision_model_is_deterministic_per_image_content():
    model = MockVisionModel()
    image_a = Image.new("RGB", (10, 10), color=(1, 2, 3))
    image_b = Image.new("RGB", (10, 10), color=(1, 2, 3))
    image_c = Image.new("RGB", (10, 10), color=(9, 9, 9))

    result_a = model.analyze(image_a)
    result_b = model.analyze(image_b)
    result_c = model.analyze(image_c)

    assert result_a.caption == result_b.caption
    assert result_a.concepts == result_b.concepts
    assert (result_a.caption, result_a.concepts) != (result_c.caption, result_c.concepts)
    assert 1 <= len(result_a.concepts) <= 50


def test_mock_embedding_model_returns_unit_vectors():
    model = MockEmbeddingModel()
    image = Image.new("RGB", (10, 10), color=(4, 5, 6))
    vector = model.image_embedding(image)
    assert len(vector) == 1152
    norm = math.sqrt(sum(c * c for c in vector))
    assert abs(norm - 1.0) < 1e-9


def test_mock_embedding_model_text_batch_matches_order():
    model = MockEmbeddingModel()
    vectors = model.text_embeddings(["one", "two", "three"])
    assert len(vectors) == 3
    assert all(len(v) == 1152 for v in vectors)
    assert vectors[0] != vectors[1]
    # Re-running the same single text reproduces the same vector as in the batch.
    assert model.text_embeddings(["two"])[0] == vectors[1]
