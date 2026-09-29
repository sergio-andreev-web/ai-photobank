"""Face detection and face embeddings (ADR-0015): InsightFace `buffalo_l` over onnxruntime.

- SCRFD-10G (`det_10g.onnx`) finds faces with 5 landmarks;
- each face is aligned to the ArcFace 112×112 template by a similarity transform;
- ArcFace R50 trained on WebFace600K (`w600k_r50.onnx`) turns it into a 512-dim vector,
  L2-normalized, so cosine similarity is a dot product.

The service returns faces and vectors only; who a face belongs to is decided by the backend
(the AI service knows no people or IDs, `.agents`). Runs on CPU: onnxruntime does not share the
MPS device with PyTorch, so faces can be computed while Qwen generates (see concurrency.py).
The implementation follows insightface/model_zoo/{scrfd,arcface_onnx}.py and utils/face_align.py
without the insightface package (it needs a compiler) or OpenCV.
"""
from __future__ import annotations

import math
from dataclasses import dataclass
from pathlib import Path
from typing import Protocol

import numpy as np
from PIL import Image as PILImage
from PIL.Image import Image

DETECTOR_FILE = "det_10g.onnx"
EMBEDDER_FILE = "w600k_r50.onnx"
EMBEDDING_DIMENSIONS = 512

# ArcFace landmark template for a 112×112 crop: eyes, nose tip, mouth corners.
ARCFACE_TEMPLATE = np.array(
    [[38.2946, 51.6963], [73.5318, 51.5014], [56.0252, 71.7366], [41.5493, 92.3655], [70.7299, 92.2041]],
    dtype=np.float32,
)


@dataclass(frozen=True)
class DetectedFace:
    # Pixel box of the face in the analyzed image: left, top, right, bottom.
    box: tuple[float, float, float, float]
    score: float
    landmarks: tuple[tuple[float, float], ...]
    embedding: list[float]


class FaceModel(Protocol):
    name: str
    revision: str

    def detect(self, image: Image) -> list[DetectedFace]: ...


def _nms(boxes: np.ndarray, scores: np.ndarray, threshold: float) -> list[int]:
    x1, y1, x2, y2 = boxes.T
    areas = (x2 - x1 + 1) * (y2 - y1 + 1)
    order = scores.argsort()[::-1]
    keep: list[int] = []
    while order.size:
        i = int(order[0])
        keep.append(i)
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])
        inter = np.maximum(0.0, xx2 - xx1 + 1) * np.maximum(0.0, yy2 - yy1 + 1)
        overlap = inter / (areas[i] + areas[order[1:]] - inter)
        order = order[1:][overlap <= threshold]
    return keep


def similarity_transform(src: np.ndarray, dst: np.ndarray) -> np.ndarray:
    """Umeyama: the 2×3 rotation+uniform scale+translation that best maps src onto dst."""
    src_mean, dst_mean = src.mean(axis=0), dst.mean(axis=0)
    src_c, dst_c = src - src_mean, dst - dst_mean
    cov = dst_c.T @ src_c / len(src)
    u, s, vt = np.linalg.svd(cov)
    d = np.ones(2)
    if np.linalg.det(u) * np.linalg.det(vt) < 0:
        d[1] = -1
    rotation = u @ np.diag(d) @ vt
    scale = (s * d).sum() / src_c.var(axis=0).sum()
    translation = dst_mean - scale * rotation @ src_mean
    return np.hstack([scale * rotation, translation[:, None]])


def align_face(image: Image, landmarks: np.ndarray, size: int = 112) -> Image:
    """Crop the face so its landmarks land on the ArcFace template (norm_crop)."""
    matrix = similarity_transform(landmarks.astype(np.float64), ARCFACE_TEMPLATE.astype(np.float64) * (size / 112.0))
    # PIL maps output → input pixels, so it needs the inverse transform.
    inverse = np.linalg.inv(np.vstack([matrix, [0.0, 0.0, 1.0]]))[:2]
    return image.transform((size, size), PILImage.Transform.AFFINE, inverse.flatten().tolist(), resample=PILImage.Resampling.BILINEAR)


class OnnxFaceModel:
    name = "insightface/buffalo_l"

    def __init__(
        self,
        model_dir: Path,
        *,
        revision: str,
        detection_threshold: float = 0.5,
        min_face_pixels: int = 32,
        input_size: int = 640,
    ) -> None:
        import onnxruntime

        options = onnxruntime.SessionOptions()
        options.log_severity_level = 3
        providers = ["CPUExecutionProvider"]
        self._detector = onnxruntime.InferenceSession(str(model_dir / DETECTOR_FILE), options, providers=providers)
        self._embedder = onnxruntime.InferenceSession(str(model_dir / EMBEDDER_FILE), options, providers=providers)
        self._det_input = self._detector.get_inputs()[0].name
        self._emb_input = self._embedder.get_inputs()[0].name
        self.revision = revision
        self._threshold = detection_threshold
        self._min_face = min_face_pixels
        self._size = input_size

    # ---- detection (SCRFD) -------------------------------------------------------------------

    def _detect(self, image: Image) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
        width, height = image.size
        scale = min(self._size / width, self._size / height)
        new_w, new_h = max(1, int(width * scale)), max(1, int(height * scale))
        canvas = np.zeros((self._size, self._size, 3), dtype=np.float32)
        canvas[:new_h, :new_w] = np.asarray(image.convert("RGB").resize((new_w, new_h), PILImage.Resampling.BILINEAR), dtype=np.float32)
        blob = ((canvas - 127.5) / 128.0).transpose(2, 0, 1)[None]

        outputs = self._detector.run(None, {self._det_input: blob})
        if outputs[0].ndim == 3:
            outputs = [out[0] for out in outputs]

        scores_all, boxes_all, points_all = [], [], []
        for index, stride in enumerate((8, 16, 32)):
            scores = outputs[index].reshape(-1)
            distances = outputs[index + 3] * stride
            point_distances = outputs[index + 6] * stride
            cells = self._size // stride
            grid = np.stack(np.mgrid[:cells, :cells][::-1], axis=-1).astype(np.float32).reshape(-1, 2) * stride
            centers = np.repeat(grid, 2, axis=0)  # two anchors per cell
            keep = scores >= self._threshold
            if not keep.any():
                continue
            c, d, p = centers[keep], distances[keep], point_distances[keep]
            boxes_all.append(np.stack([c[:, 0] - d[:, 0], c[:, 1] - d[:, 1], c[:, 0] + d[:, 2], c[:, 1] + d[:, 3]], axis=-1))
            points_all.append(np.stack([c + p[:, 2 * i : 2 * i + 2] for i in range(5)], axis=1))
            scores_all.append(scores[keep])

        if not scores_all:
            return np.zeros((0, 4)), np.zeros((0,)), np.zeros((0, 5, 2))
        boxes = np.concatenate(boxes_all) / scale
        scores = np.concatenate(scores_all)
        points = np.concatenate(points_all) / scale
        keep = _nms(boxes, scores, 0.4)
        return boxes[keep], scores[keep], points[keep]

    # ---- embedding (ArcFace) -----------------------------------------------------------------

    def _embed(self, faces: list[Image]) -> np.ndarray:
        batch = np.stack([(np.asarray(face, dtype=np.float32) - 127.5) / 127.5 for face in faces]).transpose(0, 3, 1, 2)
        vectors = self._embedder.run(None, {self._emb_input: batch})[0]
        norms = np.linalg.norm(vectors, axis=1, keepdims=True)
        return vectors / np.maximum(norms, 1e-12)

    def detect(self, image: Image) -> list[DetectedFace]:
        rgb = image.convert("RGB")
        width, height = rgb.size
        boxes, scores, points = self._detect(rgb)
        chosen = [
            i for i in range(len(scores))
            if min(boxes[i, 2] - boxes[i, 0], boxes[i, 3] - boxes[i, 1]) >= self._min_face
        ]
        if not chosen:
            return []
        vectors = self._embed([align_face(rgb, points[i]) for i in chosen])
        faces = []
        for row, i in enumerate(chosen):
            x1, y1, x2, y2 = boxes[i]
            faces.append(
                DetectedFace(
                    box=(max(0.0, float(x1)), max(0.0, float(y1)), min(float(width), float(x2)), min(float(height), float(y2))),
                    score=float(scores[i]),
                    landmarks=tuple((float(x), float(y)) for x, y in points[i]),
                    embedding=[float(v) for v in vectors[row]],
                )
            )
        # Largest faces first: the people the photo is about.
        faces.sort(key=lambda f: (f.box[2] - f.box[0]) * (f.box[3] - f.box[1]), reverse=True)
        return faces


class MockFaceModel:
    """Deterministic faces for CI (§72): one centered face whose vector depends on the image
    content, so the same image always yields the same vector and different images differ."""

    name = "mock-faces"
    revision = "mock-faces-v1"

    def detect(self, image: Image) -> list[DetectedFace]:
        import hashlib

        width, height = image.size
        digest = hashlib.sha256(image.convert("RGB").resize((8, 8)).tobytes()).digest()
        raw = [((digest[i % len(digest)] + i) % 251) / 250.0 - 0.5 for i in range(EMBEDDING_DIMENSIONS)]
        norm = math.sqrt(sum(v * v for v in raw)) or 1.0
        box = (width * 0.3, height * 0.2, width * 0.7, height * 0.8)
        cx, cy = width * 0.5, height * 0.5
        landmarks = ((cx - width * 0.08, cy - height * 0.1), (cx + width * 0.08, cy - height * 0.1), (cx, cy), (cx - width * 0.06, cy + height * 0.12), (cx + width * 0.06, cy + height * 0.12))
        return [DetectedFace(box=box, score=0.99, landmarks=landmarks, embedding=[v / norm for v in raw])]


def cosine(a: list[float], b: list[float]) -> float:
    return float(sum(x * y for x, y in zip(a, b)))


def sha256_prefix(path: Path, length: int = 12) -> str:
    import hashlib

    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()[:length]


def model_revision(model_dir: Path) -> str:
    """Version of the pair of weights: vectors of different revisions must not be compared (§77)."""
    return f"{sha256_prefix(model_dir / DETECTOR_FILE)}-{sha256_prefix(model_dir / EMBEDDER_FILE)}"


def is_finite_unit(vector: list[float]) -> bool:
    return all(math.isfinite(v) for v in vector) and abs(math.sqrt(sum(v * v for v in vector)) - 1.0) < 1e-3
