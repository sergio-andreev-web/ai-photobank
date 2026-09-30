import { test } from "node:test";
import assert from "node:assert/strict";
import {
  validateRequest,
  validateResult,
  invoke,
  ResearchError,
} from "../src/core.mjs";
import { publicUrl, publicAddress } from "../src/browser.mjs";
import { apiAgent } from "../src/agents.mjs";
import { createServer } from "../src/server.mjs";
const image = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]).toString("base64");
const input = {
  image_base64: image,
  mime_type: "image/png",
  provider: "ollama",
  question: "",
};
const uncertain = {
  status: "uncertain",
  name: null,
  description: "Недостаточно данных.",
  confidence: "low",
  sources: [],
};
test("rejects unexpected properties, invalid bytes and oversized question", () => {
  assert.equal(validateRequest(input), input);
  for (const patch of [
    { path: "/etc/passwd" },
    { mime_type: "image/jpeg" },
    { image_base64: "???" },
    { question: "x".repeat(1001) },
  ])
    assert.throws(() => validateRequest({ ...input, ...patch }), ResearchError);
});
test("results require a real search and actually read sources", () => {
  const session = {
    searched: true,
    visited: new Set(["https://example.com/"]),
  };
  assert.equal(validateResult(uncertain, session), uncertain);
  assert.throws(() =>
    validateResult(uncertain, { ...session, searched: false }),
  );
  const result = {
    ...uncertain,
    status: "identified",
    name: "Предмет",
    confidence: "medium",
    sources: [
      {
        url: "https://example.com/",
        title: "Источник",
        evidence: "Признаки совпадают.",
      },
    ],
  };
  assert.equal(validateResult(result, session), result);
  assert.throws(() =>
    validateResult(
      {
        ...result,
        sources: [{ ...result.sources[0], url: "https://invented.test" }],
      },
      session,
    ),
  );
  assert.throws(() =>
    validateResult({ ...uncertain, confidence: "high" }, session),
  );
});
test("URL policy blocks local networks, credentials and unsafe schemes including DNS resolution", async () => {
  for (const ip of [
    "127.0.0.1",
    "10.1.2.3",
    "169.254.169.254",
    "172.16.0.1",
    "192.168.1.2",
    "::1",
    "::ffff:127.0.0.1",
    "fc00::1",
  ])
    assert.equal(publicAddress(ip), false, ip);
  const dns = async () => [{ address: "127.0.0.1" }];
  for (const url of [
    "https://external.test/",
    "file:///etc/passwd",
    "http://user:pass@example.com",
    "http://example.com:8000",
  ])
    await assert.rejects(publicUrl(url, dns));
  assert.equal(
    await publicUrl("https://example.com/", async () => [
      { address: "93.184.216.34" },
    ]),
    "https://example.com/",
  );
});
test("tool scope never accepts arbitrary image paths and limits calls", async () => {
  const s = { input, calls: 0 };
  await assert.rejects(invoke(s, "inspect_image", { path: "/tmp/photo" }));
  s.calls = 12;
  await assert.rejects(invoke(s, "inspect_image", {}));
});
test("Ollama performs agent tool roundtrips and preserves text/vision context", async () => {
  process.env.OLLAMA_BASE_URL = "http://ollama:11434";
  let n = 0;
  const s = {
    input,
    calls: 0,
    searched: false,
    search: async () => {
      s.searched = true;
      return { content: [{ type: "text", text: "Нет подходящих источников" }] };
    },
  };
  const fake = async (url, opts) => {
    assert.equal(url, "http://ollama:11434/api/chat");
    const b = JSON.parse(opts.body);
    assert.equal(b.model, "qwen3.5:4b");
    if (n++ === 0)
      return {
        ok: true,
        json: async () => ({
          message: {
            role: "assistant",
            content: "",
            tool_calls: [
              { function: { name: "visual_search", arguments: {} } },
            ],
          },
        }),
      };
    assert.equal(b.messages.at(-1).role, "tool");
    return {
      ok: true,
      json: async () => ({ message: { content: JSON.stringify(uncertain) } }),
    };
  };
  assert.deepEqual(
    await apiAgent(input, s, new AbortController().signal, fake),
    uncertain,
  );
  assert.equal(s.searched, true);
});
test("OpenAI adapter replays tool calls and output with vision", async () => {
  let turn = 0;
  const session = { input, calls: 0 };
  const fake = async (url, opts) => {
    const b = JSON.parse(opts.body);
    assert.equal(b.store, false);
    if (turn++ === 0)
      return {
        ok: true,
        json: async () => ({
          output: [
            {
              type: "function_call",
              name: "inspect_image",
              call_id: "one",
              arguments: "{}",
            },
          ],
        }),
      };
    assert.equal(b.input.at(-1).output[0].type, "input_image");
    return {
      ok: true,
      json: async () => ({
        output: [
          {
            type: "message",
            content: [{ type: "output_text", text: JSON.stringify(uncertain) }],
          },
        ],
      }),
    };
  };
  assert.deepEqual(
    await apiAgent(
      { ...input, provider: "openai_api" },
      session,
      new AbortController().signal,
      fake,
    ),
    uncertain,
  );
});
test("Anthropic adapter returns tool results in user turn", async () => {
  let turn = 0;
  const session = { input, calls: 0 };
  const fake = async (url, opts) => {
    const b = JSON.parse(opts.body);
    if (turn++ === 0)
      return {
        ok: true,
        json: async () => ({
          content: [
            { type: "tool_use", name: "inspect_image", id: "one", input: {} },
          ],
        }),
      };
    assert.equal(b.messages.at(-1).content[0].content[0].type, "image");
    return {
      ok: true,
      json: async () => ({
        content: [{ type: "text", text: JSON.stringify(uncertain) }],
      }),
    };
  };
  assert.deepEqual(
    await apiAgent(
      { ...input, provider: "anthropic_api" },
      session,
      new AbortController().signal,
      fake,
    ),
    uncertain,
  );
});
test("HTTP requires auth, checks requests, exposes no keys and validates agent results", async (t) => {
  const token = "x".repeat(32);
  const server = createServer({
    token,
    getAvailability: () => [{ id: "ollama", available: true }],
    sessionFactory: () => ({
      searched: true,
      visited: new Set(),
      close: async () => {},
    }),
    runApi: async () => uncertain,
  });
  await new Promise((r) => server.listen(0, "127.0.0.1", r));
  t.after(() => server.close());
  const base = "http://127.0.0.1:" + server.address().port;
  assert.equal((await fetch(base + "/ready")).status, 401);
  const headers = {
    Authorization: "Bearer " + token,
    "Content-Type": "application/json",
  };
  const ready = await fetch(base + "/ready", { headers });
  assert.deepEqual(await ready.json(), {
    providers: [{ id: "ollama", available: true }],
  });
  const response = await fetch(base + "/v1/research", {
    method: "POST",
    headers,
    body: JSON.stringify(input),
  });
  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), uncertain);
  const bad = await fetch(base + "/v1/research", {
    method: "POST",
    headers,
    body: "{}",
  });
  assert.equal(bad.status, 400);
});
test("stdio MCP exposes job tools and roundtrips an image through the scoped capability", async (t) => {
  const { Client } = await import("@modelcontextprotocol/sdk/client/index.js");
  const { StdioClientTransport } =
    await import("@modelcontextprotocol/sdk/client/stdio.js");
  const { fileURLToPath } = await import("node:url");
  const server = createServer({
    token: "z".repeat(32),
    getAvailability: () => [{ id: "codex_cli", available: true }],
    sessionFactory: () => ({
      input,
      searched: true,
      visited: new Set(),
      calls: 0,
      close: async () => {},
    }),
    runCli: async (_, cap) => {
      const client = new Client({ name: "test", version: "1.0.0" });
      const transport = new StdioClientTransport({
        command: process.execPath,
        args: [fileURLToPath(new URL("../src/mcp.mjs", import.meta.url))],
        env: { RESEARCH_TOOL_URL: cap.url, RESEARCH_TOOL_TOKEN: cap.token },
      });
      try {
        await client.connect(transport);
        assert.deepEqual(
          (await client.listTools()).tools.map((t) => t.name).sort(),
          ["inspect_image", "read_source", "visual_search"],
        );
        const result = await client.callTool({
          name: "inspect_image",
          arguments: {},
        });
        assert.equal(result.content[0].data, image);
        return uncertain;
      } finally {
        await client.close();
      }
    },
  });
  await new Promise((r) => server.listen(0, "127.0.0.1", r));
  t.after(() => server.close());
  const response = await fetch(
    `http://127.0.0.1:${server.address().port}/v1/research`,
    {
      method: "POST",
      headers: {
        Authorization: "Bearer " + "z".repeat(32),
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ ...input, provider: "codex_cli" }),
    },
  );
  assert.equal(response.status, 200);
});
test("CLI tool failures preserve the actual search block error in final validation", async () => {
  const session = {
    input,
    calls: 0,
    searched: false,
    search: async () => {
      throw new ResearchError("WEB_SEARCH_BLOCKED");
    },
  };
  await assert.rejects(invoke(session, "visual_search", {}));
  assert.throws(
    () => validateResult(uncertain, session),
    (e) => e.code === "WEB_SEARCH_BLOCKED",
  );
});
