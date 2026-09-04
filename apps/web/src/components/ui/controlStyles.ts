// Kelas dasar bersama untuk kontrol form (Input/Select/Textarea/FilterSelect).
// Mengacu penuh ke design token di globals.css — tanpa hardcode warna.
export const controlBaseClasses = [
  "min-h-10 w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] shadow-xs",
  "placeholder:text-[var(--color-text-muted)]",
  "disabled:cursor-not-allowed disabled:bg-[var(--color-surface-subtle)] disabled:text-[var(--color-text-muted)] disabled:opacity-70",
  "read-only:bg-[var(--color-surface-subtle)]",
].join(" ");

// State error: diterapkan lewat aria-invalid="true" (diset prop `invalid` atau Field).
export const controlInvalidClasses =
  "aria-[invalid=true]:border-[var(--color-danger)] aria-[invalid=true]:shadow-[0_0_0_3px_var(--color-danger-soft)]";
