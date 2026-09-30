import type { ResearchRun, ResearchSettings } from "~/types/research";
export function useResearch() {
  const api = useApi();
  return {
    settings: async () =>
      (await api.get<ResearchSettings>("/admin/settings")).data,
    saveSettings: async (
      patch: Pick<
        ResearchSettings,
        "object_research_enabled" | "object_research_provider"
      >,
    ) => (await api.patch<ResearchSettings>("/admin/settings", patch)).data,
    history: async (id: number) =>
      (await api.get<{ data: ResearchRun[] }>(`/admin/images/${id}/research`))
        .data.data,
    start: async (id: number, question: string) =>
      (
        await api.post<ResearchRun>(`/admin/images/${id}/research`, {
          question,
        })
      ).data,
  };
}
