"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Candidate {
  candidate_source: string;
  quantity: number;
  lifecycle: string;
  existing_authority: string;
  downstream_usage: string;
  status: "DEFINED" | "PARTIAL" | "NOT DEFINED" | "LEGACY" | "DERIVED";
}

interface OutputAuthority {
  production_order: { doc_no: string; status: string; qty_planned: number };
  production_output_authority: { status: string; authoritative_source: string | null; authoritative_qty: number | null; reason: string };
  qty_produced: { stored_value: number; status: string; authoritative: boolean; warning: string };
  production_completion: { status: string; reason: string; current_status_progression: string };
  candidate_matrix: Candidate[];
  partial_production: { status: string; existing_behavior: string; reason: string };
  defect_rework_scrap: { status: string; reason: string };
  boundaries: Record<string, string>;
  lineage: { forward: string; reverse: string; authority_boundary: string };
  writes_performed: boolean;
  migration: string;
}

const statusClass: Record<string, string> = {
  DEFINED: "bg-emerald-100 text-emerald-800",
  PARTIAL: "bg-amber-100 text-amber-800",
  DERIVED: "bg-blue-100 text-blue-800",
  LEGACY: "bg-orange-100 text-orange-800",
  "NOT DEFINED": "bg-slate-200 text-slate-700",
};

export function OutputAuthorityPanel({ productionOrderId }: { productionOrderId: string }) {
  const [data, setData] = useState<OutputAuthority | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<OutputAuthority>(`/production/orders/${productionOrderId}/output-authority`)
      .then(setData)
      .catch((exception) => setError(exception.message));
  }, [productionOrderId]);

  const fmt = (value: number | null) => value === null ? "—" : Number(value).toLocaleString("id-ID", { maximumFractionDigits: 4 });

  if (error) return <section className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Output authority tidak dapat dimuat: {error}</section>;
  if (!data) return <section className="rounded-xl border bg-white p-4 text-sm text-slate-500">Memuat production output authority…</section>;

  return (
    <section className="space-y-4 rounded-xl border bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-semibold">Production Completion / Output</h2>
          <p className="mt-1 text-sm font-medium text-slate-700">⚪ Production Output Authority — NOT DEFINED</p>
          <p className="mt-1 max-w-3xl text-xs text-slate-500">{data.production_output_authority.reason}</p>
        </div>
        <div className="text-right text-xs text-slate-500">
          <p>Read-only evidence</p>
          <p>Migration: {data.migration} · Writes: {data.writes_performed ? "YES" : "NONE"}</p>
        </div>
      </div>

      <dl className="grid gap-3 sm:grid-cols-4">
        <div className="rounded-lg bg-slate-50 p-3"><dt className="text-xs text-slate-500">Planned qty</dt><dd className="mt-1 font-semibold tabular-nums">{fmt(data.production_order.qty_planned)}</dd></div>
        <div className="rounded-lg bg-orange-50 p-3"><dt className="text-xs text-orange-700">qty_produced (legacy)</dt><dd className="mt-1 font-semibold tabular-nums text-orange-800">{fmt(data.qty_produced.stored_value)}</dd></div>
        <div className="rounded-lg bg-slate-50 p-3"><dt className="text-xs text-slate-500">Authoritative output</dt><dd className="mt-1 font-semibold">{data.production_output_authority.authoritative_source ?? "NOT DEFINED"}</dd></div>
        <div className="rounded-lg bg-slate-50 p-3"><dt className="text-xs text-slate-500">Completion lifecycle</dt><dd className="mt-1 font-semibold">{data.production_completion.status}</dd></div>
      </dl>

      <div className="rounded-lg border border-orange-200 bg-orange-50 p-3 text-xs text-orange-800">
        {data.qty_produced.warning}. Nilai ini tidak dipromosikan menjadi output authority, completion quantity, FG valuation, atau cost-per-unit denominator.
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[1000px] text-sm">
          <thead className="border-b text-left text-xs text-slate-500"><tr><th className="py-2">Candidate Source</th><th className="py-2 text-right">Quantity</th><th className="py-2">Lifecycle</th><th className="py-2">Existing Authority</th><th className="py-2">Downstream Usage</th><th className="py-2">Status</th></tr></thead>
          <tbody>{data.candidate_matrix.map((candidate) => (
            <tr key={candidate.candidate_source} className="border-b align-top last:border-0">
              <td className="py-2 pr-3 font-medium">{candidate.candidate_source}</td>
              <td className="py-2 pr-3 text-right tabular-nums">{fmt(candidate.quantity)}</td>
              <td className="py-2 pr-3 text-xs text-slate-600">{candidate.lifecycle}</td>
              <td className="py-2 pr-3 text-xs text-slate-600">{candidate.existing_authority}</td>
              <td className="py-2 pr-3 text-xs text-slate-600">{candidate.downstream_usage}</td>
              <td className="py-2"><span className={`rounded px-2 py-1 text-xs font-medium ${statusClass[candidate.status] ?? statusClass["NOT DEFINED"]}`}>{candidate.status}</span></td>
            </tr>
          ))}</tbody>
        </table>
      </div>

      <div className="grid gap-3 md:grid-cols-2">
        <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><p className="font-semibold text-slate-800">Forward lineage</p><p className="mt-1">{data.lineage.forward}</p></div>
        <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><p className="font-semibold text-slate-800">Reverse trace</p><p className="mt-1">{data.lineage.reverse}</p></div>
      </div>
      <div className="grid gap-2 text-xs text-slate-600 md:grid-cols-2">
        <p><b>Partial production:</b> {data.partial_production.reason}</p>
        <p><b>Defect/Rework/Scrap:</b> {data.defect_rework_scrap.reason}</p>
        <p><b>QC:</b> {data.boundaries.qc}</p>
        <p><b>Actual Cost:</b> {data.boundaries.actual_cost}</p>
      </div>
    </section>
  );
}
