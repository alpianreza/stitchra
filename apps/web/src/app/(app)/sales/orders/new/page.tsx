"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

interface Opt { id: number; code?: string; name?: string; style_no?: string; currency?: string | null }
interface CurrencyOpt { id: number; code: string; name: string }
interface ColorwayOpt { id: number; style_id: number; color?: { name: string } }
interface MatrixLine { style_id: string; colorway_id: string; size_id: string; qty: string; price: string }

export default function NewSalesOrderPage() {
  const router = useRouter();
  const [customers, setCustomers] = useState<Opt[]>([]);
  const [styles, setStyles] = useState<Opt[]>([]);
  const [colorways, setColorways] = useState<ColorwayOpt[]>([]);
  const [sizes, setSizes] = useState<Opt[]>([]);
  const [currencies, setCurrencies] = useState<CurrencyOpt[]>([]);
  const [header, setHeader] = useState({ customer_id: "", buyer_po_no: "", currency_id: "", idr_per_usd: "", order_date: "", ex_factory_date: "", tolerance_pct: "" });
  const [lines, setLines] = useState<MatrixLine[]>([{ style_id: "", colorway_id: "", size_id: "", qty: "", price: "" }]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get<{ data: Opt[] }>("/master/customers?per_page=100").then((r) => setCustomers(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/styles?per_page=100").then((r) => setStyles(r.data)).catch(() => {});
    api.get<{ data: ColorwayOpt[] }>("/master/colorways?per_page=100").then((r) => setColorways(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/sizes?per_page=100").then((r) => setSizes(r.data)).catch(() => {});
    api.get<{ data: CurrencyOpt[] }>("/master/currencies?per_page=100").then((r) => setCurrencies(r.data)).catch(() => {});
  }, []);

  const selectedCurrency = useMemo(() => currencies.find((item) => item.id === Number(header.currency_id)), [currencies, header.currency_id]);
  const currencyCode = selectedCurrency?.code.toUpperCase() ?? "USD";

  function chooseCustomer(customerId: string) {
    const customer = customers.find((item) => item.id === Number(customerId));
    const preferredCode = customer?.currency?.toUpperCase();
    const preferred = currencies.find((item) => item.code.toUpperCase() === preferredCode);
    setHeader((current) => ({ ...current, customer_id: customerId, currency_id: preferredCode === "IDR" && preferred ? String(preferred.id) : "", idr_per_usd: "" }));
  }

  function setLine(i: number, field: keyof MatrixLine, value: string) {
    const next = [...lines]; next[i] = { ...next[i], [field]: value };
    if (field === "style_id") next[i].colorway_id = "";
    setLines(next);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault(); setSaving(true); setError(null);
    try {
      const idrPerUsd = Number(header.idr_per_usd);
      const payload = {
        customer_id: Number(header.customer_id), buyer_po_no: header.buyer_po_no || undefined,
        currency_id: header.currency_id ? Number(header.currency_id) : undefined,
        exchange_rate: currencyCode === "IDR" && idrPerUsd > 0 ? Number((1 / idrPerUsd).toFixed(12)) : undefined,
        order_date: header.order_date, ex_factory_date: header.ex_factory_date || undefined,
        tolerance_pct: header.tolerance_pct ? Number(header.tolerance_pct) : undefined,
        lines: lines.map((line) => ({ style_id: Number(line.style_id), colorway_id: Number(line.colorway_id), size_id: Number(line.size_id), qty: Number(line.qty), price: Number(line.price) })),
      };
      const so = await api.post<{ id: number; doc_no: string }>("/sales/orders", payload);
      router.push(`/sales/orders?created=${so.doc_no}`);
    } catch (err) { setError(err instanceof Error ? err.message : "Gagal menyimpan SO"); }
    finally { setSaving(false); }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  return (
    <form onSubmit={save} className="space-y-4">
      <div className="flex items-center justify-between"><div><h1 className="text-xl font-bold">Sales Order Baru</h1><p className="text-sm text-slate-500">Default USD; pilih IDR hanya untuk transaksi lokal.</p></div><button type="button" onClick={() => router.back()} className="rounded border px-3 py-1.5 text-sm">← Kembali</button></div>
      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <section className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4 md:grid-cols-4">
        <label className="text-sm"><span className="mb-1 block font-medium">Customer *</span><select value={header.customer_id} onChange={(e) => chooseCustomer(e.target.value)} required className={input}><option value="">— pilih —</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}</option>)}</select></label>
        <label className="text-sm"><span className="mb-1 block font-medium">PO Buyer</span><input value={header.buyer_po_no} onChange={(e) => setHeader({ ...header, buyer_po_no: e.target.value })} className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-medium">Currency *</span><select value={header.currency_id} onChange={(e) => setHeader({ ...header, currency_id: e.target.value, idr_per_usd: "" })} className={input}><option value="">USD — default/base</option>{currencies.filter((item) => item.code.toUpperCase() !== "USD").map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</select></label>
        {currencyCode === "IDR" && <label className="text-sm"><span className="mb-1 block font-medium">1 USD = IDR</span><input type="number" step="any" min="0" value={header.idr_per_usd} onChange={(e) => setHeader({ ...header, idr_per_usd: e.target.value })} placeholder="Kosong = kurs master" className={input} /></label>}
        <label className="text-sm"><span className="mb-1 block font-medium">Tgl Order *</span><input type="date" value={header.order_date} onChange={(e) => setHeader({ ...header, order_date: e.target.value })} required className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-medium">Ex-Factory</span><input type="date" value={header.ex_factory_date} onChange={(e) => setHeader({ ...header, ex_factory_date: e.target.value })} className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-medium">Toleransi %</span><input type="number" step="any" value={header.tolerance_pct} onChange={(e) => setHeader({ ...header, tolerance_pct: e.target.value })} className={input} /></label>
      </section>

      <section className="rounded-xl border bg-white p-4">
        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Matrix Lines (style × color × size) *</h2><button type="button" onClick={() => setLines([...lines, { style_id: "", colorway_id: "", size_id: "", qty: "", price: "" }])} className="rounded border px-3 py-1.5 text-sm">+ Baris</button></div>
        <div className="space-y-2">{lines.map((line, i) => { const styleColorways = colorways.filter((item) => item.style_id === Number(line.style_id)); return <div key={i} className="grid grid-cols-6 items-end gap-2 rounded-lg border bg-slate-50 p-3">
          <label className="text-xs"><span className="mb-1 block font-medium">Style</span><select value={line.style_id} onChange={(e) => setLine(i, "style_id", e.target.value)} required className={input}><option value="">—</option>{styles.map((item) => <option key={item.id} value={item.id}>{item.style_no}</option>)}</select></label>
          <label className="text-xs"><span className="mb-1 block font-medium">Colorway</span><select value={line.colorway_id} onChange={(e) => setLine(i, "colorway_id", e.target.value)} required disabled={!line.style_id} className={input}><option value="">—</option>{styleColorways.map((item) => <option key={item.id} value={item.id}>CW-{item.id}</option>)}</select></label>
          <label className="text-xs"><span className="mb-1 block font-medium">Size</span><select value={line.size_id} onChange={(e) => setLine(i, "size_id", e.target.value)} required className={input}><option value="">—</option>{sizes.map((item) => <option key={item.id} value={item.id}>{item.code}</option>)}</select></label>
          <label className="text-xs"><span className="mb-1 block font-medium">Qty</span><input type="number" step="any" min="0.0001" value={line.qty} onChange={(e) => setLine(i, "qty", e.target.value)} required className={input} /></label>
          <label className="text-xs"><span className="mb-1 block font-medium">Harga ({currencyCode})</span><input type="number" step="any" min="0" value={line.price} onChange={(e) => setLine(i, "price", e.target.value)} required className={input} /></label>
          <button type="button" onClick={() => setLines(lines.filter((_, x) => x !== i))} disabled={lines.length === 1} className="rounded border border-red-200 px-2 py-1.5 text-xs text-red-600 disabled:opacity-30">Hapus</button>
        </div>; })}</div>
      </section>
      <button disabled={saving} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">{saving ? "Menyimpan…" : `Simpan SO ${currencyCode} (DRAFT)`}</button>
    </form>
  );
}
