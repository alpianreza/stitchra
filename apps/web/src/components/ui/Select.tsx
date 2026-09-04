import { forwardRef, type SelectHTMLAttributes } from "react";
import { controlBaseClasses, controlInvalidClasses } from "./controlStyles";

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  invalid?: boolean;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { className = "", invalid, children, ...props },
  ref,
) {
  return (
    <select
      ref={ref}
      aria-invalid={invalid || undefined}
      className={`${controlBaseClasses} ${controlInvalidClasses} ${className}`}
      {...props}
    >
      {children}
    </select>
  );
});
