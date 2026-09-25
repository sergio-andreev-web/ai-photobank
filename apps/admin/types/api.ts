// Types mirror contracts/public-api.openapi.yaml (v1.0.0) and contracts/schemas/enums.json.
// Client code must ignore unknown response fields and treat unknown error codes as a generic error.

export interface PaginationMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

export interface PaginationParams {
  page?: number
  per_page?: number
  sort?: string
}

// Auth
export type UserRole = 'admin'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
}

export interface LoginRequest {
  email: string
  password: string
}

// Dashboard
export interface Dashboard {
  images_count: number
  tags_count: number
  pending_candidates_count: number
  processing_images_count: number
  failed_images_count: number
}

// Batches
export type BatchStatus =
  | 'created'
  | 'uploading'
  | 'processing'
  | 'completed'
  | 'completed_with_errors'
  | 'failed'

export interface BatchProgress {
  total: number
  processed: number
  failed: number
}

export interface Batch {
  id: number
  name: string | null
  status: BatchStatus
  progress: BatchProgress
  created_at: string
  updated_at: string
  completed_at: string | null
}

export interface BatchList {
  data: Batch[]
  meta: PaginationMeta
}

export interface BatchCreateRequest {
  name?: string | null
}

// Tags
export type TagType =
  | 'object'
  | 'person'
  | 'activity'
  | 'scene'
  | 'location'
  | 'season'
  | 'weather'
  | 'brand'
  | 'event'
  | 'color'
  | 'concept'
  | 'other'

export type TagStatus = 'active' | 'archived'

export interface TagRef {
  id: number
  name: string
  type: TagType
  status: TagStatus
}

export interface TagAlias {
  id: number
  tag_id: number
  alias: string
  normalized_alias: string
  locale: string | null
  created_at: string
  updated_at: string
}

export interface Tag {
  id: number
  parent_id: number | null
  name: string
  slug: string
  normalized_name: string
  type: TagType
  status: TagStatus
  images_count: number
  aliases: TagAlias[]
  created_at: string
  updated_at: string
  // Client-side only, populated while building a tree from the flat list.
  children?: Tag[]
}

export interface TagList {
  data: Tag[]
  meta: PaginationMeta
}

export interface TagCreateRequest {
  name: string
  type: TagType
  parent_id?: number | null
}

export interface TagPatchRequest {
  name?: string
  type?: TagType
  parent_id?: number | null
  status?: TagStatus
}

export interface AliasCreateRequest {
  alias: string
  locale?: string | null
}

// Images
export type ImageStatus = 'uploaded' | 'queued' | 'processing' | 'ready' | 'failed'

export interface ImageUrls {
  thumbnail: string | null
  preview: string | null
  original: string
}

export interface ImageSummary {
  id: number
  batch_id: number
  original_name: string
  status: ImageStatus
  tags: TagRef[]
  urls: ImageUrls
  created_at: string
}

export interface ImageList {
  data: ImageSummary[]
  meta: PaginationMeta
}

export type ImageTagSource = 'ai' | 'manual' | 'import'

export interface ImageTag {
  tag: TagRef
  source: ImageTagSource
  confidence: number | null
  locked: boolean
  created_at: string
  updated_at: string
}

export interface Concept {
  type: TagType
  label: string
  confidence: number
}

export interface OcrItem {
  text: string
  confidence: number
}

export interface ImageAnalysis {
  id: number
  vision_model: string
  vision_model_version: string | null
  embedding_model: string
  embedding_model_version: string | null
  prompt_version: string
  caption: string | null
  concepts: Concept[]
  ocr: OcrItem[]
  processing_ms: number | null
  created_at: string
}

export type ProcessingTaskType = 'metadata' | 'variants' | 'ai_analysis' | 'tag_resolution' | 'finalize'
export type ProcessingTaskStatus = 'queued' | 'processing' | 'succeeded' | 'failed'

export interface ProcessingTask {
  type: ProcessingTaskType
  status: ProcessingTaskStatus
  attempts: number
  max_attempts: number
  error_code: string | null
  error_message: string | null
  started_at: string | null
  finished_at: string | null
}

export interface ImageDetail {
  id: number
  batch_id: number
  original_name: string
  status: ImageStatus
  urls: ImageUrls
  created_at: string
  updated_at: string
  mime_type: string
  extension: string
  width: number | null
  height: number | null
  file_size: number
  sha256: string
  caption: string | null
  ocr_text: string | null
  error_code: string | null
  error_message: string | null
  image_tags: ImageTag[]
  latest_analysis: ImageAnalysis | null
  processing_tasks: ProcessingTask[]
}

export interface ImagePatchRequest {
  original_name?: string
}

export interface ImageTagCreateRequest {
  tag_id: number
}

// Tag candidates
export type TagCandidateStatus = 'pending' | 'created' | 'merged' | 'rejected'

export interface CandidateSampleImage {
  id: number
  thumbnail_url: string | null
  confidence: number | null
}

export interface TagCandidate {
  id: number
  display_name: string
  normalized_name: string
  type: TagType
  status: TagCandidateStatus
  occurrences_count: number
  closest_tag: TagRef | null
  similarity: number | null
  sample_images: CandidateSampleImage[]
  created_at: string
  updated_at: string
  resolved_at: string | null
  resolved_by: number | null
  resolved_tag_id: number | null
}

export interface TagCandidateList {
  data: TagCandidate[]
  meta: PaginationMeta
}

export interface CandidateApproveRequest {
  name?: string
  type?: TagType
  parent_id?: number | null
}

export interface CandidateMergeRequest {
  tag_id: number
  create_alias?: boolean
}

export interface CandidateResolution {
  candidate: TagCandidate
  tag: TagRef | null
  attached_images_count: number
  alias_created: boolean
}

// Search
export type SearchMode = 'tags' | 'semantic' | 'hybrid'

interface SearchRequestCommon {
  page?: number
  per_page?: number
  debug?: boolean
}

export interface SearchTagsRequest extends SearchRequestCommon {
  mode: 'tags'
  tag_ids: number[]
}

export interface SearchSemanticRequest extends SearchRequestCommon {
  mode: 'semantic'
  query: string
}

export interface SearchHybridRequest extends SearchRequestCommon {
  mode: 'hybrid'
  query: string
  tag_ids: number[]
}

export type SearchRequest = SearchTagsRequest | SearchSemanticRequest | SearchHybridRequest

export interface SearchResultItem extends ImageSummary {
  semantic_score?: number
}

export interface SearchResponse {
  data: SearchResultItem[]
  meta: PaginationMeta & { mode: SearchMode }
}

// Errors
export interface ApiErrorBody {
  code: string
  message: string
  details: Record<string, any>
  trace_id: string
}

export interface ApiError {
  error: ApiErrorBody
}
