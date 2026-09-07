"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Field, Input, Pagination, Select, StatusBadge } from "@/components/ui";

interface Sample { id: number; doc_no: string; stage: string; version: number; buyer_status: string }
interface Gate {
  selected: Sample | null; ready: boolean; reason: string | null; can_select: boolean; checked_at: string | null;
  release_snapshot: { doc_no: string; stage: string; version: number; approval_id: number; by_name: string | null; response_reference: string | null } | null;
  candidates: { data: Sample[]; current_page: number; last_page: number; total: number };
}

export function SampleGatePanel({ moId, moStatus, onSaved }: { moId: number; moStatus: string; onSaved: () => void }) {
  const [gate, setGate] = useState<Gate | null>(null);
  const [selected, setSelected] = useState("");
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  useEffect(() => {
    let active = true;
    const timer = setTimeout(() => api.get<Gate>(`/production/orders/${moId}/sample-gate?q=${encodeURIComponent(q)}&page=${page}`)
      .then((r) => { if (active) { setGate(r); setSelected(String(r.selected?.id ?? "")); setError(null); } })
      .catch((e: Error) => { if (active) { setGate(null); setError(e.message); } }), 200);
    return () => { active = false; clearTimeout(timer); };
  }, [moId, moStatus, q, page, revision]);
  async function save() {
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.post(`/production/orders/${moId}/sample`, { sample_id: selected ? Number(selected) : null });
      setMessage("Pilihan sample tersimpan. Release tetap memvalidasi respons dan revisi terbaru.");
      setRevision((r) => r + 1); onSaved();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan pilihan sample"); }
    finally { setBusy(false); }
  }
  const options = [...(gate?.candidates.data ?? [])];
  if (gate?.selected && !options.some((r) => r.id === gate.selected?.id)) options.unshift(gate.selected);
  return <section className="space-y-3 rounded-xl border bg-white p-4">
    <h2 className="font-semibold">Sample approval · production gate</h2>
    <p className="text-xs text-slate-500">Pilih sample APPROVED secara eksplisit. Tidak ada pemilihan stage otomatis; BOM, routing, costing, dan reservasi tetap memakai gate existing.</p>
    {error && <p role="alert" className="text-sm text-red-700">{error} <Button size="sm" onClick={() => setRevision((r) => r + 1)}>Muat ulang</Button></p>}
    {message && <p role="status" className="text-sm text-green-800">{message}</p>}
    {gate ? <>
      <div className={`rounded p-3 text-sm ${gate.ready ? "bg-green-50 text-green-800" : "bg-amber-50 text-amber-900"}`}>
        <strong>{gate.ready ? "Sample memenuhi gate saat ini" : "Gate sample BLOCKED"}</strong>
        {gate.reason && <p>{gate.reason}</p>}
        {gate.selected && <p><Link className="underline" href={`/pd/samples?sample=${gate.selected.id}`}>{gate.selected.doc_no}</Link> · {gate.selected.stage} v{gate.selected.version} · <StatusBadge status={gate.selected.buyer_status} /></p>}
      </div>
      {gate.can_select && <div className="space-y-3 border-t pt-3">
        <Input aria-label="Cari sample approved" placeholder="Cari nomor sample APPROVED…" value={q} disabled={busy} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        <Field label="Sample untuk MO" htmlFor={`sample-gate-${moId}`}><Select id={`sample-gate-${moId}`} value={selected} disabled={busy} onChange={(e) => setSelected(e.target.value)}>
          <option value="">Tidak dipilih — release akan diblokir</option>
          {options.map((s) => <option key={s.id} value={s.id}>{s.doc_no} · {s.stage} v{s.version} · {s.buyer_status}</option>)}
        </Select></Field>
        <Pagination page={gate.candidates.current_page} totalPages={gate.candidates.last_page} totalRecords={gate.candidates.total} onPageChange={setPage} />
        <Button loading={busy} onClick={save}>Simpan pilihan sample</Button>
      </div>}
      {gate.release_snapshot && <div className="rounded border p-3 text-xs text-slate-600">
        <p className="font-semibold">Evidence pada release terakhir</p>
        <p>{gate.release_snapshot.doc_no} · {gate.release_snapshot.stage} v{gate.release_snapshot.version} · approval #{gate.release_snapshot.approval_id}</p>
        <p>{gate.release_snapshot.by_name ?? "Buyer legacy"} · {gate.release_snapshot.response_reference ?? "Referensi legacy tidak tersedia"}</p>
        <p>{gate.checked_at ? new Date(gate.checked_at).toLocaleString("id-ID") : "—"}</p>
        <p className="mt-1">Respons/revisi baru tidak mengubah stok atau lifecycle MO yang sudah berjalan secara otomatis.</p>
      </div>}
    </> : !error && <p className="text-sm text-slate-500">Memuat gate sample…</p>}
  </section>;
}
