import assert from "node:assert/strict";
import test from "node:test";

import {
  bearerToken,
  mintCallToken,
  safeEqual,
  verifyCallToken,
} from "../app/call-token.ts";

const SECRET = "test-secret-0123456789abcdef0123456789abcdef";
const CALL_ID = "11111111-2222-4333-8444-555555555555";

test("mint/verify round-trips and carries the claims", async () => {
  const token = await mintCallToken(SECRET, CALL_ID, "client", 3600);
  const claims = await verifyCallToken(SECRET, token);
  assert.ok(claims);
  assert.equal(claims.cid, CALL_ID);
  assert.equal(claims.scp, "client");
  assert.ok(claims.exp > claims.iat);
});

test("rejects a token signed with a different secret", async () => {
  const token = await mintCallToken(SECRET, CALL_ID, "rep", 3600);
  assert.equal(await verifyCallToken("another-secret", token), null);
});

test("rejects a tampered payload", async () => {
  const token = await mintCallToken(SECRET, CALL_ID, "rep", 3600);
  const [header, , signature] = token.split(".");
  const forged = Buffer.from(
    JSON.stringify({ cid: CALL_ID, scp: "recording", iat: 1, exp: 9999999999 }),
  ).toString("base64url");
  assert.equal(await verifyCallToken(SECRET, `${header}.${forged}.${signature}`), null);
});

test("rejects an expired token", async () => {
  const token = await mintCallToken(SECRET, CALL_ID, "client", -1);
  assert.equal(await verifyCallToken(SECRET, token), null);
});

test("rejects malformed input", async () => {
  assert.equal(await verifyCallToken(SECRET, "not-a-token"), null);
  assert.equal(await verifyCallToken(SECRET, "a.b"), null);
  assert.equal(await verifyCallToken(SECRET, ""), null);
});

test("safeEqual compares by value, not reference", () => {
  assert.equal(safeEqual("abc", "abc"), true);
  assert.equal(safeEqual("abc", "abd"), false);
  assert.equal(safeEqual("abc", "abcd"), false);
  assert.equal(safeEqual("", ""), true);
});

test("bearerToken extracts the token, case-insensitively", () => {
  const mk = (auth) => new Request("https://x.test", { headers: { authorization: auth } });
  assert.equal(bearerToken(mk("Bearer abc123")), "abc123");
  assert.equal(bearerToken(mk("bearer abc123")), "abc123");
  assert.equal(bearerToken(mk("Basic abc123")), null);
  assert.equal(bearerToken(new Request("https://x.test")), null);
});
