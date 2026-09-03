"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface MatrixRow { domain:string; legacy:string; current:string; conflict:string; status:string; resolution:string }
interface Authority { rows:MatrixRow[]; states:Record<string,string>; safe_guards:string[]; migration:string; writes_performed:boolean }
interface Integrity {
  production_order:{doc_no:string;status:string};
  authority_conflict:{marker_present:boolean;lay_roll_present:boolean;mixed_path:boolean;status:string;new_mixed_execution:string;historical_mutation:boolean;authority_resolution:string;reason:string};
  qty_produced:{stored_value:number;classification:string;writer:string;production_output_authority:string;consumers:{consumer:string;classification:string;state:string}[]};
  backflush:{status:string;uses_qty_produced:boolean;writes_inventory_through:string;bypasses_its:boolean;bypasses_reservation:boolean;actual_backflush_overlap:boolean;convergence:string};
  inventory:{authority:string;duplicate_detected:boolean;duplicate_source_movements:number[][];fabric_dispatch_note:string};
  operational_chain:{cutting_path:string;cut_outputs:number;bundles:number;production_scans:number;wip_transfers:number;qc_final_pass:boolean;packing_lists:number;shipments:number};
  accounting:{authority:string;gr_posting:string;accounting_posting_conflict:string;production_events:string};
  legacy_endpoints:{endpoint:string;current_use:string;authority:string;conflict:string;action:string}[];
  lineage:{forward:string;reverse:string;history_policy:string};
  resolved_conflicts:string[]; decision_required:string[]; writes_performed:boolean; migration:string;
}

const badge:Record<string,string>={DEFINED:"bg-emerald-100 text-emerald-800",CONVERGED:"bg-emerald-100 text-emerald-800",LEGACY:"bg-orange-100 text-orange-800",COMPATIBILITY:"bg-blue-100 text-blue-800",PARTIAL:"bg-amber-100 text-amber-800",CONFLICT:"bg-red-100 text-red-800",BLOCKED:"bg-red-100 text-red-800","NOT DEFINED":"bg-slate-200 text-slate-700"};
const Status=({value}:{value:string})=><span className={`rounded px-2 py-1 text-xs font-medium ${badge[value]??badge["NOT DEFINED"]}`}>{value}</span>;

export function OperationalIntegrityPanel({productionOrderId}:{productionOrderId:string}){
  const [authority,setAuthority]=useState<Authority|null>(null); const [data,setData]=useState<Integrity|null>(null); const [error,setError]=useState<string|null>(null);
  useEffect(()=>{Promise.all([api.get<Authority>("/production/operational-integrity/authority"),api.get<Integrity>(`/production/orders/${productionOrderId}/operational-integrity`)]).then(([a,d])=>{setAuthority(a);setData(d);}).catch(e=>setError(e.message));},[productionOrderId]);
  if(error)return <section className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Operational integrity tidak dapat dimuat: {error}</section>;
  if(!authority||!data)return <section className="rounded-xl border bg-white p-4 text-sm text-slate-500">Memuat operational integrity…</section>;
  return <section className="space-y-4 rounded-xl border bg-white p-4">
    <div className="flex flex-wrap justify-between gap-3"><div><h2 className="font-semibold">Operational Integrity / Authority</h2><p className="mt-1 text-sm text-slate-700">Preserve history · converge authority · block provable duplicate paths</p></div><div className="text-right text-xs text-slate-500"><p>Read-only evidence · Migration {data.migration}</p><p>Writes {data.writes_performed?"YES":"NONE"}</p></div></div>
    <div className={`rounded-lg border p-3 ${data.authority_conflict.mixed_path?"border-red-200 bg-red-50":"border-amber-200 bg-amber-50"}`}><div className="flex flex-wrap items-center justify-between gap-2"><b>Marker / Lay Consumption</b><Status value={data.authority_conflict.status}/></div><p className="mt-2 text-xs text-slate-700">Marker {data.authority_conflict.marker_present?"present":"absent"} · Lay Roll {data.authority_conflict.lay_roll_present?"present":"absent"} · new mixed execution {data.authority_conflict.new_mixed_execution}</p><p className="mt-1 text-xs text-slate-600">{data.authority_conflict.reason} Resolution: {data.authority_conflict.authority_resolution}.</p></div>
    <div className="overflow-x-auto"><table className="w-full min-w-[1000px] text-sm"><thead className="border-b text-left text-xs text-slate-500"><tr><th className="py-2">Domain</th><th>Legacy</th><th>Current</th><th>Conflict</th><th>Status</th><th>Resolution</th></tr></thead><tbody>{authority.rows.map(r=><tr key={r.domain} className="border-b align-top last:border-0"><td className="py-2 pr-3 font-medium">{r.domain}</td><td className="py-2 pr-3 text-xs">{r.legacy}</td><td className="py-2 pr-3 text-xs">{r.current}</td><td className="py-2 pr-3 text-xs text-slate-600">{r.conflict}</td><td className="py-2 pr-3"><Status value={r.status}/></td><td className="py-2 text-xs text-slate-600">{r.resolution}</td></tr>)}</tbody></table></div>
    <div className="grid gap-3 md:grid-cols-3"><div className="rounded bg-orange-50 p-3 text-xs"><p className="text-orange-700">qty_produced</p><b>{data.qty_produced.stored_value.toLocaleString("id-ID")} · LEGACY</b><p className="mt-1">{data.qty_produced.production_output_authority}</p></div><div className="rounded bg-slate-50 p-3 text-xs"><p className="text-slate-500">Backflush</p><b>{data.backflush.status}</b><p className="mt-1">{data.backflush.convergence}</p></div><div className="rounded bg-slate-50 p-3 text-xs"><p className="text-slate-500">Inventory / GL</p><b>ITS · {data.accounting.authority}</b><p className="mt-1">Duplicate ITS source: {data.inventory.duplicate_detected?"DETECTED":"not detected"}</p></div></div>
    <div className="grid gap-2 sm:grid-cols-4 text-xs"><p className="rounded bg-slate-50 p-2"><b>Cutting:</b> {data.operational_chain.cutting_path}</p><p className="rounded bg-slate-50 p-2"><b>Bundle/Scans/WIP:</b> {data.operational_chain.bundles}/{data.operational_chain.production_scans}/{data.operational_chain.wip_transfers}</p><p className="rounded bg-slate-50 p-2"><b>QC/Packing:</b> {data.operational_chain.qc_final_pass?"FINAL PASS":"no pass"}/{data.operational_chain.packing_lists}</p><p className="rounded bg-slate-50 p-2"><b>Shipments:</b> {data.operational_chain.shipments}</p></div>
    <div className="grid gap-3 md:grid-cols-2"><div className="rounded bg-slate-50 p-3 text-xs"><b>Forward lineage</b><p className="mt-1 text-slate-600">{data.lineage.forward}</p></div><div className="rounded bg-slate-50 p-3 text-xs"><b>Reverse trace</b><p className="mt-1 text-slate-600">{data.lineage.reverse}</p></div></div>
    <div className="grid gap-3 md:grid-cols-2 text-xs"><div><b>Safely converged</b><ul className="mt-1 list-disc space-y-1 pl-4 text-slate-600">{data.resolved_conflicts.map(x=><li key={x}>{x}</li>)}</ul></div><div><b>Decision required</b><ul className="mt-1 list-disc space-y-1 pl-4 text-slate-600">{data.decision_required.map(x=><li key={x}>{x}</li>)}</ul></div></div>
  </section>;
}
