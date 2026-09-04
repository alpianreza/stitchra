"use client";

import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select } from "@/components/ui";

interface Currency { id: number; code: string; name: string; symbol?: string | null }
interface ExchangeRate { id: number; currency_id: number; rate_date: string; rate: string | number }

export default function CurrencyPage() {
  const [currencies, setCurrencies] = useState<Currency[]>([]);
  const [rates, setRates] = useState<ExchangeRate[]>([]);
  const [currencyId, setCurrencyId] = useState("");
  const [rateDate, setRateDate] = useState(new Date().toISOString().slice(0, 10));
  const [idrPerUsd, setIdrPerUsd] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    const [currencyResponse, rateResponse] = await Promise.all([
      api.get<{ data: Currency[] }>("/master/currencies?per_page=100"),
      api.get<{ data: ExchangeRate[] }>("/master/exchange-rates?per_page=100"),
    ]);
    setCurrencies(currencyResponse.data);
    setRates(rateResponse.data);
    const idr = currencyResponse.data.find((item) => item.code.toUpperCase() === "IDR");
    if (idr) setCurrencyId(String(idr.id));
  };

  useEffect(() => { load().catch((e) => setError(e.message)); }, []);

  async function ensureCurrency(code: "USD" | "IDR", name: string, symbol: string) {
    const existing = currencies.find((item) => item.code.toUpperCase() === code);
    if (existing) return existing;
    return api.post<Currency>("/master/currencies", { code, name, symbol });
  }

  async function initialize() {
    setBusy(true); setError(null); setMessage(null);
    try {
      await ensureCurrency("USD", "US Dollar", "$");
      await ensureCurrency("IDR", "Indonesian Rupiah", "Rp");
      await load();
      setMessage("Master USD dan IDR tersedia. USD tetap menjadi base currency.");
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyiapkan currency"); }
    finally { setBusy(false); }
  }

  async function saveRate(e: React.FormEvent) {
    e.preventDefault();
    const quoted = Number(idrPerUsd);
    if (!currencyId || !(quoted > 0)) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const currency = currencies.find((item) => item.id === Number(currencyId));
      if (!currency || currency.code.toUpperCase() !== "IDR") throw new Error("Kurs lokal hanya untuk IDR.");
      const normalizedRate = Number((1 / quoted).toFixed(12));
      await api.post("/master/exchange-rates", {
        currency_id: Number(currencyId),
        rate_date: rateDate,
        rate: normalizedRate,
      });
      setMessage(`Kurs ${rateDate} disimpan: 1 USD = ${quoted.toLocaleString("id-ID")} IDR.`);
      setIdrPerUsd("");
      await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan kurs"); }
    finally { setBusy(false); }
  }

  const rows = useMemo(() => rates.map((rate) => {
    const currency = currencies.find((item) => item.id === rate.currency_id);
    const normalized = Number(rate.rate);
    return {
      ...rate,
      code: currency?.code ?? `#${rate.currency_id}`,
      display: normalized > 0 ? 1 / normalized : 0,
    };
  }).sort((a, b) => b.rate_date.localeCompare(a.rate_date)), [currencies, rates]);

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Finance" title="Currency & Kurs" description="USD adalah base currency. Transaksi lokal boleh memakai IDR dan dikonversi ke USD memakai snapshot kurs dokumen." />

      <div className="rounded-[var(--radius-surface)] border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
        <b>Konvensi:</b> operator memasukkan nilai yang umum dipakai, yaitu <b>1 USD = sejumlah IDR</b>. Sistem menyimpan faktor IDR→USD dengan presisi 12 desimal. Kurs pada dokumen yang sudah diposting tidak diubah oleh perubahan master kurs berikutnya.
      </div>
      {error && <p role="alert" className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p role="status" className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div><h2 className="font-semibold">Master Currency</h2><p className="text-xs text-[var(--color-text-muted)]">USD untuk transaksi utama; IDR untuk transaksi lokal.</p></div>
          <Button loading={busy} variant="secondary" onClick={initialize}>Pastikan USD & IDR tersedia</Button>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          <span className="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">USD · Base</span>
          {currencies.map((item) => <span key={item.id} className="rounded-full bg-slate-100 px-3 py-1 text-sm">{item.code} · {item.name}</span>)}
        </div>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Kurs IDR → USD</h2>
        <form onSubmit={saveRate} className="mt-3 grid gap-3 sm:grid-cols-4">
          <label className="text-sm"><span className="mb-1 block font-medium">Currency</span><Select value={currencyId} onChange={(e) => setCurrencyId(e.target.value)}><option value="">Pilih IDR</option>{currencies.filter((item) => item.code.toUpperCase() === "IDR").map((item) => <option key={item.id} value={item.id}>{item.code}</option>)}</Select></label>
          <label className="text-sm"><span className="mb-1 block font-medium">Tanggal kurs</span><Input type="date" value={rateDate} onChange={(e) => setRateDate(e.target.value)} required /></label>
          <label className="text-sm"><span className="mb-1 block font-medium">1 USD = IDR</span><Input type="number" step="any" min="0" value={idrPerUsd} onChange={(e) => setIdrPerUsd(e.target.value)} placeholder="contoh 16500" required /></label>
          <div className="flex items-end"><Button loading={busy} disabled={!currencyId || !(Number(idrPerUsd) > 0)} type="submit">Simpan Kurs</Button></div>
        </form>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Riwayat Kurs</h2>
        <div className="mt-3 overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left text-xs uppercase tracking-wider text-slate-500"><th className="py-2">Tanggal</th><th>Currency</th><th>Rate tersimpan</th><th>Format operator</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className="border-b last:border-0"><td className="py-2">{row.rate_date.slice(0, 10)}</td><td>{row.code}</td><td>{Number(row.rate).toFixed(12)} USD / {row.code}</td><td>1 USD = {row.display.toLocaleString("id-ID", { maximumFractionDigits: 4 })} {row.code}</td></tr>)}{rows.length === 0 && <tr><td colSpan={4} className="py-4 text-center text-slate-500">Belum ada kurs.</td></tr>}</tbody></table></div>
      </section>
    </div>
  );
}
