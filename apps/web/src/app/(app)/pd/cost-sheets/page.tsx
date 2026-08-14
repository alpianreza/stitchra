"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Style { id: number; style_no: string }
interface Line { id: number; code: string; name: string }
interface BomLineMaterial { id: number; material_id: number; material?: { code: string; name: string } }

interface CostSheet {
  id: number;
  doc_no: string;
  status: string;
  fabric_cost: string; trim_cost: string; cm_cost: string; overhead_cost: string;
  subcon_cost: string; other_cost: string; fob_price: string; margin_pct: string;
  lines?: { component_type: string; description: string; qty: string | null; rate: string | null; amount: string }[];
}

/** Cost Sheet — hitung FOB dari BOM+Routing APPROVED (BR-100), set harga, submit approval */
export default function CostSheetsPage() {
  const [styles, setStyles] = useState<Style[]>([]);
  const [lines_, setLines] = useState<Line[]>([]);

  const [styleId, setStyleId] = useState("");
  const [lineId, setLineId] = useState("");
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [bomMaterials, setBomMaterials] = useState<BomLineMaterial[]>([]);
  const [prices, setPrices] = useState<Record<number, string>>({});

  const [sheet, setSheet] = useState<CostSheet | null>(null);
  const [fobPrice, setFobPrice] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((r) => setStyles(r.data)).catch(() => {});
    api.get<{ data: Line[] }>("/master/lines?per_page=100").then((r) => setLines(r.data)).catch(() => {});
  }, []);

  async function loadBomMaterials(sid: string) {
    setStyleId(sid); setSheet(null); setBomMaterials([]); setPrices({});
    if (!sid) return;
    // Ambil BOM aktif via costing preview? Tidak ada endpoint list — gunakan material dari BOM
    // dengan memanfaatkan error compute: lebih praktis, ambil dari halaman BOM.
    // Di sini: coba fetch BOM versi terbaru lewat endpoint show tidak tersedia →
    // fallback: biarkan user isi harga material manual setelah compute gagal/sukses.
  }

  async function compute(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null); setSheet(null);
    try {
      const materialPrices: Record<number, number> = {};
      for (const [mid, p] of Object.entries(prices)) {
        if (p !== "") materialPrices[Number(mid)] = Number(p);
      }
      const result = await api.post<CostSheet>("/pd/cost-sheets/compute", {
        style_id: Number(styleId),
        line_id: Number(lineId),
        period,
        material_prices: materialPrices,
      });
      setSheet(result);
      setMessage(`Cost sheet ${result.doc_no} dihitung (DRAFT).`);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function setPrice() {
    if (!sheet || !fobPrice) return;
    setBusy(true); setError(null);
    try {
      const updated = await api.post<CostSheet>(`/pd/cost-sheets/${sheet.id}/price`, { fob_price: Number(fobPrice) });
      setSheet({ ...sheet, ...updated });
      setMessage(`FOB diset: ${fobPrice} (margin ${Number(updated.margin_pct)}%).`);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function submitForApproval() {
    if (!sheet) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/pd/cost-sheets/${sheet.id}/submit`, {});
      setMessage(`Cost sheet ${sheet.doc_no} masuk approval flow. Setelah APPROVED → jadi standard cost (BR-100).`);
      setSheet(null); setStyleId(""); setPrices({}); setFobPrice("");
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  const fmt = (v: string | number) => Number(v).toLocaleString("id-ID", { maximumFractionDigits: 4 });
  const totalCost = sheet
    ? Number(sheet.fabric_cost) + Number(sheet.trim_cost) + Number(sheet.cm_cost) + Number(sheet.overhead_cost) + Number(sheet.subcon_cost) + Number(sheet.other_cost)
    : 0;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-xl font-bold">Cost Sheet <span className="text-sm font-normal text-slate-500">(BR-100: FOB = Fabric + Trim + CM + OH)</span></h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <form onSubmit={compute} className="space-y-3 rounded-xl border bg-white p-4">
        <div className="grid grid-cols-3 gap-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Style *</span>
            <select value={styleId} onChange={(e) => loadBomMaterials(e.target.value)} required className={input} disabled={!!sheet}>
              <option value="">— pilih —</option>
              {styles.map((s) => <option key={s.id} value={s.id}>{s.style_no}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Line (untuk cost/min) *</span>
            <select value={lineId} onChange={(e) => setLineId(e.target.value)} required className={input} disabled={!!sheet}>
              <option value="">— pilih —</option>
              {lines_.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Periode rates *</span>
            <input value={period} onChange={(e) => setPeriod(e.target.value)} required pattern="\d{4}-\d{2}" className={input} disabled={!!sheet} />
          </label>
        </div>

        <div className="rounded-lg border bg-slate-50 p-3">
          <span className="mb-1 block text-xs font-medium text-slate-600">
            Harga material per UOM pakai (map: material_id → harga). Isi untuk material di BOM style ini.
          </span>
          <PriceMapEditor prices={prices} onChange={setPrices} disabled={!!sheet} />
        </div>

        {!sheet && (
          <button disabled={busy || !styleId || !lineId} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
            {busy ? "Menghitung…" : "Hitung Cost Sheet"}
          </button>
        )}
      </form>

      {sheet && (
        <section className="space-y-3 rounded-xl border-2 border-blue-200 bg-white p-4">
          <div className="flex items-center justify-between">
            <span className="font-mono font-bold">{sheet.doc_no}</span>
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{sheet.status}</span>
          </div>

          <table className="w-full text-sm">
            <tbody>
              <tr className="border-b"><td className="py-1.5">Fabric</td><td className="py-1.5 text-right">{fmt(sheet.fabric_cost)}</td></tr>
              <tr className="border-b"><td className="py-1.5">Trim</td><td className="py-1.5 text-right">{fmt(sheet.trim_cost)}</td></tr>
              <tr className="border-b"><td className="py-1.5">CM (SAM × cost/min)</td><td className="py-1.5 text-right">{fmt(sheet.cm_cost)}</td></tr>
              <tr className="border-b"><td className="py-1.5">Overhead (SAM × OH rate)</td><td className="py-1.5 text-right">{fmt(sheet.overhead_cost)}</td></tr>
              <tr className="font-bold"><td className="py-1.5">Total manufacturing cost</td><td className="py-1.5 text-right">{fmt(totalCost)}</td></tr>
            </tbody>
          </table>

          {sheet.lines && sheet.lines.length > 0 && (
            <details className="text-xs">
              <summary className="cursor-pointer font-medium text-slate-500">Rincian per komponen</summary>
              <table className="mt-2 w-full">
                <thead className="border-b text-left text-slate-500">
                  <tr><th className="py-1">Tipe</th><th className="py-1">Deskripsi</th><th className="py-1 text-right">Qty</th><th className="py-1 text-right">Rate</th><th className="py-1 text-right">Amount</th></tr>
                </thead>
                <tbody>
                  {sheet.lines.map((l, i) => (
                    <tr key={i} className="border-b last:border-0">
                      <td className="py-1">{l.component_type}</td>
                      <td className="py-1">{l.description}</td>
                      <td className="py-1 text-right">{l.qty ?? "—"}</td>
                      <td className="py-1 text-right">{l.rate ?? "—"}</td>
                      <td className="py-1 text-right">{fmt(l.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </details>
          )}

          {sheet.status === "DRAFT" && (
            <div className="flex items-end gap-3 border-t pt-3">
              <label className="text-sm">
                <span className="mb-1 block font-medium">FOB price *</span>
                <input type="number" step="any" min={totalCost} value={fobPrice} onChange={(e) => setFobPrice(e.target.value)} className={input} />
              </label>
              <button onClick={setPrice} disabled={busy || !fobPrice} className="rounded border px-4 py-1.5 text-sm disabled:opacity-50">
                Set FOB
              </button>
              <button onClick={submitForApproval} disabled={busy || Number(sheet.fob_price) <= 0} className="rounded bg-green-700 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                Submit Approval
              </button>
            </div>
          )}
          {sheet.status === "DRAFT" && Number(sheet.fob_price) <= 0 && (
            <p className="text-xs text-amber-600">Set FOB dulu sebelum submit (FOB ≥ total cost — divalidasi server).</p>
          )}
        </section>
      )}
    </div>
  );
}

/** Editor map material_id → harga (key-value sederhana) */
function PriceMapEditor({ prices, onChange, disabled }: {
  prices: Record<number, string>;
  onChange: (p: Record<number, string>) => void;
  disabled: boolean;
}) {
  const [materials, setMaterials] = useState<{ id: number; code: string; name: string }[]>([]);
  const [selId, setSelId] = useState("");
  const [price, setPrice] = useState("");

  useEffect(() => {
    api.get<{ data: { id: number; code: string; name: string }[] }>("/master/materials?per_page=500")
      .then((r) => setMaterials(r.data)).catch(() => {});
  }, []);

  return (
    <div className="space-y-2">
      {Object.entries(prices).map(([mid, p]) => {
        const m = materials.find((x) => x.id === Number(mid));
        return (
          <div key={mid} className="flex items-center gap-2 text-sm">
            <span className="flex-1"><span className="font-mono">{m?.code ?? `#${mid}`}</span> {m?.name}</span>
            <span className="font-medium">{p}</span>
            {!disabled && (
              <button type="button" onClick={() => { const n = { ...prices }; delete n[Number(mid)]; onChange(n); }} className="text-xs text-red-600">✕</button>
            )}
          </div>
        );
      })}
      {!disabled && (
        <div className="flex items-end gap-2">
          <select value={selId} onChange={(e) => setSelId(e.target.value)} className="flex-1 rounded border px-2 py-1.5 text-sm">
            <option value="">— material —</option>
            {materials.map((m) => <option key={m.id} value={m.id}>{m.code} — {m.name}</option>)}
          </select>
          <input type="number" step="any" min="0" placeholder="harga" value={price} onChange={(e) => setPrice(e.target.value)} className="w-32 rounded border px-2 py-1.5 text-sm" />
          <button
            type="button"
            onClick={() => { if (selId && price) { onChange({ ...prices, [Number(selId)]: price }); setSelId(""); setPrice(""); } }}
            className="rounded border px-3 py-1.5 text-sm"
          >+ Tambah</button>
        </div>
      )}
    </div>
  );
}
