"use client";

import { useEffect, useId, useState } from "react";
import { api } from "@/lib/api";
import { Field, Input, Select } from "@/components/ui";

export interface SourceOption {
  id: number;
  label: string;
  material_id?: number;
  uom_id?: number;
  qty?: string;
}
export type SourceKind =
  "materials" | "uoms" | "suppliers" | "currencies" | "pr_lines";

export function SourceSelect({
  kind,
  label,
  value,
  onChange,
  required = false,
  disabled = false,
}: {
  kind: SourceKind;
  label: string;
  value: SourceOption | null;
  onChange: (option: SourceOption | null) => void;
  required?: boolean;
  disabled?: boolean;
}) {
  const id = useId();
  const [q, setQ] = useState("");
  const [options, setOptions] = useState<SourceOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let active = true;
    setLoading(true);
    const timer = setTimeout(() => {
      api
        .get<{ data: SourceOption[] }>(
          `/purchasing/sourcing/options?kind=${kind}&q=${encodeURIComponent(q)}&per_page=50`,
        )
        .then((r) => {
          if (active) {
            setOptions(r.data);
            setError(null);
          }
        })
        .catch((e: Error) => {
          if (active) setError(e.message);
        })
        .finally(() => {
          if (active) setLoading(false);
        });
    }, 200);
    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [kind, q]);
  const rows =
    value && !options.some((o) => o.id === value.id)
      ? [value, ...options]
      : options;
  return (
    <div className="space-y-1.5">
      <Field htmlFor={id} label={label} required={required}>
        <Select
          id={id}
          value={value?.id ?? ""}
          required={required}
          disabled={disabled}
          onChange={(e) =>
            onChange(rows.find((o) => o.id === Number(e.target.value)) ?? null)
          }
        >
          <option value="">{required ? "Pilih…" : "Tidak dipilih"}</option>
          {rows.map((o) => (
            <option key={o.id} value={o.id}>
              {o.label}
            </option>
          ))}
        </Select>
      </Field>
      <Input
        aria-label={`Cari ${label}`}
        value={q}
        disabled={disabled}
        onChange={(e) => setQ(e.target.value)}
        placeholder={`Cari ${label.toLowerCase()}…`}
      />
      <p className="text-xs text-[var(--color-text-muted)]" aria-live="polite">
        {loading
          ? "Memuat pilihan…"
          : "Maks. 50 pilihan; gunakan pencarian untuk data lainnya."}
      </p>
      {error && (
        <p role="alert" className="text-xs text-red-700">
          {error}
        </p>
      )}
    </div>
  );
}
