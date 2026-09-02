"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Amount = number | null;
interface Mo { id: number; doc_no: string; style?: { style_no: string } }
interface SourceRow { ledger_id?: number; issue_doc_no?: string; return_doc_no?: string; material_code?: string; warehouse_code?: string; lot_no?: string; roll_no?: string; qty_out?: string; qty_in?: string; uom_code?: string; unit_cost?: string; total_cost?: string }
interface Costing {
  mo: string;
  period: string;
  output_pcs: Amount;
  calculation: { mode: string; persisted: boolean; status: string; actual_costs_table: string; lifecycle: string };
  currency: { code?: string; authority: string; source_transaction_fx: string };
  period_control: { gl_status: string; behavior: string; recalculation: string };
  actual: { material: Amount; labor: Amount; machine: Amount; overhead: Amount; subcon: Amount; other: Amount; defined_total: Amount; total: Amount; per_pcs: null };
  components: {
    material: { gross_issue_cost: Amount; leftover_return_cost: Amount; status: string; authority: string; wastage_authority: string; issues: SourceRow[]; returns: SourceRow[] };
    production: { status: string; output: { authority: string; authoritative: boolean; scans: Array<{ id: number; bundle_no: string; stage: string; qty: string; operation_code: string }> }; labor: { line_rate: null | { period: string; cost_per_minute: number } }; overhead: { rate: null | { period: string; rate_per_minute: number } }; machine: { authority: string } };
    subcon: { amount: number; status: string; fees: Array<{ fee_id: number; job_work_doc_no: string; supplier_name: string; receipt_reference?: string; qty_returned: string; total_fee: string }> };
  };
  variance_vs_standard: { material: Amount; labor: Amount; overhead: Amount; subcon: Amount; other: Amount; total: Amount; standard_total: Amount; cost_sheet: string; status: string; authority: string };
  authorities: Record<string, string>;
}

const field = "rounded border px-2 py-1.5 text-sm";

export default function CostingPage() {
  const [mos, setMos] = useState<Mo[]>([]);
  const [moId, setMoId] = useState("");
  const [period, setPeriod] = useState("");
  const [result, setResult] = useState<Costing | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((response) => setMos(response.data)).catch(() => {});
  }, []);

  async function load(id = moId, selectedPeriod = period) {
    setMoId(id); setResult(null); setError(null);
    if (!id) return;
    setLoading(true);
    try {
      const query = selectedPeriod ? `?period=${selectedPeriod}` : "";
      setResult(await api.get<Costing>(`/finance/costing/mo/${id}/lineage${query}`));
    } catch (exception: any) {
      setError(exception.message);
    } finally {
      setLoading(false);
    }
  }

  const fmt = (value: Amount) => value === null ? "⚪ NOT DEFINED" : new Intl.NumberFormat("id-ID", { minimumFractionDigits: 4 }).format(value);
  const variance = (value: Amount) => value === null ? <span className="text-amber-700">⚪ NOT DEFINED</span> : value > 0 ? <span className="text-red-600">+{fmt(value)}</span> : <span className="text-green-700">{fmt(value)}</span>;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-bold">Actual Cost Workbench</h1>
        <p className="text-sm text-slate-500">Computed read-only trace dari transaksi operasional; tidak membuat parallel costing ledger.</p>
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
        <label className="text-sm"><span className="mb-1 block font-medium">Production Order</span>
          <select value={moId} onChange={(event) => setMoId(event.target.value)} className={field}>
            <option value="">— pilih MO —</option>
            {mos.map((mo) => <option key={mo.id} value={mo.id}>{mo.doc_no} ({mo.style?.style_no})</option>)}
          </select>
        </label>
        <label className="text-sm"><span className="mb-1 block font-medium">Rate period</span>
          <input type="month" value={period} onChange={(event) => setPeriod(event.target.value)} className={field} />
        </label>
        <button type="button" disabled={!moId || loading} onClick={() => load()} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">{loading ? "Membaca sumber…" : "Load cost lineage"}</button>
      </div>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      {result && <>
        <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          Cost per unit, WIP valuation, FG valuation, machine cost, other actual cost, dan costing lifecycle: <b>⚪ NOT DEFINED</b>. Nilai tidak dibuat dari denominator atau allocation assumption.
        </div>

        <section className="rounded-xl border bg-white p-4">
          <div className="flex flex-wrap justify-between gap-2">
            <h2 className="font-semibold">{result.mo} · {result.period} · {result.calculation.status}</h2>
            <span className="text-xs text-slate-500">{result.currency.code} · GL {result.period_control.gl_status} · {result.calculation.mode}</span>
          </div>
          <table className="mt-3 w-full text-sm"><thead className="border-b text-left text-xs text-slate-500"><tr><th>Component</th><th className="text-right">Defined actual</th><th className="text-right">Variance</th></tr></thead><tbody>
            <tr className="border-b"><td className="py-2">Material issue less valued return</td><td className="text-right">{fmt(result.actual.material)}</td><td className="text-right">{variance(result.variance_vs_standard.material)}</td></tr>
            <tr className="border-b"><td className="py-2">Labor / CM</td><td className="text-right">{fmt(result.actual.labor)}</td><td className="text-right">{variance(result.variance_vs_standard.labor)}</td></tr>
            <tr className="border-b"><td className="py-2">Machine</td><td className="text-right">{fmt(result.actual.machine)}</td><td className="text-right">—</td></tr>
            <tr className="border-b"><td className="py-2">Overhead</td><td className="text-right">{fmt(result.actual.overhead)}</td><td className="text-right">{variance(result.variance_vs_standard.overhead)}</td></tr>
            <tr className="border-b"><td className="py-2">Subcontract</td><td className="text-right">{fmt(result.actual.subcon)}</td><td className="text-right">{variance(result.variance_vs_standard.subcon)}</td></tr>
            <tr className="border-b"><td className="py-2">Other</td><td className="text-right">{fmt(result.actual.other)}</td><td className="text-right">{variance(result.variance_vs_standard.other)}</td></tr>
            <tr className="font-bold"><td className="py-2">Defined component total</td><td className="text-right">{fmt(result.actual.defined_total)}</td><td className="text-right">{variance(result.variance_vs_standard.total)}</td></tr>
            <tr><td className="py-2 text-slate-500">Cost per unit</td><td className="text-right text-amber-700">⚪ NOT DEFINED</td><td /></tr>
          </tbody></table>
          <p className="mt-2 text-xs text-slate-500">Output source: {result.components.production.output.authority} · Variance: {result.variance_vs_standard.status}</p>
        </section>

        <section className="rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Material source transactions</h2>
          <p className="text-xs text-slate-500">{result.components.material.authority} · Gross {fmt(result.components.material.gross_issue_cost)} · Return {fmt(result.components.material.leftover_return_cost)}</p>
          <div className="mt-2 overflow-x-auto"><table className="w-full text-xs"><thead><tr className="border-b text-left"><th>Source</th><th>Material</th><th>Warehouse / lot / roll</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead><tbody>
            {result.components.material.issues.map((row) => <tr key={`i-${row.ledger_id}`} className="border-b"><td>{row.issue_doc_no}</td><td>{row.material_code}</td><td>{row.warehouse_code} / {row.lot_no ?? "—"} / {row.roll_no ?? "—"}</td><td>{row.qty_out} {row.uom_code}</td><td>{row.unit_cost ?? "⚪"}</td><td>{row.total_cost ?? "⚪"}</td></tr>)}
            {result.components.material.returns.map((row) => <tr key={`r-${row.ledger_id}`} className="border-b bg-green-50"><td>{row.return_doc_no}</td><td>{row.material_code}</td><td>{row.warehouse_code} / {row.lot_no ?? "—"} / {row.roll_no ?? "—"}</td><td>-{row.qty_in} {row.uom_code}</td><td>{row.unit_cost ?? "⚪"}</td><td>-{row.total_cost ?? "⚪"}</td></tr>)}
          </tbody></table></div>
        </section>

        <section className="rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Production and subcontract sources</h2>
          <p className="text-xs text-slate-500">Output {fmt(result.output_pcs)} · {result.components.production.output.authority} · scans {result.components.production.output.scans.length}</p>
          <div className="mt-2 grid gap-2 md:grid-cols-2">
            <div className="rounded bg-slate-50 p-3 text-sm">Labor rate: {result.components.production.labor.line_rate?.cost_per_minute ?? "⚪ NOT DEFINED"}<br />OH rate: {result.components.production.overhead.rate?.rate_per_minute ?? "⚪ NOT DEFINED"}<br />Machine: ⚪ {result.components.production.machine.authority}</div>
            <div className="rounded bg-slate-50 p-3 text-sm">Subcon fees: {result.components.subcon.fees.length}<br />Amount: {fmt(result.components.subcon.amount)}<br />BR-091 source: Job Work Order → receipt fee</div>
          </div>
          {result.components.subcon.fees.map((fee) => <p key={fee.fee_id} className="mt-1 text-xs">{fee.job_work_doc_no} · {fee.supplier_name} · receipt {fee.receipt_reference ?? "legacy"} · {fee.qty_returned} → {fee.total_fee}</p>)}
        </section>

        <section className="rounded-xl border bg-white p-4 text-xs">
          <h2 className="mb-2 text-sm font-semibold">Authority matrix</h2>
          <div className="grid gap-1 md:grid-cols-2">{Object.entries(result.authorities).map(([key, value]) => <p key={key}><b>{key.replaceAll("_", " ")}:</b> {value}</p>)}</div>
        </section>
      </>}
    </div>
  );
}
