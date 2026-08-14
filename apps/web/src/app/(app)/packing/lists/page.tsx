"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface So { id: number; doc_no: string; customer?: { name: string } }
interface Pl {
  id: number; doc_no: string; status: string;
  sales_order?: { doc_no: string; customer?: { name: string } };
  cartons_count?: number;
}
interface CartonLineInput { style_id: string; colorway_id: string; size_id: string; qty: string }
interface Style { id: number; style_no: string }
interface Colorway { id: number; style_id: number }
interface Size { id: number; code: string }
interface Warehouse { id: number; code: string; name: string; type: string }

/** Packing List — karton per matrix; finalize wajib QC PASS + ratio check (BR-082/021) */
export default function PackingListsPage() {
  const [lists, setLists] = useState<Pl[]>([]);
  const [sos, setSos] = useState<So[]>([]);
  const [soId, setSoId] = useState("");
  const [active, setActive] = useState<Pl | null>(null);

  const [styles, setStyles] = useState<Style[]>([]);
  const [colorways, setColorways] = useState<Colorway[]>([]);
  const [sizes, setSizes] = useState<Size[]>([]);
  const [fgWarehouses, setFgWarehouses] = useState<Warehouse[]>([]);

  const [cartonLines, setCartonLines] = useState<CartonLineInput[]>([{ style_id: "", colorway_id: "", size_id: "", qty: "" }]);
  const [gross, setGross] = useState("");
  const [net, setNet] = useState("");
  const [fgWarehouseId, setFgWarehouseId] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    api.get<{ data: Pl[] }>("/packing/lists?per_page=100").then((r) => setLists(r.data)).catch((e) => setError(e.message));
  }

  useEffect(() => {
    load();
    Promise.all([
      api.get<{ data: So[] }>("/sales/orders?status=CONFIRMED&per_page=100"),
      api.get<{ data: So[] }>("/sales/orders?status=IN_PROGRESS&per_page=100"),
    ]).then(([a, b]) => setSos([...a.data, ...b.data])).catch(() => {});
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((r) => setStyles(r.data)).catch(() => {});
    api.get<{ data: Colorway[] }>("/master/colorways?per_page=500").then((r) => setColorways(r.data)).catch(() => {});
    api.get<{ data: Size[] }>("/master/sizes?per_page=100").then((r) => setSizes(r.data)).catch(() => {});
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100")
      .then((r) => setFgWarehouses(r.data.filter((w) => w.type === "FG")))
      .catch(() => {});
  }, []);

  async function createPl() {
    if (!soId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const pl = await api.post<Pl>(`/packing/lists/from-so/${soId}`, {});
      setMessage(`Packing list ${pl.doc_no} dibuat.`);
      setActive(pl);
      setSoId("");
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function addCarton() {
    if (!active) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/packing/lists/${active.id}/cartons`, {
        carton: { gross_weight_kg: gross ? Number(gross) : undefined, net_weight_kg: net ? Number(net) : undefined },
        lines: cartonLines.map((l) => ({
          style_id: Number(l.style_id), colorway_id: Number(l.colorway_id),
          size_id: Number(l.size_id), qty: Number(l.qty),
        })),
      });
      setMessage("Karton ditambahkan.");
      setCartonLines([{ style_id: "", colorway_id: "", size_id: "", qty: "" }]);
      setGross(""); setNet("");
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function finalize() {
    if (!active || !fgWarehouseId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const pl = await api.post<Pl>(`/packing/lists/${active.id}/finalize`, { fg_warehouse_id: Number(fgWarehouseId) });
      setMessage(`PL ${pl.doc_no} finalized — FG masuk gudang, MO → PACKED.`);
      setActive(null);
      load();
    } catch (e: any) {
      setError(e.message);   // BR-082/021: QC belum PASS / ratio check gagal
    } finally {
      setBusy(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Packing List</h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <section className="flex items-end gap-3 rounded-xl border bg-white p-4">
        <label className="text-sm">
          <span className="mb-1 block font-medium">SO (CONFIRMED/IN_PROGRESS)</span>
          <select value={soId} onChange={(e) => setSoId(e.target.value)} className={input}>
            <option value="">— pilih SO —</option>
            {sos.map((s) => <option key={s.id} value={s.id}>{s.doc_no} — {s.customer?.name}</option>)}
          </select>
        </label>
        <button onClick={createPl} disabled={!soId || busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
          Buat Packing List
        </button>
      </section>

      <section className="rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. PL</th>
              <th className="px-3 py-2 font-medium">SO</th>
              <th className="px-3 py-2 font-medium">Customer</th>
              <th className="px-3 py-2 font-medium">Karton</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            {lists.map((pl) => (
              <tr key={pl.id} className={`border-b last:border-0 ${active?.id === pl.id ? "bg-blue-50" : "hover:bg-slate-50"}`}>
                <td className="px-3 py-2 font-mono">{pl.doc_no}</td>
                <td className="px-3 py-2 font-mono">{pl.sales_order?.doc_no}</td>
                <td className="px-3 py-2">{pl.sales_order?.customer?.name}</td>
                <td className="px-3 py-2">{pl.cartons_count ?? "—"}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{pl.status}</span></td>
                <td className="px-3 py-2">
                  {pl.status === "DRAFT" && (
                    <button onClick={() => setActive(pl)} className="rounded border px-2 py-1 text-xs">Kelola</button>
                  )}
                </td>
              </tr>
            ))}
            {lists.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada packing list.</td></tr>}
          </tbody>
        </table>
      </section>

      {active && (
        <section className="space-y-3 rounded-xl border-2 border-blue-200 bg-white p-4">
          <h2 className="font-semibold">Kelola {active.doc_no}</h2>

          <div className="rounded-lg border bg-slate-50 p-3">
            <div className="mb-2 flex items-center justify-between">
              <span className="text-sm font-medium">Karton baru (isi matrix)</span>
              <button type="button" onClick={() => setCartonLines([...cartonLines, { style_id: "", colorway_id: "", size_id: "", qty: "" }])} className="rounded border px-2 py-0.5 text-xs">+ Baris</button>
            </div>
            {cartonLines.map((l, i) => (
              <div key={i} className="mb-1 grid grid-cols-4 gap-2">
                <select value={l.style_id} onChange={(e) => { const n = [...cartonLines]; n[i].style_id = e.target.value; n[i].colorway_id = ""; setCartonLines(n); }} className={input}>
                  <option value="">Style…</option>
                  {styles.map((s) => <option key={s.id} value={s.id}>{s.style_no}</option>)}
                </select>
                <select value={l.colorway_id} onChange={(e) => { const n = [...cartonLines]; n[i].colorway_id = e.target.value; setCartonLines(n); }} disabled={!l.style_id} className={input}>
                  <option value="">Colorway…</option>
                  {colorways.filter((c) => c.style_id === Number(l.style_id)).map((c) => <option key={c.id} value={c.id}>CW-{c.id}</option>)}
                </select>
                <select value={l.size_id} onChange={(e) => { const n = [...cartonLines]; n[i].size_id = e.target.value; setCartonLines(n); }} className={input}>
                  <option value="">Size…</option>
                  {sizes.map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
                </select>
                <input type="number" step="any" min="0.0001" placeholder="Qty" value={l.qty} onChange={(e) => { const n = [...cartonLines]; n[i].qty = e.target.value; setCartonLines(n); }} className={input} />
              </div>
            ))}
            <div className="mt-2 flex items-end gap-2">
              <input type="number" step="any" placeholder="Gross kg" value={gross} onChange={(e) => setGross(e.target.value)} className="w-28 rounded border px-2 py-1.5 text-sm" />
              <input type="number" step="any" placeholder="Net kg" value={net} onChange={(e) => setNet(e.target.value)} className="w-28 rounded border px-2 py-1.5 text-sm" />
              <button onClick={addCarton} disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                Simpan Karton
              </button>
            </div>
          </div>

          <div className="flex items-end gap-3 border-t pt-3">
            <label className="text-sm">
              <span className="mb-1 block font-medium">Gudang FG *</span>
              <select value={fgWarehouseId} onChange={(e) => setFgWarehouseId(e.target.value)} className={input}>
                <option value="">— pilih —</option>
                {fgWarehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
              </select>
            </label>
            <button onClick={finalize} disabled={!fgWarehouseId || busy} className="rounded bg-green-700 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
              Finalize (butuh QC FINAL PASS + ratio OK)
            </button>
          </div>
        </section>
      )}
    </div>
  );
}
