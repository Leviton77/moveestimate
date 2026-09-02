import { env } from "cloudflare:workers";
import { getChatGPTUser } from "./chatgpt-auth";

type RuntimeBindings = { REP_EMAILS?: string };

function allowedEmails() {
  const raw = (env as unknown as RuntimeBindings).REP_EMAILS ?? "";
  const emails = new Set(
    raw
      .split(",")
      .map((email) => email.trim().toLowerCase())
      .filter(Boolean),
  );
  
  // Development mode: allow test account
  if (process.env.NODE_ENV === 'development') {
    emails.add('test@tom-moving.local');
  }
  
  return emails;
}

export async function getRepAccess() {
  const user = await getChatGPTUser();
  const allowlist = allowedEmails();
  return {
    user,
    configured: allowlist.size > 0,
    authorized: Boolean(user && allowlist.has(user.email.toLowerCase())),
  };
}
