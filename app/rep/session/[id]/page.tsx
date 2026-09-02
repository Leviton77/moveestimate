import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { SiteHeader } from "../../../components/SiteHeader";
import { getRepAccess } from "../../../rep-auth";
import { getSession, isSessionId } from "../../../../db/sessions";
import { RepSessionClient } from "./RepSessionClient";

export const metadata: Metadata = { title: "Review estimate" };
export const dynamic = "force-dynamic";

export default async function RepSessionPage({ params }: { params: Promise<{ id: string }> }) {
  const access = await getRepAccess();
  if (!access.authorized) redirect("/rep");
  const { id } = await params;
  const session = isSessionId(id) ? await getSession(id) : null;
  if (!session) return <><SiteHeader /><div className="status-card status-card--page"><h1>Estimate not found</h1><Link href="/rep/dashboard" className="button button--quiet">Back to dashboard</Link></div></>;
  return <><SiteHeader /><RepSessionClient session={session} /></>;
}
