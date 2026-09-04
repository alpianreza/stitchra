"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface MatrixLine {
  id: number | null;
  sales_order_line_id: number;
  qty_planned: string;
  colorway?: { color?: { code?: string; name?: string } };
  size?: { code?: string };
}

interface MatrixResponse {
  source: "MO_SNAPSHOT" | "LEGACY_SO_FALLBACK";
  qty_planned: string;
  matrix_total: string;
  data: MatrixLine[];
}

export function MoMatrixPanel({ productionOrderId }: { productionOrderId: string }) {
  const [matrix, setMatrix] = useState<MatrixResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<MatrixResponse>(`/production/orders/${productionOrderId}/matrix`)
      .then(setMatrix)
      .catch((e) => setError(e instanceof Error ? e.message : "Gagal memuat matrix MO"));
  }, [productionOrderId]);

  const fmt = (value: string) => Number(value).toLocaleString("id-ID", { maximumFractionDigits: 4 });

  return (
    <section className="overflow-x-auto rounded-xl border bg-white p-4">
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="font-semibold">MO Matrix — Colorway × Size</h2>
          <p className="text-xs text-slate-500">BR-020: matrix MO baru disalin dari Sales Order CONFIRMED dan menjadi ceiling Cutting.</p>
        </div>
        {matrix && (
          <span className={`rounded px-2 py-1 text-xs font-medium ${matrix.source === "MO_SNAPSHOT" ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"}`}>
            {matrix.source === "MO_SNAPSHOT" ? "MO Snapshot" : "Legacy SO Fallback"}
          </span>
        )}
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {!matrix && !error && <p className="text-sm text-slate-500">Memuat matrix…</p>}

      {matrix && (
        <>
          {matrix.source === "LEGACY_SO_FALLBACK" && (
            <p className="mb-3 rounded bg-amber-50 p-3 text-xs text-amber-800">
              MO historis ini belum memiliki row mo_lines. Data ditampilkan dari matrix SO tanpa backfill atau rewrite histori.
            </p>
          )}
          <div className="mb-3 grid gap-2 sm:grid-cols-3">
            <div className="rounded border bg-slate-50 p-2 text-sm"><span className="block text-xs text-slate-500">Qty MO</span><b>{fmt(matrix.qty_planned)}</b></div>
            <div className="rounded border bg-slate-50 p-2 text-sm"><span className="block text-xs text-slate-500">Total matrix</span><b>{fmt(matrix.matrix_total)}</b></div>
            <div className="rounded border bg-slate-50 p-2 text-sm"><span className="block text-xs text-slate-500">Matrix lines</span><b>{matrix.data.length}</b></div>
          </div>
          <table className="w-full min-w-[620px] text-sm">
            <thead className="border-b text-left text-xs text-slate-500">
              <tr><th className="py-1">Colorway</th><th className="py-1">Size</th><th className="py-1 text-right">Qty Planned</th><th className="py-1">Source SO Line</th></tr>
            </thead>
            <tbody>
              {matrix.data.map((line) => (
                <tr key={`${line.sales_order_line_id}:${line.size?.code ?? "size"}`} className="border-b last:border-0">
                  <td className="py-1.5">{line.colorway?.color?.code ?? "-"} — {line.colorway?.color?.name ?? "-"}</td>
                  <td className="py-1.5 font-mono">{line.size?.code ?? "-"}</td>
                  <td className="py-1.5 text-right font-medium">{fmt(line.qty_planned)}</td>
                  <td className="py-1.5 font-mono text-xs">SO-L{line.sales_order_line_id}</td>
                </tr>
              ))}
              {matrix.data.length === 0 && <tr><td colSpan={4} className="py-4 text-center text-slate-500">Matrix tidak tersedia.</td></tr>}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}
