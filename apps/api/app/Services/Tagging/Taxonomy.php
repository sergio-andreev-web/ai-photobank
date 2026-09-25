<?php

namespace App\Services\Tagging;

/**
 * Taxonomy values from contracts/schemas/enums.json (TagType, TagStatus,
 * TagCandidateStatus, ImageTagSource). Named constants only — not a new enum.
 */
final class Taxonomy
{
    /** @var list<string> */
    public const array TAG_TYPES = [
        'object', 'person', 'activity', 'scene', 'location', 'season',
        'weather', 'brand', 'event', 'color', 'concept', 'other',
    ];

    public const string TAG_ACTIVE = 'active';

    public const string TAG_ARCHIVED = 'archived';

    public const string CANDIDATE_PENDING = 'pending';

    public const string CANDIDATE_CREATED = 'created';

    public const string CANDIDATE_MERGED = 'merged';

    public const string CANDIDATE_REJECTED = 'rejected';

    /** @var list<string> */
    public const array CANDIDATE_STATUSES = [
        self::CANDIDATE_PENDING, self::CANDIDATE_CREATED, self::CANDIDATE_MERGED, self::CANDIDATE_REJECTED,
    ];

    public const string SOURCE_AI = 'ai';

    public const string SOURCE_MANUAL = 'manual';
}
