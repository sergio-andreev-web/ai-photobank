import type { CandidateApproveRequest, TagCandidate, TagType } from '~/types/api'
import { TAG_NAME_MAX_LENGTH } from '~/utils/tagForm'

/** What the admin typed on a candidate card before creating the tag. */
export interface CandidateDraft {
  name: string
  type: TagType
  parent_id: number | null
}

export function draftFor(candidate: Pick<TagCandidate, 'display_name' | 'type'>): CandidateDraft {
  return { name: candidate.display_name, type: candidate.type, parent_id: null }
}

/**
 * Body of `POST /tag-candidates/{id}/approve`: the server defaults to the candidate's display_name and
 * type (ADR-0008), so only the fields the admin changed are sent; no body at all when nothing changed.
 */
export function approveRequest(
  candidate: Pick<TagCandidate, 'display_name' | 'type'>,
  draft: CandidateDraft,
): CandidateApproveRequest | undefined {
  const request: CandidateApproveRequest = {}
  const name = draft.name.trim()
  if (name && name !== candidate.display_name) request.name = name
  if (draft.type !== candidate.type) request.type = draft.type
  if (draft.parent_id !== null) request.parent_id = draft.parent_id
  return Object.keys(request).length ? request : undefined
}

export function draftError(draft: CandidateDraft): string | null {
  const name = draft.name.trim()
  if (!name) return 'Введите название тега.'
  if (name.length > TAG_NAME_MAX_LENGTH) return `Не больше ${TAG_NAME_MAX_LENGTH} символов.`
  return null
}

/**
 * Similarity from which the closest existing tag is offered as a one-click merge. The value is the
 * server's cosine similarity of tag embeddings (0..1); below it the hint is shown, but not promoted.
 */
export const STRONG_MATCH = 0.8

export function isStrongMatch(candidate: Pick<TagCandidate, 'closest_tag' | 'similarity'>): boolean {
  return candidate.closest_tag !== null && candidate.similarity !== null && candidate.similarity >= STRONG_MATCH
}

export type ModerationKey = 'next' | 'prev' | 'approve' | 'reject' | 'merge' | 'select'

/** Keyboard shortcuts of the moderation queue; typing in a field or a modifier key disables them. */
export function moderationKey(event: Pick<KeyboardEvent, 'key' | 'ctrlKey' | 'metaKey' | 'altKey' | 'target'>): ModerationKey | null {
  if (event.ctrlKey || event.metaKey || event.altKey) return null
  const target = event.target as { tagName?: string; isContentEditable?: boolean; closest?: (s: string) => unknown } | null
  const tag = target?.tagName?.toUpperCase()
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target?.isContentEditable) return null
  // Open select menus (Reka UI listbox/combobox) handle their own arrows and Enter.
  if (target?.closest?.('[role="listbox"],[role="combobox"],[role="dialog"]')) return null
  // Enter on a focused button or link presses that control; it must not act on the card too.
  if (event.key === 'Enter' && (tag === 'BUTTON' || tag === 'A')) return null

  switch (event.key) {
    case 'ArrowDown':
    case 'j':
    case 'о': // «j» on a Russian layout
      return 'next'
    case 'ArrowUp':
    case 'k':
    case 'л':
      return 'prev'
    case 'Enter':
      return 'approve'
    case 'r':
    case 'к':
      return 'reject'
    case 'm':
    case 'ь':
      return 'merge'
    case 'x':
    case 'ч':
      return 'select'
    default:
      return null
  }
}
