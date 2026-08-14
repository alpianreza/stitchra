"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Mo { id: number; doc_no: string; style?: { style_no: string } }
interface Costing {
  mo: string;
  period: string;
  output_pcs: number;
  actual: { material: number; labor: number; overhead: number; subcon: number; total: number; per_pcs: number };
  variance_vs_standard: null | {
    material: number; labor: number; overhead: number; subcon: number; total: number;
    standard_total: number; cost_sheet: string;
  };
}

/** Actual costing per MO — actual vs standard + variance (BR-080/081) */
export default function CostingPage() {
  const [mos, setMos] = useState<Mo[]>([]);
  const [moId, setMoId] = useState("");
  const [result, setResult] = useState<Costing | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((r) => setMos(r.data)).catch(() => {});
  }, []);

  async function load(id: string) {
    setMoId(id); setResult(null); setError(null);
    if (!id) return;
    setLoading(true);
    try {
      setResult(await api.get<Costing>(`/finance/costing/mo/${id}/actual`));
    } catch (e: any) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }

  const fmt = (n: number) => new Intl.NumberFormat("id-ID", { minimumFractionDigits: 4 }).format(n);
  const varianceBadge = (v: number) =>
    v > 0 ? <span className="text-red-600">+{fmt(v)}</span> : v < 0 ? <span className="text-green-600">{fmt(v)}</span> : <span className="text-slate-500">0</span>;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Costing Aktual per MO</h1>

      <label className="block max-w-md text-sm">
        <span className="mb-1 block font-medium">Pilih MO</span>
        <select value={moId} onChange={(e) => load(e.target.value)} className="w-full rounded border px-2 py-1.5 text-sm">
          <option value="">— pilih MO —</option>
          {mos.map((m) => <option key={m.id} value={m.id}>{m.doc_no} ({m.style?.style_no})</option>)}
        </select>
      </label>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {loading && <p className="text-slate-500">Menghitung…</p>}

      {result && (
        <div className="space-y-4">
          <section className="rounded-xl border bg-white p-4">
            <h2 className="mb-2 font-semibold">{result.mo} — output {fmt(result.output_pcs)} pcs (periode {result.period})</h2>
            <table className="w-full text-sm">
              <thead className="border-b text-left text-xs text-slate-500">
                <tr>
                  <th className="py-1">Komponen</th>
                  <th className="py-1 text-right">Aktual</th>
                  {result.variance_vs_standard && <th className="py-1 text-right">Variance vs Standard</th>}
                </tr>
              </thead>
              <tbody>
                <tr className="border-b"><td className="py-1.5">Material</td><td className="py-1.5 text-right">{fmt(result.actual.material)}</td>{result.variance_vs_standard && <td className="py-1.5 text-right">{varianceBadge(result.variance_vs_standard.material)}</td>}</tr>
                <tr className="border-b"><td className="py-1.5">Labor (CM)</td><td className="py-1.5 text-right">{fmt(result.actual.labor)}</td>{result.variance_vs_standard && <td className="py-1.5 text-right">{varianceBadge(result.variance_vs_standard.labor)}</td>}</tr>
                <tr className="border-b"><td className="py-1.5">Overhead</td><td className="py-1.5 text-right">{fmt(result.actual.overhead)}</td>{result.variance_vs_standard && <td className="py-1.5 text-right">{varianceBadge(result.variance_vs_standard.overhead)}</td>}</tr>
                <tr className="border-b"><td className="py-1.5">Subcon</td><td className="py-1.5 text-right">{fmt(result.actual.subcon)}</td>{result.variance_vs_standard && <td className="py-1.5 text-right">{varianceBadge(result.variance_vs_standard.subcon)}</td>}</tr>
                <tr className="font-bold">
                  <td className="py-1.5">Total</td>
                  <td className="py-1.5 text-right">{fmt(result.actual.total)}</td>
                  {result.variance_vs_standard && <td className="py-1.5 text-right">{varianceBadge(result.variance_vs_standard.total)}</td>}
                </tr>
                <tr><td className="py-1.5 text-slate-500">Per pcs</td><td className="py-1.5 text-right font-semibold">{fmt(result.actual.per_pcs)}</td><td></td></tr>
              </tbody>
            </table>
            {result.variance_vs_standard && (
              <p className="mt-2 text-xs text-slate-500">
                Standard: {result.variance_vs_standard.cost_sheet} (total {fmt(result.variance_vs_standard.standard_total)}) — variance positif (merah) = di atas standard.
              </p>
            )}
            {!result.variance_vs_standard && (
              <p className="mt-2 text-xs text-amber-600">Belum ada cost sheet APPROVED untuk style ini — variance tidak dihitung (BR-100).</p>
            )}
          </section>
        </div>
      )}
    </div>
  );
}
