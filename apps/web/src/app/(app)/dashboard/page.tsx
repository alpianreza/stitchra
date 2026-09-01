"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import {
  Button,
  EmptyState,
  ErrorState,
  MetricCard,
  PageHeader,
  SectionHeader,
  Skeleton,
  StatusBadge,
} from "@/components/ui";

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

const formatNumber = (value: number) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(value);

function TextLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link href={href} className="text-xs font-semibold text-[var(--color-primary)] hover:underline">
      {children}
    </Link>
  );
}

function DashboardSkeleton() {
  return (
    <div aria-label="Memuat dashboard" aria-busy="true" className="space-y-6">
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, index) => (
          <div key={index} className="rounded-[var(--radius-surface)] border bg-white p-4">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="mt-3 h-8 w-32" />
            <Skeleton className="mt-2 h-3 w-20" />
          </div>
        ))}
      </div>
      <div className="grid gap-3 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, index) => <Skeleton key={index} className="h-28" />)}
      </div>
      <Skeleton className="h-56" />
    </div>
  );
}

export default function DashboardPage() {
  const [kpi, setKpi] = useState<Kpis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get<Kpis>("/dashboard/kpis")
      .then((result) => {
        setKpi(result);
        setLastUpdated(new Date());
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const manufacturingStatuses = useMemo(() => {
    if (!kpi) return [];
    return Object.entries(kpi.mo_by_status).sort(([, countA], [, countB]) => countB - countA);
  }, [kpi]);

  const maximumStatusCount = Math.max(1, ...manufacturingStatuses.map(([, count]) => count));

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Workspace"
        title="Dasbor Operasional"
        description="Ringkasan order, produksi, kualitas, approval, pengiriman, dan nilai persediaan."
        actions={<Button onClick={load} loading={loading}>Perbarui data</Button>}
      />

      {error && !kpi ? (
        <section className="overflow-hidden rounded-[var(--radius-surface)] border bg-white">
          <ErrorState title="Dashboard tidak dapat dimuat" message={error} onRetry={load} />
        </section>
      ) : loading && !kpi ? (
        <DashboardSkeleton />
      ) : kpi ? (
        <>
          {error && (
            <div role="alert" className="flex flex-col gap-2 rounded-[var(--radius-surface)] border border-amber-300 bg-[var(--color-warning-soft)] p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
              <span>Data terbaru gagal dimuat. Ringkasan sebelumnya tetap ditampilkan.</span>
              <Button size="sm" onClick={load}>Coba lagi</Button>
            </div>
          )}

          <section className="space-y-3" aria-labelledby="business-overview-title">
            <SectionHeader
              title="Ringkasan Bisnis"
              description="Volume order, output hari ini, work in progress, dan nilai persediaan."
              actions={lastUpdated ? <span className="text-xs text-[var(--color-text-muted)]">Diperbarui {lastUpdated.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}</span> : undefined}
            />
            <div id="business-overview-title" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <MetricCard
                label="Open Order"
                value={`${formatNumber(kpi.open_orders.count)} SO`}
                supportingText={`Nilai ${formatNumber(kpi.open_orders.value)}`}
                action={<TextLink href="/sales/orders">Lihat</TextLink>}
              />
              <MetricCard
                label="Output Hari Ini"
                value={formatNumber(kpi.today_output_pcs)}
                supportingText="pcs diproduksi hari ini"
                tone="success"
                action={<TextLink href="/production/orders">Produksi</TextLink>}
              />
              <MetricCard
                label="Work in Progress"
                value={formatNumber(kpi.wip_pcs)}
                supportingText="pcs dalam proses"
                tone="info"
                action={<TextLink href="/production/orders">Pantau</TextLink>}
              />
              <MetricCard
                label="Nilai Stok"
                value={formatNumber(kpi.stock_value)}
                supportingText="berdasarkan data persediaan"
                action={<TextLink href="/inventory/stock">Stok</TextLink>}
              />
            </div>
          </section>

          <section className="space-y-3" aria-labelledby="operational-attention-title">
            <SectionHeader title="Perlu Perhatian" description="Antrian dan exception yang memerlukan tindak lanjut operasional." />
            <div id="operational-attention-title" className="grid gap-3 lg:grid-cols-3">
              <MetricCard
                label="Approval Menunggu Anda"
                value={formatNumber(kpi.pending_my_approvals)}
                supportingText={kpi.pending_my_approvals > 0 ? "Dokumen menunggu keputusan" : "Tidak ada antrian approval"}
                tone={kpi.pending_my_approvals > 0 ? "warning" : "success"}
                action={<TextLink href="/approvals">Buka</TextLink>}
              />
              <MetricCard
                label="Pengiriman Overdue"
                value={formatNumber(kpi.overdue_deliveries)}
                supportingText={kpi.overdue_deliveries > 0 ? "Order melewati target pengiriman" : "Tidak ada pengiriman overdue"}
                tone={kpi.overdue_deliveries > 0 ? "danger" : "success"}
                action={<TextLink href="/sales/orders">Order</TextLink>}
              />
              <MetricCard
                label="QC Pass Rate · 7 Hari"
                value={kpi.qc_pass_rate_7d_pct === null ? "—" : `${formatNumber(kpi.qc_pass_rate_7d_pct)}%`}
                supportingText={kpi.qc_pass_rate_7d_pct === null ? "Belum ada data inspeksi" : "Hasil inspeksi tujuh hari terakhir"}
                tone={kpi.qc_pass_rate_7d_pct === null ? "neutral" : "info"}
                action={<TextLink href="/qc/inspections">QC</TextLink>}
              />
            </div>
          </section>

          <section className="space-y-3 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-raised)]">
            <SectionHeader
              title="Manufacturing Order Aktif"
              description="Distribusi manufacturing order berdasarkan tahap produksi saat ini."
              actions={<TextLink href="/production/orders">Lihat semua MO</TextLink>}
            />

            {manufacturingStatuses.length === 0 ? (
              <EmptyState title="Belum ada MO aktif" description="Manufacturing order aktif akan muncul setelah workflow produksi dimulai." />
            ) : (
              <div className="space-y-3">
                {manufacturingStatuses.map(([status, count]) => {
                  const width = Math.max(4, (count / maximumStatusCount) * 100);
                  return (
                    <div key={status} className="grid grid-cols-[minmax(110px,180px)_1fr_auto] items-center gap-3">
                      <StatusBadge status={status} />
                      <div className="h-2 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                        <div className="h-full rounded-full bg-[var(--color-primary)]" style={{ width: `${width}%` }} />
                      </div>
                      <strong className="min-w-8 text-right text-sm tabular-nums">{formatNumber(count)}</strong>
                    </div>
                  );
                })}
              </div>
            )}
          </section>
        </>
      ) : null}
    </div>
  );
}
