"use client";

import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from "react";

interface ModalProps {
  open: boolean;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
  onClose: () => void;
  closeDisabled?: boolean;
  size?: "sm" | "md" | "lg";
  /** "alertdialog" untuk dialog konfirmasi destruktif. */
  role?: "dialog" | "alertdialog";
}

const sizeClasses = {
  sm: "max-w-md",
  md: "max-w-xl",
  lg: "max-w-3xl",
};

const focusableSelector = [
  "button:not([disabled])",
  "[href]",
  "input:not([disabled])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  "[tabindex]:not([tabindex='-1'])",
].join(",");

export function Modal({
  open,
  title,
  description,
  children,
  footer,
  onClose,
  closeDisabled = false,
  size = "md",
  role = "dialog",
}: ModalProps) {
  const titleId = useId();
  const descriptionId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);

  // Lock scroll, simpan & pulihkan fokus, pindahkan fokus ke kontrol pertama
  // (fallback: panel itu sendiri bila tidak ada kontrol fokusabel).
  useEffect(() => {
    if (!open) return;

    previousFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const frame = window.requestAnimationFrame(() => {
      const firstControl = panelRef.current?.querySelector<HTMLElement>(focusableSelector);
      (firstControl ?? panelRef.current)?.focus();
    });

    return () => {
      window.cancelAnimationFrame(frame);
      document.body.style.overflow = previousOverflow;
      previousFocusRef.current?.focus();
    };
  }, [open]);

  // ESC bekerja meski fokus secara tak sengaja keluar dari panel.
  useEffect(() => {
    if (!open || closeDisabled) return;
    const handleKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onClose();
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [open, closeDisabled, onClose]);

  if (!open) return null;

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key !== "Tab") return;
    const controls = Array.from(panelRef.current?.querySelectorAll<HTMLElement>(focusableSelector) ?? []);
    if (controls.length === 0) {
      event.preventDefault();
      panelRef.current?.focus();
      return;
    }

    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <div className="fixed inset-0 z-[80] flex items-end justify-center p-0 sm:items-center sm:p-4" onKeyDown={handleKeyDown}>
      <button
        type="button"
        aria-label="Tutup dialog"
        disabled={closeDisabled}
        className="absolute inset-0 cursor-default bg-[var(--color-overlay)] disabled:cursor-default"
        onClick={closeDisabled ? undefined : onClose}
      />
      <div
        ref={panelRef}
        role={role}
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
        className={`relative flex max-h-[92vh] w-full flex-col rounded-t-[var(--radius-surface)] bg-[var(--color-surface)] shadow-[var(--shadow-overlay)] sm:rounded-[var(--radius-surface)] ${sizeClasses[size]}`}
      >
        <header className="flex items-start justify-between gap-4 border-b border-[var(--color-border-subtle)] px-5 py-4">
          <div>
            <h2 id={titleId} className="text-lg font-semibold text-[var(--color-text)]">
              {title}
            </h2>
            {description && <p id={descriptionId} className="mt-1 text-sm text-[var(--color-text-muted)]">{description}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={closeDisabled}
            aria-label="Tutup"
            className="inline-flex size-9 shrink-0 items-center justify-center rounded-[var(--radius-control)] text-xl text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text)] disabled:opacity-40"
          >
            <span aria-hidden="true">×</span>
          </button>
        </header>
        <div className="overflow-y-auto px-5 py-4">{children}</div>
        {footer && (
          <footer className="flex flex-col-reverse gap-2 border-t border-[var(--color-border-subtle)] px-5 py-4 sm:flex-row sm:justify-end">
            {footer}
          </footer>
        )}
      </div>
    </div>
  );
}
