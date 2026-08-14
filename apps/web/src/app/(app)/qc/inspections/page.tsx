"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Mo { id: number; doc_no: string; status: string; style?: { style_no: string } }
interface Defect { id: number; code: string; name: string; severity: string }
interface Inspection {
  id: number;
  doc_no: string;
  stage: string;
  lot_qty: string;
  sample_size: number | null;
  accept_major: number | null;
  reject_major: number | null;
  defects_major: number;
  defects_minor: number;
  defects_critical: number;
  verdict: string;
  cycle: number;
}

interface DefectRow { defect_id: string; qty: string }

/** Inspeksi QC — FINAL memakai sampling AQL otomatis (BR-008/071); FAIL → REWORK loop (BR-073) */
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
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((r) => setMos(r.data)).catch(() => {});
    api.get<{ data: Defect[] }>("/master/defect-library?per_page=200").then((r) => setDefects(r.data)).catch(() => {});
  }, []);

  async function createInspection(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const created = await api.post<Inspection>(`/qc/mo/${moId}/inspections`, { stage, lot_qty: Number(lotQty) });
      setInspection(created);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function saveDefects() {
    if (!inspection) return;
    setBusy(true); setError(null);
    try {
      const rows = defectRows.filter((r) => r.defect_id && Number(r.qty) > 0);
      if (rows.length > 0) {
        await api.post(`/qc/inspections/${inspection.id}/defects`, {
          defects: rows.map((r) => ({ defect_id: Number(r.defect_id), qty: Number(r.qty) })),
        });
      }
      // refresh — ambil ulang via finalize nanti; untuk sekarang reload daftar inspeksi MO
      setDefectRows([{ defect_id: "", qty: "1" }]);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function finalize() {
    if (!inspection) return;
    setBusy(true); setError(null);
    try {
      const result = await api.post<Inspection>(`/qc/inspections/${inspection.id}/finalize`, {
        verdict: inspection.stage === "FINAL" ? undefined : manualVerdict,
      });
      setInspection(result);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  const verdictColor = inspection?.verdict === "PASS" ? "bg-green-100 text-green-700" : inspection?.verdict === "PENDING" ? "bg-slate-100 text-slate-600" : "bg-red-100 text-red-700";

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-xl font-bold">Inspeksi QC</h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      {!inspection ? (
        <form onSubmit={createInspection} className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4 md:grid-cols-4">
          <label className="text-sm">
            <span className="mb-1 block font-medium">MO *</span>
            <select value={moId} onChange={(e) => setMoId(e.target.value)} required className={input}>
              <option value="">— pilih MO —</option>
              {mos.map((m) => <option key={m.id} value={m.id}>{m.doc_no} ({m.style?.style_no})</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Stage *</span>
            <select value={stage} onChange={(e) => setStage(e.target.value as any)} className={input}>
              <option value="INLINE">Inline</option>
              <option value="ENDLINE">Endline</option>
              <option value="FINAL">Final (AQL)</option>
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Lot qty *</span>
            <input type="number" step="any" min="1" value={lotQty} onChange={(e) => setLotQty(e.target.value)} required className={input} />
          </label>
          <div className="flex items-end">
            <button disabled={busy} className="w-full rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
              {busy ? "…" : "Buat Inspeksi"}
            </button>
          </div>
        </form>
      ) : (
        <div className="space-y-4">
          <section className="rounded-xl border bg-white p-4">
            <div className="flex items-center justify-between">
              <div>
                <span className="font-mono font-bold">{inspection.doc_no}</span>
                <span className="ml-2 text-sm text-slate-500">{inspection.stage} · cycle {inspection.cycle} · lot {Number(inspection.lot_qty)}</span>
              </div>
              <span className={`rounded-full px-3 py-1 text-sm font-bold ${verdictColor}`}>{inspection.verdict}</span>
            </div>
            {inspection.stage === "FINAL" && inspection.sample_size && (
              <p className="mt-2 rounded bg-slate-50 p-2 text-sm">
                AQL (G-II): sample <b>{inspection.sample_size}</b> pcs · Major Ac <b>{inspection.accept_major}</b> / Re <b>{inspection.reject_major}</b>
                · tercatat: <b>{inspection.defects_critical}</b> critical, <b>{inspection.defects_major}</b> major, <b>{inspection.defects_minor}</b> minor
              </p>
            )}
          </section>

          {inspection.verdict === "PENDING" && (
            <>
              <section className="rounded-xl border bg-white p-4">
                <div className="mb-2 flex items-center justify-between">
                  <h2 className="font-semibold">Defect (dari library — BR-072)</h2>
                  <button type="button" onClick={() => setDefectRows([...defectRows, { defect_id: "", qty: "1" }])} className="rounded border px-2 py-1 text-xs">+ Baris</button>
                </div>
                <div className="space-y-2">
                  {defectRows.map((r, i) => (
                    <div key={i} className="flex gap-2">
                      <select value={r.defect_id} onChange={(e) => { const n = [...defectRows]; n[i].defect_id = e.target.value; setDefectRows(n); }} className={input}>
                        <option value="">— pilih defect —</option>
                        {defects.map((d) => <option key={d.id} value={d.id}>[{d.severity}] {d.code} — {d.name}</option>)}
                      </select>
                      <input type="number" min="1" value={r.qty} onChange={(e) => { const n = [...defectRows]; n[i].qty = e.target.value; setDefectRows(n); }} className="w-24 rounded border px-2 py-1.5 text-sm" />
                    </div>
                  ))}
                </div>
                <button onClick={saveDefects} disabled={busy} className="mt-3 rounded border px-4 py-1.5 text-sm disabled:opacity-50">
                  Simpan defect
                </button>
              </section>

              <section className="flex items-center gap-3 rounded-xl border bg-white p-4">
                {inspection.stage !== "FINAL" && (
                  <select value={manualVerdict} onChange={(e) => setManualVerdict(e.target.value as any)} className="rounded border px-2 py-1.5 text-sm">
                    <option value="PASS">PASS</option>
                    <option value="FAIL">FAIL</option>
                  </select>
                )}
                <button onClick={finalize} disabled={busy} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
                  {busy ? "…" : inspection.stage === "FINAL" ? "Finalisasi (verdict AQL otomatis)" : "Finalisasi"}
                </button>
                {inspection.stage === "FINAL" && <span className="text-xs text-slate-500">Verdict dihitung dari defect vs Ac/Re</span>}
              </section>
            </>
          )}

          {inspection.verdict !== "PENDING" && (
            <div className="rounded-xl border bg-white p-4 text-sm">
              {inspection.verdict === "PASS"
                ? <p className="text-green-700">✔ Inspeksi PASS — MO bisa lanjut ke packing (BR-082).</p>
                : <p className="text-red-700">✘ {inspection.verdict} — lakukan rework, lalu buat inspeksi baru (cycle otomatis naik, BR-073).</p>}
              <button onClick={() => { setInspection(null); setLotQty(""); }} className="mt-3 rounded border px-3 py-1.5 text-sm">
                Inspeksi baru
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
