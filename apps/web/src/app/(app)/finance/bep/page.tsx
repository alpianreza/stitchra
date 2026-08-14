"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Style { id: number; style_no: string }
interface BepResult {
  bep_qty: number;
  bep_revenue: number;
  contribution_margin_per_unit: number;
  contribution_margin_ratio: number | null;
  cost_sheet?: string;
  period?: string;
  styles_count?: number;
}

/** BEP (BR-104) — per style & factory-wide. Domain Accounting. */
export default function BepPage() {
  const [styles, setStyles] = useState<Style[]>([]);
  const [styleId, setStyleId] = useState("");
  const [fixedShare, setFixedShare] = useState("");
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [fixedFactory, setFixedFactory] = useState("");
  const [styleResult, setStyleResult] = useState<BepResult | null>(null);
  const [factoryResult, setFactoryResult] = useState<BepResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((r) => setStyles(r.data)).catch(() => {});
  }, []);

  async function calcStyle(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setStyleResult(null);
    try {
      setStyleResult(await api.post<BepResult>(`/finance/bep/style/${styleId}`, { fixed_cost_share: Number(fixedShare) }));
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function calcFactory(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setFactoryResult(null);
    try {
      setFactoryResult(await api.post<BepResult>("/finance/bep/factory", { period, fixed_cost: Number(fixedFactory) }));
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  const fmt = (n: number) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(n);

  function ResultCard({ r, title }: { r: BepResult; title: string }) {
    return (
      <div className="rounded-xl border border-green-200 bg-green-50 p-4 text-sm">
        <h3 className="mb-2 font-semibold text-green-800">{title}</h3>
        <div className="grid grid-cols-2 gap-2">
          <div>BEP (unit): <b>{fmt(r.bep_qty)}</b> pcs</div>
          <div>BEP (revenue): <b>{fmt(r.bep_revenue)}</b></div>
          <div>Contribution margin/unit: <b>{fmt(r.contribution_margin_per_unit)}</b></div>
          <div>CM ratio: <b>{r.contribution_margin_ratio !== null ? `${(r.contribution_margin_ratio * 100).toFixed(1)}%` : "—"}</b></div>
          {r.cost_sheet && <div className="col-span-2 text-xs text-slate-500">Berdasarkan cost sheet: {r.cost_sheet}</div>}
          {r.styles_count && <div className="col-span-2 text-xs text-slate-500">{r.styles_count} style aktif (rata-rata)</div>}
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-xl font-bold">Break-Even Point (BEP)</h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      <form onSubmit={calcStyle} className="space-y-3 rounded-xl border bg-white p-4">
        <h2 className="font-semibold">Per Style</h2>
        <div className="grid grid-cols-2 gap-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Style *</span>
            <select value={styleId} onChange={(e) => setStyleId(e.target.value)} required className={input}>
              <option value="">— pilih style —</option>
              {styles.map((s) => <option key={s.id} value={s.id}>{s.style_no}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Fixed cost dialokasikan *</span>
            <input type="number" step="any" min="0" value={fixedShare} onChange={(e) => setFixedShare(e.target.value)} required className={input} />
          </label>
        </div>
        <button disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">Hitung BEP Style</button>
        {styleResult && <ResultCard r={styleResult} title={`BEP — style terpilih`} />}
      </form>

      <form onSubmit={calcFactory} className="space-y-3 rounded-xl border bg-white p-4">
        <h2 className="font-semibold">Factory-wide per Bulan</h2>
        <div className="grid grid-cols-2 gap-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Periode *</span>
            <input value={period} onChange={(e) => setPeriod(e.target.value)} required pattern="\d{4}-\d{2}" className={input} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Fixed cost pabrik per bulan *</span>
            <input type="number" step="any" min="0" value={fixedFactory} onChange={(e) => setFixedFactory(e.target.value)} required className={input} />
          </label>
        </div>
        <button disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">Hitung BEP Pabrik</button>
        {factoryResult && <ResultCard r={factoryResult} title={`BEP — pabrik (${factoryResult.period})`} />}
      </form>
    </div>
  );
}
