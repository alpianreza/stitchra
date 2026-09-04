"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";

type PackType = "SOLID" | "RATIO" | "MIXED";
interface SoLine { id:number; style_id:number; colorway_id:number; size_id:number; qty:string; style?:{style_no:string}; colorway?:{id:number}; size?:{code:string} }
interface InstructionLine { style_id:number; colorway_id:number; size_id:number; ratio_qty:number }
interface Instruction { id:number; version:number; pack_type:PackType; lines:InstructionLine[] }
interface SalesOrder { id:number; doc_no:string; status:string; customer?:{name:string}; lines:SoLine[]; active_instruction?:Instruction }

export default function PackingInstructionsPage() {
  const [orders,setOrders]=useState<SalesOrder[]>([]); const [soId,setSoId]=useState("");
  const [type,setType]=useState<PackType>("SOLID"); const [ratios,setRatios]=useState<Record<number,string>>({});
  const [error,setError]=useState<string|null>(null); const [message,setMessage]=useState<string|null>(null); const [busy,setBusy]=useState(false);
  const selected=useMemo(()=>orders.find((order)=>order.id===Number(soId)),[orders,soId]);

  function load(){api.get<{data:SalesOrder[]}>("/packing/instructions/sales-orders").then((response)=>setOrders(response.data)).catch((exception)=>setError(exception.message));}
  useEffect(()=>{load();},[]);
  useEffect(()=>{if(!selected)return; const next:Record<number,string>={}; selected.lines.forEach((line)=>{const existing=selected.active_instruction?.lines.find((item)=>item.style_id===line.style_id&&item.colorway_id===line.colorway_id&&item.size_id===line.size_id);next[line.id]=String(existing?.ratio_qty??(type==="SOLID"?1:""));});setRatios(next);if(selected.active_instruction)setType(selected.active_instruction.pack_type);},[selected?.id]);

  async function save(){if(!selected)return; const lines=selected.lines.filter((line)=>Number(ratios[line.id])>0).map((line)=>({style_id:line.style_id,colorway_id:line.colorway_id,size_id:line.size_id,ratio_qty:type==="SOLID"?1:Number(ratios[line.id])}));
    setBusy(true);setError(null);setMessage(null);try{const created=await api.post<Instruction>(`/packing/instructions/sales-orders/${selected.id}`,{pack_type:type,lines});setMessage(`Instruction ${created.pack_type} v${created.version} aktif untuk ${selected.doc_no}.`);load();}catch(exception:any){setError(exception.message);}finally{setBusy(false);}}

  return <div className="space-y-4">
    <div><h1 className="text-xl font-bold">Packing Instructions</h1><p className="text-sm text-slate-500">Template carton versioned dari matrix Sales Order. Packing List baru menyimpan snapshot versi aktif.</p></div>
    {error&&<pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}{message&&<p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}
    <section className="space-y-3 rounded-xl border bg-white p-4">
      <div className="grid gap-3 md:grid-cols-2"><label className="text-sm"><span className="mb-1 block font-medium">Sales Order</span><select className="w-full rounded border px-3 py-2" value={soId} onChange={(event)=>setSoId(event.target.value)}><option value="">— pilih SO —</option>{orders.map((order)=><option key={order.id} value={order.id}>{order.doc_no} · {order.customer?.name??"Buyer"} · {order.active_instruction?`${order.active_instruction.pack_type} v${order.active_instruction.version}`:"belum ada instruction"}</option>)}</select></label>
      <label className="text-sm"><span className="mb-1 block font-medium">Pack type</span><select className="w-full rounded border px-3 py-2" value={type} onChange={(event)=>setType(event.target.value as PackType)}><option>SOLID</option><option>RATIO</option><option>MIXED</option></select></label></div>
      <div className="rounded bg-blue-50 p-3 text-xs text-blue-800">SOLID: satu SKU per carton. RATIO/MIXED: seluruh matrix terpilih wajib hadir dengan multiplier bilangan bulat yang sama. Versi baru tidak mengubah Packing List lama.</div>
      {selected&&<div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left"><th className="py-2">Style</th><th>Colorway</th><th>Size</th><th>Qty SO</th><th className="w-40">Ratio</th></tr></thead><tbody>{selected.lines.map((line)=><tr key={line.id} className="border-b"><td className="py-2">{line.style?.style_no??line.style_id}</td><td>{line.colorway?.id??line.colorway_id}</td><td>{line.size?.code??line.size_id}</td><td>{line.qty}</td><td><input type="number" min="0" step="1" disabled={type==="SOLID"} value={type==="SOLID"?(ratios[line.id]||"1"):(ratios[line.id]||"")} onChange={(event)=>setRatios({...ratios,[line.id]:event.target.value})} placeholder="0 = tidak dipakai" className="w-full rounded border px-2 py-1"/></td></tr>)}</tbody></table></div>}
      <button onClick={save} disabled={!selected||busy} className="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Simpan sebagai versi baru</button>
    </section>
    <section className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><b>Boundary:</b> direct Bundle/Finishing Output → Carton tetap NOT_DEFINED. Implementasi ini tidak membuat FIFO atau piece allocation.</section>
  </div>;
}
