"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select, StatusBadge } from "@/components/ui";

interface TaxCode { id: number; code: string; name: string; kind: string; rate_pct: string; is_active?: boolean }
interface Coa { id: number; code: string; name: string }

const KINDS = ["OUTPUT_TAX", "INPUT_TAX", "WITHHOLDING_RECEIVABLE", "WITHHOLDING_PAYABLE"];
const EVENTS = [
  "GR_RECEIPT", "MATERIAL_ISSUE", "PRODUCTION_RECEIPT", "SHIPMENT_COGS", "AR_INVOICE", "AR_TAX", "AR_WITHHOLDING",
  "AR_PAYMENT", "AR_FX_GAIN", "AR_FX_LOSS", "AP_TAX", "AP_WITHHOLDING", "AP_PAYMENT", "AP_FX_GAIN", "AP_FX_LOSS",
  "AR_FX_REVALUE_GAIN", "AR_FX_REVALUE_LOSS", "AP_FX_REVALUE_GAIN", "AP_FX_REVALUE_LOSS", "BANK_FEE", "SUBCON_FEE",
];

/** Pajak (tax codes) dan pemetaan event akuntansi ke akun debit/kredit. */
export default function TaxMappingsPage() {
  const [taxes, setTaxes] = useState<TaxCode[]>([]);
  const [tax, setTax] = useState({ code: "", name: "", kind: "OUTPUT_TAX", rate_pct: "11" });
  const [coas, setCoas] = useState<Coa[]>([]);
  const [mapping, setMapping] = useState({ event: EVENTS[0], debit_account_id: "", credit_account_id: "" });

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    loadTaxes();
    api.get<{ data: Coa[] }>("/master/chart-of-accounts?per_page=100").then((r) => setCoas(r.data)).catch(() => {});
  }, []);

  function loadTaxes() {
    api.get<{ data: TaxCode[] }>("/finance/tax-codes").then((r) => setTaxes(r.data)).catch((e) => setError(e.message));
  }

  async function run(fn: () => Promise<void>) {
    setBusy(true); setError(null); setMessage(null);
    try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : "Gagal"); } finally { setBusy(false); }
  }

  const createTax = () => run(async () => {
    await api.post("/finance/tax-codes", { code: tax.code, name: tax.name, kind: tax.kind, rate_pct: Number(tax.rate_pct) });
    setMessage(`Tax code ${tax.code} dibuat.`); setTax({ code: "", name: "", kind: "OUTPUT_TAX", rate_pct: "11" }); loadTaxes();
  });

  const deactivateTax = (id: number) => run(async () => {
    await api.delete(`/finance/tax-codes/${id}`);
    setMessage(`Tax code #${id} dinonaktifkan.`); loadTaxes();
  });

  const saveMapping = () => run(async () => {
    await api.post("/finance/account-mappings", {
      event: mapping.event,
      debit_account_id: Number(mapping.debit_account_id),
      credit_account_id: Number(mapping.credit_account_id),
    });
    setMessage(`Mapping ${mapping.event} disimpan (updateOrCreate).`);
  });

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Finance" title="Pajak & Mapping Akun" description="Tax codes dan pemetaan event akuntansi ke akun debit/kredit." />

      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      {message && <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</p>}

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Tax Codes</h2>
        <div className="mt-2 grid gap-2 sm:grid-cols-4">
          <Input value={tax.code} onChange={(e) => setTax({ ...tax, code: e.target.value })} placeholder="Kode *" />
          <Input value={tax.name} onChange={(e) => setTax({ ...tax, name: e.target.value })} placeholder="Nama *" />
          <Select value={tax.kind} onChange={(e) => setTax({ ...tax, kind: e.target.value })}>
            {KINDS.map((k) => <option key={k}>{k}</option>)}
          </Select>
          <Input type="number" step="any" min="0" max="100" value={tax.rate_pct} onChange={(e) => setTax({ ...tax, rate_pct: e.target.value })} placeholder="Rate %" />
        </div>
        <Button className="mt-2" size="sm" loading={busy} disabled={!tax.code || !tax.name} onClick={createTax}>Tambah Tax Code</Button>
        <div className="mt-3 overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]"><th className="py-1.5 pr-2">Kode</th><th className="py-1.5 pr-2">Nama</th><th className="py-1.5 pr-2">Kind</th><th className="py-1.5 pr-2 text-right">Rate %</th><th className="py-1.5">Status</th><th className="py-1.5" /></tr></thead>
            <tbody>
              {taxes.map((t) => (
                <tr key={t.id} className="border-b last:border-0">
                  <td className="py-1.5 pr-2 font-mono">{t.code}</td>
                  <td className="py-1.5 pr-2">{t.name}</td>
                  <td className="py-1.5 pr-2 text-xs">{t.kind}</td>
                  <td className="py-1.5 pr-2 text-right tabular-nums">{t.rate_pct}</td>
                  <td className="py-1.5 pr-2"><StatusBadge status={t.is_active === false ? "INACTIVE" : "ACTIVE"} /></td>
                  <td className="py-1.5 text-right">{t.is_active === false ? "-" : <Button size="sm" variant="ghost" loading={busy} onClick={() => deactivateTax(t.id)}>Nonaktifkan</Button>}</td>
                </tr>
              ))}
              {taxes.length === 0 && <tr><td colSpan={6} className="py-3 text-center text-[var(--color-text-muted)]">Belum ada tax code.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Account Mapping per Event</h2>
        <p className="mt-1 text-xs text-[var(--color-text-muted)]">updateOrCreate per event - debit dan kredit tidak boleh sama.</p>
        <div className="mt-2 grid gap-2 sm:grid-cols-4">
          <Select value={mapping.event} onChange={(e) => setMapping({ ...mapping, event: e.target.value })}>
            {EVENTS.map((ev) => <option key={ev}>{ev}</option>)}
          </Select>
          <Select value={mapping.debit_account_id} onChange={(e) => setMapping({ ...mapping, debit_account_id: e.target.value })}>
            <option value="">Akun debit *</option>
            {coas.map((c) => <option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}
          </Select>
          <Select value={mapping.credit_account_id} onChange={(e) => setMapping({ ...mapping, credit_account_id: e.target.value })}>
            <option value="">Akun kredit *</option>
            {coas.map((c) => <option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}
          </Select>
          <Button loading={busy} disabled={!mapping.debit_account_id || !mapping.credit_account_id} onClick={saveMapping}>Simpan Mapping</Button>
        </div>
      </section>
    </div>
  );
}