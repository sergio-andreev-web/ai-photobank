<?php

namespace App\Services\Faces;

use App\Models\Face;
use App\Support\Vectors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Unknown people» (ADR-0015): unassigned, well-visible faces grouped by similarity. A face
 * joins the first group it is close enough to (single link); groups seen on fewer images than
 * `cluster_min_images` are dropped. Computed on request over the most recent unassigned faces.
 */
final class FaceClusters
{
    private const int MAX_FACES = 2000;

    /**
     * @return list<array{face_ids: list<int>, images_count: int}> biggest first
     */
    public function find(int $limit): array
    {
        $threshold = (float) config('photobank.faces.cluster_threshold');
        $rows = DB::table('faces')
            ->join('images', 'images.id', '=', 'faces.image_id')
            ->whereNull('images.deleted_at')
            ->where('faces.status', Face::UNASSIGNED)
            ->where('faces.size_px', '>=', (int) config('photobank.faces.cluster_min_size_px'))
            ->orderByDesc('faces.size_px')
            ->orderBy('faces.id')
            ->limit(self::MAX_FACES)
            ->get(['faces.id', 'faces.image_id', 'faces.model_revision', 'faces.embedding']);

        /** @var list<array{revision: string, members: list<array{0: int, 1: int, 2: list<float>}>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $vector = Vectors::decode($row->embedding) ?? [];
            $target = null;
            foreach ($groups as $index => $group) {
                if ($group['revision'] !== $row->model_revision) {
                    continue;
                }
                foreach ($group['members'] as [, , $member]) {
                    if (count($member) === count($vector) && Vectors::cosine($vector, $member) >= $threshold) {
                        $target = $index;
                        break 2;
                    }
                }
            }
            $member = [(int) $row->id, (int) $row->image_id, $vector];
            if ($target === null) {
                $groups[] = ['revision' => (string) $row->model_revision, 'members' => [$member]];
            } else {
                $groups[$target]['members'][] = $member;
            }
        }

        $minImages = (int) config('photobank.faces.cluster_min_images');

        return (new Collection($groups))
            ->map(static fn (array $group): array => [
                'face_ids' => array_column($group['members'], 0),
                'images_count' => count(array_unique(array_column($group['members'], 1))),
            ])
            ->filter(static fn (array $group): bool => $group['images_count'] >= $minImages)
            ->sortByDesc('images_count')
            ->take($limit)
            ->values()
            ->all();
    }
}
