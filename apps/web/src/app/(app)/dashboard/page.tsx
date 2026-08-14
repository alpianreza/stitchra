"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Kpis {
  open_orders: { count: number; value: number };
  mo_by_status: Record<string, number>;
  today_output_pcs: number;
  wip_pcs: number;
  qc_pass_rate_7d_pct: number | null;
  pending_my_approvals: number;
  overdue_deliveries: number;
  stock_value: number;
}

function Card({ label, value, warn = false }: { label: string; value: string | number; warn?: boolean }) {
  return (
    <div className={`rounded-xl border bg-white p-4 ${warn ? "border-red-300" : ""}`}>
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-2xl font-bold ${warn ? "text-red-600" : ""}`}>{value}</div>
    </div>
  );
}

const fmt = (n: number) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(n);

export default function DashboardPage() {
  const [kpi, setKpi] = useState<Kpis | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<Kpis>("/dashboard/kpis").then(setKpi).catch((e) => setError(e.message));
  }, []);

  if (error) return <p className="text-red-600">{error}</p>;
  if (!kpi) return <p className="text-slate-500">Memuat…</p>;

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-bold">Dasbor</h1>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <Card label="Open Order" value={`${kpi.open_orders.count} SO`} />
        <Card label="Nilai Open Order" value={fmt(kpi.open_orders.value)} />
        <Card label="Output Hari Ini (pcs)" value={fmt(kpi.today_output_pcs)} />
        <Card label="WIP (pcs)" value={fmt(kpi.wip_pcs)} />
        <Card label="QC Pass Rate 7 hari" value={kpi.qc_pass_rate_7d_pct === null ? "—" : `${kpi.qc_pass_rate_7d_pct}%`} />
        <Card label="Approval Menunggu Anda" value={kpi.pending_my_approvals} warn={kpi.pending_my_approvals > 0} />
        <Card label="Pengiriman Overdue" value={kpi.overdue_deliveries} warn={kpi.overdue_deliveries > 0} />
        <Card label="Nilai Stok" value={fmt(kpi.stock_value)} />
      </div>

      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">MO Aktif per Status</h2>
        {Object.keys(kpi.mo_by_status).length === 0 ? (
          <p className="text-sm text-slate-500">Belum ada MO aktif.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {Object.entries(kpi.mo_by_status).map(([status, count]) => (
              <span key={status} className="rounded-full bg-slate-100 px-3 py-1 text-sm">
                {status}: <b>{count}</b>
              </span>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
