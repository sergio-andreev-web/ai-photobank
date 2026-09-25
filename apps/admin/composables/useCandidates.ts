import type {
  CandidateApproveRequest,
  CandidateMergeRequest,
  CandidateResolution,
  TagCandidateList,
  TagCandidateStatus,
  TagType,
} from '~/types/api'

export function useCandidates() {
  const api = useApi()

  async function listCandidates(params?: {
    page?: number
    per_page?: number
    status?: TagCandidateStatus
    type?: TagType
    q?: string
    sort?: string
  }) {
    const { data } = await api.get<TagCandidateList>('/admin/tag-candidates', { params })
    return data
  }

  async function approveCandidate(id: number, request?: CandidateApproveRequest) {
    const { data } = await api.post<CandidateResolution>(`/admin/tag-candidates/${id}/approve`, request)
    return data
  }

  async function mergeCandidate(id: number, request: CandidateMergeRequest) {
    const { data } = await api.post<CandidateResolution>(`/admin/tag-candidates/${id}/merge`, request)
    return data
  }

  async function rejectCandidate(id: number) {
    const { data } = await api.post<CandidateResolution>(`/admin/tag-candidates/${id}/reject`)
    return data
  }

  return {
    listCandidates,
    approveCandidate,
    mergeCandidate,
    rejectCandidate,
  }
}
