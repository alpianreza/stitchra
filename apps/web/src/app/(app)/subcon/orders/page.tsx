"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";

interface Mo { id: number; doc_no: string; status: string; style?: { style_no: string } }
interface Supplier { id: number; code: string; name: string; type: string; is_active?: boolean }
interface EligibleMaterial {
  stock_balance_id: number;
  material: { id: number; code: string; name: string; type: string };
  warehouse: { id: number; code: string; name: string; type: string };
  uom: { id: number; code: string; name: string };
  available_qty: number;
  lot_no?: string | null;
  roll_id?: number | null;
  ownership: "COMPANY";
}
interface SubconLine {
  id: number;
  material?: { id: number; code: string; name: string } | null;
  bundle?: { id: number; bundle_no: string } | null;
  qty_sent: string;
  qty_returned: string;
}
interface SubconOrder {
  id: number;
  doc_no: string;
  status: string;
  fee_per_pcs: string;
  sent_date?: string;
  expected_return?: string | null;
  supplier?: { code: string; name: string };
  production_order?: { doc_no: string; style?: { style_no: string } };
  operation?: { code: string; name: string } | null;
  lines?: SubconLine[];
}
interface LineageLine {
  id: number;
  material?: { code: string; name: string } | null;
  bundle?: { bundle_no: string } | null;
  uom?: { code: string } | null;
  qty_sent: number;
  qty_returned: number;
  outstanding_qty: number;
  outbound_ledger?: { warehouse_id: number; ownership: string; qty_out: string } | null;
  bundle_movement_authority?: string | null;
}
interface Lineage {
  subcon_order: {
    id: number; doc_no: string; status: string; outstanding_days: number;
    supplier?: { code: string; name: string }; production_order?: { doc_no: string };
    operation?: { code: string; name: string } | null;
  };
  lines: LineageLine[];
  outbound_movement?: { doc_no: string; movement_type: string } | null;
  receipts: { id: number; return_date: string; qty_returned: number; movement?: { doc_no: string } | null }[];
  authorities: Record<string, string>;
}

const input = "w-full rounded border px-2 py-1.5 text-sm";

export default function SubconOrdersPage() {
  const [orders, setOrders] = useState<SubconOrder[]>([]);
  const [mos, setMos] = useState<Mo[]>([]);
  const [vendors, setVendors] = useState<Supplier[]>([]);
  const [eligible, setEligible] = useState<EligibleMaterial[]>([]);
  const [lineage, setLineage] = useState<Lineage | null>(null);
  const [form, setForm] = useState({ mo_id: "", supplier_id: "", fee_per_pcs: "", source_id: "", qty_sent: "", expected_return: "" });
  const [receiving, setReceiving] = useState<{ orderId: number; lineId: number; qty: string; warehouse_id: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const selectedSource = useMemo(
    () => eligible.find((row) => row.stock_balance_id === Number(form.source_id)),
    [eligible, form.source_id],
  );

  async function load() {
    const [orderResponse, materialResponse] = await Promise.all([
      api.get<{ data: SubconOrder[] }>("/subcon/orders?per_page=100"),
      api.get<{ data: EligibleMaterial[] }>("/subcon/eligible-materials"),
    ]);
    setOrders(orderResponse.data);
    setEligible(materialResponse.data);
  }

  useEffect(() => {
    load().catch((exception) => setError(exception.message));
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((response) => setMos(response.data)).catch(() => {});
    api.get<{ data: Supplier[] }>("/master/suppliers?per_page=200")
      .then((response) => setVendors(response.data.filter((supplier) => supplier.type === "SUBCON" && supplier.is_active !== false)))
      .catch(() => {});
  }, []);

  async function send(event: React.FormEvent) {
    event.preventDefault();
    if (!selectedSource) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const order = await api.post<SubconOrder>(`/subcon/orders/from-mo/${form.mo_id}`, {
        client_reference: crypto.randomUUID(),
        supplier_id: Number(form.supplier_id),
        fee_per_pcs: Number(form.fee_per_pcs),
        expected_return: form.expected_return || undefined,
        warehouse_id: selectedSource.warehouse.id,
        lines: [{
          stock_balance_id: selectedSource.stock_balance_id,
          material_id: selectedSource.material.id,
          qty_sent: Number(form.qty_sent),
          uom_id: selectedSource.uom.id,
        }],
      });
      setMessage(`Job Work ${order.doc_no} terkirim melalui SUBCON_OUT.`);
      setForm({ mo_id: "", supplier_id: "", fee_per_pcs: "", source_id: "", qty_sent: "", expected_return: "" });
      await load();
    } catch (exception: any) {
      setError(exception.message);
    } finally {
      setBusy(false);
    }
  }

  async function openLineage(orderId: number) {
    setError(null);
    try {
      const data = await api.get<Lineage>(`/subcon/orders/${orderId}/lineage`);
      setLineage(data);
    } catch (exception: any) {
      setError(exception.message);
    }
  }

  async function startReceive(orderId: number) {
    const data = await api.get<Lineage>(`/subcon/orders/${orderId}/lineage`);
    setLineage(data);
    const line = data.lines.find((candidate) => candidate.outstanding_qty > 0 && candidate.outbound_ledger);
    if (!line?.outbound_ledger) {
      setError("Tidak ada material line dengan source SUBCON_OUT yang dapat diterima melalui ITS.");
      return;
    }
    setReceiving({
      orderId,
      lineId: line.id,
      qty: String(line.outstanding_qty),
      warehouse_id: line.outbound_ledger.warehouse_id,
    });
  }

  async function receive() {
    if (!receiving) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const order = await api.post<SubconOrder>(`/subcon/orders/${receiving.orderId}/receive`, {
        returns: [{
          line_id: receiving.lineId,
          qty_returned: Number(receiving.qty),
          warehouse_id: receiving.warehouse_id,
          receipt_reference: crypto.randomUUID(),
        }],
      });
      setMessage(`Vendor return tercatat melalui SUBCON_IN — status ${order.status}.`);
      setReceiving(null);
      await load();
      await openLineage(order.id);
    } catch (exception: any) {
      setError(exception.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-bold">Subcontracting / External Production</h1>
        <p className="text-sm text-slate-500">Job Work Order → material SUBCON_OUT → vendor return SUBCON_IN → actual subcon cost.</p>
      </div>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        Vendor processing detail, loss/yield/scrap arithmetic, Bundle/WIP movement, QC handoff, dan direct FG handoff: <b>⚪ NOT DEFINED</b>. UI hanya menjalankan material round-trip yang didukung ITS existing.
      </div>

      <form onSubmit={send} className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">Kirim material ke vendor</h2>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Production Order *</span>
            <select required className={input} value={form.mo_id} onChange={(event) => setForm({ ...form, mo_id: event.target.value })}>
              <option value="">— pilih MO —</option>
              {mos.filter((mo) => !["CLOSED", "CANCELLED"].includes(mo.status)).map((mo) => <option key={mo.id} value={mo.id}>{mo.doc_no} — {mo.style?.style_no}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Vendor SUBCON aktif *</span>
            <select required className={input} value={form.supplier_id} onChange={(event) => setForm({ ...form, supplier_id: event.target.value })}>
              <option value="">— pilih vendor —</option>
              {vendors.map((vendor) => <option key={vendor.id} value={vendor.id}>{vendor.code} — {vendor.name}</option>)}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Eligible material stock *</span>
            <select required className={input} value={form.source_id} onChange={(event) => setForm({ ...form, source_id: event.target.value, qty_sent: "" })}>
              <option value="">— pilih source balance —</option>
              {eligible.map((row) => (
                <option key={row.stock_balance_id} value={row.stock_balance_id}>
                  {row.material.code} · {row.warehouse.code} · available {row.available_qty} {row.uom.code}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Qty kirim *</span>
            <input required type="number" min="0.0001" step="0.0001" max={selectedSource?.available_qty} className={input} value={form.qty_sent} onChange={(event) => setForm({ ...form, qty_sent: event.target.value })} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Fee per pcs *</span>
            <input required type="number" min="0" step="0.000001" className={input} value={form.fee_per_pcs} onChange={(event) => setForm({ ...form, fee_per_pcs: event.target.value })} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Expected return</span>
            <input type="date" className={input} value={form.expected_return} onChange={(event) => setForm({ ...form, expected_return: event.target.value })} />
          </label>
        </div>
        {selectedSource && <p className="mt-2 text-xs text-slate-500">Ownership: COMPANY (BR-090) · Source warehouse: {selectedSource.warehouse.code} · UOM: {selectedSource.uom.code}</p>}
        <button disabled={busy || !selectedSource} className="mt-3 rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
          {busy ? "Memproses…" : "Buat JW & kirim via ITS"}
        </button>
      </form>

      <section className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left"><tr>
            <th className="px-3 py-2">JW</th><th className="px-3 py-2">MO</th><th className="px-3 py-2">Vendor</th>
            <th className="px-3 py-2">Status</th><th className="px-3 py-2">Sent / Returned</th><th className="px-3 py-2">Aksi</th>
          </tr></thead>
          <tbody>
            {orders.map((order) => {
              const sent = order.lines?.reduce((sum, line) => sum + Number(line.qty_sent), 0) ?? 0;
              const returned = order.lines?.reduce((sum, line) => sum + Number(line.qty_returned), 0) ?? 0;
              return <tr key={order.id} className="border-b last:border-0">
                <td className="px-3 py-2 font-mono">{order.doc_no}</td>
                <td className="px-3 py-2 font-mono">{order.production_order?.doc_no}</td>
                <td className="px-3 py-2">{order.supplier?.name}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{order.status}</span></td>
                <td className="px-3 py-2">{sent} / {returned}</td>
                <td className="space-x-2 px-3 py-2">
                  <button type="button" onClick={() => openLineage(order.id)} className="rounded border px-2 py-1 text-xs">Lineage</button>
                  {["SENT", "PARTIAL_RETURNED"].includes(order.status) && <button type="button" disabled={busy} onClick={() => startReceive(order.id)} className="rounded bg-blue-600 px-2 py-1 text-xs text-white">Terima</button>}
                </td>
              </tr>;
            })}
            {orders.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada Job Work Order.</td></tr>}
          </tbody>
        </table>
      </section>

      {receiving && <section className="rounded-xl border-2 border-blue-200 bg-white p-4">
        <h2 className="font-semibold">Vendor return</h2>
        <p className="mb-3 text-xs text-slate-500">Return material dikunci ke source warehouse/dimensi SUBCON_OUT agar tidak membuat phantom stock.</p>
        <div className="flex flex-wrap items-end gap-3">
          <label className="text-sm"><span className="mb-1 block font-medium">Qty kembali *</span>
            <input type="number" min="0.0001" step="0.0001" className={input} value={receiving.qty} onChange={(event) => setReceiving({ ...receiving, qty: event.target.value })} />
          </label>
          <span className="text-sm">Source warehouse ID: {receiving.warehouse_id}</span>
          <button type="button" disabled={busy} onClick={receive} className="rounded bg-green-700 px-4 py-1.5 text-sm font-medium text-white">Post SUBCON_IN</button>
          <button type="button" onClick={() => setReceiving(null)} className="rounded border px-4 py-1.5 text-sm">Batal</button>
        </div>
      </section>}

      {lineage && <section className="rounded-xl border bg-white p-4 text-sm">
        <h2 className="font-semibold">Lineage {lineage.subcon_order.doc_no}</h2>
        <p>{lineage.subcon_order.production_order?.doc_no} → {lineage.subcon_order.supplier?.name} → {lineage.outbound_movement?.doc_no ?? "Bundle/non-stock boundary"}</p>
        <p className="text-slate-500">Outstanding aging: {lineage.subcon_order.outstanding_days} hari · Receipts: {lineage.receipts.length}</p>
        <div className="mt-3 overflow-x-auto"><table className="w-full"><thead><tr className="border-b text-left"><th>Source</th><th>Sent</th><th>Returned</th><th>Outstanding</th><th>Authority</th></tr></thead><tbody>
          {lineage.lines.map((line) => <tr key={line.id} className="border-b"><td>{line.material?.code ?? line.bundle?.bundle_no}</td><td>{line.qty_sent} {line.uom?.code}</td><td>{line.qty_returned}</td><td>{line.outstanding_qty}</td><td>{line.bundle_movement_authority ?? line.outbound_ledger?.ownership}</td></tr>)}
        </tbody></table></div>
        <div className="mt-3 grid gap-1 rounded bg-slate-50 p-3 md:grid-cols-2">
          {Object.entries(lineage.authorities).map(([key, value]) => <p key={key}><b>{key.replaceAll("_", " ")}:</b> {value}</p>)}
        </div>
      </section>}
    </div>
  );
}
