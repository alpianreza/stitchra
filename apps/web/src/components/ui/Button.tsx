import type { ButtonHTMLAttributes, ReactNode } from "react";

type ButtonVariant = "primary" | "secondary" | "success" | "danger" | "ghost";
type ButtonSize = "sm" | "md" | "lg";

const variantClasses: Record<ButtonVariant, string> = {
  primary: "border-transparent bg-[var(--color-primary)] text-white shadow-xs hover:bg-[var(--color-primary-hover)]",
  secondary: "border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] shadow-xs hover:bg-[var(--color-surface-subtle)]",
  success: "border-transparent bg-[var(--color-success)] text-white shadow-xs hover:brightness-95",
  danger: "border-transparent bg-[var(--color-danger)] text-white shadow-xs hover:brightness-95",
  ghost: "border-transparent bg-transparent text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text)]",
};

const sizeClasses: Record<ButtonSize, string> = {
  sm: "min-h-8 px-2.5 text-xs",
  md: "min-h-9 px-3 text-sm",
  lg: "min-h-11 px-4 text-sm",
};

const spinnerSizeClasses: Record<ButtonSize, string> = {
  sm: "size-3",
  md: "size-3.5",
  lg: "size-4",
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  leadingIcon?: ReactNode;
}

export function Button({
  variant = "secondary",
  size = "md",
  loading = false,
  leadingIcon,
  className = "",
  disabled,
  children,
  type = "button",
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={[
        "inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-[var(--radius-control)] border font-medium transition-colors",
        "disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none",
        variantClasses[variant],
        sizeClasses[size],
        className,
      ].join(" ")}
      {...props}
    >
      {loading ? (
        <span
          aria-hidden="true"
          className={`animate-spin rounded-full border-2 border-current border-r-transparent ${spinnerSizeClasses[size]}`}
        />
      ) : (
        leadingIcon
      )}
      <span>{children}</span>
    </button>
  );
}
