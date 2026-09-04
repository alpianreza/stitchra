"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import ValuationBoundaryPanel from "./valuation-boundary-panel";
import { Button, ConfirmDialog, Input, PageHeader, StatusBadge } from "@/components/ui";

interface Coa { id: number; code: string; name: string; type: string }
interface JLine { coa_id: string; debit: string; credit: string; memo: string }
interface AuthorityRow { operational_event: string; accounting_event: string | null; existing_authority: string; journal_defined: string; mapping_configured: boolean; period_rule: string; reversal_defined: string; status: string; implementation: string }
interface Authority { company: { code: string; base_currency: string }; rows: AuthorityRow[]; late_transaction_treatment: string; approval: string }

type Tab = "manual" | "posting" | "authority";
const input = "w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-sm";

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

export default function JournalsPage() {
  const [tab, setTab] = useState<Tab>("manual");
  const [coas, setCoas] = useState<Coa[]>([]);
  const [authority, setAuthority] = useState<Authority | null>(null);
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [journalDate, setJournalDate] = useState(new Date().toISOString().slice(0, 10));
  const [description, setDescription] = useState("");
  const [lines, setLines] = useState<JLine[]>([
    { coa_id: "", debit: "", credit: "", memo: "" },
    { coa_id: "", debit: "", credit: "", memo: "" },
  ]);
  const [grId, setGrId] = useState("");
  const [journalId, setJournalId] = useState("");
  const [lineage, setLineage] = useState<unknown>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [reverseOpen, setReverseOpen] = useState(false);
  const [reversing, setReversing] = useState(false);
  const [reverseReason, setReverseReason] = useState("");

  useEffect(() => {
    api.get<{ data: Coa[] }>("/master/chart-of-accounts?per_page=500").then((r) => setCoas(r.data)).catch((e) => setError(e.message));
    api.get<Authority>("/finance/gl/operational-authority").then(setAuthority).catch(() => {});
  }, []);

  const totals = useMemo(() => {
    const d = lines.reduce((s, l) => s + (Number(l.debit) || 0), 0);
    const c = lines.reduce((s, l) => s + (Number(l.credit) || 0), 0);
    return { d, c, balanced: Math.abs(d - c) < 0.0001 && d > 0 };
  }, [lines]);

  function setLine(i: number, field: keyof JLine, value: string) {
    const next = [...lines]; next[i] = { ...next[i], [field]: value };
    if (field === "debit" && value) next[i].credit = "";
    if (field === "credit" && value) next[i].debit = "";
    setLines(next);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault(); setSaving(true); setError(null); setSuccess(null);
    try {
      const journal = await api.post<{ doc_no: string }>("/finance/journals", {
        period, journal_date: journalDate, description: description || undefined,
        lines: lines.map((l) => ({ coa_id: Number(l.coa_id), debit: l.debit ? Number(l.debit) : undefined, credit: l.credit ? Number(l.credit) : undefined, memo: l.memo || undefined })),
      });
      setSuccess(`Jurnal ${journal.doc_no} terposting.`);
      setLines([{ coa_id: "", debit: "", credit: "", memo: "" }, { coa_id: "", debit: "", credit: "", memo: "" }]); setDescription("");
    } catch (err: any) { setError(err.message); } finally { setSaving(false); }
  }

  async function postGr() {
    setError(null); setSuccess(null);
    try {
      const result = await api.post<{ created: boolean; journal: { id: number; doc_no: string } }>(`/finance/gl/operational-postings/goods-receipts/${grId}`, {});
      setJournalId(String(result.journal.id));
      setSuccess(`${result.created ? "Jurnal baru dibuat" : "Jurnal sudah ada"}: ${result.journal.doc_no}.`);
    } catch (err: any) { setError(err.message); }
  }

  async function loadLineage() {
    setError(null); setLineage(null);
    try { setLineage(await api.get(`/finance/journals/${journalId}/lineage`)); }
    catch (err: any) { setError(err.message); }
  }

  async function reverseJournal() {
    if (!journalId) return;
    setReversing(true); setError(null); setSuccess(null);
    try {
      await api.post(`/finance/journals/${journalId}/reverse`, reverseReason ? { reason: reverseReason } : {});
      setSuccess(`Jurnal #${journalId} berhasil direversal - jurnal balik terposting immutable.`);
      setReverseOpen(false); setReverseReason("");
      setLineage(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal reversal jurnal");
      setReverseOpen(false);
    } finally { setReversing(false); }
  }

  const fmt = (n: number) => new Intl.NumberFormat("id-ID", { minimumFractionDigits: 2 }).format(n);
  const tone = (status: string) => status === "DEFINED" ? "text-green-700" : status === "BLOCKED" ? "text-red-700" : "text-amber-700";

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="Jurnal & GL"
        description="Catat jurnal manual (debit/kredit), buat jurnal otomatis dari penerimaan barang, telusuri asal-usul jurnal, dan lihat event mana yang boleh masuk GL."
      />

      <div className="flex flex-wrap gap-2">
        {([["manual", "Jurnal Manual"], ["posting", "Posting GR & Lineage"], ["authority", "Aturan GL & Valuasi"]] as [Tab, string][]).map(([k, label]) => (
          <button key={k} onClick={() => setTab(k)} className={`rounded-full px-4 py-1.5 text-sm font-medium ${tab === k ? "bg-[var(--color-primary)] text-white" : "border bg-white"}`}>
            {label}
          </button>
        ))}
      </div>

      {error && <pre className="whitespace-pre-wrap rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">{error}</pre>}
      {success && <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{success}</p>}

      {tab === "manual" && (
        <form onSubmit={save} className="space-y-4">
          <section className="grid gap-3 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)] md:grid-cols-3">
            <label className="text-sm"><span className="mb-1 block font-medium">Periode (YYYY-MM) *</span><input value={period} onChange={(e) => setPeriod(e.target.value)} required pattern="\d{4}-\d{2}" className={input} /></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Tanggal</span><input type="date" value={journalDate} onChange={(e) => setJournalDate(e.target.value)} className={input} /></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Deskripsi</span><input value={description} onChange={(e) => setDescription(e.target.value)} className={input} /></label>
          </section>
          <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold">Baris jurnal</h2><button type="button" onClick={() => setLines([...lines, { coa_id: "", debit: "", credit: "", memo: "" }])} className="rounded border px-3 py-1 text-sm">+ Baris</button></div>
            <table className="w-full text-sm"><thead className="border-b text-left text-xs text-slate-500"><tr><th className="py-1">Akun (COA)</th><th className="text-right">Debit</th><th className="text-right">Kredit</th><th>Memo</th><th /></tr></thead><tbody>
              {lines.map((line, i) => <tr key={i} className="border-b last:border-0"><td className="py-1.5 pr-2"><select value={line.coa_id} onChange={(e) => setLine(i, "coa_id", e.target.value)} required className={input}><option value="">- pilih akun -</option>{coas.map((coa) => <option key={coa.id} value={coa.id}>{coa.code} - {coa.name}</option>)}</select></td><td className="pr-2"><input type="number" step="any" min="0" value={line.debit} onChange={(e) => setLine(i, "debit", e.target.value)} className={`${input} text-right`} /></td><td className="pr-2"><input type="number" step="any" min="0" value={line.credit} onChange={(e) => setLine(i, "credit", e.target.value)} className={`${input} text-right`} /></td><td className="pr-2"><input value={line.memo} onChange={(e) => setLine(i, "memo", e.target.value)} className={input} /></td><td><button type="button" onClick={() => setLines(lines.filter((_, x) => x !== i))} disabled={lines.length <= 2} className="text-xs text-red-600 disabled:opacity-30">x</button></td></tr>)}
            </tbody><tfoot><tr className="border-t font-semibold"><td className="py-2 text-right">Total</td><td className="text-right">{fmt(totals.d)}</td><td className="text-right">{fmt(totals.c)}</td><td colSpan={2} className="pl-2">{totals.balanced ? <span className="text-green-600">- Balanced</span> : <span className="text-red-600">Selisih {fmt(Math.abs(totals.d - totals.c))}</span>}</td></tr></tfoot></table>
          </section>
          <Button type="submit" loading={saving} disabled={saving || !totals.balanced}>Posting Jurnal</Button>
        </form>
      )}

      {tab === "posting" && (
        <div className="space-y-4">
          <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <h2 className="font-semibold">Posting otomatis dari Goods Receipt</h2>
            <p className="mt-1 text-sm text-[var(--color-text-muted)]">
              Saat penerimaan barang (GR) sudah diposting, sistem dapat membuat jurnal GL-nya otomatis - cukup masukkan ID Goods Receipt.
            </p>
            <div className="mt-3 flex flex-wrap items-end gap-2">
              <label className="text-sm"><span className="mb-1 block font-medium">Goods Receipt ID</span><input type="number" min="1" value={grId} onChange={(e) => setGrId(e.target.value)} className={input} /></label>
              <Button variant="primary" disabled={!grId} onClick={postGr}>Post GR ke GL</Button>
            </div>
          </section>

          <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <h2 className="font-semibold">Telusuri & reversal jurnal</h2>
            <p className="mt-1 text-sm text-[var(--color-text-muted)]">
              Masukkan ID jurnal untuk melihat asal-usulnya (dari dokumen apa jurnal itu lahir), atau membatalkannya lewat jurnal balik.
            </p>
            <div className="mt-3 flex flex-wrap items-end gap-2">
              <label className="text-sm"><span className="mb-1 block font-medium">Journal ID</span><input type="number" min="1" value={journalId} onChange={(e) => setJournalId(e.target.value)} className={input} /></label>
              <Button variant="secondary" disabled={!journalId} onClick={loadLineage}>Muat Lineage</Button>
              <Button variant="danger" disabled={!journalId} onClick={() => setReverseOpen(true)}>Reverse Jurnal</Button>
            </div>
            {lineage !== null && (
              <div className="mt-3 rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">Lineage jurnal #{journalId}</p>
                <GenericView data={lineage} />
              </div>
            )}
          </section>

          <ConfirmDialog
            open={reverseOpen}
            title="Reverse jurnal?"
            description={`Jurnal #${journalId} akan dibuat jurnal baliknya (immutable, teraudit). Jurnal costing/shipment harus lewat Accounting Correction dan akan ditolak sistem.`}
            confirmLabel="Reverse"
            variant="danger"
            loading={reversing}
            onConfirm={reverseJournal}
            onCancel={() => setReverseOpen(false)}
          >
            <label className="block text-sm">
              <span className="mb-1 block font-medium">Alasan (opsional)</span>
              <textarea value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} maxLength={1000} rows={2} className="w-full rounded border px-2 py-1.5 text-sm" />
            </label>
          </ConfirmDialog>
        </div>
      )}

      {tab === "authority" && (
        <div className="space-y-4">
          {authority && (
            <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
              <div className="flex justify-between gap-2"><h2 className="font-semibold">Event operasional mana yang boleh masuk GL</h2><span className="text-xs text-slate-500">{authority.company.code} - {authority.company.base_currency}</span></div>
              <div className="mt-2 overflow-x-auto"><table className="w-full text-xs"><thead><tr className="border-b text-left"><th>Event operasional</th><th>Event akuntansi</th><th>Status</th><th>Mapping</th><th>Implementasi</th></tr></thead><tbody>
                {authority.rows.map((row) => <tr key={row.operational_event} className="border-b align-top"><td className="py-2 pr-2">{row.operational_event}</td><td className="pr-2">{row.accounting_event ?? "- NOT DEFINED"}</td><td className={`${tone(row.status)} pr-2 font-medium`}>{row.status}</td><td className="pr-2">{row.mapping_configured ? "configured" : "belum"}</td><td>{row.implementation}</td></tr>)}
              </tbody></table></div>
              <p className="mt-2 text-xs text-amber-700">{authority.late_transaction_treatment}</p>
            </section>
          )}
          <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
            <h2 className="font-semibold">Batas valuasi & batas GL operasional</h2>
            <p className="mt-1 text-xs text-[var(--color-text-muted)]">Matriks siapa yang berwenang menilai (valuation) vs memposting (GL) per dokumen.</p>
            <ValuationBoundaryPanel />
          </section>
        </div>
      )}
    </div>
  );
}