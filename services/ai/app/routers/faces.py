from __future__ import annotations

import time

from fastapi import APIRouter, Depends, File, Form, Request, UploadFile

from ..concurrency import InferenceLimiters
from ..config import Settings
from ..inference import translate_model_errors
from ..preprocessing import preprocess_image
from ..schemas.common import is_valid_request_id
from ..schemas.errors import InvalidRequestError, ModelNotReadyError
from ..schemas.faces import Face, FaceBox, FaceDetectionResponse, FaceModelInfo, ImageSize
from ..security import require_api_key

router = APIRouter(tags=["inference"])

MAX_FACES = 100


@router.post("/v1/faces/detect", response_model=FaceDetectionResponse)
def detect_faces(
    request: Request,
    image: UploadFile | None = File(None),
    request_id: str | None = Form(None),
    _auth: None = Depends(require_api_key),
) -> FaceDetectionResponse:
    """Faces and their ArcFace vectors (ADR-0015); who they are is the backend's business."""
    settings: Settings = request.app.state.settings
    limiters: InferenceLimiters = request.app.state.limiters
    model = request.app.state.face_model

    missing_fields = {}
    if image is None:
        missing_fields["image"] = ["required"]
    if not request_id:
        missing_fields["request_id"] = ["required"]
    if missing_fields:
        raise InvalidRequestError(
            'Field "image" is required' if "image" in missing_fields else 'Field "request_id" is required',
            details={"fields": missing_fields},
        )
    if not is_valid_request_id(request_id):
        raise InvalidRequestError(
            'Field "request_id" has invalid format',
            details={"fields": {"request_id": ["format"]}},
            request_id=None,
        )
    if model is None:
        # FACES_ENABLED=false or the model files are missing (see ai_service.faces_model_missing).
        raise ModelNotReadyError("faces_unavailable", retry_after=60, request_id=request_id)

    processed = preprocess_image(
        image.file.read(),
        max_bytes=settings.ai_max_image_bytes,
        max_dimension=settings.ai_max_image_dimension,
        request_id=request_id,
    )
    width, height = processed.size

    with limiters.faces.slot(request_id=request_id, wait_seconds=settings.ai_inference_timeout_seconds):
        started = time.monotonic()
        with translate_model_errors(request_id=request_id, prompt_version=settings.prompt_version):
            detected = model.detect(processed)
        processing_ms = int((time.monotonic() - started) * 1000)

    def clamp(value: float) -> float:
        return min(1.0, max(0.0, value))

    faces = [
        Face(
            box=FaceBox(
                x=clamp(face.box[0] / width),
                y=clamp(face.box[1] / height),
                width=clamp((face.box[2] - face.box[0]) / width),
                height=clamp((face.box[3] - face.box[1]) / height),
            ),
            score=clamp(face.score),
            landmarks=[[x / width, y / height] for x, y in face.landmarks],
            embedding=face.embedding,
        )
        for face in detected[:MAX_FACES]
    ]

    return FaceDetectionResponse(
        request_id=request_id,
        faces=faces,
        model=FaceModelInfo(name=model.name, revision=model.revision),
        image=ImageSize(width=width, height=height),
        processing_ms=processing_ms,
    )
