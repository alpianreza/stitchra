"use client";

import { useEffect, useId, useState } from "react";
import { api } from "@/lib/api";
import { Field, Input, Pagination, Select } from "@/components/ui";

export interface PdOption { id: number; label: string }
export interface PageRows<T> { data: T[]; current_page: number; last_page: number; total: number }
type Kind = "styles" | "sizes" | "specs" | "charts" | "tech_packs";
interface Row { id: number; style_no?: string; code?: string; version?: number; file_name?: string; unit?: string | null }

export function PdPicker({ kind, styleId, label, value, onChange, required = false, disabled = false }: {
  kind: Kind; styleId?: number; label: string; value: PdOption | null;
  onChange: (value: PdOption | null) => void; required?: boolean; disabled?: boolean;
}) {
  const id = useId();
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<PageRows<Row> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const searchable = kind === "styles" || kind === "sizes";
  useEffect(() => { setPage(1); setResult(null); }, [styleId, kind]);
  useEffect(() => {
    let active = true;
    if (!searchable && !styleId) { setResult(null); return; }
    setLoading(true);
    const timer = setTimeout(() => {
      const path = searchable ? `/pd/${kind}?q=${encodeURIComponent(q)}&` : `/pd/styles/${styleId}/sample-sources?kind=${kind}&`;
      api.get<PageRows<Row>>(`${path}per_page=25&page=${page}`)
        .then((r) => { if (active) { setResult(r); setError(null); } })
        .catch((e: Error) => { if (active) { setError(e.message); setResult(null); } })
        .finally(() => { if (active) setLoading(false); });
    }, 200);
    return () => { active = false; clearTimeout(timer); };
  }, [kind, styleId, q, page, searchable]);
  const options = (result?.data ?? []).map((r) => ({ id: r.id, label: r.style_no ?? r.code ?? `v${r.version}${r.file_name ? ` · ${r.file_name}` : r.unit ? ` · ${r.unit}` : ""}` }));
  if (value && !options.some((o) => o.id === value.id)) options.unshift(value);
  return <div className="space-y-2">
    <Field htmlFor={id} label={label} required={required}>
      <Select id={id} value={value?.id ?? ""} required={required} disabled={disabled || (!searchable && !styleId)}
        onChange={(e) => onChange(options.find((o) => o.id === Number(e.target.value)) ?? null)}>
        <option value="">{required ? "Pilih…" : "Tidak ditautkan"}</option>
        {options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
      </Select>
    </Field>
    {searchable && <Input aria-label={`Cari ${label}`} placeholder={`Cari ${label.toLowerCase()}…`} value={q} disabled={disabled}
      onChange={(e) => { setQ(e.target.value); setPage(1); }} />}
    {loading && <p className="text-xs text-slate-500" role="status">Memuat pilihan…</p>}
    {error && <p role="alert" className="text-xs text-red-700">{error}</p>}
    {result && result.last_page > 1 && <Pagination page={result.current_page} totalPages={result.last_page} onPageChange={setPage} />}
  </div>;
}
