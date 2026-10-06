/** Cloudflare Worker entry point for the vinext-starter template. */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";
import { cleanupOldRecordings } from "../db/cleanup";

interface Env {
  ASSETS: Fetcher;
  DB: D1Database;
  IMAGES: {
    input(stream: ReadableStream): {
      transform(options: Record<string, unknown>): {
        output(options: { format: string; quality: number }): Promise<{ response(): Response }>;
      };
    };
  };
}

interface ExecutionContext {
  waitUntil(promise: Promise<unknown>): void;
  passThroughOnException(): void;
}

// Image security config. SVG sources with .svg extension auto-skip the
// optimization endpoint on the client side (served directly, no proxy).
// To route SVGs through the optimizer (with security headers), set
// dangerouslyAllowSVG: true in next.config.js and uncomment below:
// const imageConfig: ImageConfig = { dangerouslyAllowSVG: true };

const worker = {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const url = new URL(request.url);

    if (url.pathname === "/_vinext/image") {
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      return handleImageOptimization(request, {
        fetchAsset: (path) => env.ASSETS.fetch(new Request(new URL(path, request.url))),
        transformImage: async (body, { width, format, quality }) => {
          const result = await env.IMAGES.input(body).transform(width > 0 ? { width } : {}).output({ format, quality });
          return result.response();
        },
      }, allowedWidths);
    }

    return handler.fetch(request, env, ctx);
  },

  // Cron triggers (wrangler.jsonc → triggers):
  // - every 5 min: open WordPress's wp-cron.php, so the plugin's 5-minute
  //   import sweep runs on time even when nobody visits the site (WP-Cron only
  //   runs on page visits);
  // - daily: delete recordings WordPress has already imported.
  async scheduled(controller: { cron: string }, env: Env, ctx: ExecutionContext): Promise<void> {
    if (controller.cron === "*/5 * * * *") {
      const url = (env as unknown as { WP_CRON_URL?: string }).WP_CRON_URL;
      if (!url) return;
      ctx.waitUntil(
        fetch(url, { headers: { "user-agent": "moveestimate-wp-cron-ping" } })
          .then((res) => {
            if (!res.ok) console.warn(`[wp-cron] ${url} → HTTP ${res.status}`);
          })
          .catch((error: unknown) => console.warn(`[wp-cron] ${url} failed`, error)),
      );
      return;
    }
    ctx.waitUntil(cleanupOldRecordings().then(() => undefined));
  },
};

export default worker;
