import { forwardRef, type InputHTMLAttributes } from "react";
import { Input } from "./Input";

export const SearchInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function SearchInput({ className = "", ...props }, ref) {
  return (
    <div className="relative">
      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[var(--color-text-muted)]">
        <circle cx="11" cy="11" r="6" />
        <path d="m16 16 4 4" strokeLinecap="round" />
      </svg>
      <Input ref={ref} type="search" className={`pl-9 ${className}`} {...props} />
    </div>
  );
});
