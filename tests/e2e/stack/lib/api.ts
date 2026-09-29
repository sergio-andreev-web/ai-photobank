/**
 * The subset of contracts/public-api.openapi.yaml (1.2.x) this run reads.
 * Clients ignore unknown response fields (ADR-0002), and so does this file.
 */

export interface Paginated<T> {
  data: T[]
  meta: { page: number; per_page: number; total: number; last_page: number }
}

export interface Batch {
  id: number
  name: string | null
  status: 'created' | 'uploading' | 'processing' | 'completed' | 'completed_with_errors' | 'failed'
  progress: { total: number; processed: number; failed: number }
}

export interface TagRef {
  id: number
  name: string
  type: string
  status: 'active' | 'archived'
}

export interface Tag extends TagRef {
  images_count: number
}

export interface ImageSummary {
  id: number
  batch_id: number
  original_name: string
  status: 'uploaded' | 'queued' | 'processing' | 'ready' | 'failed'
  tags: TagRef[]
  urls: { thumbnail: string | null; preview: string | null; original: string }
}

export interface ImageTag {
  tag: TagRef
  source: 'ai' | 'manual' | 'import'
  confidence: number | null
  locked: boolean
}

export interface ImageDetail extends Omit<ImageSummary, 'tags'> {
  caption: string | null
  error_code: string | null
  error_message: string | null
  image_tags: ImageTag[]
  latest_analysis: { concepts: { type: string; label: string; confidence: number }[] } | null
  processing_tasks: { type: string; status: string; attempts: number; error_code: string | null }[]
}

export interface TagCandidate {
  id: number
  display_name: string
  type: string
  status: 'pending' | 'created' | 'merged' | 'rejected'
  occurrences_count: number
  sample_images: { id: number; thumbnail_url: string | null; confidence: number | null }[]
}

export interface CandidateResolution {
  candidate: TagCandidate
  tag: TagRef
  attached_images_count: number
  alias_created: boolean
}

export interface SearchResultItem extends ImageSummary {
  semantic_score?: number
}

export interface SearchResponse {
  data: SearchResultItem[]
  meta: Paginated<unknown>['meta'] & { mode: 'tags' | 'semantic' | 'hybrid' }
}

export interface ApiError {
  error: { code: string; message: string; details: Record<string, unknown>; trace_id: string }
}
