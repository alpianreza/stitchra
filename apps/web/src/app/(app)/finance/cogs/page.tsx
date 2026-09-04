"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, MetricCard, PageHeader, Select, StatusBadge } from "@/components/ui";

type Shipment = { id: number; doc_no: string; status: string };
type Line = {
  id: number;
  shipment_line_id: number;
  shipment_inventory_valuation_id: number;
  quantity: string;
  unit_cost: string;
  base_cogs: string;
  currency: string;
  shipment_movement_id: number;
  shipment_ledger_id: number;
  production_receipt_movement_id: number;
};
type Result = {
  status: string;
  cogs?: {
    id: number;
    base_cogs_total: string;
    currency: string;
    posting_date: string;
    gl_period: string;
    status: string;
    lines: Line[];
    journal?: { id: number; doc_no: string };
  };
  journal?: { id: number; doc_no: string };
  d11_handoff?: { correction: string };
};

const n = (v: string | number, d = 4) =>
  Number(v).toLocaleString("id-ID", { minimumFractionDigits: d, maximumFractionDigits: d });

export default function ShipmentBaseCogsPage() {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [id, setId] = useState("");
  const [data, setData] = useState<Result | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100&status=SHIPPED")
      .then((r) => setShipments(r.data))
      .catch((e) => setError(e instanceof Error ? e.message : "Gagal memuat shipment"));
  }, []);

  async function load(x: string) {
    setId(x); setData(null); setError(null); setLoading(false);
    if (!x) return;
    setLoading(true);
    try {
      setData(await api.get<Result>(`/finance/cogs/shipments/${x}`));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal memuat COGS");
    } finally {
      setLoading(false);
    }
  }

  async function post() {
    if (!id) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/finance/cogs/shipments/${id}/post`, {});
      await load(id);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal posting COGS");
    } finally { setBusy(false); }
  }

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="Shipment Base COGS"
        description="D-10 accounting recognition sourced only from immutable D-08 Shipment Inventory Cost."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select value={id} onChange={(e) => load(e.target.value)} className="w-80">
          <option value="">- pilih SHIPPED Shipment -</option>
          {shipments.map((s) => (
            <option key={s.id} value={s.id}>{s.doc_no}</option>
          ))}
        </Select>
        <Button
          variant="primary"
          loading={busy}
          disabled={!id || busy || data?.status === "POSTED" || data?.status === "ZERO_COST"}
          onClick={post}
        >
          Post Base COGS
        </Button>
        {loading && <span className="text-sm text-[var(--color-text-muted)]">Memuat...</span>}
      </div>

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}

      {!id && !error && (
        <p className="rounded-[var(--radius-surface)] border bg-white p-4 text-sm text-[var(--color-text-muted)]">
          Pilih shipment berstatus SHIPPED untuk melihat dan memposting Base COGS.
        </p>
      )}

      {data?.cogs && (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <MetricCard label="Status" value={<StatusBadge status={data.cogs.status} />} />
            <MetricCard label="BASE COGS" value={`${data.cogs.currency} ${n(data.cogs.base_cogs_total)}`} />
            <MetricCard label="Posting Date / Period" value={`${data.cogs.posting_date} / ${data.cogs.gl_period}`} />
            <MetricCard label="Journal" value={data.journal?.doc_no ?? "ZERO-COST / NO GL LINES"} />
          </div>
          <div className="overflow-auto rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                  <th className="py-2">Shipment Line</th>
                  <th className="py-2">D-08</th>
                  <th className="py-2 text-right">Qty</th>
                  <th className="py-2 text-right">D-08 Unit Cost</th>
                  <th className="py-2 text-right">BASE COGS</th>
                  <th className="py-2">ITS Lineage</th>
                </tr>
              </thead>
              <tbody>
                {data.cogs.lines.map((x) => (
                  <tr className="border-t" key={x.id}>
                    <td className="py-2">#{x.shipment_line_id}</td>
                    <td className="py-2">#{x.shipment_inventory_valuation_id}</td>
                    <td className="py-2 text-right tabular-nums">{n(x.quantity)}</td>
                    <td className="py-2 text-right tabular-nums">{n(x.unit_cost, 6)}</td>
                    <td className="py-2 text-right tabular-nums">{x.currency} {n(x.base_cogs)}</td>
                    <td className="py-2 text-xs text-[var(--color-text-muted)]">
                      Receipt #{x.production_receipt_movement_id} - Shipment #{x.shipment_movement_id} / Ledger #{x.shipment_ledger_id}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="mt-3 text-xs text-[var(--color-text-muted)]">
              Correction: {data.d11_handoff?.correction ?? "DEFERRED_TO_D11"}
            </p>
          </div>
        </>
      )}
    </div>
  );
}