"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface PackingInput {
  qc_inspection_id: number; qc_doc_no: string; qc_verdict: string; eligible_qty: number;
  packed_qty: number; remaining_qty: number; production_order_id: number; production_order_no: string;
  production_order_status: string; sales_order_id: number; sales_order_no?: string; style_no?: string;
}
interface Pl {
  id: number; doc_no: string; status: string; cartons_count?: number;
  sales_order?: { doc_no: string; customer?: { name: string } };
  production_order?: { doc_no: string; status: string };
  qc_inspection?: { doc_no: string; verdict: string; lot_qty: string };
}
interface CartonLineInput { style_id: string; colorway_id: string; size_id: string; qty: string }
interface Style { id: number; style_no: string }
interface Colorway { id: number; style_id: number }
interface Size { id: number; code: string }
interface Warehouse { id: number; code: string; name: string; type: string }
interface Lineage {
  packing_input?: { doc_no?: string; verdict?: string; lot_qty?: number; status?: string };
  cartons?: Array<{ carton_no: string; qty: number }>;
  carton_allocation?: { authority: string; direct_bundle_or_finishing_output_link: boolean };
  fg_boundary?: { status: string; production_receipt_posted: boolean };
  shipment_boundary?: { doc_no?: string; status: string };
}

/** Packing List — BR-080 QC FINAL PASS source, matrix cartons, PF-09 FG receipt boundary. */
export default function PackingListsPage() {
  const [lists, setLists] = useState<Pl[]>([]);
  const [inputs, setInputs] = useState<PackingInput[]>([]);
  const [inputId, setInputId] = useState("");
  const [active, setActive] = useState<Pl | null>(null);
  const [lineage, setLineage] = useState<Lineage | null>(null);
  const [styles, setStyles] = useState<Style[]>([]);
  const [colorways, setColorways] = useState<Colorway[]>([]);
  const [sizes, setSizes] = useState<Size[]>([]);
  const [fgWarehouses, setFgWarehouses] = useState<Warehouse[]>([]);
  const [cartonLines, setCartonLines] = useState<CartonLineInput[]>([{ style_id: "", colorway_id: "", size_id: "", qty: "" }]);
  const [gross, setGross] = useState(""); const [net, setNet] = useState(""); const [fgWarehouseId, setFgWarehouseId] = useState("");
  const [error, setError] = useState<string | null>(null); const [message, setMessage] = useState<string | null>(null); const [busy, setBusy] = useState(false);

  function load() {
    api.get<{ data: Pl[] }>("/packing/lists?per_page=100").then((response) => setLists(response.data)).catch((exception) => setError(exception.message));
    api.get<{ data: PackingInput[] }>("/packing/eligible-inputs").then((response) => setInputs(response.data)).catch((exception) => setError(exception.message));
  }

  useEffect(() => {
    load();
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((response) => setStyles(response.data)).catch(() => {});
    api.get<{ data: Colorway[] }>("/master/colorways?per_page=500").then((response) => setColorways(response.data)).catch(() => {});
    api.get<{ data: Size[] }>("/master/sizes?per_page=100").then((response) => setSizes(response.data)).catch(() => {});
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100").then((response) => setFgWarehouses(response.data.filter((warehouse) => warehouse.type === "FG"))).catch(() => {});
  }, []);

  async function createPl() {
    const source = inputs.find((item) => item.qc_inspection_id === Number(inputId));
    if (!source) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const pl = await api.post<Pl>(`/packing/lists/from-so/${source.sales_order_id}`, { production_order_id: source.production_order_id });
      setMessage(`Packing list ${pl.doc_no} dibuat dari ${source.qc_doc_no}.`); setActive(pl); setInputId(""); setLineage(null); load();
    } catch (exception: any) { setError(exception.message); } finally { setBusy(false); }
  }

  async function manage(pl: Pl) {
    setBusy(true); setError(null);
    try {
      const [detail, trace] = await Promise.all([
        api.get<Pl>(`/packing/lists/${pl.id}`), api.get<Lineage>(`/packing/lists/${pl.id}/lineage`),
      ]);
      setActive(detail); setLineage(trace);
    } catch (exception: any) { setError(exception.message); } finally { setBusy(false); }
  }

  async function addCarton() {
    if (!active) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/packing/lists/${active.id}/cartons`, {
        carton: { gross_weight_kg: gross ? Number(gross) : undefined, net_weight_kg: net ? Number(net) : undefined },
        lines: cartonLines.map((line) => ({ style_id:Number(line.style_id), colorway_id:Number(line.colorway_id), size_id:Number(line.size_id), qty:Number(line.qty) })),
      });
      setMessage("Karton ditambahkan dari source QC FINAL PASS."); setCartonLines([{ style_id:"", colorway_id:"", size_id:"", qty:"" }]); setGross(""); setNet(""); await manage(active); load();
    } catch (exception: any) { setError(exception.message); } finally { setBusy(false); }
  }

  async function finalize() {
    if (!active || !fgWarehouseId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const pl = await api.post<Pl>(`/packing/lists/${active.id}/finalize`, { fg_warehouse_id:Number(fgWarehouseId) });
      setMessage(`PL ${pl.doc_no} disetujui — PRODUCTION_RECEIPT FG diposting melalui ITS.`); setActive(null); setLineage(null); load();
    } catch (exception: any) { setError(exception.message); } finally { setBusy(false); }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  const selectedInput = inputs.find((item) => item.qc_inspection_id === Number(inputId));

  return <div className="space-y-4">
    <h1 className="text-xl font-bold">Packing / Carton / Packing List</h1>
    {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
    {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

    <section className="space-y-3 rounded-xl border bg-white p-4">
      <div><h2 className="font-semibold">Eligible Packing Input</h2><p className="text-xs text-slate-500">Server-side authority: QC FINAL PASS (BR-080). Remaining quantity sudah mengurangi seluruh carton non-cancelled untuk MO.</p></div>
      <div className="flex items-end gap-3">
        <label className="min-w-96 text-sm"><span className="mb-1 block font-medium">QC FINAL PASS / MO</span>
          <select value={inputId} onChange={(event) => setInputId(event.target.value)} className={input}>
            <option value="">— pilih output eligible —</option>
            {inputs.map((item) => <option key={item.qc_inspection_id} value={item.qc_inspection_id}>{item.qc_doc_no} · {item.production_order_no} · {item.style_no ?? "Style"} · sisa {item.remaining_qty.toLocaleString("id-ID")}</option>)}
          </select>
        </label>
        <button onClick={createPl} disabled={!selectedInput || busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">Buat Packing List</button>
      </div>
      {selectedInput && <div className="grid grid-cols-4 gap-2 rounded bg-blue-50 p-3 text-sm"><span>SO: <b>{selectedInput.sales_order_no}</b></span><span>Eligible: <b>{selectedInput.eligible_qty}</b></span><span>Packed: <b>{selectedInput.packed_qty}</b></span><span>Remaining: <b>{selectedInput.remaining_qty}</b></span></div>}
    </section>

    <section className="overflow-x-auto rounded-xl border bg-white"><table className="w-full text-sm"><thead className="border-b bg-slate-50 text-left"><tr><th className="px-3 py-2">No. PL</th><th className="px-3 py-2">SO / MO</th><th className="px-3 py-2">QC Source</th><th className="px-3 py-2">Karton</th><th className="px-3 py-2">Status</th><th /></tr></thead><tbody>
      {lists.map((pl) => <tr key={pl.id} className="border-b"><td className="px-3 py-2 font-mono">{pl.doc_no}</td><td className="px-3 py-2">{pl.sales_order?.doc_no}<br/><span className="text-xs text-slate-500">{pl.production_order?.doc_no ?? "Legacy: MO missing"}</span></td><td className="px-3 py-2">{pl.qc_inspection ? `${pl.qc_inspection.doc_no} · ${pl.qc_inspection.verdict}` : "Belum ada Packing Input"}</td><td className="px-3 py-2">{pl.cartons_count ?? 0}</td><td className="px-3 py-2">{pl.status}</td><td className="px-3 py-2">{pl.status === "DRAFT" && <button onClick={() => manage(pl)} className="rounded border px-2 py-1 text-xs">Kelola</button>}</td></tr>)}
      {lists.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada packing list.</td></tr>}
    </tbody></table></section>

    {active && <section className="space-y-3 rounded-xl border-2 border-blue-200 bg-white p-4"><h2 className="font-semibold">Kelola {active.doc_no}</h2>
      {lineage && <div className="grid gap-2 rounded bg-slate-50 p-3 text-xs md:grid-cols-4"><span>Input: <b>{lineage.packing_input?.doc_no ?? lineage.packing_input?.status}</b></span><span>Cartons: <b>{lineage.cartons?.length ?? 0}</b></span><span>FG: <b>{lineage.fg_boundary?.status}</b></span><span>Shipment: <b>{lineage.shipment_boundary?.status}</b></span><p className="md:col-span-4 text-amber-700">Direct Bundle/Finishing Output → Carton: ⚪ {lineage.carton_allocation?.authority ?? "NOT_DEFINED"}</p></div>}
      <div className="rounded-lg border bg-slate-50 p-3"><div className="mb-2 flex justify-between"><span className="text-sm font-medium">Karton baru (matrix SKU)</span><button onClick={() => setCartonLines([...cartonLines,{style_id:"",colorway_id:"",size_id:"",qty:""}])} className="rounded border px-2 text-xs">+ Baris</button></div>
        {cartonLines.map((line,index) => <div key={index} className="mb-1 grid grid-cols-4 gap-2"><select value={line.style_id} onChange={(event)=>{const next=[...cartonLines];next[index].style_id=event.target.value;next[index].colorway_id="";setCartonLines(next)}} className={input}><option value="">Style…</option>{styles.map((style)=><option key={style.id} value={style.id}>{style.style_no}</option>)}</select><select value={line.colorway_id} onChange={(event)=>{const next=[...cartonLines];next[index].colorway_id=event.target.value;setCartonLines(next)}} className={input}><option value="">Colorway…</option>{colorways.filter((colorway)=>colorway.style_id===Number(line.style_id)).map((colorway)=><option key={colorway.id} value={colorway.id}>CW-{colorway.id}</option>)}</select><select value={line.size_id} onChange={(event)=>{const next=[...cartonLines];next[index].size_id=event.target.value;setCartonLines(next)}} className={input}><option value="">Size…</option>{sizes.map((size)=><option key={size.id} value={size.id}>{size.code}</option>)}</select><input type="number" min="0.0001" step="any" placeholder="Qty" value={line.qty} onChange={(event)=>{const next=[...cartonLines];next[index].qty=event.target.value;setCartonLines(next)}} className={input}/></div>)}
        <div className="mt-2 flex gap-2"><input type="number" placeholder="Gross kg" value={gross} onChange={(event)=>setGross(event.target.value)} className="w-28 rounded border px-2 py-1.5 text-sm"/><input type="number" placeholder="Net kg" value={net} onChange={(event)=>setNet(event.target.value)} className="w-28 rounded border px-2 py-1.5 text-sm"/><button onClick={addCarton} disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm text-white">Simpan Karton</button></div>
      </div>
      <div className="flex items-end gap-3 border-t pt-3"><label className="text-sm"><span className="mb-1 block font-medium">Gudang FG</span><select value={fgWarehouseId} onChange={(event)=>setFgWarehouseId(event.target.value)} className={input}><option value="">— pilih —</option>{fgWarehouses.map((warehouse)=><option key={warehouse.id} value={warehouse.id}>{warehouse.code} — {warehouse.name}</option>)}</select></label><button onClick={finalize} disabled={!fgWarehouseId || busy} className="rounded bg-green-700 px-4 py-1.5 text-sm text-white disabled:opacity-50">Finalize → FG Receipt</button></div>
    </section>}
  </div>;
}
