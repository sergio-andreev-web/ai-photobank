import { chromium } from "playwright";
import { lookup } from "node:dns/promises";
import net from "node:net";
import { ResearchError } from "./core.mjs";
export function publicAddress(ip) {
  if (net.isIP(ip) === 4) {
    const [a, b] = ip.split(".").map(Number);
    return !(
      a === 0 ||
      a === 10 ||
      a === 127 ||
      (a === 169 && b === 254) ||
      (a === 172 && b >= 16 && b <= 31) ||
      (a === 192 && b === 168) ||
      (a === 100 && b >= 64 && b <= 127) ||
      a >= 224 ||
      (a === 198 && (b === 18 || b === 19))
    );
  }
  // Only global unicast IPv6; IPv4-mapped, loopback, ULA and link-local are rejected.
  return (
    net.isIP(ip) === 6 &&
    /^[23][0-9a-f]{3}:/i.test(ip) &&
    !ip.toLowerCase().startsWith("2001:db8:")
  );
}
export async function publicUrl(value, resolve = lookup, maxLength = 2000) {
  let u;
  try {
    u = new URL(value);
  } catch {
    throw new ResearchError("SOURCE_URL_BLOCKED");
  }
  if (
    !["http:", "https:"].includes(u.protocol) ||
    u.username ||
    u.password ||
    (u.port && !["80", "443"].includes(u.port)) ||
    u.href.length > maxLength
  )
    throw new ResearchError("SOURCE_URL_BLOCKED");
  const records = await resolve(u.hostname.replace(/^\[|\]$/g, ""), {
    all: true,
  });
  if (!records.length || records.some((r) => !publicAddress(r.address)))
    throw new ResearchError("SOURCE_URL_BLOCKED");
  return u.href;
}
export class BrowserSession {
  constructor(input) {
    this.input = input;
    this.visited = new Set();
    this.allowed = new Set();
    this.calls = 0;
    this.searched = false;
  }
  async init() {
    if (this.context) return;
    this.browser = await chromium.launch({
      headless: process.env.WEB_RESEARCH_HEADLESS !== "false",
    });
    this.context = await this.browser.newContext({
      viewport: { width: 1280, height: 900 },
      locale: "en-US",
      acceptDownloads: false,
      serviceWorkers: "block",
    });
    await this.context.route("**/*", async (route) => {
      try {
        await publicUrl(route.request().url(), lookup, 65536);
        await route.continue();
      } catch {
        await route.abort();
      }
    });
    this.page = await this.context.newPage();
    this.page.setDefaultTimeout(12000);
  }
  async snapshot(page) {
    const data = await page.evaluate(() => ({
      title: document.title,
      text: document.body.innerText.slice(0, 16000),
      links: Array.from(document.querySelectorAll("a[href]"))
        .map((a) => ({ url: a.href, title: a.innerText.trim().slice(0, 300) }))
        .filter((a) => a.title && /^https?:/.test(a.url))
        .slice(0, 80),
    }));
    return {
      content: [
        { type: "text", text: JSON.stringify({ url: page.url(), ...data }) },
        {
          type: "image",
          mimeType: "image/png",
          data: (await page.screenshot({ type: "png" })).toString("base64"),
        },
      ],
    };
  }
  async search() {
    if (this.searched) throw new ResearchError("SEARCH_ALREADY_RUN");
    await this.init();
    try {
      await this.page.goto("https://www.google.com/imghp?hl=en", {
        waitUntil: "load",
      });
      const consent = this.page
        .getByRole("button", { name: /Reject all|Accept all/ })
        .first();
      if (await consent.isVisible()) await consent.click();
      // The file chooser belongs to the Lens "Search by image" dialog.
      await this.page.getByRole("button", { name: /Search by image/i }).click();
      const fileInput = this.page.locator("input[type=file]").first();
      await fileInput.setInputFiles({
        name: "preview." + this.input.mime_type.split("/")[1],
        mimeType: this.input.mime_type,
        buffer: Buffer.from(this.input.image_base64, "base64"),
      });
      await this.page.waitForURL(/lens\.google|google\.[^/]+\/search/, {
        timeout: 30000,
      });
      if (this.page.url().includes("/sorry/"))
        throw new ResearchError("WEB_SEARCH_BLOCKED");
      await this.page
        .locator('a[href^="http"]')
        .first()
        .waitFor({ timeout: 15000 });
      const text = await this.page.locator("body").innerText();
      if (/unusual traffic|not a robot|captcha/i.test(text))
        throw new ResearchError("WEB_SEARCH_BLOCKED");
      const result = await this.snapshot(this.page);
      const links = JSON.parse(result.content[0].text).links;
      for (const link of links) {
        let url = link.url;
        const parsed = new URL(url);
        if (
          parsed.hostname.endsWith("google.com") &&
          parsed.pathname === "/url"
        )
          url =
            parsed.searchParams.get("q") ||
            parsed.searchParams.get("url") ||
            url;
        this.allowed.add(url);
      }
      const data = JSON.parse(result.content[0].text);
      data.links = data.links.map((link) => {
        const parsed = new URL(link.url);
        return {
          ...link,
          url:
            parsed.hostname.endsWith("google.com") && parsed.pathname === "/url"
              ? parsed.searchParams.get("q") ||
                parsed.searchParams.get("url") ||
                link.url
              : link.url,
        };
      });
      result.content[0].text = JSON.stringify(data);
      this.searched = true;
      return result;
    } catch (e) {
      if (e instanceof ResearchError) throw e;
      const error = new ResearchError("WEB_SEARCH_BLOCKED");
      error.cause = e;
      throw error;
    }
  }
  async read(url) {
    if (!this.allowed.has(url) || this.visited.size >= 6)
      throw new ResearchError("SOURCE_URL_BLOCKED");
    await publicUrl(url);
    await this.init();
    const page = await this.context.newPage();
    try {
      const response = await page.goto(url, {
        waitUntil: "domcontentloaded",
        timeout: 25000,
      });
      if (!response?.ok()) throw new ResearchError("SOURCE_UNAVAILABLE");
      await publicUrl(page.url());
      const result = await this.snapshot(page);
      this.visited.add(url);
      this.visited.add(page.url());
      for (const link of JSON.parse(result.content[0].text).links)
        this.allowed.add(link.url);
      return result;
    } finally {
      await page.close();
    }
  }
  async close() {
    await this.browser?.close();
  }
}
