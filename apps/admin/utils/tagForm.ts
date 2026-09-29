import type { Tag, TagPatchRequest, TagType } from '~/types/api'

/** `TagType` from contracts/schemas/enums.json, in contract order. */
export const TAG_TYPES: readonly TagType[] = [
  'object',
  'person',
  'activity',
  'scene',
  'location',
  'season',
  'weather',
  'brand',
  'event',
  'color',
  'concept',
  'other',
]

// Limits from TagCreateRequest / AliasCreateRequest in contracts/public-api.openapi.yaml.
export const TAG_NAME_MAX_LENGTH = 255
export const ALIAS_MAX_LENGTH = 255
export const LOCALE_MAX_LENGTH = 10
export const LOCALE_PATTERN = /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/

export interface TagFormValues {
  name: string
  type: TagType
  parent_id: number | null
}

/**
 * Only the fields that differ from the stored tag: `PATCH /admin/tags/{tag}`
 * needs at least one property, and an unchanged name must not trigger a
 * rename (and a tag embedding rebuild) on the server.
 */
export function buildTagPatch(tag: Pick<Tag, 'name' | 'type' | 'parent_id'>, values: TagFormValues): TagPatchRequest {
  const patch: TagPatchRequest = {}
  const name = values.name.trim()
  if (name !== tag.name) patch.name = name
  if (values.type !== tag.type) patch.type = values.type
  if (values.parent_id !== tag.parent_id) patch.parent_id = values.parent_id
  return patch
}

/** Client-side check that mirrors the request schema; the server stays the authority (422). */
export function validateTagName(name: string): string | null {
  const trimmed = name.trim()
  if (!trimmed) return 'Enter a tag name.'
  if (trimmed.length > TAG_NAME_MAX_LENGTH) return `Use at most ${TAG_NAME_MAX_LENGTH} characters.`
  return null
}

export interface AliasInput {
  alias: string
  locale: string
}

export interface AliasValidation {
  alias: string | null
  locale: string | null
}

export function validateAliasInput(input: AliasInput): AliasValidation {
  const alias = input.alias.trim()
  const locale = input.locale.trim()

  let aliasError: string | null = null
  if (!alias) aliasError = 'Enter an alias.'
  else if (alias.length > ALIAS_MAX_LENGTH) aliasError = `Use at most ${ALIAS_MAX_LENGTH} characters.`

  let localeError: string | null = null
  if (locale && (locale.length > LOCALE_MAX_LENGTH || !LOCALE_PATTERN.test(locale))) {
    localeError = 'Use a language code such as en, ru or pt-BR.'
  }

  return { alias: aliasError, locale: localeError }
}
