import AppShell from "@/components/AppShell";
import { Suspense } from "react";

export default function ProtectedLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  // AppShell and operational pages read search params. Keep the CSR bailout
  // inside the protected layout so clean Next production builds can prerender.
  return (
    <Suspense
      fallback={
        <p role="status" className="p-6">
          Memuat workspace…
        </p>
      }
    >
      <AppShell>{children}</AppShell>
    </Suspense>
  );
}
