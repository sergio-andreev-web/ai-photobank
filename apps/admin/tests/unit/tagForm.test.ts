import { describe, it, expect } from 'vitest'
import {
  ALIAS_MAX_LENGTH,
  TAG_NAME_MAX_LENGTH,
  TAG_TYPES,
  buildTagPatch,
  validateAliasInput,
  validateTagName,
} from '~/utils/tagForm'

const stored = { name: 'Каска', type: 'object' as const, parent_id: null as number | null }

describe('buildTagPatch', () => {
  it('is empty when nothing changed, so the UI sends no request', () => {
    expect(buildTagPatch(stored, { name: 'Каска', type: 'object', parent_id: null })).toEqual({})
  })

  it('contains only the changed fields', () => {
    expect(buildTagPatch(stored, { name: 'Защитная каска', type: 'object', parent_id: null })).toEqual({ name: 'Защитная каска' })
    expect(buildTagPatch(stored, { name: 'Каска', type: 'brand', parent_id: null })).toEqual({ type: 'brand' })
    expect(buildTagPatch(stored, { name: 'Каска', type: 'object', parent_id: 7 })).toEqual({ parent_id: 7 })
  })

  it('trims the name before comparing and sending it', () => {
    expect(buildTagPatch(stored, { name: '  Каска  ', type: 'object', parent_id: null })).toEqual({})
    expect(buildTagPatch(stored, { name: '  Шлем ', type: 'object', parent_id: null })).toEqual({ name: 'Шлем' })
  })

  it('clears the parent with an explicit null', () => {
    expect(buildTagPatch({ ...stored, parent_id: 7 }, { name: 'Каска', type: 'object', parent_id: null })).toEqual({ parent_id: null })
  })
})

describe('validateTagName', () => {
  it('rejects blank names and accepts the maximum length', () => {
    expect(validateTagName('')).not.toBeNull()
    expect(validateTagName('   ')).not.toBeNull()
    expect(validateTagName('Каска')).toBeNull()
    expect(validateTagName('x'.repeat(TAG_NAME_MAX_LENGTH))).toBeNull()
    expect(validateTagName('x'.repeat(TAG_NAME_MAX_LENGTH + 1))).not.toBeNull()
  })
})

describe('validateAliasInput', () => {
  it('requires an alias but not a locale', () => {
    expect(validateAliasInput({ alias: 'helmet', locale: '' })).toEqual({ alias: null, locale: null })
    expect(validateAliasInput({ alias: '  ', locale: '' }).alias).not.toBeNull()
    expect(validateAliasInput({ alias: 'x'.repeat(ALIAS_MAX_LENGTH + 1), locale: '' }).alias).not.toBeNull()
  })

  it('follows the locale pattern from the contract', () => {
    for (const locale of ['en', 'ru', 'pt-BR', 'zh-Hans', 'fil']) {
      expect(validateAliasInput({ alias: 'a', locale }).locale, locale).toBeNull()
    }
    for (const locale of ['E', 'English', 'en_US', 'EN', 'en-', 'toolonglocale']) {
      expect(validateAliasInput({ alias: 'a', locale }).locale, locale).not.toBeNull()
    }
  })
})

describe('TAG_TYPES', () => {
  it('lists the twelve TagType values of the contract', () => {
    expect(TAG_TYPES).toHaveLength(12)
    expect(new Set(TAG_TYPES).size).toBe(12)
    expect(TAG_TYPES).toContain('concept')
    expect(TAG_TYPES).toContain('other')
  })
})
