"use client";

import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

interface Opt { id: number; code: string; name: string }

interface ScanResult {
  id: number;
  direction: string;
  scanned_at: string;
}

/**
 * Stasiun scan shop floor (BR-062).
 * Input utama = bundle_no (keyboard-wedge scanner menembakkan Enter).
 * Operasi & arah dipilih sekali per sesi; scan berulang super cepat.
 */
export default function ScanStationPage() {
  const [operations, setOperations] = useState<Opt[]>([]);
  const [lines, setLines] = useState<Opt[]>([]);
  const [operationId, setOperationId] = useState("");
  const [lineId, setLineId] = useState("");
  const [direction, setDirection] = useState<"IN" | "OUT">("IN");
  const [stage, setStage] = useState<"SEWING" | "FINISHING">("SEWING");
  const [bundleNo, setBundleNo] = useState("");
  const [feedback, setFeedback] = useState<{ ok: boolean; message: string } | null>(null);
  const [recent, setRecent] = useState<string[]>([]);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    api.get<{ data: Opt[] }>("/master/operations?per_page=100").then((r) => setOperations(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/lines?per_page=100").then((r) => setLines(r.data)).catch(() => {});
    inputRef.current?.focus();
  }, []);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!bundleNo.trim() || !operationId) return;

    try {
      const res = await api.post<ScanResult>("/shopfloor/scans", {
        bundle_no: bundleNo.trim(),
        operation_id: Number(operationId),
        direction,
        stage,
        line_id: lineId ? Number(lineId) : undefined,
      });
      setFeedback({ ok: true, message: `✔ ${bundleNo} — ${direction} tercatat` });
      setRecent((r) => [`${bundleNo} ${direction}`, ...r].slice(0, 10));
    } catch (err: any) {
      setFeedback({ ok: false, message: `✘ ${err.message}` });
    }

    setBundleNo("");
    inputRef.current?.focus();
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h1 className="text-xl font-bold">Stasiun Scan — {stage}</h1>

      <div className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4">
        <label className="block text-sm">
          <span className="mb-1 block font-medium">Operasi *</span>
          <select value={operationId} onChange={(e) => setOperationId(e.target.value)} className="w-full rounded border px-2 py-2">
            <option value="">— pilih operasi —</option>
            {operations.map((o) => <option key={o.id} value={o.id}>{o.code} — {o.name}</option>)}
          </select>
        </label>
        <label className="block text-sm">
          <span className="mb-1 block font-medium">Line</span>
          <select value={lineId} onChange={(e) => setLineId(e.target.value)} className="w-full rounded border px-2 py-2">
            <option value="">— default MO —</option>
            {lines.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        </label>
        <div className="flex gap-2">
          {(["IN", "OUT"] as const).map((d) => (
            <button
              key={d}
              onClick={() => setDirection(d)}
              className={`flex-1 rounded py-2 font-bold ${direction === d ? (d === "IN" ? "bg-blue-600 text-white" : "bg-green-600 text-white") : "bg-slate-100"}`}
            >
              {d}
            </button>
          ))}
        </div>
        <div className="flex gap-2">
          {(["SEWING", "FINISHING"] as const).map((s) => (
            <button
              key={s}
              onClick={() => setStage(s)}
              className={`flex-1 rounded py-2 text-sm font-medium ${stage === s ? "bg-slate-900 text-white" : "bg-slate-100"}`}
            >
              {s}
            </button>
          ))}
        </div>
      </div>

      <form onSubmit={submit} className="rounded-xl border bg-white p-4">
        <label className="block">
          <span className="mb-1 block text-sm font-medium">Scan / ketik Bundle No</span>
          <input
            ref={inputRef}
            value={bundleNo}
            onChange={(e) => setBundleNo(e.target.value)}
            className="w-full rounded border-2 border-slate-300 px-4 py-3 font-mono text-xl focus:border-slate-900 focus:outline-none"
            placeholder="CT-2026-000001-L1-B001"
            autoComplete="off"
          />
        </label>
      </form>

      {feedback && (
        <div className={`rounded-xl p-4 text-lg font-medium ${feedback.ok ? "bg-green-50 text-green-700" : "bg-red-50 text-red-700"}`}>
          {feedback.message}
        </div>
      )}

      {recent.length > 0 && (
        <div className="rounded-xl border bg-white p-4">
          <h2 className="mb-2 text-sm font-semibold text-slate-500">10 scan terakhir</h2>
          <ul className="space-y-1 font-mono text-sm">
            {recent.map((r, i) => <li key={i}>{r}</li>)}
          </ul>
        </div>
      )}
    </div>
  );
}
