"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { api, apiDownload, apiUpload } from "@/lib/api";
import { Button, DataTable, Field, Input, PageHeader, Pagination, Select, Textarea } from "@/components/ui";
import { PdPicker, type PageRows, type PdOption } from "../_components/pd-picker";

interface Spec { id: number; version: number; description: string | null; construction_notes: string | null; revision_notes: string | null }
interface Measurement { id: number; pom_code: string; size_id: number; value: string; tolerance: string | null; size?: { code: string } }
interface Chart { id: number; version: number; unit: string | null; revision_notes: string | null; lines_count?: number; lines?: Measurement[] }
interface Pack { id: number; version: number; file_name: string; size_bytes: number | null; sha256: string | null; revision_notes: string | null }
interface Development {
  style: { id: number; style_no: string; lifecycle: string }; latest_spec_version: number; latest_chart_version: number;
  specs: PageRows<Spec>; charts: PageRows<Chart>; tech_packs: PageRows<Pack> | null; tech_pack_max_kb: number;
  permissions: { write_specs: boolean; view_packs: boolean; upload_packs: boolean };
}
interface DraftLine { key: string; pom_code: string; size: PdOption | null; value: string; tolerance: string }
const panel = "space-y-4 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]";

export default function StyleDevelopmentPage() {
  const search = useSearchParams();
  const [style, setStyle] = useState<PdOption | null>(null);
  useEffect(() => {
    const id = Number(search.get("style"));
    if (Number.isInteger(id) && id > 0) setStyle({ id, label: `Style #${id}` });
  }, [search]);
  return <div className="space-y-4">
    <PageHeader eyebrow="Product Development" title="Style Spec, Size Spec & Tech Pack"
      description="Versi tersimpan tidak ditimpa. Revisi membuat versi baru; sample menautkan versi yang dipilih secara eksplisit." />
    <section className={panel}><PdPicker kind="styles" label="Style" value={style} onChange={setStyle} required /></section>
    {style && <DevelopmentWorkbench key={style.id} styleId={style.id} />}
  </div>;
}

function DevelopmentWorkbench({ styleId }: { styleId: number }) {
  const [data, setData] = useState<Development | null>(null);
  const [pages, setPages] = useState({ spec: 1, chart: 1, pack: 1 });
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [description, setDescription] = useState("");
  const [construction, setConstruction] = useState("");
  const [specNotes, setSpecNotes] = useState("");
  const [specOpen, setSpecOpen] = useState(false);
  const [chartOpen, setChartOpen] = useState(false);
  const [unit, setUnit] = useState("CM");
  const [chartNotes, setChartNotes] = useState("");
  const [lines, setLines] = useState<DraftLine[]>([{ key: "first", pom_code: "", size: null, value: "", tolerance: "" }]);
  const [chart, setChart] = useState<Chart | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [packNotes, setPackNotes] = useState("");
  const uploadKey = useRef("");
  const fileInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    api.get<Development>(`/pd/styles/${styleId}/development?spec_page=${pages.spec}&chart_page=${pages.chart}&pack_page=${pages.pack}`)
      .then((r) => { if (active) { setData(r); setError(null); } })
      .catch((e: Error) => { if (active) setError(e.message); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [styleId, pages.spec, pages.chart, pages.pack, revision]);

  function refresh() { setRevision((r) => r + 1); }
  function start() { setBusy(true); setError(null); setMessage(null); }
  async function saveSpec(event: FormEvent) {
    event.preventDefault(); if (!data) return; start();
    try {
      const saved = await api.post<Spec>(`/pd/styles/${styleId}/specs`, { expected_version: data.latest_spec_version,
        description: description || null, construction_notes: construction || null, revision_notes: specNotes || null });
      setDescription(""); setConstruction(""); setSpecNotes("");
      setMessage(`Style Spec v${saved.version} tersimpan sebagai versi baru.`); setPages((p) => ({ ...p, spec: 1 })); refresh();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan spec"); }
    finally { setBusy(false); }
  }
  async function saveChart(event: FormEvent) {
    event.preventDefault(); if (!data) return; start();
    try {
      if (lines.some((line) => !line.size)) throw new Error("Pilih size pada setiap baris measurement.");
      const saved = await api.post<Chart>(`/pd/styles/${styleId}/measurements`, { expected_version: data.latest_chart_version,
        unit, revision_notes: chartNotes || null, lines: lines.map((line) => ({ pom_code: line.pom_code,
          size_id: line.size!.id, value: Number(line.value), tolerance: line.tolerance === "" ? null : Number(line.tolerance) })) });
      setChart(saved); setChartNotes("");
      setMessage(`Measurement / Size Spec v${saved.version} tersimpan.`); setPages((p) => ({ ...p, chart: 1 })); refresh();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan measurement"); }
    finally { setBusy(false); }
  }
  async function showChart(id: number) {
    start();
    try { setChart(await api.get<Chart>(`/pd/measurement-charts/${id}`)); }
    catch (e) { setError(e instanceof Error ? e.message : "Gagal memuat measurement"); }
    finally { setBusy(false); }
  }
  async function upload(event: FormEvent) {
    event.preventDefault(); if (!data || !file) return; start();
    try {
      if (file.size > data.tech_pack_max_kb * 1024) throw new Error("Ukuran file melebihi batas upload.");
      if (!uploadKey.current) uploadKey.current = crypto.randomUUID();
      const form = new FormData(); form.append("file", file); form.append("upload_key", uploadKey.current);
      if (packNotes) form.append("revision_notes", packNotes);
      const saved = await apiUpload<Pack>(`/pd/styles/${styleId}/tech-packs`, form);
      setFile(null); setPackNotes(""); uploadKey.current = ""; if (fileInput.current) fileInput.current.value = "";
      setMessage(`Tech Pack v${saved.version} diunggah ke private storage.`); setPages((p) => ({ ...p, pack: 1 })); refresh();
    } catch (e) { setError(e instanceof Error ? e.message : "Upload gagal"); }
    finally { setBusy(false); }
  }
  async function download(pack: Pack) {
    start();
    try { await apiDownload(`/pd/tech-packs/${pack.id}/download`, pack.file_name); }
    catch (e) { setError(e instanceof Error ? e.message : "Download gagal"); }
    finally { setBusy(false); }
  }
  function updateLine(key: string, patch: Partial<DraftLine>) { setLines((rows) => rows.map((row) => row.key === key ? { ...row, ...patch } : row)); }
  const pageControls = <T,>(rows: PageRows<T>, key: "spec" | "chart" | "pack") => <Pagination page={rows.current_page}
    totalPages={rows.last_page} totalRecords={rows.total} onPageChange={(page) => setPages((p) => ({ ...p, [key]: page }))} />;

  return <>
    {error && <div role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error} <Button size="sm" onClick={refresh}>Muat ulang</Button></div>}
    {message && <p role="status" className="rounded bg-green-50 p-3 text-sm text-green-800">{message}</p>}
    {loading && <p role="status" className="text-sm text-slate-500">Memuat versi style…</p>}
    {data && <>
      <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">{data.style.style_no} · {data.style.lifecycle}</h2>
        <Link className="text-sm text-blue-700 underline" href={`/pd/samples?style=${styleId}`}>Lanjut ke Sample & Buyer Approval</Link></div>
      <section className={panel} id="style-spec">
        <h2 className="font-semibold">Style Spec</h2>
        <DataTable<Spec> caption="Riwayat Style Spec" rows={data.specs.data} getRowKey={(r) => r.id} emptyTitle="Belum ada Style Spec" minWidth="560px"
          columns={[
            { key: "version", header: "Versi", cell: (r) => `v${r.version}` },
            { key: "description", header: "Deskripsi / konstruksi", className: "whitespace-normal max-w-lg", cell: (r) => <div><p>{r.description}</p><p className="text-slate-500">{r.construction_notes}</p></div> },
            { key: "revision", header: "Catatan revisi", className: "whitespace-normal", cell: (r) => r.revision_notes ?? "—" },
            { key: "copy", header: "Aksi", cell: (r) => data.permissions.write_specs && <Button size="sm" disabled={busy} onClick={() => { setDescription(r.description ?? ""); setConstruction(r.construction_notes ?? ""); setSpecNotes(`Revisi berdasarkan v${r.version}`); setSpecOpen(true); }}>Salin untuk revisi</Button> },
          ]} />
        {pageControls(data.specs, "spec")}
        {data.permissions.write_specs && <details open={specOpen} onToggle={(e) => setSpecOpen(e.currentTarget.open)}><summary className="cursor-pointer text-sm font-semibold">Buat Style Spec v{data.latest_spec_version + 1}</summary>
          <form onSubmit={saveSpec} className="mt-4 space-y-3">
            <Field label="Deskripsi style" htmlFor="spec-description"><Textarea id="spec-description" value={description} onChange={(e) => setDescription(e.target.value)} rows={3} maxLength={20000} /></Field>
            <Field label="Catatan konstruksi" htmlFor="spec-construction"><Textarea id="spec-construction" value={construction} onChange={(e) => setConstruction(e.target.value)} rows={3} maxLength={20000} /></Field>
            <Field label="Catatan revisi" htmlFor="spec-notes"><Input id="spec-notes" value={specNotes} onChange={(e) => setSpecNotes(e.target.value)} maxLength={5000} /></Field>
            <Button type="submit" loading={busy} disabled={loading || (!description.trim() && !construction.trim())}>Simpan versi baru</Button>
          </form></details>}
      </section>
      <section className={panel} id="size-spec">
        <h2 className="font-semibold">Measurement / Size Spec</h2>
        <DataTable<Chart> caption="Riwayat Size Spec" rows={data.charts.data} getRowKey={(r) => r.id} emptyTitle="Belum ada Size Spec" minWidth="480px"
          columns={[
            { key: "version", header: "Versi", cell: (r) => <Button size="sm" disabled={busy} onClick={() => showChart(r.id)}>v{r.version}</Button> },
            { key: "unit", header: "Unit", cell: (r) => r.unit ?? "UNKNOWN — legacy" },
            { key: "lines", header: "POM × size", cell: (r) => r.lines_count ?? 0 },
            { key: "notes", header: "Catatan revisi", className: "whitespace-normal", cell: (r) => r.revision_notes ?? "—" },
          ]} />
        {pageControls(data.charts, "chart")}
        {chart && <div className="space-y-2 border-t pt-3">
          <h3 className="text-sm font-semibold">Detail v{chart.version} · {chart.unit ?? "Unit legacy belum ditetapkan"}</h3>
          <DataTable<Measurement> caption="Point of measure per size" rows={chart.lines ?? []} getRowKey={(r) => r.id} emptyTitle="Tidak ada baris" minWidth="400px"
            columns={[
              { key: "pom", header: "POM", cell: (r) => r.pom_code }, { key: "size", header: "Size", cell: (r) => r.size?.code ?? `#${r.size_id}` },
              { key: "value", header: "Ukuran", cell: (r) => r.value }, { key: "tolerance", header: "Toleransi ±", cell: (r) => r.tolerance ?? "Tidak ditetapkan" },
            ]} />
          {data.permissions.write_specs && <Button size="sm" disabled={busy} onClick={() => { setChartOpen(true); setUnit(chart.unit ?? ""); setChartNotes(`Revisi berdasarkan v${chart.version}`);
            setLines((chart.lines ?? []).map((r) => ({ key: crypto.randomUUID(), pom_code: r.pom_code, size: { id: r.size_id, label: r.size?.code ?? `#${r.size_id}` }, value: r.value, tolerance: r.tolerance ?? "" }))); }}>Salin baris untuk revisi</Button>}
        </div>}
        {data.permissions.write_specs && <details open={chartOpen} onToggle={(e) => setChartOpen(e.currentTarget.open)}><summary className="cursor-pointer text-sm font-semibold">Buat Size Spec v{data.latest_chart_version + 1}</summary>
          <form onSubmit={saveChart} className="mt-4 space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Unit seluruh chart" htmlFor="chart-unit" required><Select id="chart-unit" value={unit} onChange={(e) => setUnit(e.target.value)} required><option value="">Pilih unit…</option><option>CM</option><option>MM</option><option>IN</option></Select></Field>
              <Field label="Catatan revisi" htmlFor="chart-notes"><Input id="chart-notes" value={chartNotes} onChange={(e) => setChartNotes(e.target.value)} maxLength={5000} /></Field>
            </div>
            {lines.map((line, index) => <fieldset key={line.key} className="rounded border p-3"><legend className="px-1 text-xs font-semibold">Measurement {index + 1}</legend>
              <div className="grid items-start gap-3 md:grid-cols-4">
                <Field htmlFor={`pom-${line.key}`} label="POM code" required><Input id={`pom-${line.key}`} value={line.pom_code} maxLength={32} required onChange={(e) => updateLine(line.key, { pom_code: e.target.value })} /></Field>
                <PdPicker kind="sizes" label="Size" value={line.size} onChange={(size) => updateLine(line.key, { size })} required disabled={busy} />
                <Field htmlFor={`value-${line.key}`} label="Ukuran" required><Input id={`value-${line.key}`} type="number" min="0.001" step="0.001" required value={line.value} onChange={(e) => updateLine(line.key, { value: e.target.value })} /></Field>
                <Field htmlFor={`tol-${line.key}`} label="Toleransi ±" hint="Kosong = belum ditetapkan"><Input id={`tol-${line.key}`} type="number" min="0" step="0.001" value={line.tolerance} onChange={(e) => updateLine(line.key, { tolerance: e.target.value })} /></Field>
              </div><Button size="sm" disabled={busy || lines.length <= 1} onClick={() => setLines((rows) => rows.filter((r) => r.key !== line.key))}>Hapus baris</Button>
            </fieldset>)}
            <div className="flex flex-wrap gap-2"><Button disabled={busy || lines.length >= 500} onClick={() => setLines((rows) => [...rows, { key: crypto.randomUUID(), pom_code: "", size: null, value: "", tolerance: "" }])}>Tambah POM × size</Button>
              <Button type="submit" loading={busy} disabled={loading || lines.length === 0}>Simpan versi baru</Button></div>
          </form></details>}
      </section>
      <section className={panel} id="tech-pack">
        <h2 className="font-semibold">Tech Pack · private upload & versioning</h2>
        <p className="text-xs text-slate-500">File lama tidak ditimpa. Download memerlukan permission Tech Pack; file tidak dibuka lewat URL publik.</p>
        {data.tech_packs ? <><DataTable<Pack> caption="Riwayat Tech Pack" rows={data.tech_packs.data} getRowKey={(r) => r.id} emptyTitle="Belum ada Tech Pack" minWidth="560px"
          columns={[
            { key: "version", header: "Versi", cell: (r) => `v${r.version}` },
            { key: "file", header: "File", className: "whitespace-normal", cell: (r) => <div>{r.file_name}<p className="text-xs text-slate-500">{r.size_bytes !== null ? `${r.size_bytes.toLocaleString("id-ID")} bytes` : "Metadata legacy"}</p></div> },
            { key: "notes", header: "Catatan revisi", className: "whitespace-normal", cell: (r) => r.revision_notes ?? "—" },
            { key: "download", header: "Aksi", cell: (r) => <Button size="sm" disabled={busy || !r.sha256} onClick={() => download(r)}>{r.sha256 ? "Download" : "Perlu rekonsiliasi"}</Button> },
          ]} />{pageControls(data.tech_packs, "pack")}</> : <p className="text-sm text-slate-500">Permission pd.techpack.view diperlukan untuk melihat file.</p>}
        {data.permissions.upload_packs && <form onSubmit={upload} className="space-y-3 border-t pt-3">
          <Field label="Upload versi Tech Pack baru" htmlFor="pack-file" required hint={`PDF, PNG, JPEG, DOCX, XLSX, ZIP · maks. ${data.tech_pack_max_kb / 1024} MiB`}>
            <Input id="pack-file" ref={fileInput} type="file" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx,.zip" required disabled={busy}
              onChange={(e) => { setFile(e.target.files?.[0] ?? null); uploadKey.current = ""; }} /></Field>
          <Field label="Catatan revisi file" htmlFor="pack-notes"><Input id="pack-notes" value={packNotes} disabled={busy} maxLength={5000}
            onChange={(e) => { setPackNotes(e.target.value); uploadKey.current = ""; }} /></Field>
          <Button type="submit" loading={busy} disabled={!file}>Upload versi baru</Button>
        </form>}
      </section>
    </>}
  </>;
}
