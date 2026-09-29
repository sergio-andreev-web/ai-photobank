#!/usr/bin/env bash
# Downloads the face models of ADR-0015 (InsightFace buffalo_l, release v0.7) once and keeps only
# the two files the AI service uses. Checksums pin the exact weights; a mismatch aborts.
# InsightFace models are licensed for non-commercial use only.
#
#   infra/scripts/fetch-face-model.sh [target_dir]   # default: ~/.cache/photobank-ai/insightface/buffalo_l
set -euo pipefail

TARGET="${1:-${FACE_MODEL_DIR:-$HOME/.cache/photobank-ai/insightface/buffalo_l}}"
URL="https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_l.zip"
ZIP_SHA256="80ffe37d8a5940d59a7384c201a2a38d4741f2f3c51eef46ebb28218a7b0ca2f"
DET_SHA256="5838f7fe053675b1c7a08b633df49e7af5495cee0493c7dcf6697200b85b5b91"
EMB_SHA256="4c06341c33c2ca1f86781dab0e829f88ad5b64be9fba56e56bc9ebdefc619e43"

check() { [ -f "$1" ] && [ "$(shasum -a 256 "$1" | cut -d' ' -f1)" = "$2" ]; }

if check "$TARGET/det_10g.onnx" "$DET_SHA256" && check "$TARGET/w600k_r50.onnx" "$EMB_SHA256"; then
  echo "✓ Face models present: $TARGET"
  exit 0
fi

mkdir -p "$TARGET"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
echo "Downloading face models (InsightFace buffalo_l, ~280 MB, non-commercial license)..."
curl -fSL --retry 3 -o "$tmp/buffalo_l.zip" "$URL"
check "$tmp/buffalo_l.zip" "$ZIP_SHA256" || { echo "❌ buffalo_l.zip checksum mismatch" >&2; exit 1; }
unzip -q -o "$tmp/buffalo_l.zip" det_10g.onnx w600k_r50.onnx -d "$tmp/x"
check "$tmp/x/det_10g.onnx" "$DET_SHA256" && check "$tmp/x/w600k_r50.onnx" "$EMB_SHA256" \
  || { echo "❌ model file checksum mismatch" >&2; exit 1; }
mv "$tmp/x/det_10g.onnx" "$tmp/x/w600k_r50.onnx" "$TARGET/"
echo "✓ Face models installed: $TARGET"
