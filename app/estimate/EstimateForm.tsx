"use client";

import { FormEvent, useMemo, useState } from "react";
import { useRouter } from "next/navigation";

const initialForm = {
  clientName: "", email: "", phone: "", moveDate: "", currentAddress: "",
  destinationAddress: "", estimatedSize: "", specialItems: "", website: "",
};

export function EstimateForm() {
  const router = useRouter();
  const [form, setForm] = useState(initialForm);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError("");
    try {
      const response = await fetch("/api/sessions", {
        method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(form),
      });
      const result = (await response.json()) as { id?: string; error?: string };
      if (!response.ok || !result.id) throw new Error(result.error ?? "Unable to start your estimate.");
      router.push(`/session/${result.id}`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to start your estimate.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="form-card" onSubmit={submit}>
      <div className="form-grid">
        <label><span>Full name *</span><input required autoComplete="name" value={form.clientName} onChange={(event) => update("clientName", event.target.value)} placeholder="Jane Smith" /></label>
        <label><span>Email *</span><input required type="email" autoComplete="email" value={form.email} onChange={(event) => update("email", event.target.value)} placeholder="jane@email.com" /></label>
        <label><span>Phone *</span><input required type="tel" autoComplete="tel" value={form.phone} onChange={(event) => update("phone", event.target.value)} placeholder="(819) 555-0123" /></label>
        <label><span>Move date *</span><input required type="date" min={today} value={form.moveDate} onChange={(event) => update("moveDate", event.target.value)} /></label>
      </div>
      <label><span>Current address *</span><input required autoComplete="street-address" value={form.currentAddress} onChange={(event) => update("currentAddress", event.target.value)} placeholder="123 Main St, Ottawa, ON" /></label>
      <label><span>Destination address *</span><input required value={form.destinationAddress} onChange={(event) => update("destinationAddress", event.target.value)} placeholder="456 Oak Ave, Gatineau, QC" /></label>
      <label><span>Estimated home size *</span><select required value={form.estimatedSize} onChange={(event) => update("estimatedSize", event.target.value)}><option value="">Select home size</option><option>Studio</option><option>1BR</option><option>2BR</option><option>3BR</option><option>House</option></select></label>
      <label><span>Special items or notes</span><textarea rows={4} value={form.specialItems} onChange={(event) => update("specialItems", event.target.value)} placeholder="Piano, antiques, fragile items, stairs, elevator details…" /></label>
      <label className="honeypot" aria-hidden="true">Website<input tabIndex={-1} autoComplete="off" value={form.website} onChange={(event) => update("website", event.target.value)} /></label>
      {error && <p className="form-error" role="alert">{error}</p>}
      <button className="button button--primary button--wide" type="submit" disabled={submitting}>{submitting ? "Preparing your walkthrough…" : "Continue to video walkthrough"}</button>
      <p className="form-footnote">Your details and recording are used only to prepare your moving estimate.</p>
    </form>
  );
}
