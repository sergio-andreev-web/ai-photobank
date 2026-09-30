export type ResearchProvider =
  "codex_cli" | "claude_cli" | "openai_api" | "anthropic_api" | "ollama";
export interface ResearchSettings {
  object_research_enabled: boolean;
  object_research_provider: ResearchProvider;
  providers: { id: ResearchProvider; available: boolean }[];
}
export interface ResearchResult {
  status: "identified" | "uncertain";
  name: string | null;
  description: string;
  confidence: "low" | "medium" | "high";
  sources: { url: string; title: string; evidence: string }[];
}
export interface ResearchRun {
  id: number;
  image_id: number;
  trigger: "manual" | "automatic";
  provider: ResearchProvider;
  question: string;
  status: "queued" | "running" | "succeeded" | "failed" | "cancelled";
  result: ResearchResult | null;
  error_code: string | null;
  created_at: string;
  finished_at: string | null;
}
export const RESEARCH_PROVIDERS = [
  { value: "codex_cli" as const, label: "Codex CLI" },
  { value: "claude_cli" as const, label: "Claude CLI" },
  { value: "openai_api" as const, label: "OpenAI API" },
  { value: "anthropic_api" as const, label: "Anthropic API" },
  { value: "ollama" as const, label: "Локальная модель · Ollama (Qwen3.5 4B)" },
];
