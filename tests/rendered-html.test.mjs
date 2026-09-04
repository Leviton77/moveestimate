import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);

test("ships the MoveEstimate homepage, pointing off-site for the async estimate", async () => {
  const [page, header, layout, css, packageJson] = await Promise.all([
    readFile(new URL("app/page.tsx", root), "utf8"),
    readFile(new URL("app/components/SiteHeader.tsx", root), "utf8"),
    readFile(new URL("app/layout.tsx", root), "utf8"),
    readFile(new URL("app/globals.css", root), "utf8"),
    readFile(new URL("package.json", root), "utf8"),
  ]);
  assert.match(page, /See the move/);
  assert.match(page, /Start my free estimate/);
  // The async self-serve flow moved to the WordPress plugin; both entry
  // points now link off-site instead of to a route this app no longer has.
  assert.match(page, /https:\/\/tommoving\.ca\/estimate\//);
  assert.match(header, /https:\/\/tommoving\.ca\/estimate\//);
  assert.doesNotMatch(page, /href="\/estimate"/);
  assert.doesNotMatch(page, /href="\/rep"/);
  assert.doesNotMatch(header, /href="\/rep"/);
  assert.match(layout, /MoveEstimate \| Tom Moving/);
  assert.match(css, /\.video-call-container/);
  assert.doesNotMatch(packageJson, /react-loading-skeleton/);
});

test("retires the async self-serve flow and ChatGPT rep sign-in", async () => {
  const retired = [
    "app/estimate/page.tsx",
    "app/estimate/EstimateForm.tsx",
    "app/session/[id]/page.tsx",
    "app/session/[id]/SessionRecorder.tsx",
    "app/rep/page.tsx",
    "app/rep/dashboard/page.tsx",
    "app/rep/video-sessions/page.tsx",
    "app/rep/video-sessions/[id]/VideoSessionDetail.tsx",
    "app/rep/session/[id]/page.tsx",
    "app/rep-auth.ts",
    "app/chatgpt-auth.ts",
    "app/components/VideoLinkGenerator.tsx",
    "app/api/sessions/route.ts",
    "app/api/rep/sessions/[id]/route.ts",
    "app/api/video-sessions/route.ts",
    "app/api/video-sessions/[id]/route.ts",
    "app/api/video-sessions/[id]/video/route.ts",
  ];
  await Promise.all(
    retired.map((path) =>
      assert.rejects(
        () => readFile(new URL(path, root), "utf8"),
        `expected ${path} to be gone`,
      ),
    ),
  );
});

test("the live call is gated by WordPress-issued tokens, not a ChatGPT login", async () => {
  const [clientPage, repPage, repCall, contactRoute] = await Promise.all([
    readFile(new URL("app/video-call/[id]/page.tsx", root), "utf8"),
    readFile(new URL("app/video-call/[id]/rep/page.tsx", root), "utf8"),
    readFile(new URL("app/video-call/[id]/rep/RepCall.tsx", root), "utf8"),
    readFile(new URL("app/api/video-sessions/[id]/contact/route.ts", root), "utf8"),
  ]);
  assert.match(clientPage, /verifyCallLinkToken\(t, id, "client"\)/);
  assert.match(repPage, /verifyCallLinkToken\(t, id, "rep"\)/);
  assert.match(repCall, /tme_live_import/);
  assert.doesNotMatch(contactRoute, /export async function PATCH/);
  assert.doesNotMatch(contactRoute, /rep-auth/);
});

test("exposes the WordPress live-call API, bearer-protected", async () => {
  const [create, pull, ingested, recording, wpAuth, devVars] = await Promise.all([
    readFile(new URL("app/api/calls/route.ts", root), "utf8"),
    readFile(new URL("app/api/calls/[id]/route.ts", root), "utf8"),
    readFile(new URL("app/api/calls/[id]/ingested/route.ts", root), "utf8"),
    readFile(new URL("app/api/calls/[id]/recording/route.ts", root), "utf8"),
    readFile(new URL("app/wp-auth.ts", root), "utf8"),
    readFile(new URL(".dev.vars.example", root), "utf8"),
  ]);
  assert.match(create, /isWordPressRequest\(request\)/);
  assert.match(create, /rep_url/);
  assert.match(create, /client_url/);
  assert.match(pull, /recording/);
  assert.match(pull, /mintCallToken\(secret, id, "recording"/);
  assert.match(ingested, /markWpIngested/);
  assert.match(recording, /verifyCallToken/);
  assert.match(recording, /content-range/);
  assert.match(wpAuth, /WP_SHARED_SECRET/);
  assert.match(devVars, /WP_SHARED_SECRET/);
  assert.doesNotMatch(devVars, /REP_EMAILS/);
});

test("stores real videos in R2 with the correct bindings", async () => {
  const [uploadRoute, media, hosting] = await Promise.all([
    readFile(new URL("app/api/video-sessions/[id]/upload/route.ts", root), "utf8"),
    readFile(new URL("db/media.ts", root), "utf8"),
    readFile(new URL(".openai/hosting.json", root), "utf8"),
  ]);
  assert.match(uploadRoute, /bucket\.put/);
  assert.doesNotMatch(uploadRoute, /base64|placeholder/i);
  assert.match(media, /MEDIA/);
  const hostingConfig = JSON.parse(hosting);
  assert.equal(hostingConfig.d1, "DB");
  assert.equal(hostingConfig.r2, "MEDIA");
});
