"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select, StatusBadge } from "@/components/ui";

interface BankAccount { id: number; code: string; name: string; bank_name: string }
interface Coa { id: number; code: string; name: string }
interface StatementLine { id: number; transaction_date: string; direction: string; amount: string; reference_no?: string | null; description?: string | null; matches?: unknown[] }
interface Statement { id: number; statement_no: string; period_start: string; period_end: string; opening_balance: string; closing_balance: string; status?: string; lines: StatementLine[] }
interface Row { transaction_date: string; direction: string; amount: string; reference_no: string; description: string }
const blankRow = (): Row => ({ transaction_date: new Date().toISOString().slice(0, 10), direction: "CREDIT", amount: "", reference_no: "", description: "" });

/** Bank reconciliation: rekening, import statement, match/ignore/fee per line, reconcile. */
export default function BankReconPage() {
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [coas, setCoas] = useState<Coa[]>([]);
  const [acc, setAcc] = useState({ code: "", name: "", bank_name: "", coa_id: "" });
  const [accountId, setAccountId] = useState("");

  const [stmt, setStmt] = useState({ statement_no: "", period_start: new Date().toISOString().slice(0, 10), period_end: new Date().toISOString().slice(0, 10), opening_balance: "0", closing_balance: "0" });
  const [rows, setRows] = useState<Row[]>([blankRow()]);
  const [statement, setStatement] = useState<Statement | null>(null);
  const [stmtId, setStmtId] = useState("");

  const [matchLine, setMatchLine] = useState<number | null>(null);
  const [matchType, setMatchType] = useState("AR_PAYMENT");
  const [matchSource, setMatchSource] = useState("");
  const [matchAmount, setMatchAmount] = useState("");
  const [ignoreReason, setIgnoreReason] = useState<Record<number, string>>({});
  const [feeAmount, setFeeAmount] = useState<Record<number, string>>({});

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    loadAccounts();
    api.get<{ data: Coa[] }>("/master/chart-of-accounts?per_page=500").then((r) => setCoas(r.data)).catch(() => {});
  }, []);

  function loadAccounts() {
    api.get<{ data: BankAccount[] }>("/finance/bank-accounts").then((r) => setAccounts(r.data)).catch(() => {});
  }

  async function run(fn: () => Promise<void>) {
    setBusy(true); setError(null); setMessage(null);
    try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : "Gagal"); } finally { setBusy(false); }
  }

  const createAccount = () => run(async () => {
    await api.post("/finance/bank-accounts", { code: acc.code, name: acc.name, bank_name: acc.bank_name, coa_id: Number(acc.coa_id) });
    setMessage(`Rekening ${acc.code} dibuat.`); setAcc({ code: "", name: "", bank_name: "", coa_id: "" }); loadAccounts();
  });

  const importStatement = () => run(async () => {
    if (!accountId) { setError("Pilih rekening dulu."); return; }
    const r = await api.post<Statement>(`/finance/bank-accounts/${accountId}/statements`, {
      statement_no: stmt.statement_no,
      period_start: stmt.period_start,
      period_end: stmt.period_end,
      opening_balance: Number(stmt.opening_balance),
      closing_balance: Number(stmt.closing_balance),
      rows: rows.filter((x) => Number(x.amount) > 0).map((x) => ({
        transaction_date: x.transaction_date, direction: x.direction, amount: Number(x.amount),
        reference_no: x.reference_no || undefined, description: x.description || undefined,
      })),
    });
    setStatement(r); setStmtId(String(r.id));
    setMessage(`Statement ${r.statement_no} diimport (${r.lines.length} baris).`);
  });

  const loadStatement = () => run(async () => {
    const r = await api.get<Statement>(`/finance/bank-statements/${stmtId}`);
    setStatement(r);
  });

  const doMatch = (lineId: number) => run(async () => {
    const r = await api.post(`/finance/bank-statement-lines/${lineId}/match`, { source_type: matchType, source_id: Number(matchSource), amount: Number(matchAmount) });
    setMessage(`Line #${lineId} matched (${(r as unknown as { match_status?: string }).match_status ?? "OK"}).`);
    if (stmtId) { const s = await api.get<Statement>(`/finance/bank-statements/${stmtId}`); setStatement(s); }
    setMatchLine(null); setMatchSource(""); setMatchAmount("");
  });

  const doIgnore = (lineId: number) => run(async () => {
    await api.post(`/finance/bank-statement-lines/${lineId}/ignore`, { reason: ignoreReason[lineId] || "-" });
    setMessage(`Line #${lineId} diabaikan.`);
    if (stmtId) { const s = await api.get<Statement>(`/finance/bank-statements/${stmtId}`); setStatement(s); }
  });

  const doFee = (lineId: number) => run(async () => {
    await api.post(`/finance/bank-statement-lines/${lineId}/bank-fee`, { amount: Number(feeAmount[lineId]) });
    setMessage(`Bank fee line #${lineId} dicatat.`);
    if (stmtId) { const s = await api.get<Statement>(`/finance/bank-statements/${stmtId}`); setStatement(s); }
  });

  const doReconcile = () => run(async () => {
    const r = await api.post<Statement>(`/finance/bank-statements/${stmtId}/reconcile`, {});
    setStatement(r); setMessage(`Statement ${r.statement_no} reconcile selesai.`);
  });

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Finance" title="Bank Reconciliation" description="Rekening bank, import statement, match/ignore/fee per baris, dan reconcile." />

      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      {message && <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</p>}

      <div className="grid gap-4 md:grid-cols-2">
        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Rekening Bank</h2>
          <ul className="mt-2 space-y-1 text-sm">{accounts.map((a) => <li key={a.id}><span className="font-mono">{a.code}</span> {a.name} - {a.bank_name}</li>)}
            {accounts.length === 0 && <li className="text-[var(--color-text-muted)]">Belum ada rekening.</li>}</ul>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">
            <Input value={acc.code} onChange={(e) => setAcc({ ...acc, code: e.target.value })} placeholder="Kode *" />
            <Input value={acc.name} onChange={(e) => setAcc({ ...acc, name: e.target.value })} placeholder="Nama *" />
            <Input value={acc.bank_name} onChange={(e) => setAcc({ ...acc, bank_name: e.target.value })} placeholder="Nama bank *" />
            <Select value={acc.coa_id} onChange={(e) => setAcc({ ...acc, coa_id: e.target.value })}>
              <option value="">COA *</option>
              {coas.map((c) => <option key={c.id} value={c.id}>{c.code} - {c.name}</option>)}
            </Select>
          </div>
          <Button className="mt-2" size="sm" loading={busy} disabled={!acc.code || !acc.name || !acc.bank_name || !acc.coa_id} onClick={createAccount}>Tambah Rekening</Button>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Import Statement</h2>
          <div className="mt-2 space-y-2">
            <Select value={accountId} onChange={(e) => setAccountId(e.target.value)}>
              <option value="">- rekening -</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} - {a.bank_name}</option>)}
            </Select>
            <Input value={stmt.statement_no} onChange={(e) => setStmt({ ...stmt, statement_no: e.target.value })} placeholder="No. statement *" />
            <div className="grid grid-cols-2 gap-2">
              <Input type="date" value={stmt.period_start} onChange={(e) => setStmt({ ...stmt, period_start: e.target.value })} aria-label="Periode mulai" />
              <Input type="date" value={stmt.period_end} onChange={(e) => setStmt({ ...stmt, period_end: e.target.value })} aria-label="Periode akhir" />
              <Input type="number" step="any" value={stmt.opening_balance} onChange={(e) => setStmt({ ...stmt, opening_balance: e.target.value })} placeholder="Saldo awal *" aria-label="Saldo awal" />
              <Input type="number" step="any" value={stmt.closing_balance} onChange={(e) => setStmt({ ...stmt, closing_balance: e.target.value })} placeholder="Saldo akhir *" aria-label="Saldo akhir" />
            </div>
            <div className="space-y-1">
              {rows.map((r, i) => (
                <div key={i} className="grid grid-cols-[130px_100px_1fr_32px] gap-1">
                  <Input type="date" value={r.transaction_date} onChange={(e) => { const n = [...rows]; n[i].transaction_date = e.target.value; setRows(n); }} aria-label="Tanggal" />
                  <Select value={r.direction} onChange={(e) => { const n = [...rows]; n[i].direction = e.target.value; setRows(n); }} aria-label="Arah">
                    <option value="CREDIT">CREDIT</option><option value="DEBIT">DEBIT</option>
                  </Select>
                  <Input type="number" step="any" min="0" value={r.amount} onChange={(e) => { const n = [...rows]; n[i].amount = e.target.value; setRows(n); }} placeholder="Amount *" aria-label="Amount" />
                  <Button size="sm" variant="ghost" disabled={rows.length === 1} onClick={() => setRows(rows.filter((_, x) => x !== i))} aria-label="Hapus baris">x</Button>
                </div>
              ))}
              <Button size="sm" variant="ghost" onClick={() => setRows([...rows, blankRow()])}>+ Baris</Button>
            </div>
            <Button loading={busy} disabled={!accountId || !stmt.statement_no} onClick={importStatement}>Import Statement</Button>
          </div>
        </section>
      </div>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-semibold">Statement & Matching</h2>
          <Input value={stmtId} onChange={(e) => setStmtId(e.target.value.replace(/\D/g, ""))} placeholder="Statement ID" className="ml-auto w-32" aria-label="Statement ID" />
          <Button size="sm" variant="secondary" loading={busy} disabled={!stmtId} onClick={loadStatement}>Muat</Button>
          {statement && <Button size="sm" variant="danger" loading={busy} onClick={doReconcile}>Reconcile</Button>}
        </div>
        {statement && (
          <div className="mt-3 overflow-x-auto">
            <p className="text-sm font-medium">{statement.statement_no} ({statement.period_start} s/d {statement.period_end})</p>
            <table className="mt-2 w-full min-w-[900px] text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                  <th className="py-1.5 pr-2">Tanggal</th><th className="py-1.5 pr-2">Arah</th><th className="py-1.5 pr-2 text-right">Amount</th>
                  <th className="py-1.5 pr-2">Deskripsi</th><th className="py-1.5">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {(statement.lines ?? []).map((l) => (
                  <tr key={l.id} className="border-b last:border-0 align-top">
                    <td className="py-1.5 pr-2">{l.transaction_date}</td>
                    <td className="py-1.5 pr-2"><StatusBadge status={l.direction} /></td>
                    <td className="py-1.5 pr-2 text-right tabular-nums">{Number(l.amount).toLocaleString("id-ID")}</td>
                    <td className="py-1.5 pr-2 text-xs text-[var(--color-text-muted)]">{l.description ?? l.reference_no ?? "-"}</td>
                    <td className="py-1.5">
                      {matchLine === l.id ? (
                        <div className="space-y-1">
                          <div className="flex gap-1">
                            <Select value={matchType} onChange={(e) => setMatchType(e.target.value)} className="w-36">
                              <option value="AR_PAYMENT">AR_PAYMENT</option>
                              <option value="AP_PAYMENT">AP_PAYMENT</option>
                            </Select>
                            <Input value={matchSource} onChange={(e) => setMatchSource(e.target.value)} placeholder="Source ID" />
                            <Input type="number" step="any" min="0" value={matchAmount} onChange={(e) => setMatchAmount(e.target.value)} placeholder="Amount" />
                          </div>
                          <div className="flex gap-1">
                            <Button size="sm" variant="success" loading={busy} onClick={() => doMatch(l.id)}>Match</Button>
                            <Button size="sm" variant="ghost" onClick={() => setMatchLine(null)}>Batal</Button>
                          </div>
                        </div>
                      ) : (
                        <div className="flex flex-wrap gap-1">
                          <Button size="sm" variant="secondary" onClick={() => setMatchLine(l.id)}>Match</Button>
                          <Button size="sm" variant="ghost" loading={busy} onClick={() => doIgnore(l.id)}>Ignore</Button>
                          <Button size="sm" variant="ghost" loading={busy} onClick={() => doFee(l.id)}>Bank Fee</Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="mt-2 text-xs text-[var(--color-text-muted)]">Ignore butuh alasan (dikirim "-" bila kosong); Bank Fee memakai amount baris terpilih. Ignore by ID baris.</p>
          </div>
        )}
      </section>
    </div>
  );
}