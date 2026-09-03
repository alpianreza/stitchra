"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Mo = { id: number; doc_no: string };
type EventRow = { id: number; valuation_stage?: string; boundary?: string; component: string; quantity_delta?: string; receipt_quantity?: string; provisional_value: string };
type Adjustment = { id: number; valuation_object: string; component: string; provisional_value: string; actual_value: string; variance_amount: string };
type Status = {
  policy: string;
  production_order: { id: number; doc_no: string; status: string; standard_snapshot_hash?: string };
  eligibility: null | { status: string; policy_version: string; effective_date: string; allocation_snapshot_hash: string };
  wip: { events: EventRow[]; totals: Array<{ valuation_stage: string; component: string; quantity: string; value: string }> };
  fg: { events: EventRow[] };
  freeze: null | { status: string; freeze_version: number; period: string; denominator_quantity: string };
  adjustments: Adjustment[];
  fail_closed_reason: string | null;
};

export default function ManufacturingValuationPage() {
  const [mos,setMos]=useState<Mo[]>([]); const [mo,setMo]=useState(""); const [data,setData]=useState<Status|null>(null); const [error,setError]=useState<string|null>(null);
  useEffect(()=>{api.get<{data:Mo[]}>("/production/orders?per_page=100").then(r=>setMos(r.data)).catch(()=>{});},[]);
  async function load(id:string){setMo(id);setData(null);setError(null);if(!id)return;try{setData(await api.get<Status>(`/finance/valuation/production-orders/${id}`));}catch(e:any){setError(e.message);}}
  const money=(v:string|number)=>new Intl.NumberFormat("id-ID",{minimumFractionDigits:4,maximumFractionDigits:4}).format(Number(v));
  return <div className="space-y-4">
    <div><h1 className="text-xl font-bold">WIP & FG Valuation</h1><p className="text-sm text-slate-500">D-06/D-07 prospective, append-only valuation lineage. No manual valuation editing.</p></div>
    <select className="rounded border px-3 py-2 text-sm" value={mo} onChange={e=>load(e.target.value)}><option value="">— select MO —</option>{mos.map(x=><option key={x.id} value={x.id}>{x.doc_no}</option>)}</select>
    {error&&<div className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</div>}
    {data&&<>
      {data.fail_closed_reason&&<div className="rounded border border-amber-300 bg-amber-50 p-3 text-sm"><b>FAIL CLOSED:</b> {data.fail_closed_reason}</div>}
      <div className="grid gap-3 md:grid-cols-3">
        <div className="rounded border bg-white p-3"><b>Policy</b><p>{data.policy}</p><p className="text-xs text-slate-500">MO {data.production_order.status}</p></div>
        <div className="rounded border bg-white p-3"><b>Eligibility</b><p>{data.eligibility?.status??"NOT APPROVED"}</p><p className="text-xs text-slate-500">{data.eligibility?.effective_date}</p></div>
        <div className="rounded border bg-white p-3"><b>Actual cost</b><p>{data.freeze?.status??"PARTIAL / NOT FINAL"}</p><p className="text-xs text-slate-500">{data.freeze?`v${data.freeze.freeze_version} · ${data.freeze.period}`:"Explicit approved freeze required"}</p></div>
      </div>
      <section className="rounded border bg-white p-4"><h2 className="font-semibold">Provisional WIP</h2><table className="mt-2 w-full text-sm"><thead><tr className="text-left"><th>Stage</th><th>Component</th><th className="text-right">Qty</th><th className="text-right">Value</th></tr></thead><tbody>{data.wip.totals.map((r,i)=><tr className="border-t" key={i}><td>{r.valuation_stage}</td><td>{r.component}</td><td className="text-right">{r.quantity}</td><td className="text-right">{money(r.value)}</td></tr>)}</tbody></table></section>
      <section className="rounded border bg-white p-4"><h2 className="font-semibold">Provisional FG receipts</h2><table className="mt-2 w-full text-sm"><thead><tr className="text-left"><th>Receipt event</th><th>Component</th><th className="text-right">Qty</th><th className="text-right">Value</th></tr></thead><tbody>{data.fg.events.map(r=><tr className="border-t" key={r.id}><td>#{r.id}</td><td>{r.component}</td><td className="text-right">{r.receipt_quantity}</td><td className="text-right">{money(r.provisional_value)}</td></tr>)}</tbody></table></section>
      <section className="rounded border bg-white p-4"><h2 className="font-semibold">Append-only variance</h2><table className="mt-2 w-full text-sm"><thead><tr className="text-left"><th>Object</th><th>Component</th><th className="text-right">Provisional</th><th className="text-right">Actual</th><th className="text-right">Variance</th></tr></thead><tbody>{data.adjustments.map(r=><tr className="border-t" key={r.id}><td>{r.valuation_object}</td><td>{r.component}</td><td className="text-right">{money(r.provisional_value)}</td><td className="text-right">{money(r.actual_value)}</td><td className="text-right">{money(r.variance_amount)}</td></tr>)}</tbody></table></section>
    </>}
  </div>;
}
