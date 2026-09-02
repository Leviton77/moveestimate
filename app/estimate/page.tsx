import type { Metadata } from "next";
import { SiteHeader } from "../components/SiteHeader";
import { EstimateForm } from "./EstimateForm";

export const metadata: Metadata = { title: "Get your free estimate" };

export default function EstimatePage() {
  return <><SiteHeader /><main className="page-shell"><div className="page-intro"><p className="eyebrow">Step 1 of 2</p><h1>Tell us about your move</h1><p>After this short form, you’ll record a private walkthrough from your phone.</p></div><EstimateForm /></main></>;
}
