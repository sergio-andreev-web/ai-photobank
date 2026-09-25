"""SiglipEmbeddingModel invariants over a fake encoder (no torch)."""
from __future__ import annotations

import math

import pytest
from PIL import Image

from app.models.embedding import SiglipEmbeddingModel, l2_normalize
from app.models.errors import InferenceFailed


class FakeEncoder:
    dimensions = 1152

    def __init__(self, image_vectors=None, text_fn=None):
        self.image_vectors = image_vectors
        self.text_fn = text_fn or (lambda texts: [[float(len(t))] + [1.0] * 1151 for t in texts])
        self.seen_texts: list[str] = []
        self.closed = False

    def encode_images(self, images):
        return self.image_vectors if self.image_vectors is not None else [[2.0] * 1152 for _ in images]

    def encode_texts(self, texts):
        self.seen_texts.extend(texts)
        return self.text_fn(texts)

    def close(self):
        self.closed = True


def make_model(encoder) -> SiglipEmbeddingModel:
    return SiglipEmbeddingModel(name="google/siglip2-so400m-patch14-384", revision="sha", encoder=encoder)


def norm(vector):
    return math.sqrt(sum(c * c for c in vector))


def test_image_embedding_is_l2_normalized():
    vector = make_model(FakeEncoder()).image_embedding(Image.new("RGB", (4, 4)))
    assert len(vector) == 1152
    assert abs(norm(vector) - 1.0) < 1e-9


def test_text_embeddings_keep_order_and_lowercase_input():
    encoder = FakeEncoder()
    vectors = make_model(encoder).text_embeddings(["Инженер", "HELMET"])
    assert encoder.seen_texts == ["инженер", "helmet"]
    assert len(vectors) == 2
    assert vectors[0] != vectors[1]
    assert all(abs(norm(v) - 1.0) < 1e-9 for v in vectors)


def test_empty_text_batch_skips_encoder():
    encoder = FakeEncoder()
    assert make_model(encoder).text_embeddings([]) == []
    assert encoder.seen_texts == []


@pytest.mark.parametrize(
    "vectors",
    [
        [[float("nan")] + [1.0] * 1151],
        [[float("inf")] + [1.0] * 1151],
        [[0.0] * 1152],
        [[1.0] * 768],
        [],
        [[1.0] * 1152, [1.0] * 1152],
    ],
)
def test_invalid_encoder_output_raises_inference_failed(vectors):
    with pytest.raises(InferenceFailed):
        make_model(FakeEncoder(image_vectors=vectors)).image_embedding(Image.new("RGB", (4, 4)))


def test_text_count_mismatch_raises():
    encoder = FakeEncoder(text_fn=lambda texts: [[1.0] * 1152])
    with pytest.raises(InferenceFailed):
        make_model(encoder).text_embeddings(["a", "b"])


def test_l2_normalize_basic():
    assert l2_normalize([3.0, 4.0]) == [0.6, 0.8]


def test_close_releases_encoder():
    encoder = FakeEncoder()
    make_model(encoder).close()
    assert encoder.closed
