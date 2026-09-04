"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { ErrorState, Skeleton, StatusBadge } from "@/components/ui";

interface Measure { key: string; label: string; qty: number | null; status: string; authority: string }
interface Candidate {
  measure_key: string;
  candidate_source: string;
  quantity: number;
  status: string;
  lifecycle: string;
  existing_authority: string;
  downstream_usage: string;
}
interface Payload {
  production_output_authority: { status: string; business_rule: string; reason: string };
  qty_produced: { stored_value: number; warning: string };
  named_measures: Record<string, Measure>;
  candidate_matrix: Candidate[];
  lineage: { forward: string; authority_boundary: string };
  writes_performed: boolean;
  migration: string;
}

const fmt = (v: number | null) =>
  v === null ? "-" : Number(v).toLocaleString("id-ID", { maximumFractionDigits: 4 });

export function OutputAuthorityPanel({ productionOrderId }: { productionOrderId: string }) {
  const [data, setData] = useState<Payload | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    api
      .get<Payload>(`/production/orders/${productionOrderId}/output-authority`)
      .then((r) => {
        if (!cancelled) setData(r);
      })
      .catch((e) => {
        if (!cancelled) setError(e instanceof Error ? e.message : "Gagal memuat output authority");
      });
    return () => {
      cancelled = true;
    };
  }, [productionOrderId]);

  if (error) {
    return <ErrorState title="Gagal memuat output authority" message={error} />;
  }

  if (!data) {
    return (
      <section aria-busy="true" className="space-y-3 rounded-[var(--radius-surface)] border bg-white p-4">
        <Skeleton className="h-5 w-72" />
        <div className="grid gap-2 sm:grid-cols-3">
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
        </div>
        <p className="text-sm text-[var(--color-text-muted)]">Memuat named measures...</p>
      </section>
    );
  }

  return (
    <section className="space-y-4 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
      <div>
        <h2 className="font-semibold">Production Output - Separate Named Measures</h2>
        <p className="mt-1 flex flex-wrap items-center gap-2 text-sm font-medium text-emerald-700">
          {data.production_output_authority.business_rule}
          <StatusBadge status={data.production_output_authority.status} />
        </p>
        <p className="text-xs text-[var(--color-text-muted)]">{data.production_output_authority.reason}</p>
      </div>

      <div className="grid gap-2 sm:grid-cols-3">
        {Object.values(data.named_measures).map((m) => (
          <div key={m.key} className="rounded-[var(--radius-control)] bg-[var(--color-surface-subtle)] p-3">
            <p className="text-xs text-[var(--color-text-muted)]">{m.key}</p>
            <p className="font-semibold tabular-nums">{fmt(m.qty)}</p>
            <p className="mt-0.5 text-xs">
              <StatusBadge status={m.status} />
            </p>
          </div>
        ))}
      </div>

      <div className="rounded-[var(--radius-control)] border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
        qty_produced legacy: {fmt(data.qty_produced.stored_value)} - {data.qty_produced.warning}
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[800px] text-sm">
          <thead>
            <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
              <th className="py-2">Named Measure</th>
              <th className="py-2 text-right">Qty</th>
              <th className="py-2">Status</th>
              <th className="py-2">Scope</th>
            </tr>
          </thead>
          <tbody>
            {data.candidate_matrix.map((c) => (
              <tr key={c.measure_key} className="border-b last:border-0">
                <td className="py-2 font-medium">{c.measure_key}</td>
                <td className="py-2 text-right tabular-nums">{fmt(c.quantity)}</td>
                <td className="py-2">
                  <StatusBadge status={c.status} />
                </td>
                <td className="py-2 text-xs text-[var(--color-text-muted)]">{c.existing_authority}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-xs text-[var(--color-text-muted)]">{data.lineage.authority_boundary}</p>
    </section>
  );
}