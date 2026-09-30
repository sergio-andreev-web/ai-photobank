import http from "node:http";
import { randomBytes, timingSafeEqual } from "node:crypto";
import { pathToFileURL } from "node:url";
import {
  ResearchError,
  validateRequest,
  validateResult,
  invoke,
} from "./core.mjs";
import { BrowserSession } from "./browser.mjs";
import { availability, cliAgent, apiAgent } from "./agents.mjs";
function equal(a, b) {
  return (
    typeof a === "string" &&
    typeof b === "string" &&
    a.length === b.length &&
    timingSafeEqual(Buffer.from(a), Buffer.from(b))
  );
}
async function body(req) {
  const chunks = [];
  let size = 0;
  for await (const c of req) {
    size += c.length;
    if (size > 15 * 1024 * 1024)
      throw new ResearchError("INVALID_REQUEST", 400);
    chunks.push(c);
  }
  try {
    return JSON.parse(Buffer.concat(chunks));
  } catch {
    throw new ResearchError("INVALID_REQUEST", 400);
  }
}
export function createServer({
  token = process.env.WEB_RESEARCH_TOKEN,
  sessionFactory = (input) => new BrowserSession(input),
  getAvailability = availability,
  runCli = cliAgent,
  runApi = apiAgent,
} = {}) {
  if (!token || token.length < 24)
    throw new Error("WEB_RESEARCH_TOKEN must contain at least 24 characters");
  const jobs = new Map();
  let busy = false;
  const server = http.createServer(async (req, res) => {
    const send = (status, data) => {
      if (!res.destroyed) {
        res.writeHead(status, {
          "Content-Type": "application/json",
          "Cache-Control": "no-store",
        });
        res.end(JSON.stringify(data));
      }
    };
    try {
      if (req.url === "/health" && req.method === "GET")
        return send(200, { status: "ok" });
      const internal = /^\/internal\/tools\/([a-f0-9]+)$/.exec(req.url);
      if (internal && req.method === "POST") {
        const job = jobs.get(internal[1]);
        if (!job || !equal(req.headers.authorization, `Bearer ${job.token}`))
          throw new ResearchError("UNAUTHENTICATED", 401);
        const payload = await body(req);
        return send(200, await invoke(job.session, payload.name, payload.args));
      }
      if (!equal(req.headers.authorization, `Bearer ${token}`))
        throw new ResearchError("UNAUTHENTICATED", 401);
      if (req.url === "/ready" && req.method === "GET")
        return send(200, { providers: getAvailability() });
      if (req.url !== "/v1/research" || req.method !== "POST")
        throw new ResearchError("NOT_FOUND", 404);
      const input = validateRequest(await body(req));
      if (busy) throw new ResearchError("BUSY", 503);
      if (
        !getAvailability().some((p) => p.id === input.provider && p.available)
      )
        throw new ResearchError("PROVIDER_UNAVAILABLE", 503);
      busy = true;
      const id = randomBytes(16).toString("hex"),
        jobToken = randomBytes(32).toString("hex");
      const session = sessionFactory(input);
      jobs.set(id, { session, token: jobToken });
      const abort = new AbortController();
      const timer = setTimeout(() => abort.abort(), 180000);
      res.on("close", () => {
        if (!res.writableEnded) abort.abort();
      });
      try {
        const capability = {
          url: `http://127.0.0.1:${server.address().port}/internal/tools/${id}`,
          token: jobToken,
        };
        const work = input.provider.endsWith("_cli")
          ? runCli(input, capability, abort.signal)
          : runApi(input, session, abort.signal);
        const timeout = new Promise((_, reject) =>
          abort.signal.addEventListener(
            "abort",
            () => reject(new ResearchError("RESEARCH_TIMEOUT", 504)),
            { once: true },
          ),
        );
        const result = await Promise.race([work, timeout]);
        send(200, validateResult(result, session));
      } finally {
        clearTimeout(timer);
        jobs.delete(id);
        await session.close();
        busy = false;
      }
    } catch (e) {
      send(e instanceof ResearchError ? e.status : 502, {
        error: { code: e instanceof ResearchError ? e.code : "AGENT_FAILED" },
      });
    }
  });
  server.requestTimeout = 200000;
  return server;
}
if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const server = createServer();
  server.listen(
    Number(process.env.WEB_RESEARCH_PORT || 8002),
    process.env.WEB_RESEARCH_HOST || "127.0.0.1",
    () => console.log("Object research service listening"),
  );
  for (const sig of ["SIGTERM", "SIGINT"])
    process.on(sig, () => server.close(() => process.exit(0)));
}
