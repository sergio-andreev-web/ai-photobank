import type { ApiError } from '~/types/api'

export function getErrorMessage(error: unknown): string {
  if (typeof error === 'string') return error

  if (error && typeof error === 'object') {
    if ('response' in error && error.response) {
      const data = (error.response as any).data as ApiError | undefined
      return data?.error?.message || 'An error occurred'
    }
    if ('message' in error) {
      return String(error.message)
    }
  }

  return 'An unexpected error occurred'
}

export function getErrorCode(error: unknown): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const data = (error.response as any).data as ApiError | undefined
    return data?.error?.code || 'UNKNOWN_ERROR'
  }
  return 'UNKNOWN_ERROR'
}

export function isRetryableError(code: string): boolean {
  const retryableCodes = [
    'AI_SERVICE_UNAVAILABLE',
    'TOO_MANY_REQUESTS',
    'INTERNAL_ERROR',
  ]
  return retryableCodes.includes(code)
}

export function getHttpStatus(error: unknown): number | null {
  if (error && typeof error === 'object' && 'response' in error) {
    return (error.response as any).status || null
  }
  return null
}

/** A public API error (§51) flattened into what the UI needs; unknown codes stay generic. */
export interface ApiProblem {
  code: string
  status: number | null
  message: string
  /** `error.details` as sent by the server (`existing_tag_id`, `existing_image_id`, ...). */
  details: Record<string, unknown>
  /** `VALIDATION_ERROR` details: field name -> messages (`error.details.fields`). */
  fields: Record<string, string[]>
  traceId: string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function toApiProblem(error: unknown): ApiProblem {
  const response = isRecord(error) && isRecord(error.response) ? error.response : undefined
  const body = response && isRecord(response.data) && isRecord(response.data.error) ? response.data.error : undefined

  const details = body && isRecord(body.details) ? body.details : {}
  const fields: Record<string, string[]> = {}
  if (isRecord(details.fields)) {
    for (const [field, messages] of Object.entries(details.fields)) {
      if (Array.isArray(messages)) fields[field] = messages.map(String)
      else if (typeof messages === 'string') fields[field] = [messages]
    }
  }

  const fallbackMessage = error instanceof Error ? error.message : getErrorMessage(error)
  return {
    code: typeof body?.code === 'string' ? body.code : 'UNKNOWN_ERROR',
    status: typeof response?.status === 'number' ? response.status : null,
    message: typeof body?.message === 'string' && body.message ? body.message : fallbackMessage,
    details,
    fields,
    traceId: typeof body?.trace_id === 'string' ? body.trace_id : null,
  }
}

export interface DescribeProblemOptions {
  /** Fields the form already shows inline; other `VALIDATION_ERROR` fields are listed in the text. */
  knownFields?: readonly string[]
  /** Resolves `existing_tag_id` to a readable name when the tag is in the loaded catalog. */
  lookupTag?: (id: number) => string | null
}

/**
 * One line of technical context for an error banner: the error code, the tag
 * a conflict points to, field errors the form cannot show, and the trace id
 * to quote when reporting the problem (§64). Never parses `message`.
 */
export function describeProblem(problem: ApiProblem, options: DescribeProblemOptions = {}): string {
  const parts: string[] = []
  if (problem.code !== 'UNKNOWN_ERROR') parts.push(problem.code)

  const existingTagId = problem.details.existing_tag_id
  if (typeof existingTagId === 'number') {
    const name = options.lookupTag?.(existingTagId)
    parts.push(`existing tag: ${name ? `${name} (#${existingTagId})` : `#${existingTagId}`}`)
  }

  const known = new Set(options.knownFields ?? [])
  for (const [field, messages] of Object.entries(problem.fields)) {
    if (!known.has(field)) parts.push(`${field}: ${messages.join(' ')}`)
  }

  if (problem.traceId) parts.push(`trace ${problem.traceId}`)
  return parts.join(' · ')
}
