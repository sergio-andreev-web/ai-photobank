<?php

namespace App\Http\Controllers;

use App\Contracts\Storage\PhotoStorageInterface;
use App\Exceptions\ApiException;
use App\Models\Image;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * contracts/public-api.openapi.yaml — GET /api/media/images/{image}/{variant} (ТЗ §57; ADR-0011).
 *
 * ADR-0011 prefers nginx X-Accel-Redirect in Docker, but explicitly allows a
 * PHP fallback serving the same client URL outside Docker; the nginx wiring
 * itself is infra-owned (infra/**), so this always streams the file directly.
 */
class MediaController extends Controller
{
    public function show(Request $request, PhotoStorageInterface $storage, int $image, string $variant): Response
    {
        // Images in the trash are served too, so the trash can be browsed (ADR-0013).
        $imageModel = Image::withTrashed()->find($image);

        if ($imageModel === null) {
            throw new ApiException('IMAGE_NOT_FOUND', 404, 'Image not found');
        }

        [$storageKey, $mimeType] = match ($variant) {
            'original' => [$imageModel->storage_key, $imageModel->mime_type],
            'preview' => [$imageModel->preview_key, 'image/webp'],
            'thumbnail' => [$imageModel->thumbnail_key, 'image/webp'],
            default => throw new ApiException('MEDIA_NOT_AVAILABLE', 404, 'Unknown media variant', [
                'variant' => $variant,
            ]),
        };

        if ($storageKey === null || ! $storage->exists($storageKey)) {
            throw new ApiException('MEDIA_NOT_AVAILABLE', 404, ucfirst($variant).' is not generated yet', [
                'variant' => $variant,
            ]);
        }

        $absolutePath = $storage->path($storageKey);
        $etag = '"'.$this->etagFor($variant, $imageModel, $absolutePath).'"';
        $maxAge = (int) config('photobank.media.cache_max_age');

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return response('', 304, [
                'Cache-Control' => "private, max-age={$maxAge}",
                'ETag' => $etag,
            ]);
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Cache-Control' => "private, max-age={$maxAge}",
            'ETag' => $etag,
        ];

        if ($variant === 'original') {
            $filename = $this->sanitizeDownloadName($imageModel->original_name, $imageModel->extension);
            $headers['Content-Disposition'] = 'inline; filename="'.$filename.'"';
        }

        // BinaryFileResponse marks itself public by default, dropping the private directive.
        return response()->file($absolutePath, $headers)->setPrivate();
    }

    private function etagFor(string $variant, Image $image, string $absolutePath): string
    {
        if ($variant === 'original') {
            return $image->sha256;
        }

        return md5($absolutePath.filemtime($absolutePath).filesize($absolutePath));
    }

    private function sanitizeDownloadName(string $originalName, string $extension): string
    {
        $safe = preg_replace('/[^\w.\- ]+/u', '_', $originalName) ?? 'file';
        $safe = trim($safe) !== '' ? trim($safe) : 'file.'.$extension;

        return str_replace('"', '', $safe);
    }
}
