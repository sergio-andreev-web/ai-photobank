from __future__ import annotations

import io

import pytest
from PIL import Image

from app.preprocessing import preprocess_image, sniff_mime
from app.schemas.errors import ImageDecodeFailedError, ImageTooLargeError, UnsupportedMediaTypeError


def _jpeg_bytes(size=(32, 32)) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", size, color=(1, 2, 3)).save(buffer, format="JPEG")
    return buffer.getvalue()


def test_sniff_mime_detects_jpeg_png_webp():
    assert sniff_mime(_jpeg_bytes()) == "image/jpeg"

    png_buffer = io.BytesIO()
    Image.new("RGB", (4, 4)).save(png_buffer, format="PNG")
    assert sniff_mime(png_buffer.getvalue()) == "image/png"

    webp_buffer = io.BytesIO()
    Image.new("RGB", (4, 4)).save(webp_buffer, format="WEBP")
    assert sniff_mime(webp_buffer.getvalue()) == "image/webp"


def test_sniff_mime_detects_disallowed_types_for_error_details():
    assert sniff_mime(b"%PDF-1.4 rest of file") == "application/pdf"
    assert sniff_mime(b"GIF89a....") == "image/gif"
    assert sniff_mime(b"totally unknown bytes") == "application/octet-stream"


def test_preprocess_image_normalizes_mode_and_caps_dimensions():
    buffer = io.BytesIO()
    Image.new("RGB", (4000, 10), color=(5, 6, 7)).save(buffer, format="JPEG")
    image = preprocess_image(buffer.getvalue(), max_bytes=50_000_000, max_dimension=1000)
    assert image.mode == "RGB"
    assert max(image.size) <= 1000


def test_preprocess_image_rejects_oversized_payload():
    data = _jpeg_bytes()
    with pytest.raises(ImageTooLargeError):
        preprocess_image(data, max_bytes=len(data) - 1, max_dimension=1000)


def test_preprocess_image_rejects_unsupported_mime():
    with pytest.raises(UnsupportedMediaTypeError):
        preprocess_image(b"%PDF-1.4 not an image", max_bytes=1000, max_dimension=1000)


def test_preprocess_image_rejects_corrupt_bytes_with_valid_signature():
    corrupt = b"\xff\xd8\xff" + b"not a real jpeg body" * 5
    with pytest.raises(ImageDecodeFailedError):
        preprocess_image(corrupt, max_bytes=len(corrupt) + 10, max_dimension=1000)


def test_preprocess_image_applies_exif_orientation():
    base = Image.new("RGB", (20, 10), color=(9, 9, 9))
    exif = base.getexif()
    exif[0x0112] = 6  # Orientation: rotate 270
    buffer = io.BytesIO()
    base.save(buffer, format="JPEG", exif=exif)
    image = preprocess_image(buffer.getvalue(), max_bytes=1_000_000, max_dimension=1000)
    # Orientation 6 swaps width/height for a non-square source.
    assert image.size == (10, 20)
