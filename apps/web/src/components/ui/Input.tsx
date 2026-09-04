import { forwardRef, type InputHTMLAttributes } from "react";
import { controlBaseClasses, controlInvalidClasses } from "./controlStyles";

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className = "", invalid, ...props },
  ref,
) {
  return (
    <input
      ref={ref}
      aria-invalid={invalid || undefined}
      className={`${controlBaseClasses} ${controlInvalidClasses} ${className}`}
      {...props}
    />
  );
});
