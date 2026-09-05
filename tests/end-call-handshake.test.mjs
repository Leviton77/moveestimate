import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);

const read = (path) => readFile(new URL(path, root), "utf8");

// The recording only ever exists on the client. Ending the call — from either
// side — must therefore run through the client's finalize/upload, and the rep
// must not tear the transport down in a way that stops the client's capture
// tracks before that upload happens. See the "either side can end" fix.

test("the transport carries a rep -> client 'end-call' app message", async () => {
  const call = await read("app/video-call/[id]/call.ts");
  // Part of the AppMessage union, so send()/routing pass it through untouched.
  assert.match(call, /type:\s*"end-call"/);
  // It is an ordinary app message, never a signalling type.
  assert.doesNotMatch(call, /SIGNAL_TYPES\s*=\s*new Set\(\[[^\]]*"end-call"/);
});

test("the rep ends the call by asking the client to wrap up, not by closing the transport", async () => {
  const repCall = await read("app/video-call/[id]/rep/RepCall.tsx");
  const endStart = repCall.indexOf("const endCall = useCallback(");
  assert.ok(endStart > -1, "could not locate endCall in RepCall.tsx");
  // The endCall callback body only, up to its closing `}, []);`.
  const endCallBody = repCall.slice(endStart, repCall.indexOf("}, []);", endStart));
  // Sends the wrap-up request...
  assert.match(endCallBody, /callRef\.current\?\.send\(\{\s*type:\s*"end-call"\s*\}\)/);
  // ...and does NOT synchronously close the transport (that would "bye" the
  // client and stop the tracks its recorder still needs).
  assert.doesNotMatch(endCallBody, /callRef\.current\?\.close\(\)/);
  // The background transport is still closed eventually — once the client
  // confirms (its "bye" surfaces as state "closed") or drops off.
  assert.match(repCall, /ended && \(state === "closed" \|\| !clientHere\)/);
  assert.match(repCall, /callRef\.current\?\.close\(\)/);
});

test("the client finalizes and uploads when the rep sends 'end-call'", async () => {
  const client = await read("app/video-call/[id]/VideoCallInterface.tsx");
  assert.match(
    client,
    /msg\.type === "end-call" && msg\.from === "rep"[\s\S]{0,400}?void finalize\(\)/,
  );
  // finalize() still uploads before signalling "bye" (b4120b7): the fetch to
  // the upload route comes before callRef.current?.close().
  const finalize = client.slice(
    client.indexOf("const finalize = useCallback("),
    client.indexOf("const switchCamera = useCallback("),
  );
  const uploadAt = finalize.indexOf("/upload`");
  const closeAt = finalize.indexOf("callRef.current?.close()");
  assert.ok(uploadAt > -1 && closeAt > -1, "finalize should upload and then close");
  assert.ok(uploadAt < closeAt, "finalize must upload before it closes the transport");
});
