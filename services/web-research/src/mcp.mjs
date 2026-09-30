// A job-scoped stdio proxy: the host owns browser state and verifies visited sources.
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { tools } from "./core.mjs";
const base = process.env.RESEARCH_TOOL_URL;
if (
  !base ||
  !/^http:\/\/127\.0\.0\.1:\d+\/internal\/tools\/[a-f0-9]+$/.test(base)
)
  throw new Error("Missing job capability");
const server = new McpServer({ name: "photobank-research", version: "1.0.0" });
for (const tool of tools)
  server.registerTool(
    tool.name,
    {
      description: tool.description,
      inputSchema:
        tool.name === "read_source" ? { url: z.string().max(2000) } : {},
    },
    async (args) => {
      const response = await fetch(base, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${process.env.RESEARCH_TOOL_TOKEN}`,
        },
        body: JSON.stringify({ name: tool.name, args }),
        signal: AbortSignal.timeout(45000),
      });
      if (!response.ok)
        return {
          isError: true,
          content: [{ type: "text", text: (await response.json()).error.code }],
        };
      return response.json();
    },
  );
await server.connect(new StdioServerTransport());
