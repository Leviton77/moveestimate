/**
 * Runtime glue for the WordPress ("Tom Moving Estimate") integration: reads the
 * shared secret from the environment and checks incoming server-to-server
 * requests. Kept separate from `call-token.ts` so that module stays free of any
 * `cloudflare:workers` import and can be unit-tested on its own.
 */
import { env } from "cloudflare:workers";
import { bearerToken, safeEqual } from "./call-token";

type RuntimeEnv = { WP_SHARED_SECRET?: string };

export function wpSharedSecret(): string | null {
  return (env as unknown as RuntimeEnv).WP_SHARED_SECRET ?? null;
}

/** True when the request carries `Authorization: Bearer <WP_SHARED_SECRET>`. */
export function isWordPressRequest(request: Request): boolean {
  const secret = wpSharedSecret();
  if (!secret) return false;
  return safeEqual(bearerToken(request) ?? "", secret);
}

/** Public origin to build rep/client call links against (mirrors app/layout.tsx). */
export function callLinkOrigin(request: Request): string {
  const url = new URL(request.url);
  const host =
    request.headers.get("x-forwarded-host") ??
    request.headers.get("host") ??
    url.host;
  const protocol =
    request.headers.get("x-forwarded-proto") ?? url.protocol.replace(":", "");
  return `${protocol}://${host}`;
}
