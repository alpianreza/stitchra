"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, PageHeader, StatusBadge } from "@/components/ui";

type Action = "REWORK" | "REPAIR" | "REJECT" | "SECOND_GRADE" | "SCRAP";
interface Disposition { id: number; action: Action; qty: string; target_stage: string | null; approved_at: string | null; }
interface ReworkOrder { id: number; target_stage: string; qty: string; status: string; reinspection?: { doc_no: string; verdict: string } | null; }
interface Ncr { id: number; doc_no: string; qty: string; status: string; qc_inspection: { doc_no: string; stage: string; verdict: string; cycle: number }; production_order: { doc_no: string }; dispositions: Disposition[]; rework_orders: ReworkOrder[]; }
interface Page<T> { data: T[]; }

const actions: Action[] = ["REWORK", "REPAIR", "REJECT", "SECOND_GRADE", "SCRAP"];

export default function NcrPage() {
  const [items, setItems] = useState<Ncr[]>([]); const [selected, setSelected] = useState<Ncr | null>(null);
  const [action, setAction] = useState<Action>("REWORK"); const [qty, setQty] = useState(""); const [stage, setStage] = useState("SEWING"); const [notes, setNotes] = useState("");
  const [loading, setLoading] = useState(true); const [saving, setSaving] = useState(false); const [error, setError] = useState<string | null>(null); const [message, setMessage] = useState<string | null>(null);
  const load = useCallback(() => { setLoading(true); setError(null); api.get<Page<Ncr>>("/qc/ncrs").then((r) => setItems(r.data)).catch((e) => setError(e.message)).finally(() => setLoading(false)); }, []);
  useEffect(load, [load]);
  async function addDisposition() { if (!selected) return; setSaving(true); setError(null); try { await api.post(`/qc/ncrs/${selected.id}/dispositions`, { action, qty: Number(qty), target_stage: action === "REWORK" || action === "REPAIR" ? stage : undefined, notes: notes || undefined }); setMessage(`Disposition ${action} ditambahkan ke ${selected.doc_no}.`); setSelected(null); setQty(""); setNotes(""); load(); } catch (e: any) { setError(e.message); } finally { setSaving(false); } }
  async function submit(ncr: Ncr) { setSaving(true); setError(null); try { await api.post(`/qc/ncrs/${ncr.id}/submit`, {}); setMessage(`${ncr.doc_no} dikirim ke approval.`); load(); } catch (e: any) { setError(e.message); } finally { setSaving(false); } }
  return <div className="space-y-4">
    <PageHeader eyebrow="Quality" title="NCR & Disposition" description="Trace QC gagal menuju keputusan, rework order, dan inspeksi ulang." />
    {message && <div className="rounded border border-green-200 bg-green-50 p-3 text-sm text-green-700">{message}</div>}
    {error && <div role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}
    <section className="overflow-x-auto rounded border border-[var(--color-border)] bg-[var(--color-surface)]">
      <table className="min-w-[1050px] w-full text-sm"><thead className="bg-[var(--color-surface-subtle)] text-left text-xs uppercase text-[var(--color-text-muted)]"><tr><th className="p-3">NCR</th><th className="p-3">Upstream</th><th className="p-3">Qty</th><th className="p-3">Disposition</th><th className="p-3">Downstream</th><th className="p-3">Status</th><th className="p-3 text-right">Action</th></tr></thead>
      <tbody>{items.map((ncr) => <tr key={ncr.id} className="border-t border-[var(--color-border-subtle)] align-top"><td className="p-3 font-mono font-semibold">{ncr.doc_no}</td><td className="p-3"><p>{ncr.qc_inspection.doc_no} · {ncr.qc_inspection.stage}</p><p className="text-xs text-[var(--color-text-muted)]">{ncr.production_order.doc_no} · cycle {ncr.qc_inspection.cycle}</p></td><td className="p-3">{Number(ncr.qty).toLocaleString("id-ID")}</td><td className="p-3">{ncr.dispositions.length ? ncr.dispositions.map((d) => <p key={d.id}>{d.action} · {Number(d.qty).toLocaleString("id-ID")}{d.target_stage ? ` → ${d.target_stage}` : ""}</p>) : <span className="text-[var(--color-text-muted)]">Belum diputuskan</span>}</td><td className="p-3">{ncr.rework_orders.length ? ncr.rework_orders.map((r) => <p key={r.id}>RO #{r.id} · {r.target_stage} · {r.status}{r.reinspection ? ` → ${r.reinspection.doc_no} (${r.reinspection.verdict})` : ""}</p>) : <span className="text-[var(--color-text-muted)]">—</span>}</td><td className="p-3"><StatusBadge status={ncr.status} /></td><td className="p-3"><div className="flex justify-end gap-2">{ncr.status === "DRAFT" && <><Button size="sm" onClick={() => { setSelected(ncr); setQty(ncr.qty); }}>Disposition</Button><Button size="sm" variant="success" loading={saving} onClick={() => submit(ncr)}>Submit</Button></>}</div></td></tr>)}{!loading && !items.length && <tr><td colSpan={7} className="p-8 text-center text-[var(--color-text-muted)]">Belum ada NCR. NCR dibuat otomatis ketika QC gagal.</td></tr>}</tbody></table>
      {loading && <p className="p-6 text-sm text-[var(--color-text-muted)]">Memuat NCR…</p>}
    </section>
    {selected && <section className="rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4"><div className="mb-4 flex items-center justify-between"><div><h2 className="font-semibold">Disposition {selected.doc_no}</h2><p className="text-xs text-[var(--color-text-muted)]">Total alokasi harus sama dengan qty NCR sebelum submit.</p></div><Button size="sm" onClick={() => setSelected(null)}>Tutup</Button></div><div className="grid gap-3 md:grid-cols-4"><label className="text-sm">Action<select value={action} onChange={(e) => setAction(e.target.value as Action)} className="mt-1 w-full rounded border p-2">{actions.map((a) => <option key={a}>{a}</option>)}</select></label><label className="text-sm">Qty<input type="number" min="0.0001" step="0.0001" value={qty} onChange={(e) => setQty(e.target.value)} className="mt-1 w-full rounded border p-2" /></label>{(action === "REWORK" || action === "REPAIR") && <label className="text-sm">Target stage<select value={stage} onChange={(e) => setStage(e.target.value)} className="mt-1 w-full rounded border p-2"><option>CUTTING</option><option>SEWING</option><option>FINISHING</option></select></label>}<label className="text-sm">Notes<input value={notes} onChange={(e) => setNotes(e.target.value)} className="mt-1 w-full rounded border p-2" /></label></div><div className="mt-4 flex justify-end"><Button variant="success" loading={saving} onClick={addDisposition}>Simpan disposition</Button></div></section>}
  </div>;
}
