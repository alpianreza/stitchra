"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Allocation { material_id:number; is_backflush:boolean; backflush_stage:string|null; uom_id:number|null; qty_required:string; qty_issued:string; material?:{code:string;name:string} }
interface Mo { status:string; material_allocations?:Allocation[] }
interface Warehouse { id:number; code:string; name:string; type:string }

export function BackflushPanel({productionOrderId}:{productionOrderId:string}) {
  const [mo,setMo]=useState<Mo|null>(null); const [warehouses,setWarehouses]=useState<Warehouse[]>([]); const [warehouseId,setWarehouseId]=useState("");
  const [message,setMessage]=useState(""); const [busy,setBusy]=useState(false);
  const load=()=>api.get<Mo>(`/production/orders/${productionOrderId}`).then(setMo).catch(e=>setMessage(e.message));
  useEffect(()=>{load();api.get<{data:Warehouse[]}>("/master/warehouses?per_page=100").then(r=>setWarehouses(r.data.filter(w=>w.type==="RM"))).catch(()=>{});},[productionOrderId]);
  const rows=(mo?.material_allocations??[]).filter(a=>a.is_backflush);
  if(rows.length===0)return null;
  async function post(){if(!warehouseId)return;setBusy(true);setMessage("");try{const result=await api.post<{message:string}>(`/production/orders/${productionOrderId}/issues/backflush`,{warehouse_id:Number(warehouseId)});setMessage(result.message);await load();}catch(e:any){setMessage(e.message)}finally{setBusy(false)}}
  return <section className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4"><div><h2 className="font-semibold text-blue-900">BR-066 Named-Stage Backflush</h2><p className="text-xs text-blue-800">ACTUAL dan BACKFLUSH exclusive per material. Posting tetap melalui Material Issue → ITS.</p></div><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left"><th>Material</th><th>Named Stage</th><th className="text-right">Required</th><th className="text-right">Issued</th></tr></thead><tbody>{rows.map(a=><tr key={a.material_id} className="border-b"><td className="py-2">{a.material?.code} — {a.material?.name}</td><td>{a.backflush_stage??"BLOCKED: missing stage"}</td><td className="text-right">{Number(a.qty_required).toLocaleString("id-ID")}</td><td className="text-right">{Number(a.qty_issued).toLocaleString("id-ID")}</td></tr>)}</tbody></table></div><div className="flex gap-2"><select value={warehouseId} onChange={e=>setWarehouseId(e.target.value)} className="rounded border bg-white px-2 py-1.5 text-sm"><option value="">— gudang RM —</option>{warehouses.map(w=><option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}</select><button onClick={post} disabled={!warehouseId||busy} className="rounded bg-blue-700 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">{busy?"Memproses…":"Post delta Backflush"}</button></div>{message&&<p className="text-sm text-blue-900">{message}</p>}</section>
}
