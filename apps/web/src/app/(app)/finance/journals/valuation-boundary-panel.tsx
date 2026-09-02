"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface BoundaryRow {
  boundary: string;
  quantity_authority: string;
  cost_authority: string;
  accounting_event: string | null;
  mapping_configured: boolean;
  status: string;
  reason: string;
}
interface Matrix {
  company: { code: string; base_currency: string };
  rows: BoundaryRow[];
  actual_cost_dependency: string;
  cost_per_unit: string;
}

export default function ValuationBoundaryPanel() {
  const [matrix, setMatrix] = useState<Matrix | null>(null);
  const [moId, setMoId] = useState("");
  const [shipmentId, setShipmentId] = useState("");
  const [trace, setTrace] = useState<unknown>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<Matrix>("/finance/gl/valuation-authority").then(setMatrix).catch((e) => setError(e.message));
  }, []);

  async function load(path: string) {
    setError(null); setTrace(null);
    try { setTrace(await api.get(path)); }
    catch (e: any) { setError(e.message); }
  }

  const input = "rounded border px-2 py-1.5 text-sm";
  const tone = (status: string) => status === "DEFINED" ? "text-green-700" : status === "BLOCKED" ? "text-red-700" : "text-amber-700";

  return (
    <section className="rounded-xl border bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div><h2 className="font-semibold">WIP / FG / COGS valuation boundary</h2><p className="text-xs text-slate-500">ITS quantity remains valid. Undefined cost and accounting treatment is blocked, not calculated.</p></div>
        {matrix && <span className="text-xs text-slate-500">{matrix.company.code} · {matrix.company.base_currency}</span>}
      </div>
      {error && <p className="mt-2 rounded bg-red-50 p-2 text-xs text-red-700">{error}</p>}
      {matrix && <>
        <div className="mt-3 overflow-x-auto"><table className="w-full text-xs"><thead><tr className="border-b text-left"><th>Boundary</th><th>Quantity authority</th><th>Cost authority</th><th>Event / mapping</th><th>Status</th></tr></thead><tbody>
          {matrix.rows.map((row) => <tr key={row.boundary} className="border-b align-top"><td className="py-2 pr-2 font-medium">{row.boundary}</td><td className="pr-2">{row.quantity_authority}</td><td className="pr-2">{row.cost_authority}</td><td className="pr-2">{row.accounting_event ?? "NOT DEFINED"}<br />{row.mapping_configured ? "mapping configured" : "mapping missing / n.a."}</td><td className={tone(row.status)}>{row.status}<br /><span className="font-normal">{row.reason}</span></td></tr>)}
        </tbody></table></div>
        <p className="mt-2 text-xs text-amber-700">{matrix.actual_cost_dependency} {matrix.cost_per_unit}</p>
      </>}
      <div className="mt-3 flex flex-wrap items-end gap-2">
        <label className="text-sm"><span className="block">Production Order ID</span><input type="number" min="1" className={input} value={moId} onChange={(e) => setMoId(e.target.value)} /></label>
        <button type="button" disabled={!moId} className="rounded border px-3 py-1.5 text-sm disabled:opacity-50" onClick={() => load(`/finance/gl/valuation-boundaries/production-orders/${moId}`)}>Load WIP/FG source</button>
        <label className="text-sm"><span className="block">Shipment ID</span><input type="number" min="1" className={input} value={shipmentId} onChange={(e) => setShipmentId(e.target.value)} /></label>
        <button type="button" disabled={!shipmentId} className="rounded border px-3 py-1.5 text-sm disabled:opacity-50" onClick={() => load(`/finance/gl/valuation-boundaries/shipments/${shipmentId}`)}>Load shipment/COGS source</button>
      </div>
      {trace && <pre className="mt-3 max-h-96 overflow-auto rounded bg-slate-950 p-3 text-xs text-slate-100">{JSON.stringify(trace, null, 2)}</pre>}
    </section>
  );
}
