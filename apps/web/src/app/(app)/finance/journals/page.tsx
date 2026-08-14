"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";

interface Coa { id: number; code: string; name: string; type: string }
interface JLine { coa_id: string; debit: string; credit: string; memo: string }

/** Jurnal manual — BR-101: wajib balanced; indikator Σdebit/Σkredit real-time */
export default function JournalsPage() {
  const [coas, setCoas] = useState<Coa[]>([]);
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [journalDate, setJournalDate] = useState(new Date().toISOString().slice(0, 10));
  const [description, setDescription] = useState("");
  const [lines, setLines] = useState<JLine[]>([
    { coa_id: "", debit: "", credit: "", memo: "" },
    { coa_id: "", debit: "", credit: "", memo: "" },
  ]);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get<{ data: Coa[] }>("/master/chart-of-accounts?per_page=500").then((r) => setCoas(r.data)).catch((e) => setError(e.message));
  }, []);

  const totals = useMemo(() => {
    const d = lines.reduce((s, l) => s + (Number(l.debit) || 0), 0);
    const c = lines.reduce((s, l) => s + (Number(l.credit) || 0), 0);
    return { d, c, balanced: Math.abs(d - c) < 0.0001 && d > 0 };
  }, [lines]);

  function setLine(i: number, field: keyof JLine, value: string) {
    const next = [...lines];
    next[i] = { ...next[i], [field]: value };
    // XOR UX: isi debit → kosongkan kredit (dan sebaliknya)
    if (field === "debit" && value) next[i].credit = "";
    if (field === "credit" && value) next[i].debit = "";
    setLines(next);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true); setError(null); setSuccess(null);
    try {
      const journal = await api.post<{ doc_no: string }>("/finance/journals", {
        period,
        journal_date: journalDate,
        description: description || undefined,
        lines: lines.map((l) => ({
          coa_id: Number(l.coa_id),
          debit: l.debit ? Number(l.debit) : undefined,
          credit: l.credit ? Number(l.credit) : undefined,
          memo: l.memo || undefined,
        })),
      });
      setSuccess(`Jurnal ${journal.doc_no} terposting.`);
      setLines([{ coa_id: "", debit: "", credit: "", memo: "" }, { coa_id: "", debit: "", credit: "", memo: "" }]);
      setDescription("");
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";
  const fmt = (n: number) => new Intl.NumberFormat("id-ID", { minimumFractionDigits: 2 }).format(n);

  return (
    <form onSubmit={save} className="space-y-4">
      <h1 className="text-xl font-bold">Jurnal Manual</h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {success && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{success}</p>}

      <section className="grid grid-cols-3 gap-3 rounded-xl border bg-white p-4">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Periode (YYYY-MM) *</span>
          <input value={period} onChange={(e) => setPeriod(e.target.value)} required pattern="\d{4}-\d{2}" className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Tanggal</span>
          <input type="date" value={journalDate} onChange={(e) => setJournalDate(e.target.value)} className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Deskripsi</span>
          <input value={description} onChange={(e) => setDescription(e.target.value)} className={input} />
        </label>
      </section>

      <section className="rounded-xl border bg-white p-4">
        <div className="mb-2 flex items-center justify-between">
          <h2 className="font-semibold">Baris jurnal</h2>
          <button type="button" onClick={() => setLines([...lines, { coa_id: "", debit: "", credit: "", memo: "" }])} className="rounded border px-3 py-1 text-sm">+ Baris</button>
        </div>

        <table className="w-full text-sm">
          <thead className="border-b text-left text-xs text-slate-500">
            <tr><th className="py-1">Akun (COA)</th><th className="py-1 text-right">Debit</th><th className="py-1 text-right">Kredit</th><th className="py-1">Memo</th><th></th></tr>
          </thead>
          <tbody>
            {lines.map((l, i) => (
              <tr key={i} className="border-b last:border-0">
                <td className="py-1.5 pr-2">
                  <select value={l.coa_id} onChange={(e) => setLine(i, "coa_id", e.target.value)} required className={input}>
                    <option value="">— pilih akun —</option>
                    {coas.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}</option>)}
                  </select>
                </td>
                <td className="py-1.5 pr-2"><input type="number" step="any" min="0" value={l.debit} onChange={(e) => setLine(i, "debit", e.target.value)} className={`${input} text-right`} /></td>
                <td className="py-1.5 pr-2"><input type="number" step="any" min="0" value={l.credit} onChange={(e) => setLine(i, "credit", e.target.value)} className={`${input} text-right`} /></td>
                <td className="py-1.5 pr-2"><input value={l.memo} onChange={(e) => setLine(i, "memo", e.target.value)} className={input} /></td>
                <td className="py-1.5">
                  <button type="button" onClick={() => setLines(lines.filter((_, x) => x !== i))} disabled={lines.length <= 2} className="text-xs text-red-600 disabled:opacity-30">✕</button>
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t font-semibold">
              <td className="py-2 text-right">Total</td>
              <td className="py-2 text-right">{fmt(totals.d)}</td>
              <td className="py-2 text-right">{fmt(totals.c)}</td>
              <td colSpan={2} className="py-2 pl-2">
                {totals.balanced
                  ? <span className="text-green-600">✔ Balanced</span>
                  : <span className="text-red-600">✘ Selisih {fmt(Math.abs(totals.d - totals.c))}</span>}
              </td>
            </tr>
          </tfoot>
        </table>
      </section>

      <button disabled={saving || !totals.balanced} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
        {saving ? "Memproses…" : "Posting Jurnal"}
      </button>
    </form>
  );
}
