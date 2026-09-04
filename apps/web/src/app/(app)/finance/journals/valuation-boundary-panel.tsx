"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface BoundaryRow {
  boundary: string;
  quantity_authority: string;
  cost_authority: string;
  accounting_event: string | null;
  mapping_configured: boolean;
  status: string;
  reason: string;
}
interface Matrix {
  company: { code: string; base_currency: string };
  rows: BoundaryRow[];
  actual_cost_dependency: string;
  cost_per_unit: string;
}

const STATUS_META: Record<string, { label: string; cls: string }> = {
  DEFINED: { label: "Siap otomatis", cls: "bg-green-100 text-green-800" },
  PARTIAL: { label: "Sebagian", cls: "bg-amber-100 text-amber-800" },
  BLOCKED: { label: "Ditahan", cls: "bg-red-100 text-red-800" },
  "NOT DEFINED": { label: "Belum ditetapkan", cls: "bg-slate-100 text-slate-600" },
};

function StatusChip({ status }: { status: string }) {
  const meta = STATUS_META[status.toUpperCase()] ?? { label: status, cls: "bg-slate-100 text-slate-600" };
  return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${meta.cls}`}>{meta.label}</span>;
}

export default function ValuationBoundaryPanel() {
  const [matrix, setMatrix] = useState<Matrix | null>(null);
  const [moId, setMoId] = useState("");
  const [shipmentId, setShipmentId] = useState("");
  const [trace, setTrace] = useState<Record<string, unknown> | null>(null);
  const [traceLabel, setTraceLabel] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<Matrix>("/finance/gl/valuation-authority").then(setMatrix).catch((e) => setError(e.message));
  }, []);

  async function load(path: string, label: string) {
    setError(null); setTrace(null); setTraceLabel(label);
    try { setTrace(await api.get<Record<string, unknown>>(path)); }
    catch (e: any) { setError(e.message); }
  }

  return (
    <section className="rounded-[var(--radius-surface)] border bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="font-semibold">WIP / FG / COGS - batas valuasi</h2>
          <p className="mt-1 max-w-3xl text-sm text-[var(--color-text-muted)]">
            Matriks ini memisahkan dua wewenang: <b>kuantitas</b> (dicatat oleh sistem ITS - selalu valid) dan <b>nilai biaya</b> (menunggu keputusan costing resmi).
            Status <b>Ditahan / Belum ditetapkan bukan error</b> - sistem sengaja tidak menghitung biaya otomatis sampai aturan costing ditetapkan, agar tidak ada angka palsu masuk GL.
          </p>
        </div>
        {matrix && (
          <span className="rounded-full bg-[var(--color-primary-soft)] px-3 py-0.5 text-xs font-bold text-[var(--color-primary)]">
            Mata uang perusahaan: {matrix.company.base_currency}
          </span>
        )}
      </div>
      {error && <p className="mt-2 rounded bg-red-50 p-2 text-xs text-red-700">{error}</p>}
      {matrix && (
        <div className="mt-3 space-y-2">
          {matrix.rows.map((row) => (
            <div key={row.boundary} className="rounded-[var(--radius-control)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">{row.boundary}</p>
                <StatusChip status={row.status} />
              </div>
              <p className="mt-1 text-xs text-[var(--color-text-muted)]"><b>Kuantitas:</b> {row.quantity_authority}</p>
              <p className="text-xs text-[var(--color-text-muted)]"><b>Biaya:</b> {row.cost_authority}</p>
              <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                Event: {row.accounting_event ?? "belum ditetapkan"} - {row.mapping_configured ? "mapping tersedia" : "mapping belum ada"}
              </p>
            </div>
          ))}
        </div>
      )}
      {matrix && <p className="mt-2 text-xs text-amber-700">{matrix.actual_cost_dependency} {matrix.cost_per_unit}</p>}

      <div className="mt-4 border-t pt-3">
        <h3 className="text-sm font-semibold">Telusuri sumber valuasi</h3>
        <p className="mt-1 text-xs text-[var(--color-text-muted)]">Lihat dari mana kuantitas WIP/FG sebuah MO berasal, atau sumber COGS sebuah shipment.</p>
        <div className="mt-2 flex flex-wrap items-end gap-2">
          <label className="text-sm"><span className="block">Production Order ID</span><input type="number" min="1" className="rounded border px-2 py-1.5 text-sm" value={moId} onChange={(e) => setMoId(e.target.value)} /></label>
          <Button type="button" size="sm" variant="secondary" disabled={!moId} onClick={() => load(`/finance/gl/valuation-boundaries/production-orders/${moId}`, `Valuasi MO #${moId}`)}>Muat WIP/FG</Button>
          <label className="text-sm"><span className="block">Shipment ID</span><input type="number" min="1" className="rounded border px-2 py-1.5 text-sm" value={shipmentId} onChange={(e) => setShipmentId(e.target.value)} /></label>
          <Button type="button" size="sm" variant="secondary" disabled={!shipmentId} onClick={() => load(`/finance/gl/valuation-boundaries/shipments/${shipmentId}`, `COGS Shipment #${shipmentId}`)}>Muat Shipment</Button>
        </div>
        {trace && (
          <div className="mt-3 rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">{traceLabel || "Hasil"}</p>
            <GenericView data={trace} />
          </div>
        )}
      </div>
    </section>
  );
}

function Button({ type = "button", size = "sm", variant = "primary", disabled, onClick, children }: {
  type?: "button" | "submit"; size?: "sm" | "md"; variant?: "primary" | "secondary"; disabled?: boolean; onClick?: () => void; children: React.ReactNode;
}) {
  const base = "inline-flex items-center rounded-[var(--radius-control)] px-3 font-medium disabled:opacity-50";
  const sizing = size === "sm" ? "py-1 text-xs" : "py-1.5 text-sm";
  const styles = variant === "secondary" ? "border bg-white" : "bg-slate-900 text-white";
  return <button type={type} disabled={disabled} onClick={onClick} className={`${base} ${sizing} ${styles}`}>{children}</button>;
}

function GenericView({ data }: { data: unknown }) {
  if (data === null || data === undefined) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
  if (Array.isArray(data)) {
    if (data.length === 0) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
    if (typeof data[0] === "object" && data[0] !== null) {
      const keys = Object.keys(data[0] as Record<string, unknown>);
      return (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b text-left text-xs text-[var(--color-text-muted)]">{keys.map((k) => <th key={k} className="py-1.5 pr-3">{k}</th>)}</tr></thead>
            <tbody>{data.map((row, i) => <tr key={i} className="border-b last:border-0">{keys.map((k) => <td key={k} className="py-1.5 pr-3">{String((row as Record<string, unknown>)[k] ?? "-")}</td>)}</tr>)}</tbody>
          </table>
        </div>
      );
    }
    return <ul className="list-disc space-y-0.5 pl-5 text-sm">{data.map((x, i) => <li key={i}>{String(x)}</li>)}</ul>;
  }
  if (typeof data === "object") {
    return (
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        {Object.entries(data as Record<string, unknown>).map(([k, v]) => (
          <div key={k}><dt className="text-xs text-[var(--color-text-muted)]">{k}</dt><dd className="font-medium">{typeof v === "object" ? JSON.stringify(v) : String(v)}</dd></div>
        ))}
      </dl>
    );
  }
  return <p className="text-sm">{String(data)}</p>;
}