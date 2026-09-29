import path from 'node:path'

function intFromEnv(name: string, fallback: number): number {
  const raw = process.env[name]
  if (raw === undefined || raw === '') return fallback
  const value = Number(raw)
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${name} must be a positive integer, got "${raw}"`)
  }
  return value
}

/**
 * Everything the stack run needs from the outside, with defaults that match a
 * fresh `make setup` (docker-compose.yml, .env.example, DatabaseSeeder).
 */
export const env = {
  /** nginx origin: Nuxt, /api and /sanctum share it (ADR-0001). Nuxt on :3000 alone has no /api. */
  baseURL: process.env.E2E_BASE_URL || 'http://localhost',
  /** Seeded by apps/api/database/seeders/DatabaseSeeder.php for APP_ENV=local. */
  adminEmail: process.env.E2E_ADMIN_EMAIL || 'admin@example.com',
  adminPassword: process.env.E2E_ADMIN_PASSWORD || 'password123',
  /** ТЗ §75 uploads 20 photos; a smaller value is only for a quick local smoke. */
  imageCount: intFromEnv('E2E_IMAGE_COUNT', 20),
  /** Upper bound for the whole batch to reach a final status. */
  processingTimeoutMs: intFromEnv('E2E_PROCESSING_TIMEOUT_MS', 8 * 60_000),
  /** Per-test timeout; the §75 test includes the processing wait. */
  testTimeoutMs: intFromEnv('E2E_TEST_TIMEOUT_MS', 20 * 60_000),
  /** Opt-in: the outage test stops and restarts the AI container. */
  aiOutage: process.env.E2E_AI_OUTAGE === '1',
  aiService: process.env.E2E_AI_SERVICE || 'ai-cpu',
  aiReadyURL: process.env.E2E_AI_READY_URL || 'http://localhost:8001/ready',
  aiReadyTimeoutMs: intFromEnv('E2E_AI_READY_TIMEOUT_MS', 180_000),
  /** Directory `docker compose` runs in (the stack's project); defaults to the repository root. */
  composeDir: process.env.E2E_COMPOSE_DIR || path.resolve(__dirname, '..', '..', '..', '..'),
}
