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
      return (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                {keys.map((k) => <th key={k} className="py-1.5 pr-3">{k}</th>)}
              </tr>
            </thead>
            <tbody>
              {data.map((row, i) => (
                <tr key={i} className="border-b last:border-0">
                  {keys.map((k) => (
                    <td key={k} className="py-1.5 pr-3">{String((row as Record<string, unknown>)[k] ?? "-")}</td>
                  ))}
                </tr>
              ))}
            </tbody>
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
          <div key={k}>
            <dt className="text-xs text-[var(--color-text-muted)]">{k}</dt>
            <dd className="font-medium">{typeof v === "object" ? JSON.stringify(v) : String(v)}</dd>
          </div>
        ))}
      </dl>
    );
  }
  return <p className="text-sm">{String(data)}</p>;
}

function PaymentForm({ endpoint, label }: { endpoint: (invoiceId: string) => string; label: string }) {
  const [invoiceId, setInvoiceId] = useState("");
  const [amount, setAmount] = useState("");
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [method, setMethod] = useState("");
  const [referenceNo, setReferenceNo] = useState("");
  const [currency, setCurrency] = useState("IDR");
  const [exchangeRate, setExchangeRate] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  async function pay() {
    if (!invoiceId || !(Number(amount) > 0)) return;
    setBusy(true); setError(null); setDone(null);
    try {
      await api.post(endpoint(invoiceId), {
        amount: Number(amount),
        payment_date: paymentDate || undefined,
        method: method || undefined,
        reference_no: referenceNo || undefined,
      });
      setDone(`Pembayaran ${label} #${invoiceId} tercatat.`);
      setInvoiceId(""); setAmount(""); setReferenceNo("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal mencatat pembayaran");
    } finally { setBusy(false); }
  }

  return (
    <div className="space-y-2">
      <div className="mb-2 flex flex-wrap items-center gap-2 text-sm">
        <span className="font-medium">Mata uang:</span>
        <Select value={currency} onChange={(e) => setCurrency(e.target.value)} className="w-32">
          <option value="IDR">IDR - Rupiah (default)</option>
          <option value="USD">USD - Dolar</option>
        </Select>
        {currency === "USD" && (
          <Input type="number" step="any" min="0" value={exchangeRate} onChange={(e) => setExchangeRate(e.target.value)} placeholder="Exchange rate ke IDR *" className="w-48" aria-label="Exchange rate" />
        )}
        {currency === "USD" && !exchangeRate && <span className="text-xs text-amber-700">USD wajib diisi exchange rate.</span>}
      </div>
      <div className="grid gap-2 sm:grid-cols-5">
        <Input value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} placeholder={`ID ${label} *`} aria-label={`ID ${label}`} />
        <Input type="number" step="any" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="Amount *" aria-label="Amount" />
        <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} aria-label="Tanggal bayar" />
        <Input value={method} onChange={(e) => setMethod(e.target.value)} placeholder="Metode (transfer)" aria-label="Metode" />
        <Input value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} placeholder="No. referensi" aria-label="No referensi" />
      </div>
      {error && <p role="alert" className="text-sm text-[var(--color-danger)]">{error}</p>}
      {done && <p role="status" className="text-sm text-[var(--color-success)]">{done}</p>}
      <Button loading={busy} disabled={!invoiceId || !(Number(amount) > 0) || (currency === "USD" && !exchangeRate)} onClick={pay}>Catat Pembayaran</Button>
    </div>
  );
}

/** AR/AP: invoice dari shipment, finalize AP, pembayaran, dan aging report. */
export default function ArApPage() {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [arShipment, setArShipment] = useState("");
  const [arDue, setArDue] = useState("");
  const [apInvoiceId, setApInvoiceId] = useState("");
  const [apExchange, setApExchange] = useState("");
  const [agingAsOf, setAgingAsOf] = useState(new Date().toISOString().slice(0, 10));
  const [arAging, setArAging] = useState<unknown>(null);
  const [apAging, setApAging] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100&status=SHIPPED")
      .then((r) => setShipments(r.data))
      .catch(() => {});
  }, []);

  async function run(fn: () => Promise<void>) {
    setBusy(true); setError(null); setMessage(null);
    try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : "Gagal"); } finally { setBusy(false); }
  }

  const createAr = () => run(async () => {
    if (!arShipment) return;
    const r = await api.post<{ id?: number; doc_no?: string }>(`/finance/ar/invoices/from-shipment/${arShipment}`, { due_date: arDue || undefined });
    setMessage(`AR Invoice ${r.doc_no ?? `#${r.id ?? ""}`} dibuat dari shipment.`);
  });

  const finalizeAp = () => run(async () => {
    if (!apInvoiceId) return;
    const r = await api.post<{ id?: number; doc_no?: string; status?: string }>(`/finance/ap/invoices/${apInvoiceId}/finalize-finance`, {
      exchange_rate: apExchange ? Number(apExchange) : undefined,
    });
    setMessage(`Supplier invoice #${apInvoiceId} difinalisasi${r.status ? ` (${r.status})` : ""}.`);
  });

  const loadAging = (kind: "ar" | "ap") => run(async () => {
    const r = await api.get<{ data: unknown }>(`/finance/${kind}/aging?as_of=${agingAsOf}`);
    if (kind === "ar") setArAging(r.data); else setApAging(r.data);
  });

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="AR / AP"
        description="Invoice piutang dari shipment, finalisasi utang supplier, pembayaran, dan aging report."
      />

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}
      {message && (
        <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">
          {message}
        </p>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">AR: Buat Invoice dari Shipment</h2>
          <p className="mt-1 text-xs text-[var(--color-text-muted)]">Shipment berstatus SHIPPED menjadi invoice piutang.</p>
          <div className="mt-2 space-y-2">
            <Select value={arShipment} onChange={(e) => setArShipment(e.target.value)} className="w-full">
              <option value="">- pilih SHIPPED shipment -</option>
              {shipments.map((s) => <option key={s.id} value={s.id}>{s.doc_no}</option>)}
            </Select>
            <label className="block text-sm">
              <span className="mb-1 block font-medium">Jatuh tempo</span>
              <Input type="date" value={arDue} onChange={(e) => setArDue(e.target.value)} />
            </label>
            <Button loading={busy} disabled={!arShipment} onClick={createAr}>Buat AR Invoice</Button>
          </div>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">AP: Finalisasi Supplier Invoice</h2>
          <p className="mt-1 text-xs text-[var(--color-text-muted)]">Supplier invoice yang sudah di-match dapat difinalisasi ke finance (BR-109 area AP).</p>
          <div className="mt-2 space-y-2">
            <Input value={apInvoiceId} onChange={(e) => setApInvoiceId(e.target.value)} placeholder="ID Supplier Invoice *" aria-label="ID supplier invoice" />
            <Input type="number" step="any" min="0" value={apExchange} onChange={(e) => setApExchange(e.target.value)} placeholder="Exchange rate (opsional)" aria-label="Exchange rate" />
            <Button loading={busy} disabled={!apInvoiceId} onClick={finalizeAp}>Finalisasi AP</Button>
          </div>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Pembayaran AR</h2>
          <p className="mt-1 text-xs text-[var(--color-text-muted)]">ID invoice AR dapat dilihat pada aging di bawah.</p>
          <div className="mt-2">
            <PaymentForm endpoint={(id) => `/finance/ar/invoices/${id}/payments`} label="AR Invoice" />
          </div>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Pembayaran AP</h2>
          <p className="mt-1 text-xs text-[var(--color-text-muted)]">Gunakan ID supplier invoice yang sudah difinalisasi.</p>
          <div className="mt-2">
            <PaymentForm endpoint={(id) => `/finance/ap/invoices/${id}/payments`} label="AP Invoice" />
          </div>
        </section>
      </div>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-semibold">Aging Report</h2>
          <label className="ml-auto text-sm">
            <span className="mr-2 text-xs text-[var(--color-text-muted)]">As of</span>
            <Input type="date" value={agingAsOf} onChange={(e) => setAgingAsOf(e.target.value)} className="w-44" />
          </label>
          <Button size="sm" variant="secondary" loading={busy} onClick={() => loadAging("ar")}>Muat AR Aging</Button>
          <Button size="sm" variant="secondary" loading={busy} onClick={() => loadAging("ap")}>Muat AP Aging</Button>
        </div>
        <div className="mt-4 space-y-4">
          {arAging !== null && (
            <MetricCard label="AR Aging" value="" supportingText="Tabel di bawah menampilkan bucket aging AR." />
          )}
          {arAging !== null && <GenericView data={arAging} />}
          {apAging !== null && (
            <MetricCard label="AP Aging" value="" supportingText="Tabel di bawah menampilkan bucket aging AP." />
          )}
          {apAging !== null && <GenericView data={apAging} />}
        </div>
      </section>
    </div>
  );
}