"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { MetricCard, PageHeader, Select, StatusBadge } from "@/components/ui";

type Shipment = { id: number; doc_no: string; status: string };
type Row = {
  id: number;
  shipment_line_id: number;
  style_id: number;
  colorway_id: number | null;
  size_id: number | null;
  shipment_quantity: string;
  moving_average_unit_cost: string;
  shipment_inventory_cost: string;
  currency: string;
  valuation_event: string;
  valuation_version: number;
  shipment_movement_id: number;
  shipment_ledger_id: number;
  production_receipt_movement_id: number;
  source_hash: string;
};
type Result = {
  status: string;
  shipment: { doc_no: string; status: string };
  packing_list: { doc_no: string };
  production_order: { doc_no: string };
  valuation: { method: string; event: string; total_cost: number; rows: Row[] };
  cogs: { status: string; handoff: string };
};

const n = (v: string | number, d = 4) =>
  Number(v).toLocaleString("id-ID", { minimumFractionDigits: d, maximumFractionDigits: d });

export default function ShipmentValuationPage() {
  const [items, setItems] = useState<Shipment[]>([]);
  const [selected, setSelected] = useState("");
  const [data, setData] = useState<Result | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100")
      .then((r) => setItems(r.data))
      .catch((e) => setError(e instanceof Error ? e.message : "Gagal memuat shipment"));
  }, []);

  async function load(id: string) {
    setSelected(id); setData(null); setError(null);
    if (!id) return;
    setLoading(true);
    try {
      setData(await api.get<Result>(`/shipping/shipments/${id}/valuation`));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal memuat valuation");
    } finally { setLoading(false); }
  }

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Shipping"
        title="Shipment Inventory Valuation"
        description="D-08 prevailing FG Moving Average captured at ITS Shipment OUT."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select value={selected} onChange={(e) => load(e.target.value)} className="w-80">
          <option value="">- pilih Shipment -</option>
          {items.map((s) => (
            <option key={s.id} value={s.id}>{s.doc_no} - {s.status}</option>
          ))}
        </Select>
        {loading && <span className="text-sm text-[var(--color-text-muted)]">Memuat...</span>}
      </div>

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}

      {data && (
        <>
          <div className="grid gap-3 md:grid-cols-4">
            <MetricCard
              label="Shipment"
              value={
                <span className="flex items-center gap-2">
                  {data.shipment.doc_no} <StatusBadge status={data.shipment.status} />
                </span>
              }
            />
            <MetricCard label="Packing / MO" value={`${data.packing_list.doc_no ?? "-"} / ${data.production_order.doc_no ?? "-"}`} />
            <MetricCard label="Method / Event" value={`${data.valuation.method} / ${data.valuation.event}`} />
            <MetricCard label="Total Inventory Cost" value={n(data.valuation.total_cost)} />
          </div>

          <section className="overflow-auto rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                  <th className="py-2">Line / FG</th>
                  <th className="py-2 text-right">Qty</th>
                  <th className="py-2 text-right">Pre-OUT MA</th>
                  <th className="py-2 text-right">Inventory Cost</th>
                  <th className="py-2">ITS Source</th>
                  <th className="py-2">Version</th>
                </tr>
              </thead>
              <tbody>
                {data.valuation.rows.map((r) => (
                  <tr key={r.id} className="border-t">
                    <td className="py-2">#{r.shipment_line_id} - {r.style_id}/{r.colorway_id ?? "-"}/{r.size_id ?? "-"}</td>
                    <td className="py-2 text-right tabular-nums">{n(r.shipment_quantity)}</td>
                    <td className="py-2 text-right tabular-nums">{n(r.moving_average_unit_cost, 6)}</td>
                    <td className="py-2 text-right tabular-nums">{r.currency} {n(r.shipment_inventory_cost)}</td>
                    <td className="py-2 text-xs text-[var(--color-text-muted)]">
                      Movement #{r.shipment_movement_id} - Ledger #{r.shipment_ledger_id} - Receipt #{r.production_receipt_movement_id}
                    </td>
                    <td className="py-2">v{r.valuation_version}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="mt-3 text-xs text-[var(--color-text-muted)]">
              D-10 handoff: {data.cogs.handoff}. COGS status: {data.cogs.status}.
            </p>
          </section>
        </>
      )}
    </div>
  );
}