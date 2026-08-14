"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Style { id: number; style_no: string }
interface Operation { id: number; code: string; name: string }

interface OpLine { operation_id: string; smv: string }
interface RoutingVersion { id: number; version_no: number; status: string; total_sam: string }

/** Editor Routing versioned (BR-033) — urutan operasi + SMV → total SAM */
export default function RoutingEditorPage() {
  const [styles, setStyles] = useState<Style[]>([]);
  const [operations, setOperations] = useState<Operation[]>([]);

  const [styleId, setStyleId] = useState("");
  const [ops, setOps] = useState<OpLine[]>([{ operation_id: "", smv: "" }]);
  const [created, setCreated] = useState<RoutingVersion | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((r) => setStyles(r.data)).catch(() => {});
    api.get<{ data: Operation[] }>("/master/operations?per_page=200").then((r) => setOperations(r.data)).catch(() => {});
  }, []);

  const totalSam = ops.reduce((s, o) => s + (Number(o.smv) || 0), 0);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const version = await api.post<RoutingVersion>("/pd/routings", {
        style_id: Number(styleId),
        operations: ops.map((o, i) => ({
          operation_id: Number(o.operation_id),
          smv: Number(o.smv),
          seq: i + 1,
        })),
      });
      setCreated(version);
      setMessage(`Routing v${version.version_no} dibuat (DRAFT), total SAM ${Number(version.total_sam)}.`);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function submitForApproval() {
    if (!created) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/pd/routings/${created.id}/submit`, {});
      setMessage(`Routing v${created.version_no} masuk approval flow. Approve di menu Approval.`);
      setCreated(null); setOps([{ operation_id: "", smv: "" }]); setStyleId("");
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Routing Editor <span className="text-sm font-normal text-slate-500">(versioned — BR-033)</span></h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <form onSubmit={save} className="space-y-4 rounded-xl border bg-white p-4">
        <label className="block max-w-sm text-sm">
          <span className="mb-1 block font-medium">Style *</span>
          <select value={styleId} onChange={(e) => setStyleId(e.target.value)} required className={input} disabled={!!created}>
            <option value="">— pilih style —</option>
            {styles.map((s) => <option key={s.id} value={s.id}>{s.style_no}</option>)}
          </select>
        </label>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <h2 className="font-semibold">Operasi (berurutan)</h2>
            <button type="button" onClick={() => setOps([...ops, { operation_id: "", smv: "" }])} className="rounded border px-3 py-1 text-sm" disabled={!!created}>+ Operasi</button>
          </div>

          <div className="space-y-2">
            {ops.map((o, i) => (
              <div key={i} className="flex items-end gap-2 rounded-lg border bg-slate-50 p-3">
                <span className="w-8 text-center font-mono text-sm font-bold text-slate-500">{i + 1}</span>
                <label className="flex-1 text-xs">
                  <span className="mb-1 block font-medium">Operasi</span>
                  <select value={o.operation_id} onChange={(e) => { const n = [...ops]; n[i].operation_id = e.target.value; setOps(n); }} required className={input} disabled={!!created}>
                    <option value="">— pilih operasi —</option>
                    {operations.map((op) => <option key={op.id} value={op.id}>{op.code} — {op.name}</option>)}
                  </select>
                </label>
                <label className="w-32 text-xs">
                  <span className="mb-1 block font-medium">SMV (menit)</span>
                  <input type="number" step="any" min="0" value={o.smv} onChange={(e) => { const n = [...ops]; n[i].smv = e.target.value; setOps(n); }} required className={`${input} text-right`} disabled={!!created} />
                </label>
                <button type="button" onClick={() => setOps(ops.filter((_, x) => x !== i))} disabled={ops.length === 1 || !!created} className="rounded border border-red-200 px-2 py-1.5 text-xs text-red-600 disabled:opacity-30">✕</button>
              </div>
            ))}
          </div>

          <p className="mt-3 text-right text-sm font-semibold">Total SAM: {totalSam.toLocaleString("id-ID", { maximumFractionDigits: 4 })} menit</p>
        </div>

        {!created ? (
          <button disabled={busy} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
            {busy ? "Menyimpan…" : "Simpan Versi Routing (DRAFT)"}
          </button>
        ) : (
          <div className="flex items-center gap-3">
            <span className="rounded bg-blue-100 px-3 py-1.5 text-sm font-medium text-blue-700">Routing v{created.version_no} — DRAFT</span>
            <button type="button" onClick={submitForApproval} disabled={busy} className="rounded bg-green-700 px-6 py-2 font-medium text-white disabled:opacity-50">
              Submit untuk Approval
            </button>
          </div>
        )}
      </form>
    </div>
  );
}
