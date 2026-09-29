/**
 * Maps the gallery's `<input type="date">` values (`YYYY-MM-DD`, the admin's local calendar day) to the
 * `created_from` / `created_to` filters of `GET /api/admin/images`: RFC 3339 timestamps in UTC,
 * `created_from` inclusive, `created_to` exclusive (contracts/public-api.openapi.yaml, ADR-0002).
 *
 * The API compares against UTC `created_at`, so the local day boundaries are converted to UTC instants
 * (a "24 Sep" filter in UTC+3 starts at `2026-09-23T21:00:00Z`) and always sent with the `Z` suffix.
 */

const DATE_INPUT = /^(\d{4})-(\d{2})-(\d{2})$/

/** Start of the local calendar day, or null for anything that is not a real `YYYY-MM-DD` date. */
function localMidnight(value: string): Date | null {
  const match = DATE_INPUT.exec(value)
  if (!match) return null
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  // setFullYear/setHours instead of `new Date(y, m, d)`, which maps years 0-99 to 1900-1999.
  const date = new Date(0)
  date.setFullYear(year, month - 1, day)
  date.setHours(0, 0, 0, 0)
  // Reject dates that only exist after overflow (2026-02-31 would silently become 3 March).
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null
  return date
}

function toRfc3339(date: Date): string | undefined {
  // Drop the milliseconds to match the contract's `2026-09-25T13:43:55Z` form. Years beyond 9999 would
  // serialize as `+010000-…`, which is not RFC 3339, so such input is ignored rather than sent.
  const iso = date.toISOString().replace(/\.\d{3}Z$/, 'Z')
  return /^\d{4}-/.test(iso) ? iso : undefined
}

/** Start of the picked day → `created_from` (inclusive). Empty or malformed input means "no bound". */
export function createdFromParam(value: string): string | undefined {
  const date = localMidnight(value)
  return date ? toRfc3339(date) : undefined
}

/**
 * The picked day is included in the range, but `created_to` is exclusive, so send the start of the
 * following day. Empty or malformed input means "no bound".
 */
export function createdToParam(value: string): string | undefined {
  const date = localMidnight(value)
  if (!date) return undefined
  // Calendar arithmetic, not +24h: a day with a DST change is 23 or 25 hours long.
  date.setDate(date.getDate() + 1)
  return toRfc3339(date)
}
