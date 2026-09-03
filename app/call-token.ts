/**
 * Shared-secret auth + signed call-link tokens for the WordPress
 * ("Tom Moving Estimate") integration.
 *
 * The WordPress plugin and this app share one secret (`WP_SHARED_SECRET`).
 * - Server-to-server calls (`/api/calls*`) send it as `Authorization: Bearer`.
 * - The rep / client / recording links carry a compact HS256 token signed with
 *   the same secret, so the call pages can be opened without a ChatGPT login.
 *
 * This module is deliberately free of any `cloudflare:workers` import so it can
 * be unit-tested directly; route handlers read `env.WP_SHARED_SECRET` and pass
 * it in.
 */

export type CallScope = "rep" | "client" | "recording";

export type CallClaims = {
  /** call id (the video_sessions row id) */
  cid: string;
  /** what the bearer of this token may do */
  scp: CallScope;
  iat: number;
  exp: number;
};

/** Timing-safe string comparison. */
export function safeEqual(a: string, b: string): boolean {
  const enc = new TextEncoder();
  const ab = enc.encode(a);
  const bb = enc.encode(b);
  if (ab.length !== bb.length) return false;
  let diff = 0;
  for (let i = 0; i < ab.length; i += 1) diff |= ab[i] ^ bb[i];
  return diff === 0;
}

/** Pull the token from an `Authorization: Bearer <token>` header, or null. */
export function bearerToken(request: Request): string | null {
  const header = request.headers.get("authorization") ?? "";
  const match = /^Bearer\s+(.+)$/i.exec(header.trim());
  return match ? match[1] : null;
}

const textEncoder = new TextEncoder();

const base64url = {
  encode(bytes: Uint8Array): string {
    let binary = "";
    for (let i = 0; i < bytes.length; i += 1) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  },
  decode(value: string): Uint8Array<ArrayBuffer> {
    let s = value.replace(/-/g, "+").replace(/_/g, "/");
    while (s.length % 4) s += "=";
    const binary = atob(s);
    const out = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) out[i] = binary.charCodeAt(i);
    return out;
  },
};

async function hmacKey(secret: string): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    "raw",
    textEncoder.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign", "verify"],
  );
}

/** Mint an HS256 token for a call link. */
export async function mintCallToken(
  secret: string,
  cid: string,
  scp: CallScope,
  ttlSeconds: number,
): Promise<string> {
  const now = Math.floor(Date.now() / 1000);
  const claims: CallClaims = { cid, scp, iat: now, exp: now + ttlSeconds };
  const header = base64url.encode(
    textEncoder.encode(JSON.stringify({ alg: "HS256", typ: "JWT" })),
  );
  const payload = base64url.encode(textEncoder.encode(JSON.stringify(claims)));
  const data = `${header}.${payload}`;
  const signature = new Uint8Array(
    await crypto.subtle.sign("HMAC", await hmacKey(secret), textEncoder.encode(data)),
  );
  return `${data}.${base64url.encode(signature)}`;
}

/** Verify a call-link token. Returns the claims, or null if bad/expired. */
export async function verifyCallToken(
  secret: string,
  token: string,
): Promise<CallClaims | null> {
  const parts = token.split(".");
  if (parts.length !== 3) return null;
  const [header, payload, signature] = parts;

  let signatureBytes: Uint8Array<ArrayBuffer>;
  try {
    signatureBytes = base64url.decode(signature);
  } catch {
    return null;
  }
  const valid = await crypto.subtle.verify(
    "HMAC",
    await hmacKey(secret),
    signatureBytes,
    textEncoder.encode(`${header}.${payload}`),
  );
  if (!valid) return null;

  let claims: CallClaims;
  try {
    claims = JSON.parse(new TextDecoder().decode(base64url.decode(payload))) as CallClaims;
  } catch {
    return null;
  }
  if (!claims || typeof claims.cid !== "string") return null;
  if (claims.scp !== "rep" && claims.scp !== "client" && claims.scp !== "recording") {
    return null;
  }
  if (typeof claims.exp !== "number" || claims.exp < Math.floor(Date.now() / 1000)) {
    return null;
  }
  return claims;
}
