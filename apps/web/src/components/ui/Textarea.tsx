import { forwardRef, type TextareaHTMLAttributes } from "react";
import { controlBaseClasses, controlInvalidClasses } from "./controlStyles";

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  invalid?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { className = "", invalid, ...props },
  ref,
) {
  return (
    <textarea
      ref={ref}
      aria-invalid={invalid || undefined}
      className={`min-h-24 w-full resize-y ${controlBaseClasses} ${controlInvalidClasses} ${className}`}
      {...props}
    />
  );
});
