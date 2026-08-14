"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

interface Opt { id: number; code?: string; name?: string; style_no?: string }
interface ColorwayOpt { id: number; style_id: number; color?: { name: string } }

interface MatrixLine {
  style_id: string;
  colorway_id: string;
  size_id: string;
  qty: string;
  price: string;
}

/** Buat SO baru — matrix editor style×colorway×size (BR-020) */
export default function NewSalesOrderPage() {
  const router = useRouter();

  const [customers, setCustomers] = useState<Opt[]>([]);
  const [styles, setStyles] = useState<Opt[]>([]);
  const [colorways, setColorways] = useState<ColorwayOpt[]>([]);
  const [sizes, setSizes] = useState<Opt[]>([]);

  const [header, setHeader] = useState({ customer_id: "", buyer_po_no: "", order_date: "", ex_factory_date: "", tolerance_pct: "" });
  const [lines, setLines] = useState<MatrixLine[]>([{ style_id: "", colorway_id: "", size_id: "", qty: "", price: "" }]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get<{ data: Opt[] }>("/master/customers?per_page=100").then((r) => setCustomers(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/styles?per_page=100").then((r) => setStyles(r.data)).catch(() => {});
    api.get<{ data: ColorwayOpt[] }>("/master/colorways?per_page=500").then((r) => setColorways(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/sizes?per_page=100").then((r) => setSizes(r.data)).catch(() => {});
  }, []);

  function setLine(i: number, field: keyof MatrixLine, value: string) {
    const next = [...lines];
    next[i] = { ...next[i], [field]: value };
    // Ganti style → reset colorway (colorway milik style)
    if (field === "style_id") next[i].colorway_id = "";
    setLines(next);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const payload = {
        customer_id: Number(header.customer_id),
        buyer_po_no: header.buyer_po_no || undefined,
        order_date: header.order_date,
        ex_factory_date: header.ex_factory_date || undefined,
        tolerance_pct: header.tolerance_pct ? Number(header.tolerance_pct) : undefined,
        lines: lines.map((l) => ({
          style_id: Number(l.style_id),
          colorway_id: Number(l.colorway_id),
          size_id: Number(l.size_id),
          qty: Number(l.qty),
          price: Number(l.price),
        })),
      };
      const so = await api.post<{ id: number; doc_no: string }>("/sales/orders", payload);
      router.push(`/sales/orders?created=${so.doc_no}`);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";

  return (
    <form onSubmit={save} className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Sales Order Baru</h1>
        <button type="button" onClick={() => router.back()} className="rounded border px-3 py-1.5 text-sm">← Kembali</button>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <section className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4 md:grid-cols-5">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Customer *</span>
          <select value={header.customer_id} onChange={(e) => setHeader({ ...header, customer_id: e.target.value })} required className={input}>
            <option value="">— pilih —</option>
            {customers.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">PO Buyer</span>
          <input value={header.buyer_po_no} onChange={(e) => setHeader({ ...header, buyer_po_no: e.target.value })} className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Tgl Order *</span>
          <input type="date" value={header.order_date} onChange={(e) => setHeader({ ...header, order_date: e.target.value })} required className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Ex-Factory</span>
          <input type="date" value={header.ex_factory_date} onChange={(e) => setHeader({ ...header, ex_factory_date: e.target.value })} className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Toleransi % (override buyer)</span>
          <input type="number" step="any" value={header.tolerance_pct} onChange={(e) => setHeader({ ...header, tolerance_pct: e.target.value })} className={input} />
        </label>
      </section>

      <section className="rounded-xl border bg-white p-4">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-semibold">Matrix Lines (style × color × size) *</h2>
          <button type="button" onClick={() => setLines([...lines, { style_id: "", colorway_id: "", size_id: "", qty: "", price: "" }])} className="rounded border px-3 py-1.5 text-sm">
            + Baris
          </button>
        </div>

        <div className="space-y-2">
          {lines.map((l, i) => {
            const styleColorways = colorways.filter((c) => c.style_id === Number(l.style_id));
            return (
              <div key={i} className="grid grid-cols-6 items-end gap-2 rounded-lg border bg-slate-50 p-3">
                <label className="text-xs">
                  <span className="mb-1 block font-medium">Style</span>
                  <select value={l.style_id} onChange={(e) => setLine(i, "style_id", e.target.value)} required className={input}>
                    <option value="">—</option>
                    {styles.map((s) => <option key={s.id} value={s.id}>{s.style_no}</option>)}
                  </select>
                </label>
                <label className="text-xs">
                  <span className="mb-1 block font-medium">Colorway</span>
                  <select value={l.colorway_id} onChange={(e) => setLine(i, "colorway_id", e.target.value)} required disabled={!l.style_id} className={input}>
                    <option value="">—</option>
                    {styleColorways.map((c) => <option key={c.id} value={c.id}>CW-{c.id}</option>)}
                  </select>
                </label>
                <label className="text-xs">
                  <span className="mb-1 block font-medium">Size</span>
                  <select value={l.size_id} onChange={(e) => setLine(i, "size_id", e.target.value)} required className={input}>
                    <option value="">—</option>
                    {sizes.map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
                  </select>
                </label>
                <label className="text-xs">
                  <span className="mb-1 block font-medium">Qty</span>
                  <input type="number" step="any" min="0.0001" value={l.qty} onChange={(e) => setLine(i, "qty", e.target.value)} required className={input} />
                </label>
                <label className="text-xs">
                  <span className="mb-1 block font-medium">Harga</span>
                  <input type="number" step="any" min="0" value={l.price} onChange={(e) => setLine(i, "price", e.target.value)} required className={input} />
                </label>
                <button
                  type="button"
                  onClick={() => setLines(lines.filter((_, x) => x !== i))}
                  disabled={lines.length === 1}
                  className="rounded border border-red-200 px-2 py-1.5 text-xs text-red-600 disabled:opacity-30"
                >
                  Hapus
                </button>
              </div>
            );
          })}
        </div>
      </section>

      <button disabled={saving} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
        {saving ? "Menyimpan…" : "Simpan SO (DRAFT)"}
      </button>
    </form>
  );
}
