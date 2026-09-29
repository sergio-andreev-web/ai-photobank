import type { SearchMode } from '~/types/api'

const SEARCH_MODES: readonly SearchMode[] = ['tags', 'semantic', 'hybrid']

export interface SearchRouteQuery {
  mode?: SearchMode
  q?: string
}

function firstString(value: unknown): string | undefined {
  const first = Array.isArray(value) ? value[0] : value
  return typeof first === 'string' ? first : undefined
}

/**
 * Reads the deep-link parameters of the search page (`/search?mode=semantic&q=…`). An unknown `mode`
 * is dropped, so the page falls back to its default mode instead of sending an invalid request.
 */
export function readSearchRouteQuery(query: Record<string, unknown>): SearchRouteQuery {
  const mode = firstString(query.mode)
  return {
    mode: SEARCH_MODES.find((candidate) => candidate === mode),
    q: firstString(query.q),
  }
}

/** Where the gallery's AI search box sends the admin: the search page in semantic mode with the query. */
export function semanticSearchLocation(query: string) {
  return { path: '/search', query: { mode: 'semantic', q: query.trim() } }
}
