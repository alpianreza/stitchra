"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Button, ConfirmDialog, DataTable, Field, Input, PageHeader, Select, StatusBadge, type DataTableColumn } from "@/components/ui";

interface EligibleFg { packing_list_id: number; packing_list_no: string; sales_order_no: string; buyer: string; production_order_no: string; qc_doc_no: string; warehouse_id: number; warehouse_code: string; production_receipt_no: string; received_qty: number; available_qty: number }
interface Shipment { id: number; doc_no: string; status: string; ship_date: string; tolerance_check: string; over_tolerance_approved: boolean; forwarder: string | null; container_no: string | null; sales_order?: { doc_no: string; customer?: { name: string } }; packing_list?: { doc_no: string } }
interface Lineage { production_receipt: { doc_no: string; warehouse: { code: string }; received_qty: number }; fg_stock: { available_qty: number }; shipment_movement: { doc_no?: string; status?: string } }

export default function ShipmentsPage() {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [eligibleFg, setEligibleFg] = useState<EligibleFg[]>([]);
  const [plId, setPlId] = useState("");
  const [shipDate, setShipDate] = useState(new Date().toISOString().slice(0, 10));
  const [forwarder, setForwarder] = useState("");
  const [containerNo, setContainerNo] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [shipmentToApprove, setShipmentToApprove] = useState<Shipment | null>(null);
  const [lineage, setLineage] = useState<{ shipment: Shipment; data: Lineage } | null>(null);

  const selectedFg = useMemo(() => eligibleFg.find((item) => String(item.packing_list_id) === plId), [eligibleFg, plId]);
  const fmt = (value: number) => Number(value).toLocaleString("id-ID", { maximumFractionDigits: 4 });

  const load = useCallback(() => {
    setLoading(true); setError(null);
    Promise.all([
      api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100"),
      api.get<{ data: EligibleFg[] }>("/shipping/eligible-fg"),
    ]).then(([shipmentResponse, fgResponse]) => { setShipments(shipmentResponse.data); setEligibleFg(fgResponse.data); })
      .catch((requestError) => setError(requestError.message)).finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  async function createShipment() {
    if (!plId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const shipment = await api.post<Shipment>(`/shipping/shipments/from-pl/${plId}`, { ship_date: shipDate, forwarder: forwarder || undefined, container_no: containerNo || undefined });
      setMessage(`Shipment ${shipment.doc_no} dibuat dari eligible FG ${selectedFg?.production_receipt_no ?? ""}.`);
      setPlId(""); setForwarder(""); setContainerNo(""); load();
    } catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  async function approveTolerance() {
    if (!shipmentToApprove) return;
    setBusy(true); setError(null); setMessage(null);
    try { await api.post(`/shipping/shipments/${shipmentToApprove.id}/approve-over-tolerance`, {}); setMessage(`Toleransi shipment ${shipmentToApprove.doc_no} disetujui dan tercatat dalam audit.`); setShipmentToApprove(null); load(); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  async function ship(shipment: Shipment) {
    const source = eligibleFg.find((item) => item.packing_list_no === shipment.packing_list?.doc_no);
    if (!source) { setError("Eligible FG source tidak tersedia atau Packing List sudah tidak eligible."); return; }
    setBusy(true); setError(null); setMessage(null);
    try { const result = await api.post<Shipment>(`/shipping/shipments/${shipment.id}/ship`, { fg_warehouse_id: source.warehouse_id }); setMessage(`Shipment ${result.doc_no} SHIPPED — FG keluar dari warehouse sumber ${source.warehouse_code}.`); load(); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  async function showLineage(shipment: Shipment) {
    setBusy(true); setError(null);
    try { setLineage({ shipment, data: await api.get<Lineage>(`/shipping/shipments/${shipment.id}/lineage`) }); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  const toleranceBadge = (shipment: Shipment) => <StatusBadge status={shipment.over_tolerance_approved ? "DEVIATION" : shipment.tolerance_check} label={`${shipment.tolerance_check}${shipment.over_tolerance_approved ? " · approved" : ""}`} />;
  const columns: DataTableColumn<Shipment>[] = [
    { key: "document", header: "Shipment", cell: (shipment) => <span className="font-mono font-semibold">{shipment.doc_no}</span> },
    { key: "source", header: "Packing List", cell: (shipment) => <span className="font-mono">{shipment.packing_list?.doc_no ?? "—"}</span> },
    { key: "so", header: "SO / Buyer", cell: (shipment) => <div><p className="font-mono">{shipment.sales_order?.doc_no ?? "—"}</p><p className="text-xs text-[var(--color-text-muted)]">{shipment.sales_order?.customer?.name ?? "—"}</p></div> },
    { key: "date", header: "Tgl Kirim", cell: (shipment) => shipment.ship_date },
    { key: "tolerance", header: "Toleransi", cell: toleranceBadge },
    { key: "status", header: "Status", cell: (shipment) => <StatusBadge status={shipment.status} /> },
    { key: "action", header: "Aksi", align: "right", cell: (shipment) => <div className="flex justify-end gap-1.5"><Button size="sm" onClick={() => showLineage(shipment)} disabled={busy}>Lineage</Button>{shipment.tolerance_check !== "OK" && !shipment.over_tolerance_approved && shipment.status !== "SHIPPED" && <Button size="sm" onClick={() => setShipmentToApprove(shipment)} disabled={busy}>Approve</Button>}{shipment.status !== "SHIPPED" && <Button size="sm" variant="success" onClick={() => ship(shipment)} disabled={busy}>Ship</Button>}</div> },
  ];

  return <div className="space-y-4">
    <PageHeader eyebrow="Fulfillment" title="FG & Shipment" description="Pilih FG yang traceable ke Packing List dan PRODUCTION_RECEIPT, lalu posting stock OUT melalui ITS SHIPMENT." />
    {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-red-200 bg-[var(--color-danger-soft)] p-3 text-sm text-[var(--color-danger)]">{error}</div>}
    {message && <div role="status" className="rounded-[var(--radius-surface)] border border-green-200 bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</div>}
    <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
      <h2 className="mb-4 font-semibold">Eligible Finished Goods</h2>
      <div className="grid gap-3 lg:grid-cols-3">{eligibleFg.map((item) => <button type="button" key={item.packing_list_id} onClick={() => setPlId(String(item.packing_list_id))} className={`rounded-lg border p-3 text-left ${plId === String(item.packing_list_id) ? "border-blue-500 bg-blue-50" : "border-[var(--color-border-subtle)]"}`}><div className="flex justify-between gap-2"><span className="font-mono font-semibold">{item.packing_list_no}</span><StatusBadge status="APPROVED" /></div><p className="mt-1 text-sm">SO {item.sales_order_no} · {item.buyer}</p><p className="text-xs text-[var(--color-text-muted)]">MO {item.production_order_no} · QC {item.qc_doc_no}</p><dl className="mt-3 grid grid-cols-3 gap-2 text-xs"><div><dt>Receipt</dt><dd className="font-semibold">{fmt(item.received_qty)}</dd></div><div><dt>Available</dt><dd className="font-semibold">{fmt(item.available_qty)}</dd></div><div><dt>Warehouse</dt><dd className="font-mono">{item.warehouse_code}</dd></div></dl><p className="mt-2 font-mono text-xs">{item.production_receipt_no}</p></button>)}</div>
      {!loading && eligibleFg.length === 0 && <p className="text-sm text-[var(--color-text-muted)]">Tidak ada Packing List APPROVED dengan PRODUCTION_RECEIPT valid yang belum memiliki Shipment.</p>}
    </section>
    <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
      <h2 className="mb-4 font-semibold">Buat Shipment</h2>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><Field htmlFor="packing-list" label="Eligible FG" required><Select id="packing-list" value={plId} onChange={(event) => setPlId(event.target.value)}><option value="">Pilih source</option>{eligibleFg.map((item) => <option key={item.packing_list_id} value={item.packing_list_id}>{item.packing_list_no} · {item.production_receipt_no}</option>)}</Select></Field><Field htmlFor="ship-date" label="Tanggal Kirim" required><Input id="ship-date" type="date" value={shipDate} onChange={(event) => setShipDate(event.target.value)} /></Field><Field htmlFor="forwarder" label="Forwarder"><Input id="forwarder" value={forwarder} onChange={(event) => setForwarder(event.target.value)} /></Field><Field htmlFor="container" label="No. Kontainer"><Input id="container" value={containerNo} onChange={(event) => setContainerNo(event.target.value)} /></Field><div className="flex items-end"><Button className="w-full" variant="primary" onClick={createShipment} disabled={!plId} loading={busy}>Buat Shipment</Button></div></div>
      {selectedFg && <p className="mt-3 text-xs text-[var(--color-text-muted)]">Stock OUT dikunci ke warehouse receipt <b>{selectedFg.warehouse_code}</b>. Warehouse tidak dipilih bebas oleh frontend.</p>}
    </section>
    {lineage && <section className="rounded-[var(--radius-surface)] border border-blue-200 bg-blue-50 p-4"><div className="flex justify-between"><h2 className="font-semibold">Lineage {lineage.shipment.doc_no}</h2><Button size="sm" onClick={() => setLineage(null)}>Tutup</Button></div><p className="mt-2 font-mono text-sm">Packing List → {lineage.data.production_receipt.doc_no} → {lineage.data.production_receipt.warehouse.code} → FG → {lineage.data.shipment_movement.doc_no ?? lineage.data.shipment_movement.status}</p><p className="mt-1 text-sm">Received {fmt(lineage.data.production_receipt.received_qty)} · Available saat dibaca {fmt(lineage.data.fg_stock.available_qty)}</p></section>}
    <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]"><DataTable caption="Daftar shipment" columns={columns} rows={shipments} getRowKey={(shipment) => shipment.id} loading={loading} error={!shipments.length ? error : null} onRetry={load} emptyTitle="Belum ada shipment" emptyDescription="Shipment dibuat secara eksplisit dari eligible FG." minWidth="1040px" /></section>
    <ConfirmDialog open={Boolean(shipmentToApprove)} title="Approve Toleransi Buyer?" description="Shipment berada di luar toleransi buyer dan memerlukan persetujuan eksplisit." confirmLabel="Approve Toleransi" variant="danger" loading={busy} onConfirm={approveTolerance} onCancel={() => { if (!busy) setShipmentToApprove(null); }} />
  </div>;
}
