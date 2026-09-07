"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { api } from "@/lib/api";
import { Button, ConfirmDialog, DataTable, Field, Input, PageHeader, Pagination, Select, StatusBadge, Textarea } from "@/components/ui";
import { PdPicker, type PageRows, type PdOption } from "../_components/pd-picker";

interface SampleRow {
  id: number; doc_no: string; style_id: number; stage: string; version: number; buyer_status: string;
  style?: { id: number; style_no: string }; style_spec_id: number | null; measurement_chart_id: number | null;
  tech_pack_id: number | null; revision_of_id: number | null; notes: string | null;
}
interface Approval { id: number; status: string; comment: string | null; by_name: string | null; response_reference: string | null; recorded_by: number | null; created_at: string }
interface ProductionUse { id: number; doc_no: string; status: string; sample_gate_checked_at: string | null }
interface SampleDetail extends SampleRow {
  superseded: boolean; approvals: PageRows<Approval>; production_uses: PageRows<ProductionUse>;
  style_spec?: { version: number } | null; measurement_chart?: { version: number; unit: string | null } | null;
  permissions: { create: boolean; respond: boolean };
}
interface SampleList extends PageRows<SampleRow> { permissions: { create: boolean } }
const STAGES = ["PROTO", "FIT", "PP", "TOP"];
const panel = "space-y-4 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]";

export default function SamplesPage() {
  const search = useSearchParams();
  const [style, setStyle] = useState<PdOption | null>(null);
  const [stage, setStage] = useState("PROTO");
  const [spec, setSpec] = useState<PdOption | null>(null);
  const [chart, setChart] = useState<PdOption | null>(null);
  const [pack, setPack] = useState<PdOption | null>(null);
  const [revisionOf, setRevisionOf] = useState<number | null>(null);
  const [notes, setNotes] = useState("");
  const [q, setQ] = useState("");
  const [stageFilter, setStageFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState<SampleList | null>(null);
  const [sampleId, setSampleId] = useState<number | null>(null);
  const [detail, setDetail] = useState<SampleDetail | null>(null);
  const [approvalPage, setApprovalPage] = useState(1);
  const [usePage, setUsePage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [status, setStatus] = useState("APPROVED");
  const [byName, setByName] = useState("");
  const [reference, setReference] = useState("");
  const [comment, setComment] = useState("");
  const [confirm, setConfirm] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const createRequest = useRef({ fingerprint: "", key: "" });
  const responseRequest = useRef({ fingerprint: "", key: "" });

  useEffect(() => {
    const initialStyle = Number(search.get("style"));
    if (Number.isInteger(initialStyle) && initialStyle > 0) setStyle({ id: initialStyle, label: `Style #${initialStyle}` });
    const initialSample = Number(search.get("sample"));
    if (Number.isInteger(initialSample) && initialSample > 0) setSampleId(initialSample);
  }, [search]);
  useEffect(() => {
    let active = true; setLoading(true);
    const timer = setTimeout(() => {
      const params = new URLSearchParams({ q, stage: stageFilter, buyer_status: statusFilter, page: String(page), per_page: "20" });
      if (style) params.set("style_id", String(style.id));
      api.get<SampleList>(`/pd/samples?${params}`)
        .then((r) => { if (active) setRows(r); })
        .catch((e: Error) => { if (active) setError(e.message); })
        .finally(() => { if (active) setLoading(false); });
    }, 200);
    return () => { active = false; clearTimeout(timer); };
  }, [q, stageFilter, statusFilter, page, style?.id, revision]);
  useEffect(() => {
    let active = true;
    if (!sampleId) { setDetail(null); return; }
    api.get<SampleDetail>(`/pd/samples/${sampleId}?approval_page=${approvalPage}&use_page=${usePage}`)
      .then((r) => { if (active) setDetail(r); })
      .catch((e: Error) => { if (active) { setError(e.message); setDetail(null); } });
    return () => { active = false; };
  }, [sampleId, approvalPage, usePage, revision]);

  function changeStyle(value: PdOption | null) {
    setStyle(value); setSpec(null); setChart(null); setPack(null); setRevisionOf(null); setPage(1);
  }
  function selectSample(id: number) {
    setDetail(null); setSampleId(id); setApprovalPage(1); setUsePage(1); setError(null); setMessage(null);
    setReference(""); setComment(""); setConfirm(false);
  }
  function start() { setBusy(true); setError(null); setMessage(null); }
  async function create(event: FormEvent) {
    event.preventDefault(); if (!style) return; start();
    const payload = { style_id: style.id, stage, style_spec_id: spec?.id ?? null, measurement_chart_id: chart?.id ?? null,
      tech_pack_id: pack?.id ?? null, revision_of_id: revisionOf, notes: notes || null };
    const fingerprint = JSON.stringify(payload);
    if (createRequest.current.fingerprint !== fingerprint) createRequest.current = { fingerprint, key: crypto.randomUUID() };
    try {
      const saved = await api.post<SampleRow>("/pd/samples", { ...payload, request_key: createRequest.current.key });
      selectSample(saved.id); setRevision((r) => r + 1); setPage(1); setCreateOpen(false); setNotes(""); setRevisionOf(null);
      createRequest.current = { fingerprint: "", key: "" };
      setMessage(`${saved.doc_no} · ${saved.stage} v${saved.version} dibuat dengan status PENDING.`);
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal membuat sample"); }
    finally { setBusy(false); }
  }
  async function recordResponse() {
    if (!detail) return; start();
    const payload = { status, by_name: byName, response_reference: reference, comment: comment || null };
    const fingerprint = JSON.stringify({ sample: detail.id, ...payload });
    if (responseRequest.current.fingerprint !== fingerprint) responseRequest.current = { fingerprint, key: crypto.randomUUID() };
    try {
      await api.post(`/pd/samples/${detail.id}/approvals`, { ...payload, request_key: responseRequest.current.key });
      setConfirm(false); setReference(""); setComment(""); setApprovalPage(1); setRevision((r) => r + 1);
      responseRequest.current = { fingerprint: "", key: "" };
      setMessage(`Respons buyer ${status} dicatat. Gate MO akan memvalidasi ulang saat release.`);
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal mencatat respons"); setConfirm(false); }
    finally { setBusy(false); }
  }
  function revise() {
    if (!detail) return;
    setStyle({ id: detail.style_id, label: detail.style?.style_no ?? `Style #${detail.style_id}` }); setStage(detail.stage);
    setSpec(detail.style_spec_id ? { id: detail.style_spec_id, label: `Style Spec v${detail.style_spec?.version ?? "?"}` } : null);
    setChart(detail.measurement_chart_id ? { id: detail.measurement_chart_id, label: `Size Spec v${detail.measurement_chart?.version ?? "?"}` } : null);
    setPack(detail.tech_pack_id ? { id: detail.tech_pack_id, label: `Tech Pack #${detail.tech_pack_id}` } : null);
    setRevisionOf(detail.id); setNotes(`Revisi dari ${detail.doc_no}`); setCreateOpen(true); setPage(1);
  }
  return <div className="space-y-4">
    <PageHeader eyebrow="Product Development" title="Sample & Buyer Approval"
      description="PROTO / FIT / PP / TOP, revisi per stage, referensi spec yang tersimpan, dan riwayat respons buyer." />
    {error && <p role="alert" className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    {message && <p role="status" className="rounded bg-green-50 p-3 text-sm text-green-800">{message}</p>}
    <section className={panel}>
      <PdPicker kind="styles" label="Style (filter dan pembuatan sample)" value={style} onChange={changeStyle} disabled={busy} />
      {style && <Link className="text-sm text-blue-700 underline" href={`/pd/styles?style=${style.id}`}>Buka Style Spec, Size Spec & Tech Pack</Link>}
      {rows?.permissions.create && <details open={createOpen} onToggle={(e) => setCreateOpen(e.currentTarget.open)}>
        <summary className="cursor-pointer text-sm font-semibold">{revisionOf ? "Revisi sample — versi baru" : "Buat sample / versi baru"}</summary>
        <form onSubmit={create} className="mt-4 space-y-3">
          <p className="text-xs text-slate-500">Versi dihitung server per style/stage. Referensi spec opsional dan tidak otomatis berubah saat versi baru diterbitkan.</p>
          <Field htmlFor="sample-stage" label="Stage" required><Select id="sample-stage" value={stage} disabled={busy} onChange={(e) => { setStage(e.target.value); setRevisionOf(null); }}>
            {STAGES.map((s) => <option key={s}>{s}</option>)}</Select></Field>
          <div className="grid gap-4 lg:grid-cols-3">
            <PdPicker key={`spec-${style?.id}`} kind="specs" styleId={style?.id} label="Versi Style Spec" value={spec} onChange={setSpec} disabled={busy} />
            <PdPicker key={`chart-${style?.id}`} kind="charts" styleId={style?.id} label="Versi Measurement / Size Spec" value={chart} onChange={setChart} disabled={busy} />
            <PdPicker key={`pack-${style?.id}`} kind="tech_packs" styleId={style?.id} label="Versi Tech Pack" value={pack} onChange={setPack} disabled={busy} />
          </div>
          <Field htmlFor="sample-notes" label="Catatan sample / revisi"><Textarea id="sample-notes" value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={5000} rows={2} disabled={busy} /></Field>
          <Button type="submit" loading={busy} disabled={!style}>Buat sample PENDING</Button>
        </form>
      </details>}
    </section>
    <section className={panel}>
      <div className="grid gap-3 md:grid-cols-3">
        <Input aria-label="Cari nomor sample" placeholder="Cari nomor sample…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        <Select aria-label="Filter stage" value={stageFilter} onChange={(e) => { setStageFilter(e.target.value); setPage(1); }}><option value="">Semua stage</option>{STAGES.map((s) => <option key={s}>{s}</option>)}</Select>
        <Select aria-label="Filter status buyer" value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}><option value="">Semua status</option>{["PENDING", "APPROVED", "REJECTED", "COMMENTED"].map((s) => <option key={s}>{s}</option>)}</Select>
      </div>
      <DataTable<SampleRow> caption="Sample per style/stage" rows={rows?.data ?? []} getRowKey={(r) => r.id} loading={loading} emptyTitle="Belum ada sample" minWidth="620px"
        columns={[
          { key: "doc", header: "Sample", cell: (r) => <Button size="sm" disabled={busy} onClick={() => selectSample(r.id)}>{r.doc_no}</Button> },
          { key: "style", header: "Style", cell: (r) => r.style?.style_no ?? `#${r.style_id}` },
          { key: "version", header: "Stage / versi", cell: (r) => `${r.stage} · v${r.version}` },
          { key: "status", header: "Buyer status", cell: (r) => <StatusBadge status={r.buyer_status} /> },
        ]} />
      {rows && <Pagination page={rows.current_page} totalPages={rows.last_page} totalRecords={rows.total} onPageChange={setPage} />}
    </section>
    {detail && <section className={panel}>
      <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-mono font-semibold">{detail.doc_no}</h2>
        <p className="text-sm text-slate-500">{detail.style?.style_no} · {detail.stage} v{detail.version}</p></div><StatusBadge status={detail.buyer_status} /></div>
      <p className="text-sm">{detail.notes}</p>
      <dl className="grid gap-3 rounded bg-slate-50 p-3 text-sm sm:grid-cols-3">
        <div><dt className="text-xs text-slate-500">Style Spec snapshot</dt><dd>{detail.style_spec_id ? `v${detail.style_spec?.version ?? "?"} · #${detail.style_spec_id}` : "Tidak ditautkan"}</dd></div>
        <div><dt className="text-xs text-slate-500">Size Spec snapshot</dt><dd>{detail.measurement_chart_id ? `v${detail.measurement_chart?.version ?? "?"} · ${detail.measurement_chart?.unit ?? "unit unknown"}` : "Tidak ditautkan"}</dd></div>
        <div><dt className="text-xs text-slate-500">Tech Pack snapshot</dt><dd>{detail.tech_pack_id ? `Tech Pack #${detail.tech_pack_id}` : "Tidak ditautkan"}</dd></div>
      </dl>
      {detail.superseded ? <p className="rounded bg-amber-50 p-3 text-sm text-amber-900">Versi lama/ambigu: tidak dapat dipakai untuk release MO baru. Gunakan versi terbaru pada stage ini.</p>
        : detail.permissions.create && <Button disabled={busy} onClick={revise}>Buat revisi dari sample ini</Button>}
      <p className="text-xs text-slate-500">Gate produksi memakai sample APPROVED yang dipilih planner secara eksplisit pada MO. Stage tidak otomatis dianggap PP; kebijakan stage wajib per buyer belum ditetapkan.</p>
      {detail.permissions.respond && !detail.superseded && <form onSubmit={(e) => { e.preventDefault(); setConfirm(true); }} className="space-y-3 border-t pt-4">
        <h3 className="text-sm font-semibold">Catat respons buyer</h3>
        <p className="text-xs text-slate-500">Dicatat oleh user internal berdasarkan bukti buyer, bukan approval internal atau autentikasi buyer.</p>
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="Respons" htmlFor="response-status" required><Select id="response-status" value={status} disabled={busy} onChange={(e) => setStatus(e.target.value)}>{["APPROVED", "REJECTED", "COMMENTED"].map((s) => <option key={s}>{s}</option>)}</Select></Field>
          <Field label="Nama pihak buyer" htmlFor="response-buyer" required><Input id="response-buyer" required maxLength={255} value={byName} disabled={busy} onChange={(e) => setByName(e.target.value)} /></Field>
          <Field label="Referensi respons buyer" htmlFor="response-reference" required hint="Nomor/email/thread atau referensi bukti"><Input id="response-reference" required maxLength={255} value={reference} disabled={busy} onChange={(e) => setReference(e.target.value)} /></Field>
        </div>
        <Field label="Komentar" htmlFor="response-comment" required={status !== "APPROVED"}><Textarea id="response-comment" rows={2} maxLength={5000} required={status !== "APPROVED"} value={comment} disabled={busy} onChange={(e) => setComment(e.target.value)} /></Field>
        <Button type="submit" disabled={busy || !byName.trim() || !reference.trim()}>Simpan respons buyer</Button>
      </form>}
      <h3 className="text-sm font-semibold">Riwayat respons · append-only</h3>
      <DataTable<Approval> caption="Riwayat respons buyer" rows={detail.approvals.data} getRowKey={(r) => r.id} emptyTitle="Belum ada respons buyer" minWidth="680px"
        columns={[
          { key: "status", header: "Respons", cell: (r) => <StatusBadge status={r.status} /> },
          { key: "buyer", header: "Buyer / bukti", className: "whitespace-normal", cell: (r) => <div>{r.by_name ?? "Legacy — tidak tercatat"}<p className="text-xs text-slate-500">{r.response_reference ?? "Referensi legacy tidak tersedia"}</p></div> },
          { key: "comment", header: "Komentar", className: "whitespace-normal max-w-lg", cell: (r) => r.comment ?? "—" },
          { key: "recorded", header: "Dicatat", cell: (r) => <div>{new Date(r.created_at).toLocaleString("id-ID")}<p className="text-xs text-slate-500">User {r.recorded_by ?? "legacy"}</p></div> },
        ]} />
      <Pagination page={detail.approvals.current_page} totalPages={detail.approvals.last_page} totalRecords={detail.approvals.total} onPageChange={setApprovalPage} />
      <h3 className="text-sm font-semibold">MO yang memilih sample ini</h3>
      <DataTable<ProductionUse> caption="Pemakaian sample pada MO" rows={detail.production_uses.data} getRowKey={(r) => r.id} emptyTitle="Belum dipilih pada MO" minWidth="500px"
        columns={[
          { key: "mo", header: "MO", cell: (r) => <Link className="text-blue-700 underline" href={`/production/orders/${r.id}`}>{r.doc_no}</Link> },
          { key: "status", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
          { key: "gate", header: "Gate terakhir saat release", cell: (r) => r.sample_gate_checked_at ? new Date(r.sample_gate_checked_at).toLocaleString("id-ID") : "Dipilih; belum diverifikasi saat release" },
        ]} />
      <Pagination page={detail.production_uses.current_page} totalPages={detail.production_uses.last_page} totalRecords={detail.production_uses.total} onPageChange={setUsePage} />
    </section>}
    <ConfirmDialog open={confirm} title={`Catat respons ${status}?`} description={`Respons untuk ${detail?.doc_no ?? "sample"} akan menjadi evidence terbaru. Gate MO memvalidasi ulang status ini saat release; MO yang sudah berjalan tidak diubah otomatis.`}
      confirmLabel="Catat respons buyer" loading={busy} onConfirm={recordResponse} onCancel={() => setConfirm(false)} />
  </div>;
}
