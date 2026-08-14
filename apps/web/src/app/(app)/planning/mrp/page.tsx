"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface So { id: number; doc_no: string; customer?: { name: string } }

interface Requirement {
  id: number;
  material_id: number;
  gross_qty: string;
  available_qty: string;
  on_order_qty: string;
  safety_stock_qty: string;
  net_qty: string;
  need_date: string | null;
  converted_to_pr: boolean;
  material?: { code: string; name: string };
}

interface Run {
  id: number;
  run_no: number;
  status: string;
  created_at: string;
  requirements?: Requirement[];
}

/** MRP planner: pilih SO CONFIRMED → run → shortage → konversi PR (BR-043/045/120) */
export default function MrpPage() {
  const [runs, setRuns] = useState<Run[]>([]);
  const [confirmedSos, setConfirmedSos] = useState<So[]>([]);
  const [selectedSos, setSelectedSos] = useState<number[]>([]);
  const [activeRun, setActiveRun] = useState<Run | null>(null);
  const [selectedReqs, setSelectedReqs] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    api.get<{ data: Run[] }>("/planning/mrp-runs").then((r) => setRuns(r.data)).catch((e) => setError(e.message));
    api.get<{ data: So[] }>("/sales/orders?status=CONFIRMED&per_page=100").then((r) => setConfirmedSos(r.data)).catch(() => {});
  }

  useEffect(load, []);

  async function runMrp() {
    if (selectedSos.length === 0) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const run = await api.post<Run>("/planning/mrp-runs", { so_ids: selectedSos });
      setMessage(`MRP run #${run.run_no} selesai.`);
      setSelectedSos([]);
      load();
      openRun(run.id);
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function openRun(id: number) {
    setError(null);
    try {
      const run = await api.get<Run>(`/planning/mrp-runs/${id}`);
      setActiveRun(run);
      setSelectedReqs([]);
    } catch (e: any) {
      setError(e.message);
    }
  }

  async function convertToPr() {
    if (!activeRun || selectedReqs.length === 0) return;
    setBusy(true); setError(null);
    try {
      const pr = await api.post<{ doc_no: string }>(`/planning/mrp-runs/${activeRun.id}/convert-to-pr`, { requirement_ids: selectedReqs });
      setMessage(`PR ${pr.doc_no} dibuat (source: MRP).`);
      openRun(activeRun.id);
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  const fmt = (v: string) => Number(v).toLocaleString("id-ID", { maximumFractionDigits: 2 });

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-bold">MRP — Perencanaan Kebutuhan Material</h1>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">Run baru — SO CONFIRMED</h2>
        {confirmedSos.length === 0 ? (
          <p className="text-sm text-slate-500">Tidak ada SO berstatus CONFIRMED.</p>
        ) : (
          <div className="flex flex-wrap items-center gap-3">
            {confirmedSos.map((so) => (
              <label key={so.id} className="flex items-center gap-2 rounded border px-3 py-2 text-sm">
                <input
                  type="checkbox"
                  checked={selectedSos.includes(so.id)}
                  onChange={(e) => setSelectedSos(e.target.checked ? [...selectedSos, so.id] : selectedSos.filter((x) => x !== so.id))}
                />
                <span className="font-mono">{so.doc_no}</span>
                <span className="text-slate-500">{so.customer?.name}</span>
              </label>
            ))}
            <button
              onClick={runMrp}
              disabled={selectedSos.length === 0 || busy}
              className="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
              {busy ? "Memproses…" : "Jalankan MRP"}
            </button>
          </div>
        )}
      </section>

      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">Riwayat run</h2>
        <div className="flex flex-wrap gap-2">
          {runs.map((r) => (
            <button
              key={r.id}
              onClick={() => openRun(r.id)}
              className={`rounded border px-3 py-1.5 text-sm ${activeRun?.id === r.id ? "border-slate-900 bg-slate-900 text-white" : "hover:bg-slate-50"}`}
            >
              Run #{r.run_no} — {new Date(r.created_at).toLocaleString("id-ID")}
            </button>
          ))}
          {runs.length === 0 && <p className="text-sm text-slate-500">Belum ada run.</p>}
        </div>
      </section>

      {activeRun && (
        <section className="rounded-xl border bg-white p-4">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">Hasil Run #{activeRun.run_no} — kebutuhan per material</h2>
            <button
              onClick={convertToPr}
              disabled={selectedReqs.length === 0 || busy}
              className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
              Konversi terpilih → PR
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b bg-slate-50 text-left">
                <tr>
                  <th className="px-3 py-2"></th>
                  <th className="px-3 py-2 font-medium">Material</th>
                  <th className="px-3 py-2 text-right font-medium">Gross</th>
                  <th className="px-3 py-2 text-right font-medium">Safety</th>
                  <th className="px-3 py-2 text-right font-medium">Available</th>
                  <th className="px-3 py-2 text-right font-medium">On-Order</th>
                  <th className="px-3 py-2 text-right font-medium">Net (shortage)</th>
                  <th className="px-3 py-2 font-medium">Need Date</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {(activeRun.requirements ?? []).map((req) => {
                  const isShort = Number(req.net_qty) > 0;
                  return (
                    <tr key={req.id} className={`border-b last:border-0 ${isShort && !req.converted_to_pr ? "bg-red-50" : ""}`}>
                      <td className="px-3 py-2">
                        {isShort && !req.converted_to_pr && (
                          <input
                            type="checkbox"
                            checked={selectedReqs.includes(req.id)}
                            onChange={(e) => setSelectedReqs(e.target.checked ? [...selectedReqs, req.id] : selectedReqs.filter((x) => x !== req.id))}
                          />
                        )}
                      </td>
                      <td className="px-3 py-2"><span className="font-mono">{req.material?.code}</span> {req.material?.name}</td>
                      <td className="px-3 py-2 text-right">{fmt(req.gross_qty)}</td>
                      <td className="px-3 py-2 text-right">{fmt(req.safety_stock_qty)}</td>
                      <td className="px-3 py-2 text-right">{fmt(req.available_qty)}</td>
                      <td className="px-3 py-2 text-right">{fmt(req.on_order_qty)}</td>
                      <td className="px-3 py-2 text-right font-bold">{fmt(req.net_qty)}</td>
                      <td className="px-3 py-2">{req.need_date ?? "—"}</td>
                      <td className="px-3 py-2">{req.converted_to_pr ? <span className="text-green-700">Sudah jadi PR</span> : isShort ? <span className="text-red-600">Shortage</span> : <span className="text-slate-500">Cukup</span>}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}
