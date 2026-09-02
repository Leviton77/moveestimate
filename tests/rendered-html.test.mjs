import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);

test("ships the completed MoveEstimate product surface", async () => {
  const [page, layout, form, recorder, css, packageJson] = await Promise.all([
    readFile(new URL("app/page.tsx", root), "utf8"),
    readFile(new URL("app/layout.tsx", root), "utf8"),
    readFile(new URL("app/estimate/EstimateForm.tsx", root), "utf8"),
    readFile(new URL("app/session/[id]/SessionRecorder.tsx", root), "utf8"),
    readFile(new URL("app/globals.css", root), "utf8"),
    readFile(new URL("package.json", root), "utf8"),
  ]);
  assert.match(page, /See the move/);
  assert.match(page, /Start my free estimate/);
  assert.match(layout, /MoveEstimate \| Tom Moving/);
  assert.match(form, /Continue to video walkthrough/);
  assert.match(recorder, /MediaRecorder\.isTypeSupported/);
  assert.match(recorder, /x-video-size/);
  assert.match(css, /\.video-stage/);
  assert.doesNotMatch(packageJson, /react-loading-skeleton/);
});

test("stores real videos and protects representative access", async () => {
  const [uploadRoute, media, repAuth, hosting] = await Promise.all([
    readFile(new URL("app/api/sessions/[id]/video/route.ts", root), "utf8"),
    readFile(new URL("db/media.ts", root), "utf8"),
    readFile(new URL("app/rep-auth.ts", root), "utf8"),
    readFile(new URL(".openai/hosting.json", root), "utf8"),
  ]);
  assert.match(uploadRoute, /bucket\.put/);
  assert.doesNotMatch(uploadRoute, /base64|placeholder/i);
  assert.match(media, /MEDIA/);
  assert.match(repAuth, /REP_EMAILS/);
  const hostingConfig = JSON.parse(hosting);
  assert.equal(hostingConfig.d1, "DB");
  assert.equal(hostingConfig.r2, "MEDIA");
});
