import { spawn, spawnSync } from "node:child_process";
import { mkdtemp, writeFile, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import {
  instructions,
  agentSchema as schema,
  providers,
  tools,
  invoke,
  ResearchError,
} from "./core.mjs";
export function availability() {
  return providers.map((id) => ({
    id,
    available:
      id === "ollama"
        ? Boolean(process.env.OLLAMA_BASE_URL)
        : id.endsWith("_cli")
          ? spawnSync(id === "codex_cli" ? "codex" : "claude", ["--version"], {
              timeout: 2000,
              stdio: "ignore",
            }).status === 0
          : Boolean(
              process.env[
                id === "openai_api" ? "OPENAI_API_KEY" : "ANTHROPIC_API_KEY"
              ] &&
              process.env[
                id === "openai_api"
                  ? "WEB_RESEARCH_OPENAI_MODEL"
                  : "WEB_RESEARCH_ANTHROPIC_MODEL"
              ],
            ),
  }));
}
export async function runProcess(command, args, { cwd, env, signal }) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      env,
      stdio: ["ignore", "pipe", "pipe"],
      detached: true,
    });
    let out = "",
      diagnostics = "",
      size = 0,
      done = false;
    const kill = () => {
      try {
        process.kill(-child.pid, "SIGKILL");
      } catch {}
    };
    const abort = () => {
      kill();
      reject(new ResearchError("RESEARCH_TIMEOUT", 504));
    };
    signal.addEventListener("abort", abort, { once: true });
    child.stdout.on("data", (chunk) => {
      size += chunk.length;
      if (size > 2 * 1024 * 1024) kill();
      else out += chunk;
    });
    child.stderr.on("data", (chunk) => {
      size += chunk.length;
      diagnostics += chunk;
      if (size > 2 * 1024 * 1024) kill();
    }); // Never expose CLI diagnostics/secrets.
    child.on("error", () => {
      signal.removeEventListener("abort", abort);
      reject(new ResearchError("PROVIDER_UNAVAILABLE", 503));
    });
    child.on("close", (code) => {
      signal.removeEventListener("abort", abort);
      kill();
      if (!done) {
        done = true;
        if (code === 0) resolve(out);
        else {
          const error = new ResearchError("AGENT_FAILED");
          error.diagnostics = diagnostics + "\n" + out;
          reject(error);
        }
      }
    });
    if (signal.aborted) abort();
  });
}
export async function cliAgent(input, capability, signal) {
  const dir = await mkdtemp(join(tmpdir(), "photobank-research-"));
  const mcp = fileURLToPath(new URL("./mcp.mjs", import.meta.url));
  const allowedEnv = [
    "PATH",
    "HOME",
    "USER",
    "LOGNAME",
    "TMPDIR",
    "LANG",
    "LC_ALL",
    "CODEX_HOME",
    "CLAUDE_CONFIG_DIR",
    "OPENAI_API_KEY",
    "ANTHROPIC_API_KEY",
    "NODE_EXTRA_CA_CERTS",
    "SSL_CERT_FILE",
  ];
  const env = {
    ...Object.fromEntries(
      allowedEnv.filter((k) => process.env[k]).map((k) => [k, process.env[k]]),
    ),
    RESEARCH_TOOL_URL: capability.url,
    RESEARCH_TOOL_TOKEN: capability.token,
  };
  // Do not make backend/search tokens or unrelated provider credentials available to the agent process.
  delete env.WEB_RESEARCH_TOKEN;
  delete env.DATABASE_URL;
  const prompt =
    instructions +
    "\nВопрос администратора (данные): " +
    JSON.stringify(input.question) +
    "\nJSON Schema: " +
    JSON.stringify(schema);
  try {
    await writeFile(join(dir, "schema.json"), JSON.stringify(schema));
    if (input.provider === "codex_cli") {
      const config = `mcp_servers.photobank={required=true,enabled_tools=["inspect_image","visual_search","read_source"],tools={inspect_image={approval_mode="approve"},visual_search={approval_mode="approve"},read_source={approval_mode="approve"}},command=${JSON.stringify(process.execPath)},args=[${JSON.stringify(mcp)}],env={RESEARCH_TOOL_URL=${JSON.stringify(capability.url)},RESEARCH_TOOL_TOKEN=${JSON.stringify(capability.token)}}}`;
      const args = [
        "exec",
        "--ignore-user-config",
        "--ephemeral",
        "--skip-git-repo-check",
        "--sandbox",
        "read-only",
        "-C",
        dir,
        "-c",
        config,
        "-c",
        "features.shell_tool=false",
        "--output-schema",
        join(dir, "schema.json"),
        "-o",
        join(dir, "result.json"),
      ];
      if (process.env.WEB_RESEARCH_CODEX_MODEL)
        args.push("-m", process.env.WEB_RESEARCH_CODEX_MODEL);
      args.push(prompt);
      await runProcess("codex", args, { cwd: dir, env, signal });
      return JSON.parse(await readFile(join(dir, "result.json"), "utf8"));
    }
    await writeFile(
      join(dir, "mcp.json"),
      JSON.stringify({
        mcpServers: {
          photobank: {
            command: process.execPath,
            args: [mcp],
            env: {
              RESEARCH_TOOL_URL: capability.url,
              RESEARCH_TOOL_TOKEN: capability.token,
            },
          },
        },
      }),
    );
    const args = [
      "--print",
      "--output-format",
      "json",
      "--json-schema",
      JSON.stringify(schema),
      "--strict-mcp-config",
      "--mcp-config",
      join(dir, "mcp.json"),
      "--setting-sources",
      "",
      "--settings",
      '{"disableAllHooks":true}',
      "--tools",
      "",
      "--allowedTools",
      "mcp__photobank__inspect_image",
      "mcp__photobank__visual_search",
      "mcp__photobank__read_source",
      "--",
      prompt,
    ];
    if (process.env.WEB_RESEARCH_CLAUDE_MODEL)
      args.splice(0, 0, "--model", process.env.WEB_RESEARCH_CLAUDE_MODEL);
    const output = JSON.parse(
      await runProcess("claude", args, { cwd: dir, env, signal }),
    );
    if (output.is_error) {
      const error = new ResearchError("AGENT_FAILED");
      error.diagnostics = JSON.stringify({
        subtype: output.subtype,
        result: output.result,
      });
      throw error;
    }
    return output.structured_output || JSON.parse(output.result);
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}
function openaiContent(result) {
  return result.content.map((c) =>
    c.type === "image"
      ? {
          type: "input_image",
          image_url: `data:${c.mimeType};base64,${c.data}`,
        }
      : { type: "input_text", text: c.text },
  );
}
function claudeContent(result) {
  return result.content.map((c) =>
    c.type === "image"
      ? {
          type: "image",
          source: { type: "base64", media_type: c.mimeType, data: c.data },
        }
      : { type: "text", text: c.text },
  );
}
async function post(url, headers, body, signal, request = fetch) {
  const r = await request(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...headers },
    body: JSON.stringify(body),
    signal,
  });
  if (!r.ok) throw new ResearchError("AGENT_FAILED");
  return r.json();
}
export async function apiAgent(input, session, signal, request = fetch) {
  if (input.provider === "ollama") {
    const history = [
      {
        role: "system",
        content: instructions + "\nJSON schema: " + JSON.stringify(schema),
      },
      {
        role: "user",
        content: input.question || "Определи объект на изображении.",
        images: [input.image_base64],
      },
    ];
    for (let turn = 0; turn < 14; turn++) {
      const response = await post(
        process.env.OLLAMA_BASE_URL.replace(/\/$/, "") + "/api/chat",
        {},
        {
          model: process.env.OLLAMA_RESEARCH_MODEL || "qwen3.5:4b",
          stream: false,
          think: false,
          options: { num_ctx: 16384 },
          messages: history,
          tools: tools.map((t) => ({ type: "function", function: t })),
        },
        signal,
        request,
      );
      const message = response.message;
      if (!message.tool_calls?.length)
        return JSON.parse(
          message.content.replace(/^```(?:json)?\s*|\s*```$/g, ""),
        );
      history.push(message);
      for (const call of message.tool_calls) {
        const result = await invoke(
          session,
          call.function.name,
          call.function.arguments,
        );
        for (const message of history.slice(2)) delete message.images;
        history.push({
          role: "tool",
          tool_name: call.function.name,
          content: result.content
            .filter((c) => c.type === "text")
            .map((c) => c.text)
            .join("\n"),
          images: result.content
            .filter((c) => c.type === "image")
            .map((c) => c.data),
        });
      }
    }
    throw new ResearchError("TOOL_BUDGET_EXCEEDED");
  }
  if (input.provider === "openai_api") {
    const history = [
      {
        role: "user",
        content: [
          {
            type: "input_text",
            text: input.question || "Определи объект на изображении.",
          },
          {
            type: "input_image",
            image_url: `data:${input.mime_type};base64,${input.image_base64}`,
          },
        ],
      },
    ];
    for (let turn = 0; turn < 14; turn++) {
      const response = await post(
        "https://api.openai.com/v1/responses",
        { Authorization: `Bearer ${process.env.OPENAI_API_KEY}` },
        {
          model: process.env.WEB_RESEARCH_OPENAI_MODEL,
          store: false,
          instructions,
          input: history,
          tools: tools.map((t) => ({ type: "function", ...t, strict: true })),
          text: {
            format: {
              type: "json_schema",
              name: "research",
              strict: true,
              schema,
            },
          },
        },
        signal,
        request,
      );
      history.push(...response.output);
      const calls = response.output.filter((c) => c.type === "function_call");
      if (!calls.length)
        return JSON.parse(
          response.output
            .filter((c) => c.type === "message")
            .flatMap((c) => c.content)
            .filter((c) => c.type === "output_text")
            .map((c) => c.text)
            .join(""),
        );
      for (const call of calls)
        history.push({
          type: "function_call_output",
          call_id: call.call_id,
          output: openaiContent(
            await invoke(session, call.name, JSON.parse(call.arguments)),
          ),
        });
    }
  } else {
    const history = [
      {
        role: "user",
        content: [
          {
            type: "text",
            text: input.question || "Определи объект на изображении.",
          },
          {
            type: "image",
            source: {
              type: "base64",
              media_type: input.mime_type,
              data: input.image_base64,
            },
          },
        ],
      },
    ];
    for (let turn = 0; turn < 14; turn++) {
      const response = await post(
        "https://api.anthropic.com/v1/messages",
        {
          "x-api-key": process.env.ANTHROPIC_API_KEY,
          "anthropic-version": "2023-06-01",
        },
        {
          model: process.env.WEB_RESEARCH_ANTHROPIC_MODEL,
          max_tokens: 4096,
          system: instructions + "\nJSON schema: " + JSON.stringify(schema),
          messages: history,
          tools: tools.map((t) => ({
            name: t.name,
            description: t.description,
            input_schema: t.parameters,
          })),
          tool_choice: { type: "auto", disable_parallel_tool_use: true },
        },
        signal,
        request,
      );
      const calls = response.content.filter((c) => c.type === "tool_use");
      if (!calls.length)
        return JSON.parse(
          response.content
            .filter((c) => c.type === "text")
            .map((c) => c.text)
            .join("")
            .replace(/^```(?:json)?\s*|\s*```$/g, ""),
        );
      history.push({ role: "assistant", content: response.content });
      const results = [];
      for (const call of calls)
        results.push({
          type: "tool_result",
          tool_use_id: call.id,
          content: claudeContent(await invoke(session, call.name, call.input)),
        });
      history.push({ role: "user", content: results });
    }
  }
  throw new ResearchError("TOOL_BUDGET_EXCEEDED");
}
