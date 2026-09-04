"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { MetricCard, PageHeader, Select, StatusBadge } from "@/components/ui";

type Mo = { id: number; doc_no: string };
type Row = { id: number; valuation_stage?: string; component: string; receipt_quantity?: string; provisional_value: string };
type Component = {
  id: number;
  component: string;
  completeness_status: string;
  actual_cost: string;
  provisional_value: string;
  variance_amount: string;
  source_evidence: unknown;
};
type Costing = {
  id: number;
  costing_version: number;
  fg_received_quantity: string;
  actual_total_cost: string;
  actual_cost_per_pcs: string;
  standard_cost_per_pcs: string;
  provisional_fg_value: string;
  component_variance_total: string;
  currency: string;
  status: string;
  source_hash: string;
  components: Component[];
  freeze: { status: string; freeze_version: number } | null;
};
type Valuation = {
  policy: string;
  production_order: { id: number; doc_no: string; status: string };
  eligibility: { status?: string } | null;
  wip: { totals: Array<{ valuation_stage: string; component: string; quantity: string; value: string }> };
  fg: { events: Row[] };
  adjustments: unknown[];
  fail_closed_reason: string | null;
};

const money = (x: string | number) =>
  new Intl.NumberFormat("id-ID", { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(Number(x));

export default function CostingValuationPage() {
  const [mos, setMos] = useState<Mo[]>([]);
  const [mo, setMo] = useState("");
  const [v, setV] = useState<Valuation | null>(null);
  const [c, setC] = useState<Costing | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ data: Mo[] }>("/production/orders?per_page=100")
      .then((r) => setMos(r.data))
      .catch(() => {});
  }, []);

  async function load(id: string) {
    setMo(id); setError(null); setV(null); setC(null);
    if (!id) return;
    setLoading(true);
    try {
      const [a, b] = await Promise.all([
        api.get<Valuation>(`/finance/valuation/production-orders/${id}`),
        api.get<{ costing: Costing | null }>(`/finance/costing/mo/${id}/fg-actual`),
      ]);
      setV(a); setC(b.costing);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal memuat costing");
    } finally { setLoading(false); }
  }

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="WIP, FG & Actual Costing"
        description="D-06/D-07 provisional valuation and D-09 frozen actual cost per PCS."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select value={mo} onChange={(e) => load(e.target.value)} className="w-80">
          <option value="">- pilih MO -</option>
          {mos.map((x) => (
            <option key={x.id} value={x.id}>{x.doc_no}</option>
          ))}
        </Select>
        {loading && <span className="text-sm text-[var(--color-text-muted)]">Memuat...</span>}
      </div>

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}

      {v && (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <MetricCard
              label="MO"
              value={`${v.production_order.doc_no} - ${v.production_order.status}`}
            />
            <MetricCard label="Eligibility" value={v.eligibility?.status ?? "NOT APPROVED"} />
            <MetricCard label="FG Received Qty" value={c?.fg_received_quantity ?? "NOT CALCULATED"} />
            <MetricCard label="Freeze" value={c?.freeze?.status ?? "PARTIAL / NOT FINAL"} />
          </div>

          {(v.fail_closed_reason || (!c && mo)) && (
            <div className="rounded-[var(--radius-surface)] border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
              <b>FAIL CLOSED:</b> {v.fail_closed_reason ?? "D09_COSTING_NOT_CALCULATED"}
            </div>
          )}

          {c && (
            <>
              <div className="grid gap-3 md:grid-cols-4">
                <MetricCard label="Actual Total" value={`${c.currency} ${money(c.actual_total_cost)}`} />
                <MetricCard label="Actual / PCS" value={money(c.actual_cost_per_pcs)} />
                <MetricCard label="Standard / PCS" value={money(c.standard_cost_per_pcs)} />
                <MetricCard
                  label="Costing Version"
                  value={
                    <span className="flex items-center gap-2">
                      v{c.costing_version} <StatusBadge status={c.status} />
                    </span>
                  }
                />
              </div>

              <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
                <h2 className="font-semibold">D-09 Component Breakdown</h2>
                <table className="mt-2 w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                      <th className="py-2">Component</th>
                      <th className="py-2">Completeness</th>
                      <th className="py-2 text-right">Provisional</th>
                      <th className="py-2 text-right">Actual</th>
                      <th className="py-2 text-right">Variance</th>
                    </tr>
                  </thead>
                  <tbody>
                    {c.components.map((r) => (
                      <tr className="border-t" key={r.id}>
                        <td className="py-2">{r.component}</td>
                        <td className="py-2">{r.completeness_status}</td>
                        <td className="py-2 text-right tabular-nums">{money(r.provisional_value)}</td>
                        <td className="py-2 text-right tabular-nums">{money(r.actual_cost)}</td>
                        <td className="py-2 text-right tabular-nums">{money(r.variance_amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="mt-2 break-all text-xs text-[var(--color-text-muted)]">Source hash: {c.source_hash}</p>
              </section>
            </>
          )}

          <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <h2 className="font-semibold">Provisional WIP</h2>
            {v.wip.totals.map((r, i) => (
              <div className="grid grid-cols-4 border-t py-1.5 text-sm" key={i}>
                <span>{r.valuation_stage}</span>
                <span>{r.component}</span>
                <span className="text-right tabular-nums">{r.quantity}</span>
                <span className="text-right tabular-nums">{money(r.value)}</span>
              </div>
            ))}
          </section>
        </>
      )}
    </div>
  );
}