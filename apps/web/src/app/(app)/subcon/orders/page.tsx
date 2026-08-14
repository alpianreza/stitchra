"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Mo { id: number; doc_no: string; style?: { style_no: string } }
interface Supplier { id: number; code: string; name: string; type: string }
interface Material { id: number; code: string; name: string }
interface Uom { id: number; code: string }
interface Warehouse { id: number; code: string; name: string }
interface SubconOrder {
  id: number; doc_no: string; status: string; fee_per_pcs: string;
  supplier?: { name: string }; production_order?: { doc_no: string };
  lines?: { id: number; qty_sent: string; qty_returned: string; material?: { code: string } }[];
}

/** Subcon CMT — kirim bahan/WIP (SUBCON_OUT) + terima hasil (SUBCON_IN + fee) */
export default function SubconOrdersPage() {
  const [orders, setOrders] = useState<SubconOrder[]>([]);
  const [mos, setMos] = useState<Mo[]>([]);
  const [subcons, setSubcons] = useState<Supplier[]>([]);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [uoms, setUoms] = useState<Uom[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);

  const [form, setForm] = useState({ mo_id: "", supplier_id: "", fee_per_pcs: "", warehouse_id: "", material_id: "", qty_sent: "", uom_id: "" });
  const [receiving, setReceiving] = useState<{ orderId: number; lineId: number; qty: string; warehouse_id: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    api.get<{ data: SubconOrder[] }>("/subcon/orders?per_page=100").then((r) => setOrders(r.data)).catch((e) => setError(e.message));
  }

  useEffect(() => {
    load();
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((r) => setMos(r.data)).catch(() => {});
    api.get<{ data: Supplier[] }>("/master/suppliers?per_page=200")
      .then((r) => setSubcons(r.data.filter((s) => s.type === "SUBCON"))).catch(() => {});
    api.get<{ data: Material[] }>("/master/materials?per_page=500").then((r) => setMaterials(r.data)).catch(() => {});
    api.get<{ data: Uom[] }>("/master/uoms?per_page=100").then((r) => setUoms(r.data)).catch(() => {});
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100").then((r) => setWarehouses(r.data)).catch(() => {});
  }, []);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const order = await api.post<SubconOrder>(`/subcon/orders/from-mo/${form.mo_id}`, {
        supplier_id: Number(form.supplier_id),
        fee_per_pcs: Number(form.fee_per_pcs),
        warehouse_id: Number(form.warehouse_id),
        lines: [{
          material_id: form.material_id ? Number(form.material_id) : undefined,
          qty_sent: Number(form.qty_sent),
          uom_id: form.uom_id ? Number(form.uom_id) : undefined,
        }],
      });
      setMessage(`Subcon order ${order.doc_no} terkirim (SENT).`);
      setForm({ mo_id: "", supplier_id: "", fee_per_pcs: "", warehouse_id: "", material_id: "", qty_sent: "", uom_id: "" });
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function receive() {
    if (!receiving) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const order = await api.post<SubconOrder>(`/subcon/orders/${receiving.orderId}/receive`, {
        returns: [{ line_id: receiving.lineId, qty_returned: Number(receiving.qty), warehouse_id: Number(receiving.warehouse_id) }],
      });
      setMessage(`Return tercatat — status: ${order.status}.`);
      setReceiving(null);
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function startReceive(orderId: number) {
    const detail = await api.get<SubconOrder>(`/subcon/orders/${orderId}`);
    const line = detail.lines?.find((l) => Number(l.qty_returned) < Number(l.qty_sent));
    if (!line) { setError("Semua line sudah penuh kembali."); return; }
    setReceiving({ orderId, lineId: line.id, qty: String(Number(line.qty_sent) - Number(line.qty_returned)), warehouse_id: "" });
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Subcontracting (CMT)</h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <form onSubmit={send} className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">Kirim ke Subcon</h2>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <label className="text-sm">
            <span className="mb-1 block font-medium">MO *</span>
            <select value={form.mo_id} onChange={(e) => setForm({ ...form, mo_id: e.target.value })} required className={input}>
              <option value="">— pilih MO —</option>
              {mos.map((m) => <option key={m.id} value={m.id}>{m.doc_no} ({m.style?.style_no})</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Subcon *</span>
            <select value={form.supplier_id} onChange={(e) => setForm({ ...form, supplier_id: e.target.value })} required className={input}>
              <option value="">— pilih —</option>
              {subcons.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Fee per pcs *</span>
            <input type="number" step="any" min="0" value={form.fee_per_pcs} onChange={(e) => setForm({ ...form, fee_per_pcs: e.target.value })} required className={input} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Gudang asal *</span>
            <select value={form.warehouse_id} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })} required className={input}>
              <option value="">— pilih —</option>
              {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Bahan pendamping (opsional)</span>
            <select value={form.material_id} onChange={(e) => setForm({ ...form, material_id: e.target.value })} className={input}>
              <option value="">— tanpa bahan —</option>
              {materials.map((m) => <option key={m.id} value={m.id}>{m.code} — {m.name}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Qty kirim *</span>
            <input type="number" step="any" min="0.0001" value={form.qty_sent} onChange={(e) => setForm({ ...form, qty_sent: e.target.value })} required className={input} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">UOM</span>
            <select value={form.uom_id} onChange={(e) => setForm({ ...form, uom_id: e.target.value })} className={input}>
              <option value="">—</option>
              {uoms.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
            </select>
          </label>
        </div>
        <button disabled={busy} className="mt-3 rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
          {busy ? "Memproses…" : "Kirim (SUBCON_OUT)"}
        </button>
      </form>

      <section className="rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. Order</th>
              <th className="px-3 py-2 font-medium">MO</th>
              <th className="px-3 py-2 font-medium">Subcon</th>
              <th className="px-3 py-2 text-right font-medium">Fee/pcs</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {orders.map((o) => (
              <tr key={o.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{o.doc_no}</td>
                <td className="px-3 py-2 font-mono">{o.production_order?.doc_no}</td>
                <td className="px-3 py-2">{o.supplier?.name}</td>
                <td className="px-3 py-2 text-right">{Number(o.fee_per_pcs).toLocaleString("id-ID")}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{o.status}</span></td>
                <td className="px-3 py-2">
                  {["SENT", "PARTIAL_RETURNED"].includes(o.status) && (
                    <button onClick={() => startReceive(o.id)} disabled={busy} className="rounded bg-blue-600 px-2 py-1 text-xs font-medium text-white disabled:opacity-50">
                      Terima
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {orders.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada subcon order.</td></tr>}
          </tbody>
        </table>
      </section>

      {receiving && (
        <div className="rounded-xl border-2 border-blue-200 bg-white p-4">
          <h3 className="mb-2 font-semibold">Terima return (SUBCON_IN)</h3>
          <div className="flex items-end gap-3">
            <label className="text-sm">
              <span className="mb-1 block font-medium">Qty kembali *</span>
              <input type="number" step="any" min="0.0001" value={receiving.qty} onChange={(e) => setReceiving({ ...receiving, qty: e.target.value })} className={input} />
            </label>
            <label className="text-sm">
              <span className="mb-1 block font-medium">Ke gudang *</span>
              <select value={receiving.warehouse_id} onChange={(e) => setReceiving({ ...receiving, warehouse_id: e.target.value })} className={input}>
                <option value="">— pilih —</option>
                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
              </select>
            </label>
            <button onClick={receive} disabled={busy || !receiving.warehouse_id} className="rounded bg-green-700 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
              Simpan Return
            </button>
            <button onClick={() => setReceiving(null)} className="rounded border px-4 py-1.5 text-sm">Batal</button>
          </div>
        </div>
      )}
    </div>
  );
}
