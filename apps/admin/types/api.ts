// Auth types
export interface User {
  id: number
  email: string
  name: string
  role: 'admin' | 'user'
  created_at: string
  updated_at: string
}

export interface LoginRequest {
  email: string
  password: string
}

// Dashboard types
export interface Dashboard {
  images_count: number
  tags_count: number
  pending_candidates_count: number
  processing_images_count: number
  failed_images_count: number
}

// Batch types
export type BatchStatus = 'created' | 'uploading' | 'processing' | 'completed' | 'failed'

export interface BatchProgress {
  total: number
  processed: number
  failed: number
}

export interface Batch {
  id: number
  name: string
  status: BatchStatus
  progress: BatchProgress
  created_at: string
  updated_at: string
  completed_at: string | null
}

export interface BatchList {
  data: Batch[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
}

export interface BatchCreateRequest {
  name?: string
}

// Image types
export type ImageStatus = 'pending' | 'processing' | 'completed' | 'failed'

export interface Image {
  id: number
  batch_id: number
  filename: string
  status: ImageStatus
  error_code: string | null
  created_at: string
  updated_at: string
}

export interface ImageDetail extends Image {
  caption: string | null
  extracted_text: string | null
  embedding_model_key: string | null
  image_tags: ImageTag[]
  tag_candidates: TagCandidate[]
  processing_tasks: ProcessingTask[]
}

export interface ImageList {
  data: Image[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
}

// Image tags types
export interface ImageTag {
  id: number
  image_id: number
  tag_id: number
  reason: 'auto' | 'manual'
  created_at: string
  tag?: Tag
}

// Tag types
export type TagType = 'concept' | 'category' | 'property'

export interface Tag {
  id: number
  name: string
  type: TagType
  parent_id: number | null
  archived_at: string | null
  created_at: string
  updated_at: string
  children?: Tag[]
  aliases?: Alias[]
}

export interface TagList {
  data: Tag[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
}

// Alias types
export interface Alias {
  id: number
  tag_id: number
  alias: string
  created_at: string
  updated_at: string
}

// Tag candidate types
export type CandidateStatus = 'pending' | 'approved' | 'merged' | 'rejected'

export interface TagCandidate {
  id: number
  image_id: number
  concept: string
  status: CandidateStatus
  confidence: number
  created_at: string
  updated_at: string
  resolved_at: string | null
  resolved_tag_id: number | null
  resolved_tag?: Tag
}

export interface TagCandidateList {
  data: TagCandidate[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
}

// Processing task types
export type ProcessingTaskStatus = 'pending' | 'processing' | 'completed' | 'failed'
export type ProcessingTaskType = 'metadata_extraction' | 'variant_generation' | 'ai_analysis' | 'tag_resolution'

export interface ProcessingTask {
  id: number
  image_id: number
  type: ProcessingTaskType
  status: ProcessingTaskStatus
  error_code: string | null
  created_at: string
  updated_at: string
  completed_at: string | null
}

// Search types
export type SearchMode = 'tags' | 'semantic' | 'hybrid'

export interface SearchRequest {
  q: string
  mode: SearchMode
  tag_ids?: number[]
  page?: number
  per_page?: number
  debug?: boolean
}

export interface SearchResult {
  id: number
  filename: string
  similarity?: number
}

export interface SearchResponse {
  data: SearchResult[]
  meta: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
  debug?: Record<string, any>
}

// Error types
export interface ApiError {
  error: {
    code: string
    message: string
    details: Record<string, any>
    trace_id: string
  }
}

export interface ValidationDetails {
  fields?: Record<string, string[]>
}

// Pagination
export interface PaginationParams {
  page?: number
  per_page?: number
  sort?: string
}
