import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { createdFromParam, createdToParam } from '~/utils/dateRange'

// The mapping depends on the admin's time zone, so pin it per group (Node re-reads TZ on assignment).
function inTimeZone(zone: string, body: () => void) {
  describe(zone, () => {
    let original: string | undefined
    beforeAll(() => {
      original = process.env.TZ
      process.env.TZ = zone
    })
    afterAll(() => {
      if (original === undefined) delete process.env.TZ
      else process.env.TZ = original
    })
    body()
  })
}

describe('created_from / created_to', () => {
  inTimeZone('UTC', () => {
    it('sends the start of the picked day, and the start of the next day for the inclusive end', () => {
      expect(createdFromParam('2026-09-24')).toBe('2026-09-24T00:00:00Z')
      expect(createdToParam('2026-09-24')).toBe('2026-09-25T00:00:00Z')
    })

    it('rolls over month and year ends', () => {
      expect(createdToParam('2026-09-30')).toBe('2026-10-01T00:00:00Z')
      expect(createdToParam('2026-12-31')).toBe('2027-01-01T00:00:00Z')
      expect(createdToParam('2028-02-28')).toBe('2028-02-29T00:00:00Z')
    })
  })

  inTimeZone('Europe/Moscow', () => {
    it('converts local day boundaries to UTC instants (UTC+3)', () => {
      expect(createdFromParam('2026-09-24')).toBe('2026-09-23T21:00:00Z')
      expect(createdToParam('2026-09-25')).toBe('2026-09-25T21:00:00Z')
    })
  })

  inTimeZone('America/New_York', () => {
    it('follows the offset in force on that day (EDT in September)', () => {
      expect(createdFromParam('2026-09-24')).toBe('2026-09-24T04:00:00Z')
    })

    it('uses calendar days across the DST change (1 Nov 2026 has 25 hours)', () => {
      expect(createdFromParam('2026-11-01')).toBe('2026-11-01T04:00:00Z')
      expect(createdToParam('2026-11-01')).toBe('2026-11-02T05:00:00Z')
    })
  })

  inTimeZone('UTC', () => {
    it('sends no bound for an empty or malformed value', () => {
      for (const value of ['', '2026-9-1', '24.09.2026', 'yesterday', '2026-09-24T10:00']) {
        expect(createdFromParam(value)).toBeUndefined()
        expect(createdToParam(value)).toBeUndefined()
      }
    })

    it('sends no bound for a day that does not exist', () => {
      expect(createdFromParam('2026-02-30')).toBeUndefined()
      expect(createdFromParam('2026-13-01')).toBeUndefined()
      expect(createdToParam('2026-00-10')).toBeUndefined()
    })

    it('produces RFC 3339 without fractional seconds', () => {
      expect(createdFromParam('2026-09-24')).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/)
      expect(createdToParam('2026-09-24')).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/)
    })
  })
})
