"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

interface Opt { id: number; code?: string; name: string; type?: string }
interface LineInput { material_id: string; qty: string; uom_id: string; unit_price: string }

/** Buat PO manual (PO dari MRP dibuat dari halaman MRP → PR → PO) */
export default function NewPoPage() {
  const router = useRouter();

  const [suppliers, setSuppliers] = useState<Opt[]>([]);
  const [materials, setMaterials] = useState<Opt[]>([]);
  const [uoms, setUoms] = useState<Opt[]>([]);
  const [header, setHeader] = useState({ supplier_id: "", order_date: new Date().toISOString().slice(0, 10), expected_date: "", payment_term: "" });
  const [lines, setLines] = useState<LineInput[]>([{ material_id: "", qty: "", uom_id: "", unit_price: "" }]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get<{ data: Opt[] }>("/master/suppliers?per_page=200").then((r) => setSuppliers(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/materials?per_page=500").then((r) => setMaterials(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/uoms?per_page=100").then((r) => setUoms(r.data)).catch(() => {});
  }, []);

  const total = lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.unit_price) || 0), 0);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      await api.post("/purchasing/pos", {
        supplier_id: Number(header.supplier_id),
        order_date: header.order_date,
        expected_date: header.expected_date || undefined,
        payment_term: header.payment_term || undefined,
        lines: lines.map((l) => ({
          material_id: Number(l.material_id),
          qty: Number(l.qty),
          uom_id: Number(l.uom_id),
          unit_price: Number(l.unit_price),
        })),
      });
      router.push("/purchasing/pos");
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  const fmt = (n: number) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(n);

  return (
    <form onSubmit={save} className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Purchase Order Baru</h1>
        <button type="button" onClick={() => router.back()} className="rounded border px-3 py-1.5 text-sm">← Kembali</button>
      </div>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      <section className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4 md:grid-cols-4">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Supplier *</span>
          <select value={header.supplier_id} onChange={(e) => setHeader({ ...header, supplier_id: e.target.value })} required className={input}>
            <option value="">— pilih —</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.code} — {s.name}</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Tgl Order *</span>
          <input type="date" value={header.order_date} onChange={(e) => setHeader({ ...header, order_date: e.target.value })} required className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Ekspektasi Tiba</span>
          <input type="date" value={header.expected_date} onChange={(e) => setHeader({ ...header, expected_date: e.target.value })} className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Termin</span>
          <input value={header.payment_term} onChange={(e) => setHeader({ ...header, payment_term: e.target.value })} placeholder="mis. Net 30" className={input} />
        </label>
      </section>

      <section className="rounded-xl border bg-white p-4">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-semibold">Lines *</h2>
          <button type="button" onClick={() => setLines([...lines, { material_id: "", qty: "", uom_id: "", unit_price: "" }])} className="rounded border px-3 py-1.5 text-sm">+ Baris</button>
        </div>

        <div className="space-y-2">
          {lines.map((l, i) => (
            <div key={i} className="grid grid-cols-5 items-end gap-2 rounded-lg border bg-slate-50 p-3">
              <label className="col-span-2 text-xs">
                <span className="mb-1 block font-medium">Material</span>
                <select value={l.material_id} onChange={(e) => { const n = [...lines]; n[i].material_id = e.target.value; setLines(n); }} required className={input}>
                  <option value="">— pilih —</option>
                  {materials.map((m) => <option key={m.id} value={m.id}>{m.code} — {m.name}</option>)}
                </select>
              </label>
              <label className="text-xs">
                <span className="mb-1 block font-medium">Qty (UOM beli)</span>
                <input type="number" step="any" min="0.0001" value={l.qty} onChange={(e) => { const n = [...lines]; n[i].qty = e.target.value; setLines(n); }} required className={input} />
              </label>
              <label className="text-xs">
                <span className="mb-1 block font-medium">UOM</span>
                <select value={l.uom_id} onChange={(e) => { const n = [...lines]; n[i].uom_id = e.target.value; setLines(n); }} required className={input}>
                  <option value="">—</option>
                  {uoms.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
                </select>
              </label>
              <label className="text-xs">
                <span className="mb-1 block font-medium">Harga satuan</span>
                <input type="number" step="any" min="0" value={l.unit_price} onChange={(e) => { const n = [...lines]; n[i].unit_price = e.target.value; setLines(n); }} required className={input} />
              </label>
            </div>
          ))}
        </div>

        <div className="mt-3 text-right text-sm font-semibold">Total: {fmt(total)}</div>
      </section>

      <button disabled={saving} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
        {saving ? "Menyimpan…" : "Simpan PO (DRAFT)"}
      </button>
    </form>
  );
}
