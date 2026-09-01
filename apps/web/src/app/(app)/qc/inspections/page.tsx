"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Field, Input, PageHeader, Select, StatusBadge } from "@/components/ui";

interface Mo { id: number; doc_no: string; status: string; style?: { style_no: string } }
interface Defect { id: number; code: string; name: string; severity: string }
interface Inspection { id: number; doc_no: string; stage: string; lot_qty: string; sample_size: number | null; accept_major: number | null; reject_major: number | null; defects_major: number; defects_minor: number; defects_critical: number; verdict: string; cycle: number }
interface DefectRow { defect_id: string; qty: string }

export default function QcInspectionsPage() {
  const [mos, setMos] = useState<Mo[]>([]);
  const [moId, setMoId] = useState("");
  const [stage, setStage] = useState<"INLINE" | "ENDLINE" | "FINAL">("FINAL");
  const [lotQty, setLotQty] = useState("");
  const [defects, setDefects] = useState<Defect[]>([]);
  const [defectRows, setDefectRows] = useState<DefectRow[]>([{ defect_id: "", qty: "1" }]);
  const [inspection, setInspection] = useState<Inspection | null>(null);
  const [manualVerdict, setManualVerdict] = useState<"PASS" | "FAIL">("PASS");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((response) => setMos(response.data)).catch(() => {});
    api.get<{ data: Defect[] }>("/master/defect-library?per_page=200").then((response) => setDefects(response.data)).catch(() => {});
  }, []);

  async function createInspection(event: React.FormEvent) {
    event.preventDefault(); setBusy(true); setError(null); setMessage(null);
    try { setInspection(await api.post<Inspection>(`/qc/mo/${moId}/inspections`, { stage, lot_qty: Number(lotQty) })); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  async function saveDefects() {
    if (!inspection) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const rows = defectRows.filter((row) => row.defect_id && Number(row.qty) > 0);
      if (rows.length > 0) await api.post(`/qc/inspections/${inspection.id}/defects`, { defects: rows.map((row) => ({ defect_id: Number(row.defect_id), qty: Number(row.qty) })) });
      setDefectRows([{ defect_id: "", qty: "1" }]);
      setMessage(rows.length > 0 ? `${rows.length} baris defect disimpan.` : "Tidak ada defect yang disimpan.");
    } catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  async function finalize() {
    if (!inspection) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const result = await api.post<Inspection>(`/qc/inspections/${inspection.id}/finalize`, { verdict: inspection.stage === "FINAL" ? undefined : manualVerdict });
      setInspection(result); setMessage(`Inspeksi ${result.doc_no} difinalisasi dengan verdict ${result.verdict}.`);
    } catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <PageHeader eyebrow="Quality Control" title="Inspeksi QC" description="Catat defect, jalankan sampling AQL untuk final inspection, dan finalisasi verdict produksi." />
      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-red-200 bg-[var(--color-danger-soft)] p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      {message && <div role="status" aria-live="polite" className="rounded-[var(--radius-surface)] border border-green-200 bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</div>}

      {!inspection ? (
        <form onSubmit={createInspection} className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="mb-4 font-semibold">Buat Inspeksi</h2>
          <div className="grid gap-4 md:grid-cols-4">
            <Field htmlFor="qc-mo" label="Manufacturing Order" required><Select id="qc-mo" value={moId} onChange={(event) => setMoId(event.target.value)} required><option value="">Pilih MO</option>{mos.map((mo) => <option key={mo.id} value={mo.id}>{mo.doc_no} ({mo.style?.style_no})</option>)}</Select></Field>
            <Field htmlFor="qc-stage" label="Stage" required><Select id="qc-stage" value={stage} onChange={(event) => setStage(event.target.value as typeof stage)}><option value="INLINE">Inline</option><option value="ENDLINE">Endline</option><option value="FINAL">Final (AQL)</option></Select></Field>
            <Field htmlFor="lot-qty" label="Lot Quantity" required><Input id="lot-qty" type="number" step="any" min="1" value={lotQty} onChange={(event) => setLotQty(event.target.value)} required /></Field>
            <div className="flex items-end"><Button className="w-full" type="submit" variant="primary" loading={busy}>Buat Inspeksi</Button></div>
          </div>
        </form>
      ) : (
        <div className="space-y-4">
          <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
              <div><p className="font-mono text-lg font-bold">{inspection.doc_no}</p><p className="text-sm text-[var(--color-text-muted)]">{inspection.stage} · cycle {inspection.cycle} · lot {Number(inspection.lot_qty).toLocaleString("id-ID")}</p></div>
              <StatusBadge status={inspection.verdict} />
            </div>
            {inspection.stage === "FINAL" && inspection.sample_size && <dl className="mt-4 grid grid-cols-2 gap-3 rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-3 text-sm sm:grid-cols-4"><div><dt className="text-xs text-[var(--color-text-muted)]">Sample</dt><dd className="font-bold">{inspection.sample_size} pcs</dd></div><div><dt className="text-xs text-[var(--color-text-muted)]">Major Ac / Re</dt><dd className="font-bold">{inspection.accept_major} / {inspection.reject_major}</dd></div><div><dt className="text-xs text-[var(--color-text-muted)]">Critical / Major</dt><dd className="font-bold">{inspection.defects_critical} / {inspection.defects_major}</dd></div><div><dt className="text-xs text-[var(--color-text-muted)]">Minor</dt><dd className="font-bold">{inspection.defects_minor}</dd></div></dl>}
          </section>

          {inspection.verdict === "PENDING" ? <>
            <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
              <div className="mb-4 flex items-center justify-between"><div><h2 className="font-semibold">Defect Inspection</h2><p className="text-xs text-[var(--color-text-muted)]">Pilih defect dari library dan masukkan quantity aktual.</p></div><Button size="sm" onClick={() => setDefectRows([...defectRows, { defect_id: "", qty: "1" }])}>Tambah Baris</Button></div>
              <div className="space-y-3">{defectRows.map((row, index) => <div key={index} className="grid gap-2 rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-3 sm:grid-cols-[1fr_120px_auto]"><Select aria-label={`Defect baris ${index + 1}`} value={row.defect_id} onChange={(event) => { const next = [...defectRows]; next[index].defect_id = event.target.value; setDefectRows(next); }}><option value="">Pilih defect</option>{defects.map((defect) => <option key={defect.id} value={defect.id}>[{defect.severity}] {defect.code} — {defect.name}</option>)}</Select><Input aria-label={`Quantity defect baris ${index + 1}`} type="number" min="1" value={row.qty} onChange={(event) => { const next = [...defectRows]; next[index].qty = event.target.value; setDefectRows(next); }} /><Button size="sm" variant="ghost" disabled={defectRows.length === 1} onClick={() => setDefectRows(defectRows.filter((_, itemIndex) => itemIndex !== index))}>Hapus</Button></div>)}</div>
              <Button className="mt-4" onClick={saveDefects} loading={busy}>Simpan Defect</Button>
            </section>
            <section className="flex flex-col gap-3 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)] sm:flex-row sm:items-end">
              {inspection.stage !== "FINAL" && <Field htmlFor="manual-verdict" label="Manual Verdict" className="sm:w-48"><Select id="manual-verdict" value={manualVerdict} onChange={(event) => setManualVerdict(event.target.value as typeof manualVerdict)}><option value="PASS">PASS</option><option value="FAIL">FAIL</option></Select></Field>}
              <Button variant="primary" onClick={finalize} loading={busy}>{inspection.stage === "FINAL" ? "Finalisasi · Hitung AQL" : "Finalisasi Inspeksi"}</Button>
              {inspection.stage === "FINAL" && <p className="text-xs text-[var(--color-text-muted)]">Verdict dihitung otomatis dari defect terhadap batas Ac/Re.</p>}
            </section>
          </> : <section className={`rounded-[var(--radius-surface)] border p-4 ${inspection.verdict === "PASS" ? "border-green-200 bg-[var(--color-success-soft)]" : "border-red-200 bg-[var(--color-danger-soft)]"}`}><p className="font-semibold">{inspection.verdict === "PASS" ? "Inspeksi PASS — MO dapat melanjutkan ke packing." : `${inspection.verdict} — lakukan rework lalu buat inspection cycle baru.`}</p><Button className="mt-3" onClick={() => { setInspection(null); setLotQty(""); setMessage(null); }}>Inspeksi Baru</Button></section>}
        </div>
      )}
    </div>
  );
}
