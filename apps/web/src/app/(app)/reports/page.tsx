"use client";

import { useEffect, useState } from "react";
import { getToken } from "@/lib/auth";
import { api } from "@/lib/api";

interface ReportResult {
  columns: string[];
  rows: Record<string, any>[];
  verdict_summary?: any[];
}

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

/** Pusat laporan — 8 report inti + export CSV (Phase 9) */
export default function ReportsPage() {
  const [reports, setReports] = useState<string[]>([]);
  const [active, setActive] = useState<string | null>(null);
  const [result, setResult] = useState<ReportResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ data: string[] }>("/reporting/reports").then((r) => setReports(r.data)).catch((e) => setError(e.message));
  }, []);

  async function run(name: string) {
    setLoading(true); setError(null); setActive(name);
    try {
      setResult(await api.get<ReportResult>(`/reporting/reports/${name}`));
    } catch (e: any) {
      setError(e.message);
      setResult(null);
    } finally {
      setLoading(false);
    }
  }

  async function exportCsv() {
    if (!active) return;
    const res = await fetch(`${API_URL}/api/reporting/reports/${active}/export`, {
      headers: { Authorization: `Bearer ${getToken()}`, "X-Company-Id": localStorage.getItem("stitchra_company") ?? "" },
    });
    if (!res.ok) { setError(`Export gagal: HTTP ${res.status}`); return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${active}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Laporan</h1>

      <div className="flex flex-wrap gap-2">
        {reports.map((r) => (
          <button
            key={r}
            onClick={() => run(r)}
            className={`rounded border px-3 py-1.5 text-sm ${active === r ? "border-slate-900 bg-slate-900 text-white" : "hover:bg-slate-50"}`}
          >
            {r}
          </button>
        ))}
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {loading && <p className="text-slate-500">Memuat…</p>}

      {result && (
        <div className="space-y-3 rounded-xl border bg-white p-4">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold">{active} — {result.rows.length} baris</h2>
            <button onClick={exportCsv} className="rounded border px-3 py-1.5 text-sm hover:bg-slate-50">⬇ Export CSV</button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b bg-slate-50 text-left">
                <tr>{result.columns.map((c) => <th key={c} className="px-3 py-2 font-medium">{c}</th>)}</tr>
              </thead>
              <tbody>
                {result.rows.map((row, i) => (
                  <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                    {result.columns.map((c) => <td key={c} className="px-3 py-2">{row[c] ?? "—"}</td>)}
                  </tr>
                ))}
                {result.rows.length === 0 && (
                  <tr><td colSpan={result.columns.length} className="px-3 py-6 text-center text-slate-500">Tidak ada data.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
