<?php

namespace App\Contracts\Ai\Data;

/**
 * schemas/enums.json #/$defs/TagType, as returned by AI in concept.type (§16, ADR-0010).
 * Mirrors the contract catalog; it is not the canonical tags.type enum owned by the
 * taxonomy domain, only the shape of an untrusted AI response field.
 */
enum ConceptType: string
{
    case Object = 'object';
    case Person = 'person';
    case Activity = 'activity';
    case Scene = 'scene';
    case Location = 'location';
    case Season = 'season';
    case Weather = 'weather';
    case Brand = 'brand';
    case Event = 'event';
    case Color = 'color';
    case Concept = 'concept';
    case Other = 'other';
}
