"use client";

import { useCallback, useEffect, useState } from "react";
import { getToken } from "@/lib/auth";
import { api } from "@/lib/api";
import { Button, DataTable, EmptyState, PageHeader, type DataTableColumn } from "@/components/ui";

interface ReportResult { columns: string[]; rows: Record<string, any>[]; verdict_summary?: any[] }
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export default function ReportsPage() {
  const [reports, setReports] = useState<string[]>([]);
  const [active, setActive] = useState<string | null>(null);
  const [result, setResult] = useState<ReportResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [catalogLoading, setCatalogLoading] = useState(true);

  const loadCatalog = useCallback(() => {
    setCatalogLoading(true); setError(null);
    api.get<{ data: string[] }>("/reporting/reports").then((response) => setReports(response.data)).catch((requestError) => setError(requestError.message)).finally(() => setCatalogLoading(false));
  }, []);
  useEffect(loadCatalog, [loadCatalog]);

  async function run(name: string) {
    setLoading(true); setError(null); setActive(name);
    try { setResult(await api.get<ReportResult>(`/reporting/reports/${name}`)); }
    catch (requestError: any) { setError(requestError.message); setResult(null); }
    finally { setLoading(false); }
  }

  async function exportCsv() {
    if (!active) return;
    setError(null);
    const response = await fetch(`${API_URL}/api/reporting/reports/${active}/export`, { headers: { Authorization: `Bearer ${getToken()}`, "X-Company-Id": localStorage.getItem("stitchra_company") ?? "" } });
    if (!response.ok) { setError(`Export gagal: HTTP ${response.status}`); return; }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url; anchor.download = `${active}.csv`; anchor.click(); URL.revokeObjectURL(url);
  }

  const columns: DataTableColumn<Record<string, any>>[] = (result?.columns ?? []).map((column) => ({
    key: column,
    header: column,
    cell: (row) => {
      const value = row[column];
      if (value === null || value === undefined || value === "") return "—";
      return typeof value === "object" ? JSON.stringify(value) : String(value);
    },
  }));

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Analytics" title="Laporan" description="Jalankan laporan operasional dan ekspor hasil yang sedang ditampilkan ke CSV." actions={result && active ? <Button onClick={exportCsv}>Export CSV</Button> : undefined} />
      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-red-200 bg-[var(--color-danger-soft)] p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-raised)]">
        <h2 className="text-sm font-semibold">Pilih laporan</h2>
        <div className="mt-3 flex flex-wrap gap-2">
          {catalogLoading ? <span className="text-sm text-[var(--color-text-muted)]">Memuat katalog laporan…</span> : reports.map((report) => <Button key={report} variant={active === report ? "primary" : "secondary"} onClick={() => run(report)} loading={loading && active === report}>{report}</Button>)}
          {!catalogLoading && reports.length === 0 && <Button onClick={loadCatalog}>Muat ulang</Button>}
        </div>
      </section>
      {!active && !catalogLoading ? <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white"><EmptyState title="Pilih laporan untuk memulai" description="Hasil laporan akan ditampilkan sebagai tabel dan dapat diekspor ke CSV." /></section> : active ? <DataTable caption={`Hasil laporan ${active}`} columns={columns} rows={result?.rows ?? []} getRowKey={(_, index?: number) => index ?? 0} loading={loading} error={!result && error ? error : null} onRetry={() => run(active)} emptyTitle="Tidak ada data" emptyDescription="Laporan ini tidak menghasilkan baris untuk kondisi saat ini." minWidth={`${Math.max(720, columns.length * 150)}px`} /> : null}
    </div>
  );
}
