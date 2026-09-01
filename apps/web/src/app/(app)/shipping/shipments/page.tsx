"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, ConfirmDialog, DataTable, Field, Input, PageHeader, Select, StatusBadge, type DataTableColumn } from "@/components/ui";

interface Pl { id: number; doc_no: string; sales_order?: { doc_no: string } }
interface Shipment { id: number; doc_no: string; status: string; ship_date: string; tolerance_check: string; over_tolerance_approved: boolean; forwarder: string | null; container_no: string | null; sales_order?: { doc_no: string; customer?: { name: string } } }
interface Warehouse { id: number; code: string; name: string; type: string }

export default function ShipmentsPage() {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [approvedPls, setApprovedPls] = useState<Pl[]>([]);
  const [plId, setPlId] = useState("");
  const [shipDate, setShipDate] = useState(new Date().toISOString().slice(0, 10));
  const [forwarder, setForwarder] = useState("");
  const [containerNo, setContainerNo] = useState("");
  const [fgWarehouses, setFgWarehouses] = useState<Warehouse[]>([]);
  const [fgWarehouseId, setFgWarehouseId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [shipmentToApprove, setShipmentToApprove] = useState<Shipment | null>(null);

  const load = useCallback(() => {
    setLoading(true); setError(null);
    Promise.all([
      api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100"),
      api.get<{ data: Pl[] }>("/packing/lists?status=APPROVED&per_page=100"),
    ]).then(([shipmentResponse, packingResponse]) => { setShipments(shipmentResponse.data); setApprovedPls(packingResponse.data); }).catch((requestError) => setError(requestError.message)).finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100").then((response) => setFgWarehouses(response.data.filter((warehouse) => warehouse.type === "FG"))).catch(() => {});
  }, [load]);

  async function createShipment() {
    if (!plId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const shipment = await api.post<Shipment>(`/shipping/shipments/from-pl/${plId}`, { ship_date: shipDate, forwarder: forwarder || undefined, container_no: containerNo || undefined });
      setMessage(`Shipment ${shipment.doc_no} dibuat — tolerance check: ${shipment.tolerance_check}.`);
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

  async function ship(id: number) {
    if (!fgWarehouseId) { setError("Pilih gudang FG sebelum melakukan ship."); return; }
    setBusy(true); setError(null); setMessage(null);
    try { const shipment = await api.post<Shipment>(`/shipping/shipments/${id}/ship`, { fg_warehouse_id: Number(fgWarehouseId) }); setMessage(`Shipment ${shipment.doc_no} SHIPPED — FG keluar dari gudang.`); load(); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setBusy(false); }
  }

  const toleranceBadge = (shipment: Shipment) => <StatusBadge status={shipment.over_tolerance_approved ? "DEVIATION" : shipment.tolerance_check} label={`${shipment.tolerance_check}${shipment.over_tolerance_approved ? " · approved" : ""}`} />;
  const columns: DataTableColumn<Shipment>[] = [
    { key: "document", header: "Shipment", cell: (shipment) => <span className="font-mono font-semibold">{shipment.doc_no}</span> },
    { key: "so", header: "SO", cell: (shipment) => <span className="font-mono">{shipment.sales_order?.doc_no ?? "—"}</span> },
    { key: "customer", header: "Customer", cell: (shipment) => shipment.sales_order?.customer?.name ?? "—" },
    { key: "date", header: "Tgl Kirim", cell: (shipment) => shipment.ship_date },
    { key: "tolerance", header: "Toleransi", cell: toleranceBadge },
    { key: "status", header: "Status", cell: (shipment) => <StatusBadge status={shipment.status} /> },
    { key: "action", header: "Aksi", align: "right", cell: (shipment) => <div className="flex justify-end gap-1.5">{shipment.tolerance_check !== "OK" && !shipment.over_tolerance_approved && shipment.status !== "SHIPPED" && <Button size="sm" onClick={() => { setMessage(null); setShipmentToApprove(shipment); }} disabled={busy}>Approve Toleransi</Button>}{shipment.status !== "SHIPPED" && <Button size="sm" variant="success" onClick={() => ship(shipment.id)} disabled={busy}>Ship</Button>}</div> },
  ];

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Fulfillment" title="Shipment" description="Buat shipment dari packing list, kendalikan toleransi buyer, dan posting pengeluaran finished goods." />
      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-red-200 bg-[var(--color-danger-soft)] p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      {message && <div role="status" aria-live="polite" className="rounded-[var(--radius-surface)] border border-green-200 bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</div>}
      <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="mb-4 font-semibold">Buat Shipment</h2>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <Field htmlFor="packing-list" label="Packing List" required><Select id="packing-list" value={plId} onChange={(event) => setPlId(event.target.value)}><option value="">Pilih packing list</option>{approvedPls.map((item) => <option key={item.id} value={item.id}>{item.doc_no} (SO {item.sales_order?.doc_no})</option>)}</Select></Field>
          <Field htmlFor="ship-date" label="Tanggal Kirim" required><Input id="ship-date" type="date" value={shipDate} onChange={(event) => setShipDate(event.target.value)} /></Field>
          <Field htmlFor="forwarder" label="Forwarder"><Input id="forwarder" value={forwarder} onChange={(event) => setForwarder(event.target.value)} /></Field>
          <Field htmlFor="container" label="No. Kontainer"><Input id="container" value={containerNo} onChange={(event) => setContainerNo(event.target.value)} /></Field>
          <div className="flex items-end"><Button className="w-full" variant="primary" onClick={createShipment} disabled={!plId} loading={busy}>Buat Shipment</Button></div>
        </div>
      </section>
      <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="mb-4 max-w-md"><Field htmlFor="fg-warehouse" label="Gudang FG untuk Ship" hint="Gudang ini digunakan saat aksi Ship dijalankan."><Select id="fg-warehouse" value={fgWarehouseId} onChange={(event) => setFgWarehouseId(event.target.value)}><option value="">Pilih gudang FG</option>{fgWarehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} — {warehouse.name}</option>)}</Select></Field></div>
        <DataTable caption="Daftar shipment" columns={columns} rows={shipments} getRowKey={(shipment) => shipment.id} loading={loading} error={!shipments.length ? error : null} onRetry={load} emptyTitle="Belum ada shipment" emptyDescription="Shipment yang dibuat dari packing list akan muncul di sini." minWidth="960px" mobileCard={(shipment) => <article className="space-y-3 p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-mono font-semibold">{shipment.doc_no}</p><p className="text-sm">{shipment.sales_order?.customer?.name ?? "—"}</p><p className="text-xs text-[var(--color-text-muted)]">SO {shipment.sales_order?.doc_no ?? "—"} · {shipment.ship_date}</p></div><StatusBadge status={shipment.status} /></div><div>{toleranceBadge(shipment)}</div>{shipment.status !== "SHIPPED" && <div className="grid grid-cols-2 gap-2">{shipment.tolerance_check !== "OK" && !shipment.over_tolerance_approved && <Button size="sm" onClick={() => setShipmentToApprove(shipment)}>Approve Toleransi</Button>}<Button size="sm" variant="success" onClick={() => ship(shipment.id)}>Ship</Button></div>}</article>} />
      </section>
      <ConfirmDialog open={Boolean(shipmentToApprove)} title="Approve Toleransi Buyer?" description="Shipment berada di luar toleransi buyer dan memerlukan persetujuan eksplisit." confirmLabel="Approve Toleransi" variant="danger" loading={busy} onConfirm={approveTolerance} onCancel={() => { if (!busy) setShipmentToApprove(null); }}>
        {shipmentToApprove && <div className="space-y-3"><dl className="grid grid-cols-2 gap-3 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-3 text-sm"><div><dt className="text-xs text-[var(--color-text-muted)]">Shipment</dt><dd className="font-mono font-semibold">{shipmentToApprove.doc_no}</dd></div><div><dt className="text-xs text-[var(--color-text-muted)]">Tolerance</dt><dd className="font-semibold text-[var(--color-danger)]">{shipmentToApprove.tolerance_check}</dd></div><div><dt className="text-xs text-[var(--color-text-muted)]">Sales order</dt><dd className="font-mono">{shipmentToApprove.sales_order?.doc_no ?? "—"}</dd></div><div><dt className="text-xs text-[var(--color-text-muted)]">Customer</dt><dd>{shipmentToApprove.sales_order?.customer?.name ?? "—"}</dd></div></dl><p className="text-sm text-[var(--color-danger)]">Persetujuan tercatat dalam audit dan mengizinkan proses di luar toleransi buyer.</p></div>}
      </ConfirmDialog>
    </div>
  );
}
