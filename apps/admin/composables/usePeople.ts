import type { Face, FaceAssignResult, FaceCluster, FaceList, FaceStatus, PaginationParams, Person, PersonList } from '~/types/api'

/** People and faces (ADR-0015, contracts 1.10.0). */
export function usePeople() {
  const api = useApi()

  async function listPeople(params?: PaginationParams & { q?: string }) {
    const { data } = await api.get<PersonList>('/admin/people', { params })
    return data
  }

  async function getPerson(id: number) {
    const { data } = await api.get<Person>(`/admin/people/${id}`)
    return data
  }

  async function updatePerson(id: number, patch: { name?: string; note?: string | null }) {
    const { data } = await api.patch<Person>(`/admin/people/${id}`, patch)
    return data
  }

  /** Deletes the person together with every face vector attached to them (152-ФЗ). */
  async function deletePerson(id: number) {
    await api.delete(`/admin/people/${id}`)
  }

  async function personFaces(id: number, params?: PaginationParams & { status?: FaceStatus }) {
    const { data } = await api.get<FaceList>(`/admin/people/${id}/faces`, { params })
    return data
  }

  async function imageFaces(imageId: number) {
    const { data } = await api.get<{ data: Face[] }>(`/admin/images/${imageId}/faces`)
    return data.data
  }

  async function clusters(limit = 20) {
    const { data } = await api.get<{ data: FaceCluster[] }>('/admin/faces/clusters', { params: { limit } })
    return data.data
  }

  /** «Remember this person»: the faces become references of an existing (person_id) or new (name) person. */
  async function assignFaces(faceIds: number[], target: { personId: number } | { name: string }) {
    const body = 'personId' in target ? { face_ids: faceIds, person_id: target.personId } : { face_ids: faceIds, name: target.name.trim() }
    const { data } = await api.post<FaceAssignResult>('/admin/faces/assign', body)
    return data
  }

  /** «This is not them». */
  async function rejectFace(faceId: number) {
    const { data } = await api.post<Face>(`/admin/faces/${faceId}/reject`)
    return data
  }

  return { listPeople, getPerson, updatePerson, deletePerson, personFaces, imageFaces, clusters, assignFaces, rejectFace }
}
