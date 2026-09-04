"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, StatusBadge } from "@/components/ui";

interface Mo { id: number; doc_no: string }

function GenericView({ data }: { data: unknown }) {
  if (data === null || data === undefined) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
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

/** Manufacturing valuation: allocation profiles, eligibility, WIP/FG valuation, freeze (D-06/D-07). */
export default function ValuationPage() {
  const [mos, setMos] = useState<Mo[]>([]);
  const [mo, setMo] = useState("");
  const [moDetail, setMoDetail] = useState<unknown>(null);

  const [profile, setProfile] = useState({ code: "", version: "1", effective_date: new Date().toISOString().slice(0, 10), rules: "" });
  const [profileId, setProfileId] = useState("");
  const [eligibilityProfile, setEligibilityProfile] = useState("");
  const [eligibilityDate, setEligibilityDate] = useState(new Date().toISOString().slice(0, 10));
  const [eligibilityId, setEligibilityId] = useState("");
  const [transferId, setTransferId] = useState("");
  const [movementId, setMovementId] = useState("");
  const [freezePeriod, setFreezePeriod] = useState(new Date().toISOString().slice(0, 7));
  const [freezeOther, setFreezeOther] = useState("");
  const [freezeSource, setFreezeSource] = useState("");
  const [freezeId, setFreezeId] = useState("");

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((r) => setMos(r.data)).catch(() => {});
  }, []);

  async function run(fn: () => Promise<void>) {
    setBusy(true); setError(null); setMessage(null);
    try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : "Gagal"); } finally { setBusy(false); }
  }

  const createProfile = () => run(async () => {
    let rules: unknown;
    try { rules = JSON.parse(profile.rules); } catch { setError("Rules harus JSON array valid."); return; }
    const r = await api.post<{ id: number }>(
      "/finance/valuation/allocation-profiles",
      { code: profile.code, version: Number(profile.version), effective_date: profile.effective_date, rules },
    );
    setProfileId(String(r.id));
    setMessage(`Allocation profile #${r.id} dibuat. Aktifkan dengan ID tersebut.`);
  });

  const activateProfile = () => run(async () => {
    const r = await api.post<{ id: number }>(`/finance/valuation/allocation-profiles/${profileId}/activate`, {});
    setMessage(`Profile #${r.id ?? profileId} diaktifkan.`);
  });

  const createEligibility = () => run(async () => {
    const r = await api.post<{ id: number }>(`/finance/valuation/production-orders/${mo}/eligibility`, {
      allocation_profile_id: Number(eligibilityProfile), effective_date: eligibilityDate,
    });
    setEligibilityId(String(r.id));
    setMessage(`Eligibility #${r.id} dibuat untuk MO. Aktifkan dengan ID tersebut.`);
  });

  const activateEligibility = () => run(async () => {
    await api.post(`/finance/valuation/eligibilities/${eligibilityId}/activate`, {});
    setMessage(`Eligibility #${eligibilityId} diaktifkan.`);
  });

  const valueWip = () => run(async () => {
    const r = await api.post<Record<string, unknown>>(`/finance/valuation/production-orders/${mo}/wip-transfers/${transferId}`, {});
    setMessage(`WIP transfer #${transferId} divaluasi: ${JSON.stringify(r).slice(0, 160)}`);
  });

  const valueFg = () => run(async () => {
    const r = await api.post<Record<string, unknown>>(`/finance/valuation/production-orders/${mo}/fg-receipts/${movementId}`, {});
    setMessage(`FG receipt #${movementId} divaluasi: ${JSON.stringify(r).slice(0, 160)}`);
  });

  const createFreeze = () => run(async () => {
    const r = await api.post<{ id: number }>(`/finance/valuation/production-orders/${mo}/freezes`, {
      period: freezePeriod,
      other_amount: freezeOther ? Number(freezeOther) : undefined,
      other_source: freezeSource || undefined,
    });
    setFreezeId(String(r.id));
    setMessage(`Freeze #${r.id} dibuat - apply untuk finalisasi D-09.`);
  });

  const applyFreeze = () => run(async () => {
    await api.post(`/finance/valuation/freezes/${freezeId}/apply`, {});
    setMessage(`Freeze #${freezeId} diaplikasikan.`);
  });

  const loadMoValuation = () => run(async () => {
    const r = await api.get<unknown>(`/finance/valuation/production-orders/${mo}`);
    setMoDetail(r);
  });

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="Valuasi Manufaktur"
        description="D-06/D-07: allocation profile, eligibility MO, valuasi WIP & FG receipt, freeze actual cost."
      />

      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      {message && <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</p>}

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-semibold">MO Kerja</h2>
          <select value={mo} onChange={(e) => setMo(e.target.value)} className="ml-auto rounded border px-2 py-1.5 text-sm">
            <option value="">- pilih MO -</option>
            {mos.map((m) => <option key={m.id} value={m.id}>{m.doc_no}</option>)}
          </select>
          <Button size="sm" variant="secondary" disabled={!mo} onClick={loadMoValuation}>Muat Valuasi MO</Button>
        </div>
        {moDetail !== null && <div className="mt-3"><GenericView data={moDetail} /></div>}
      </section>

      <div className="grid gap-4 md:grid-cols-2">
        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Allocation Profile</h2>
          <div className="mt-2 space-y-2">
            <Input value={profile.code} onChange={(e) => setProfile({ ...profile, code: e.target.value })} placeholder="Kode *" />
            <div className="grid grid-cols-2 gap-2">
              <Input type="number" min="1" value={profile.version} onChange={(e) => setProfile({ ...profile, version: e.target.value })} aria-label="Version" />
              <Input type="date" value={profile.effective_date} onChange={(e) => setProfile({ ...profile, effective_date: e.target.value })} aria-label="Effective date" />
            </div>
            <textarea
              value={profile.rules}
              onChange={(e) => setProfile({ ...profile, rules: e.target.value })}
              rows={4}
              placeholder={'Rules JSON array (12 entri): [{"component":"...","stage":"...","allocation_rule":"..."}, ...]'}
              className="w-full rounded border px-2 py-1.5 font-mono text-xs"
            />
            <div className="flex items-center gap-2">
              <Button loading={busy} disabled={!profile.code || !profile.rules} onClick={createProfile}>Buat Profile</Button>
              <Input value={profileId} onChange={(e) => setProfileId(e.target.value.replace(/\D/g, ""))} placeholder="Profile ID" className="w-28" aria-label="Profile ID" />
              <Button variant="secondary" disabled={!profileId} onClick={activateProfile}>Aktifkan</Button>
            </div>
          </div>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Eligibility MO</h2>
          <div className="mt-2 space-y-2">
            <Input value={eligibilityProfile} onChange={(e) => setEligibilityProfile(e.target.value.replace(/\D/g, ""))} placeholder="Allocation Profile ID *" />
            <Input type="date" value={eligibilityDate} onChange={(e) => setEligibilityDate(e.target.value)} aria-label="Effective date eligibility" />
            <div className="flex items-center gap-2">
              <Button loading={busy} disabled={!mo || !eligibilityProfile} onClick={createEligibility}>Buat Eligibility</Button>
            </div>
            <div className="flex items-center gap-2 border-t pt-2">
              <Input value={eligibilityId} onChange={(e) => setEligibilityId(e.target.value.replace(/\D/g, ""))} placeholder="Eligibility ID" className="w-36" aria-label="Eligibility ID" />
              <Button variant="secondary" disabled={!eligibilityId} onClick={activateEligibility}>Aktifkan</Button>
            </div>
          </div>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Valuasi WIP & FG</h2>
          <div className="mt-2 space-y-2">
            <div className="flex items-center gap-2">
              <Input value={transferId} onChange={(e) => setTransferId(e.target.value.replace(/\D/g, ""))} placeholder="WIP Transfer ID" className="w-44" aria-label="WIP transfer ID" />
              <Button loading={busy} disabled={!mo || !transferId} onClick={valueWip}>Value WIP</Button>
            </div>
            <div className="flex items-center gap-2">
              <Input value={movementId} onChange={(e) => setMovementId(e.target.value.replace(/\D/g, ""))} placeholder="FG Receipt Movement ID" className="w-44" aria-label="FG movement ID" />
              <Button loading={busy} disabled={!mo || !movementId} onClick={valueFg}>Value FG</Button>
            </div>
          </div>
        </section>

        <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="font-semibold">Freeze Actual Cost</h2>
          <div className="mt-2 space-y-2">
            <Input value={freezePeriod} onChange={(e) => setFreezePeriod(e.target.value)} placeholder="Periode (YYYY-MM)" aria-label="Periode freeze" />
            <div className="grid grid-cols-2 gap-2">
              <Input type="number" step="any" value={freezeOther} onChange={(e) => setFreezeOther(e.target.value)} placeholder="Other amount" aria-label="Other amount" />
              <Input value={freezeSource} onChange={(e) => setFreezeSource(e.target.value)} placeholder="Other source" aria-label="Other source" />
            </div>
            <div className="flex items-center gap-2">
              <Button loading={busy} disabled={!mo || !freezePeriod} onClick={createFreeze}>Buat Freeze</Button>
              <Input value={freezeId} onChange={(e) => setFreezeId(e.target.value.replace(/\D/g, ""))} placeholder="Freeze ID" className="w-32" aria-label="Freeze ID" />
              <Button variant="danger" disabled={!freezeId} onClick={applyFreeze}>Apply</Button>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}