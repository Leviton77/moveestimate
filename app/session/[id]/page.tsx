import type { Metadata } from "next";
import { SiteHeader } from "../../components/SiteHeader";
import { SessionRecorder } from "./SessionRecorder";

export const metadata: Metadata = { title: "Video walkthrough" };

export default async function SessionPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <><SiteHeader /><main className="page-shell page-shell--wide"><SessionRecorder id={id} /></main></>;
}
