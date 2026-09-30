// Opt-in live smoke; uploads only a synthetic drawing, never project/user photos.
import { BrowserSession } from "../src/browser.mjs";
import { chromium } from "playwright";
const b = await chromium.launch();
const p = await b.newPage();
await p.setContent(
  '<html><body style="margin:0"><svg width="600" height="400"><rect width="600" height="400" fill="white"/><circle cx="300" cy="200" r="130" fill="orange"/><path d="M170 200H430M300 70V330M220 90Q370 200 220 310M380 90Q230 200 380 310" stroke="black" stroke-width="8" fill="none"/></svg></body></html>',
);
const bytes = await p.screenshot({
  clip: { x: 0, y: 0, width: 600, height: 400 },
});
await b.close();
const session = new BrowserSession({
  mime_type: "image/png",
  image_base64: bytes.toString("base64"),
});
await session.init();
session.page.on("pageerror", (e) => console.log("PAGE_ERROR", e.message));
session.page.on("requestfailed", (r) =>
  console.log(
    "REQUEST_FAILED",
    new URL(r.url()).hostname,
    new URL(r.url()).pathname.slice(0, 100),
    r.failure().errorText,
  ),
);
try {
  const result = await session.search();
  console.log(result.content[0].text);
} catch (e) {
  console.log("LIVE_RESULT:", e.code || e.message, e.cause?.message);
  if (session.page) {
    console.log("URL:", session.page.url());
    console.log(
      await session.page
        .locator("[aria-label],[role=button]")
        .evaluateAll((nodes) =>
          nodes.map((n) => ({
            tag: n.tagName,
            role: n.getAttribute("role"),
            aria: n.getAttribute("aria-label"),
            title: n.getAttribute("title"),
          })),
        ),
    );
    console.log(
      (await session.page.locator("body").innerText()).slice(0, 2500),
    );
    await session.page.screenshot({ path: "/tmp/photobank-lens.png" });
  }
  process.exitCode = 1;
} finally {
  await session.close();
}
