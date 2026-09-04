"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, MetricCard, PageHeader, Select } from "@/components/ui";

interface Shipment { id: number; doc_no: string; status: string }

function GenericView({ data }: { data: unknown }) {
  if (data === null || data === undefined) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
  if (Array.isArray(data)) {
    if (data.length === 0) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
    if (typeof data[0] === "object" && data[0] !== null) {
      const keys = Object.keys(data[0] as Record<string, unknown>);
      return <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">{keys.map((key) => <th key={key} className="py-1.5 pr-3">{key}</th>)}</tr></thead><tbody>{data.map((row, index) => <tr key={index} className="border-b last:border-0">{keys.map((key) => <td key={key} className="py-1.5 pr-3">{String((row as Record<string, unknown>)[key] ?? "-")}</td>)}</tr>)}</tbody></table></div>;
    }
    return <ul className="list-disc space-y-0.5 pl-5 text-sm">{data.map((item, index) => <li key={index}>{String(item)}</li>)}</ul>;
  }
  if (typeof data === "object") return <dl className="grid gap-2 text-sm sm:grid-cols-2">{Object.entries(data as Record<string, unknown>).map(([key, value]) => <div key={key}><dt className="text-xs text-[var(--color-text-muted)]">{key}</dt><dd className="font-medium">{typeof value === "object" ? JSON.stringify(value) : String(value)}</dd></div>)}</dl>;
  return <p className="text-sm">{String(data)}</p>;
}

function PaymentForm({ endpoint, label }: { endpoint: (invoiceId: string) => string; label: string }) {
  const [invoiceId, setInvoiceId] = useState("");
  const [amount, setAmount] = useState("");
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [method, setMethod] = useState("");
  const [referenceNo, setReferenceNo] = useState("");
  const [currency, setCurrency] = useState<"USD" | "IDR">("USD");
  const [idrPerUsd, setIdrPerUsd] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  async function pay() {
    if (!invoiceId || !(Number(amount) > 0)) return;
    setBusy(true); setError(null); setDone(null);
    try {
      const quotedRate = Number(idrPerUsd);
      await api.post(endpoint(invoiceId), {
        amount: Number(amount), payment_date: paymentDate || undefined,
        exchange_rate: currency === "IDR" && quotedRate > 0 ? Number((1 / quotedRate).toFixed(12)) : undefined,
        method: method || undefined, reference_no: referenceNo || undefined,
      });
      setDone(`Pembayaran ${label} #${invoiceId} tercatat dalam ${currency}.`);
      setInvoiceId(""); setAmount(""); setReferenceNo(""); setIdrPerUsd("");
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal mencatat pembayaran"); }
    finally { setBusy(false); }
  }

  return <div className="space-y-2">
    <div className="mb-2 flex flex-wrap items-center gap-2 text-sm">
      <span className="font-medium">Currency invoice:</span>
      <Select value={currency} onChange={(e) => { setCurrency(e.target.value as "USD" | "IDR"); setIdrPerUsd(""); }} className="w-40"><option value="USD">USD — default</option><option value="IDR">IDR — lokal</option></Select>
      {currency === "IDR" && <Input type="number" step="any" min="0" value={idrPerUsd} onChange={(e) => setIdrPerUsd(e.target.value)} placeholder="1 USD = IDR" className="w-48" aria-label="Kurs IDR per USD" />}
      <span className="text-xs text-[var(--color-text-muted)]">Harus sama dengan currency invoice; settlement menyimpan snapshot kurs.</span>
    </div>
    <div className="grid gap-2 sm:grid-cols-5"><Input value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} placeholder={`ID ${label} *`} /><Input type="number" step="any" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={`Amount ${currency} *`} /><Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} /><Input value={method} onChange={(e) => setMethod(e.target.value)} placeholder="Metode" /><Input value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} placeholder="No. referensi" /></div>
    {error && <p role="alert" className="text-sm text-[var(--color-danger)]">{error}</p>}{done && <p role="status" className="text-sm text-[var(--color-success)]">{done}</p>}
    <Button loading={busy} disabled={!invoiceId || !(Number(amount) > 0) || (currency === "IDR" && !(Number(idrPerUsd) > 0))} onClick={pay}>Catat Pembayaran</Button>
  </div>;
}

export default function ArApPage() {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [arShipment, setArShipment] = useState(""); const [arDue, setArDue] = useState("");
  const [apInvoiceId, setApInvoiceId] = useState(""); const [apCurrency, setApCurrency] = useState<"USD" | "IDR">("USD"); const [apIdrPerUsd, setApIdrPerUsd] = useState("");
  const [agingAsOf, setAgingAsOf] = useState(new Date().toISOString().slice(0, 10));
  const [arAging, setArAging] = useState<unknown>(null); const [apAging, setApAging] = useState<unknown>(null);
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [message, setMessage] = useState<string | null>(null);

  useEffect(() => { api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100&status=SHIPPED").then((r) => setShipments(r.data)).catch(() => {}); }, []);
  async function run(fn: () => Promise<void>) { setBusy(true); setError(null); setMessage(null); try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : "Gagal"); } finally { setBusy(false); } }
  const createAr = () => run(async () => { if (!arShipment) return; const result = await api.post<{ id?: number; doc_no?: string }>(`/finance/ar/invoices/from-shipment/${arShipment}`, { due_date: arDue || undefined }); setMessage(`AR Invoice ${result.doc_no ?? `#${result.id ?? ""}`} dibuat dari shipment.`); });
  const finalizeAp = () => run(async () => { if (!apInvoiceId) return; const quoted = Number(apIdrPerUsd); const result = await api.post<{ status?: string }>(`/finance/ap/invoices/${apInvoiceId}/finalize-finance`, { exchange_rate: apCurrency === "IDR" && quoted > 0 ? Number((1 / quoted).toFixed(12)) : undefined }); setMessage(`Supplier invoice #${apInvoiceId} difinalisasi${result.status ? ` (${result.status})` : ""}.`); });
  const loadAging = (kind: "ar" | "ap") => run(async () => { const result = await api.get<{ data: unknown }>(`/finance/${kind}/aging?as_of=${agingAsOf}`); if (kind === "ar") setArAging(result.data); else setApAging(result.data); });

  return <div className="space-y-4">
    <PageHeader eyebrow="Finance" title="AR / AP" description="USD adalah base currency. Invoice lokal dapat memakai IDR dengan snapshot kurs ke USD." />
    {error && <div role="alert" className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</div>}{message && <p role="status" className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}
    <div className="grid gap-4 md:grid-cols-2">
      <section className="rounded-[var(--radius-surface)] border bg-white p-4"><h2 className="font-semibold">AR: Buat Invoice dari Shipment</h2><p className="mt-1 text-xs text-slate-500">Currency dan kurs diwarisi dari Sales Order.</p><div className="mt-2 space-y-2"><Select value={arShipment} onChange={(e) => setArShipment(e.target.value)}><option value="">- pilih SHIPPED shipment -</option>{shipments.map((item) => <option key={item.id} value={item.id}>{item.doc_no}</option>)}</Select><Input type="date" value={arDue} onChange={(e) => setArDue(e.target.value)} /><Button loading={busy} disabled={!arShipment} onClick={createAr}>Buat AR Invoice</Button></div></section>
      <section className="rounded-[var(--radius-surface)] border bg-white p-4"><h2 className="font-semibold">AP: Finalisasi Supplier Invoice</h2><div className="mt-2 space-y-2"><Input value={apInvoiceId} onChange={(e) => setApInvoiceId(e.target.value)} placeholder="ID Supplier Invoice *" /><Select value={apCurrency} onChange={(e) => { setApCurrency(e.target.value as "USD" | "IDR"); setApIdrPerUsd(""); }}><option value="USD">USD — default</option><option value="IDR">IDR — lokal</option></Select>{apCurrency === "IDR" && <Input type="number" step="any" min="0" value={apIdrPerUsd} onChange={(e) => setApIdrPerUsd(e.target.value)} placeholder="1 USD = IDR" />}<Button loading={busy} disabled={!apInvoiceId || (apCurrency === "IDR" && !(Number(apIdrPerUsd) > 0))} onClick={finalizeAp}>Finalisasi AP</Button></div></section>
      <section className="rounded-[var(--radius-surface)] border bg-white p-4"><h2 className="font-semibold">Pembayaran AR</h2><div className="mt-2"><PaymentForm endpoint={(id) => `/finance/ar/invoices/${id}/payments`} label="AR Invoice" /></div></section>
      <section className="rounded-[var(--radius-surface)] border bg-white p-4"><h2 className="font-semibold">Pembayaran AP</h2><div className="mt-2"><PaymentForm endpoint={(id) => `/finance/ap/invoices/${id}/payments`} label="AP Invoice" /></div></section>
    </div>
    <section className="rounded-[var(--radius-surface)] border bg-white p-4"><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold">Aging Report</h2><Input type="date" value={agingAsOf} onChange={(e) => setAgingAsOf(e.target.value)} className="ml-auto w-44" /><Button size="sm" variant="secondary" onClick={() => loadAging("ar")}>Muat AR</Button><Button size="sm" variant="secondary" onClick={() => loadAging("ap")}>Muat AP</Button></div><div className="mt-4 space-y-4">{arAging !== null && <><MetricCard label="AR Aging" value="" /><GenericView data={arAging} /></>}{apAging !== null && <><MetricCard label="AP Aging" value="" /><GenericView data={apAging} /></>}</div></section>
  </div>;
}
