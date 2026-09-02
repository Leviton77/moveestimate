import type { Metadata } from "next";
import Link from "next/link";
import { SiteHeader } from "../components/SiteHeader";
import { chatGPTSignInPath, chatGPTSignOutPath } from "../chatgpt-auth";
import { getRepAccess } from "../rep-auth";

export const metadata: Metadata = { title: "Representative portal" };
export const dynamic = "force-dynamic";

export default async function RepPortalPage() {
  const access = await getRepAccess();
  return (
    <><SiteHeader /><main className="page-shell portal-entry"><div className="portal-card"><p className="eyebrow">Tom Moving team</p><h1>Representative portal</h1>
      {!access.user && <><p>Sign in with your authorized account to review estimate requests and customer walkthroughs.</p><Link className="button button--primary button--wide" href={chatGPTSignInPath("/rep/dashboard")}>Sign in securely</Link></>}
      {access.user && access.authorized && <><p>Signed in as <strong>{access.user.email}</strong>.</p><Link className="button button--primary button--wide" href="/rep/dashboard">Open estimate dashboard</Link><Link className="text-link" href={chatGPTSignOutPath("/rep")}>Sign out</Link></>}
      {access.user && !access.authorized && <><div className="access-warning"><strong>{access.configured ? "This account is not authorized." : "Portal access is not configured yet."}</strong><span>Ask the site owner to add {access.user.email} to the representative allowlist.</span></div><Link className="text-link" href={chatGPTSignOutPath("/rep")}>Sign in with another account</Link></>}
    </div></main></>
  );
}
