"use client";

import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

interface Opt { id: number; code: string; name: string }
interface EligibleBundle {
  bundle_no: string;
  qty: number;
  production_order_id: number;
  cut_output_id: number | null;
  lineage_complete: boolean;
}
interface Lineage {
  bundle: { cut_output_id: number | null; current_stage: string };
  sewing_inputs: unknown[];
  sewing_outputs: unknown[];
  wip_transfers: unknown[];
}

export default function ScanStationPage() {
  const [operations, setOperations] = useState<Opt[]>([]);
  const [lines, setLines] = useState<Opt[]>([]);
  const [eligible, setEligible] = useState<EligibleBundle[]>([]);
  const [operationId, setOperationId] = useState("");
  const [lineId, setLineId] = useState("");
  const [direction, setDirection] = useState<"IN" | "OUT">("IN");
  const [stage, setStage] = useState<"SEWING" | "FINISHING">("SEWING");
  const [bundleNo, setBundleNo] = useState("");
  const [feedback, setFeedback] = useState<{ ok: boolean; message: string } | null>(null);
  const [recent, setRecent] = useState<string[]>([]);
  const [lineage, setLineage] = useState<Lineage | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const refreshEligible = () => api.get<{ data: EligibleBundle[] }>("/shopfloor/bundles/eligible")
    .then((response) => setEligible(response.data))
    .catch(() => {});

  useEffect(() => {
    api.get<{ data: Opt[] }>("/master/operations?per_page=100").then((r) => setOperations(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/lines?per_page=100").then((r) => setLines(r.data)).catch(() => {});
    refreshEligible();
    inputRef.current?.focus();
  }, []);

  async function inspect(no: string) {
    setBundleNo(no);
    try {
      const response = await api.get<{ data: Lineage }>(`/shopfloor/bundles/${encodeURIComponent(no)}/lineage`);
      setLineage(response.data);
    } catch {
      setLineage(null);
    }
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!bundleNo.trim() || !operationId) return;
    const scannedBundle = bundleNo.trim();

    try {
      await api.post("/shopfloor/scans", {
        bundle_no: scannedBundle,
        operation_id: Number(operationId),
        direction,
        stage,
        line_id: lineId ? Number(lineId) : undefined,
      });
      setFeedback({ ok: true, message: `✔ ${scannedBundle} — ${direction} tercatat` });
      setRecent((items) => [`${scannedBundle} ${direction}`, ...items].slice(0, 10));
      await inspect(scannedBundle);
      refreshEligible();
    } catch (error: any) {
      setFeedback({ ok: false, message: `✘ ${error.message}` });
    }

    inputRef.current?.focus();
  }

  const selected = eligible.find((bundle) => bundle.bundle_no === bundleNo);

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <h1 className="text-xl font-bold">Sewing / Shop Floor / WIP</h1>

      <div className="grid gap-4 lg:grid-cols-2">
        <section className="rounded-xl border bg-white p-4">
          <h2 className="mb-3 font-semibold">Bundle eligible untuk Sewing</h2>
          <div className="max-h-64 overflow-auto">
            <table className="w-full text-sm">
              <thead><tr className="text-left"><th>Bundle</th><th>Qty</th><th>Lineage</th></tr></thead>
              <tbody>
                {eligible.map((bundle) => (
                  <tr key={bundle.bundle_no} className="border-t">
                    <td><button type="button" className="py-2 font-mono text-blue-700" onClick={() => inspect(bundle.bundle_no)}>{bundle.bundle_no}</button></td>
                    <td>{bundle.qty}</td>
                    <td>{bundle.lineage_complete ? "Cut Output" : "Legacy"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        <section className="rounded-xl border bg-white p-4">
          <div className="grid grid-cols-2 gap-3">
            <label className="text-sm">
              <span className="block font-medium">Operasi *</span>
              <select value={operationId} onChange={(e) => setOperationId(e.target.value)} className="w-full rounded border p-2">
                <option value="">— pilih operasi —</option>
                {operations.map((operation) => <option key={operation.id} value={operation.id}>{operation.code} — {operation.name}</option>)}
              </select>
            </label>
            <label className="text-sm">
              <span className="block font-medium">Line</span>
              <select value={lineId} onChange={(e) => setLineId(e.target.value)} className="w-full rounded border p-2">
                <option value="">— default MO —</option>
                {lines.map((line) => <option key={line.id} value={line.id}>{line.name}</option>)}
              </select>
            </label>
            <div className="flex gap-2">
              {(["IN", "OUT"] as const).map((value) => <button type="button" key={value} onClick={() => setDirection(value)} className={`flex-1 rounded py-2 ${direction === value ? "bg-slate-900 text-white" : "bg-slate-100"}`}>{value}</button>)}
            </div>
            <div className="flex gap-2">
              {(["SEWING", "FINISHING"] as const).map((value) => <button type="button" key={value} onClick={() => setStage(value)} className={`flex-1 rounded py-2 text-sm ${stage === value ? "bg-slate-900 text-white" : "bg-slate-100"}`}>{value}</button>)}
            </div>
          </div>

          <form onSubmit={submit} className="mt-4">
            <input ref={inputRef} value={bundleNo} onChange={(e) => setBundleNo(e.target.value)} className="w-full rounded border-2 p-3 font-mono" placeholder="Scan / pilih Bundle No" autoComplete="off" />
            <div className="mt-2 text-sm">Available qty: <b>{selected?.qty ?? "—"}</b></div>
            <button className="mt-3 w-full rounded bg-blue-600 py-2 font-semibold text-white">Catat transaksi</button>
          </form>

          {feedback && <div className={`mt-3 rounded p-3 ${feedback.ok ? "bg-green-50 text-green-700" : "bg-red-50 text-red-700"}`}>{feedback.message}</div>}
        </section>
      </div>

      {lineage && (
        <section className="rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Lineage & WIP result</h2>
          <p className="mt-2 text-sm">Bundle → Sewing Input ({lineage.sewing_inputs.length}) → Sewing Output ({lineage.sewing_outputs.length}) → WIP Transfer ({lineage.wip_transfers.length})</p>
          <p className="mt-1 text-sm">Cut Output: {lineage.bundle.cut_output_id ?? "Legacy / NULL"} · Current stage: {lineage.bundle.current_stage}</p>
        </section>
      )}

      {recent.length > 0 && (
        <section className="rounded-xl border bg-white p-4">
          <h2 className="mb-2 text-sm font-semibold text-slate-500">10 scan terakhir</h2>
          <ul className="space-y-1 font-mono text-sm">{recent.map((item, index) => <li key={index}>{item}</li>)}</ul>
        </section>
      )}
    </div>
  );
}
