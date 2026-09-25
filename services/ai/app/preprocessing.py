"""Image intake pipeline per docs/technical-specification.md §62/§63.

validate MIME by content -> decode -> correct EXIF orientation -> convert RGB
-> limit resolution. Never trusts the client-supplied filename/extension.
"""
from __future__ import annotations

import io

from PIL import Image, ImageOps, UnidentifiedImageError

from .schemas.errors import ImageDecodeFailedError, ImageTooLargeError, UnsupportedMediaTypeError

ALLOWED_MIME_TYPES = {"image/jpeg", "image/png", "image/webp"}

_SIGNATURES: list[tuple[bytes, str]] = [
    (b"\xff\xd8\xff", "image/jpeg"),
    (b"\x89PNG\r\n\x1a\n", "image/png"),
    (b"GIF87a", "image/gif"),
    (b"GIF89a", "image/gif"),
    (b"BM", "image/bmp"),
    (b"%PDF", "application/pdf"),
    (b"II*\x00", "image/tiff"),
    (b"MM\x00*", "image/tiff"),
]


def sniff_mime(data: bytes) -> str:
    """Best-effort content sniffing, independent of any file extension."""
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    for signature, mime in _SIGNATURES:
        if data.startswith(signature):
            return mime
    return "application/octet-stream"


def validate_size(data: bytes, max_bytes: int, *, request_id: str | None) -> None:
    if len(data) > max_bytes:
        raise ImageTooLargeError(max_bytes, request_id=request_id)


def preprocess_image(
    data: bytes,
    *,
    max_bytes: int,
    max_dimension: int,
    request_id: str | None = None,
) -> Image.Image:
    """Return a decoded, EXIF-corrected, RGB, resolution-limited image.

    Raises ImageTooLargeError, UnsupportedMediaTypeError or
    ImageDecodeFailedError — never a bare PIL/OS exception.
    """
    validate_size(data, max_bytes, request_id=request_id)

    detected_mime = sniff_mime(data)
    if detected_mime not in ALLOWED_MIME_TYPES:
        raise UnsupportedMediaTypeError(detected_mime, request_id=request_id)

    try:
        image = Image.open(io.BytesIO(data))
        image.load()
    except (UnidentifiedImageError, OSError, ValueError):
        raise ImageDecodeFailedError(request_id=request_id) from None

    image = ImageOps.exif_transpose(image)
    if image is None:
        raise ImageDecodeFailedError(request_id=request_id)

    image = image.convert("RGB")

    if max(image.size) > max_dimension:
        image.thumbnail((max_dimension, max_dimension), Image.LANCZOS)

    return image
