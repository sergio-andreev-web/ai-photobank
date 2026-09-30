// Opt-in real CLI smoke with deterministic MCP sources; no live source accuracy claim.
import { createServer } from "../src/server.mjs";
const input = {
  provider: process.argv[2] || "codex_cli",
  mime_type: "image/png",
  image_base64:
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB9kAAAAASUVORK5CYII=",
  question:
    "Контрольный тест MCP: обязательно вызови inspect_image, visual_search и read_source, даже если мало признаков. Определи объект по источникам. Не ищи вне MCP.",
};
const source = "https://example.com/fixture";
const server = createServer({
  token: "x".repeat(32),
  getAvailability: () => [{ id: input.provider, available: true }],
  sessionFactory: () => {
    const s = {
      input,
      calls: 0,
      searched: false,
      visited: new Set(),
      close: async () => {},
      search: async () => {
        s.searched = true;
        console.log("TOOL visual_search");
        return {
          content: [
            {
              type: "text",
              text: JSON.stringify({
                links: [{ url: source, title: "Тестовая страница" }],
              }),
            },
          ],
        };
      },
      read: async (url) => {
        s.visited.add(url);
        console.log("TOOL read_source");
        return {
          content: [
            {
              type: "text",
              text: "Недостаточно признаков: белый пиксель, объект не определен.",
            },
          ],
        };
      },
    };
    return s;
  },
});
await new Promise((r) => server.listen(0, "127.0.0.1", r));
try {
  const r = await fetch(
    `http://127.0.0.1:${server.address().port}/v1/research`,
    {
      method: "POST",
      headers: {
        Authorization: "Bearer " + "x".repeat(32),
        "Content-Type": "application/json",
      },
      body: JSON.stringify(input),
    },
  );
  console.log("HTTP", r.status, "RESULT", JSON.stringify(await r.json()));
  if (!r.ok) process.exitCode = 1;
} finally {
  server.close();
}
