"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Button, DataTable, Field, Input, PageHeader, Select, StatusBadge, type DataTableColumn } from "@/components/ui";

interface SoOption { id:number; doc_no:string; status:string; customer?:{name:string}; ex_factory_date?:string; order_qty:number; scheduled_qty:number }
interface ShipmentRef { id:number; doc_no:string; status:string; ship_date:string; date_variance_days:number; qty:number }
interface Schedule { id:number; sales_order_id:number; sales_order_no:string; buyer?:string; delivery_date:string; destination?:string; planned_qty:number; allocated_qty:number; shipped_qty:number; remaining_qty:number; status:string; shipments:ShipmentRef[] }

export default function DeliverySchedulesPage(){
  const [orders,setOrders]=useState<SoOption[]>([]);const [schedules,setSchedules]=useState<Schedule[]>([]);const [soId,setSoId]=useState("");
  const [date,setDate]=useState("");const [qty,setQty]=useState("");const [destination,setDestination]=useState("");
  const [loading,setLoading]=useState(true);const [busy,setBusy]=useState(false);const [error,setError]=useState<string|null>(null);const [message,setMessage]=useState<string|null>(null);
  const selected=useMemo(()=>orders.find((order)=>order.id===Number(soId)),[orders,soId]);
  const load=useCallback(()=>{setLoading(true);setError(null);Promise.all([api.get<{data:SoOption[]}>("/shipping/delivery-schedules/sales-orders"),api.get<{data:Schedule[]}>("/shipping/delivery-schedules")]).then(([soResponse,scheduleResponse])=>{setOrders(soResponse.data);setSchedules(scheduleResponse.data);}).catch((exception)=>setError(exception.message)).finally(()=>setLoading(false));},[]);
  useEffect(load,[load]);
  async function create(){if(!selected||!date||!qty)return;setBusy(true);setError(null);setMessage(null);try{await api.post(`/shipping/delivery-schedules/from-so/${selected.id}`,{delivery_date:date,qty:Number(qty),destination:destination||undefined});setMessage(`Delivery Schedule untuk ${selected.doc_no} dibuat.`);setQty("");setDestination("");load();}catch(exception:any){setError(exception.message);}finally{setBusy(false);}}
  const fmt=(value:number)=>Number(value).toLocaleString("id-ID",{maximumFractionDigits:4});
  const columns:DataTableColumn<Schedule>[]=[
    {key:"so",header:"SO / Buyer",cell:(row)=><div><p className="font-mono font-semibold">{row.sales_order_no}</p><p className="text-xs text-slate-500">{row.buyer??"—"}</p></div>},
    {key:"plan",header:"Plan",cell:(row)=><div><p>{row.delivery_date}</p><p className="text-xs text-slate-500">{row.destination??"Destination belum diisi"}</p></div>},
    {key:"qty",header:"Planned / Allocated",cell:(row)=><div><b>{fmt(row.planned_qty)}</b><p className="text-xs text-slate-500">Allocated {fmt(row.allocated_qty)} · remaining {fmt(row.remaining_qty)}</p></div>},
    {key:"shipped",header:"Shipped",cell:(row)=><div><b>{fmt(row.shipped_qty)}</b>{row.shipments.map((shipment)=><p key={shipment.id} className="text-xs text-slate-500">{shipment.doc_no} · {shipment.ship_date} · Δ {shipment.date_variance_days} hari</p>)}</div>},
    {key:"status",header:"Status",cell:(row)=><StatusBadge status={row.status}/>},
  ];
  return <div className="space-y-4">
    <PageHeader eyebrow="Shipping Plan" title="Delivery Schedule" description="Rencanakan pengiriman dari Sales Order dan pantau planned, allocated, shipped, remaining, serta deviasi tanggal."/>
    {error&&<div role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}{message&&<div role="status" className="rounded border border-green-200 bg-green-50 p-3 text-sm text-green-700">{message}</div>}
    <section className="rounded-xl border bg-white p-4"><h2 className="mb-4 font-semibold">Buat Schedule</h2><div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
      <Field htmlFor="schedule-so" label="Sales Order" required><Select id="schedule-so" value={soId} onChange={(event)=>setSoId(event.target.value)}><option value="">Pilih SO</option>{orders.map((order)=><option key={order.id} value={order.id}>{order.doc_no} · {order.customer?.name??"Buyer"}</option>)}</Select></Field>
      <Field htmlFor="schedule-date" label="Delivery date" required><Input id="schedule-date" type="date" value={date} onChange={(event)=>setDate(event.target.value)}/></Field>
      <Field htmlFor="schedule-qty" label="Planned qty" required><Input id="schedule-qty" type="number" min="0.0001" step="any" value={qty} onChange={(event)=>setQty(event.target.value)}/></Field>
      <Field htmlFor="schedule-destination" label="Destination"><Input id="schedule-destination" value={destination} onChange={(event)=>setDestination(event.target.value)}/></Field>
      <div className="flex items-end"><Button className="w-full" variant="primary" onClick={create} disabled={!selected||!date||!qty} loading={busy}>Simpan Schedule</Button></div>
    </div>{selected&&<p className="mt-3 text-xs text-slate-500">Order {fmt(selected.order_qty)} · sudah dijadwalkan {fmt(selected.scheduled_qty)} · tersedia {fmt(Math.max(0,selected.order_qty-selected.scheduled_qty))}</p>}</section>
    <section className="rounded-xl border bg-white p-4"><DataTable caption="Delivery schedules" columns={columns} rows={schedules} getRowKey={(row)=>row.id} loading={loading} emptyTitle="Belum ada delivery schedule" emptyDescription="Buat schedule dari SO CONFIRMED atau IN_PROGRESS." minWidth="900px"/></section>
    <section className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Shipment baru dialokasikan otomatis ke schedule OPEN paling awal yang memiliki remaining quantity cukup. Stock-out tetap hanya terjadi saat aksi Ship.</section>
  </div>;
}
