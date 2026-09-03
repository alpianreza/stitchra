"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Style { id: number; style_no: string }
interface Material { id: number; code: string; name: string; type: string }
interface Uom { id: number; code: string }
interface Colorway { id: number; style_id: number }
interface BomVersion { id: number; version_no: number; status: string; bom_id: number }
interface BomLineInput { material_id: string; uom_id: string; qty_per_pcs: string; wastage_pct: string; shrinkage_pct: string; consumption_estimated: string; is_backflush: boolean; backflush_stage: string; colorway_id: string }

const BACKFLUSH_STAGES = ["CUT_OUTPUT", "SEWING_FINAL_OUT", "FINISHING_OUT", "QC_FINAL_PASS", "PACKED_QTY", "FG_RECEIVED_QTY"];
const emptyLine = (): BomLineInput => ({ material_id: "", uom_id: "", qty_per_pcs: "", wastage_pct: "0", shrinkage_pct: "0", consumption_estimated: "", is_backflush: false, backflush_stage: "", colorway_id: "" });

export default function BomEditorPage() {
  const [styles, setStyles] = useState<Style[]>([]); const [materials, setMaterials] = useState<Material[]>([]); const [uoms, setUoms] = useState<Uom[]>([]); const [colorways, setColorways] = useState<Colorway[]>([]);
  const [styleId, setStyleId] = useState(""); const [lines, setLines] = useState<BomLineInput[]>([emptyLine()]); const [created, setCreated] = useState<BomVersion | null>(null);
  const [error, setError] = useState<string | null>(null); const [message, setMessage] = useState<string | null>(null); const [busy, setBusy] = useState(false);
  useEffect(() => { api.get<{data:Style[]}>("/master/styles?per_page=200").then(r=>setStyles(r.data)).catch(()=>{}); api.get<{data:Material[]}>("/master/materials?per_page=500").then(r=>setMaterials(r.data)).catch(()=>{}); api.get<{data:Uom[]}>("/master/uoms?per_page=100").then(r=>setUoms(r.data)).catch(()=>{}); api.get<{data:Colorway[]}>("/master/colorways?per_page=500").then(r=>setColorways(r.data)).catch(()=>{}); }, []);
  function setLine(i:number, field:keyof BomLineInput, value:string|boolean) { const next=[...lines]; (next[i] as any)[field]=value; if(field==="is_backflush"&&!value) next[i].backflush_stage=""; setLines(next); }
  async function save(e:React.FormEvent) { e.preventDefault(); setBusy(true); setError(null); try { const version=await api.post<BomVersion>("/pd/boms", { style_id:Number(styleId), lines:lines.map(l=>({ material_id:Number(l.material_id), uom_id:Number(l.uom_id), qty_per_pcs:Number(l.qty_per_pcs), wastage_pct:Number(l.wastage_pct)||0, shrinkage_pct:Number(l.shrinkage_pct)||0, consumption_estimated:l.consumption_estimated?Number(l.consumption_estimated):undefined, is_backflush:l.is_backflush, backflush_stage:l.is_backflush?l.backflush_stage:undefined, colorway_id:l.colorway_id?Number(l.colorway_id):undefined })) }); setCreated(version); setMessage(`BOM v${version.version_no} dibuat.`); } catch(err:any){setError(err.message)} finally{setBusy(false)} }
  async function submit(){if(!created)return;setBusy(true);setError(null);try{await api.post(`/pd/boms/${created.id}/submit`,{});setMessage(`BOM v${created.version_no} masuk approval.`);setCreated(null);setLines([emptyLine()]);setStyleId("");}catch(err:any){setError(err.message)}finally{setBusy(false)}}
  const input="w-full rounded border px-2 py-1.5 text-sm"; const styleColorways=colorways.filter(c=>c.style_id===Number(styleId));
  return <div className="space-y-4"><h1 className="text-xl font-bold">BOM Editor <span className="text-sm font-normal text-slate-500">BR-030 / BR-066</span></h1>
    {error&&<pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}{message&&<p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}
    <div className="rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800"><b>BR-066:</b> satu material memakai ACTUAL atau BACKFLUSH. Fabric wajib ACTUAL. BACKFLUSH wajib memilih tepat satu Named Stage dan UOM material.</div>
    <form onSubmit={save} className="space-y-4 rounded-xl border bg-white p-4"><label className="block max-w-sm text-sm"><span className="mb-1 block font-medium">Style *</span><select value={styleId} onChange={e=>setStyleId(e.target.value)} required className={input} disabled={!!created}><option value="">— pilih style —</option>{styles.map(s=><option key={s.id} value={s.id}>{s.style_no}</option>)}</select></label>
      <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold">Lines</h2><button type="button" onClick={()=>setLines([...lines,emptyLine()])} className="rounded border px-3 py-1 text-sm" disabled={!!created}>+ Baris</button></div>
      <div className="overflow-x-auto"><table className="w-full min-w-[1250px] text-sm"><thead className="border-b text-left text-xs text-slate-500"><tr><th>Material *</th><th>Colorway</th><th>Qty/pcs *</th><th>UOM *</th><th>Wastage %</th><th>Shrinkage %</th><th>Consumption Est.</th><th>Method</th><th>Named Stage</th><th/></tr></thead><tbody>{lines.map((l,i)=><tr key={i} className="border-b">
        <td className="py-1 pr-2"><select value={l.material_id} onChange={e=>setLine(i,"material_id",e.target.value)} required className={input} disabled={!!created}><option value="">— pilih —</option>{materials.map(m=><option key={m.id} value={m.id}>{m.code} — {m.name} ({m.type})</option>)}</select></td>
        <td className="py-1 pr-2"><select value={l.colorway_id} onChange={e=>setLine(i,"colorway_id",e.target.value)} className={input} disabled={!!created||!styleId}><option value="">Semua</option>{styleColorways.map(c=><option key={c.id} value={c.id}>CW-{c.id}</option>)}</select></td>
        <td className="pr-2"><input type="number" step="any" min="0.000001" value={l.qty_per_pcs} onChange={e=>setLine(i,"qty_per_pcs",e.target.value)} required className={input}/></td><td className="pr-2"><select value={l.uom_id} onChange={e=>setLine(i,"uom_id",e.target.value)} required className={input}><option value="">—</option>{uoms.map(u=><option key={u.id} value={u.id}>{u.code}</option>)}</select></td>
        <td className="pr-2"><input type="number" step="any" min="0" value={l.wastage_pct} onChange={e=>setLine(i,"wastage_pct",e.target.value)} className={input}/></td><td className="pr-2"><input type="number" step="any" min="0" value={l.shrinkage_pct} onChange={e=>setLine(i,"shrinkage_pct",e.target.value)} className={input}/></td><td className="pr-2"><input type="number" step="any" min="0" value={l.consumption_estimated} onChange={e=>setLine(i,"consumption_estimated",e.target.value)} className={input}/></td>
        <td className="pr-2"><label className="flex items-center gap-2"><input type="checkbox" checked={l.is_backflush} onChange={e=>setLine(i,"is_backflush",e.target.checked)}/>{l.is_backflush?"BACKFLUSH":"ACTUAL"}</label></td>
        <td className="pr-2"><select value={l.backflush_stage} onChange={e=>setLine(i,"backflush_stage",e.target.value)} required={l.is_backflush} disabled={!l.is_backflush||!!created} className={input}><option value="">— pilih —</option>{BACKFLUSH_STAGES.map(s=><option key={s} value={s}>{s}</option>)}</select></td>
        <td><button type="button" onClick={()=>setLines(lines.filter((_,x)=>x!==i))} disabled={lines.length===1||!!created} className="text-red-600">✕</button></td></tr>)}</tbody></table></div>
      {!created?<button disabled={busy} className="rounded bg-slate-900 px-6 py-2 font-medium text-white">Simpan Versi BOM</button>:<button type="button" onClick={submit} disabled={busy} className="rounded bg-green-700 px-6 py-2 font-medium text-white">Submit untuk Approval</button>}
    </form></div>;
}
