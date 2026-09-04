import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

// Inter — dipublikasikan sebagai CSS variable --font-sans dan dipasang di <body>.
// globals.css memakai var(--font-sans) dengan fallback system stack.
const inter = Inter({
  subsets: ["latin"],
  display: "swap",
  variable: "--font-sans",
});

export const metadata: Metadata = {
  title: "Stitchra ERP",
  description: "Apparel Manufacturing Management System",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id">
      <body className={inter.variable}>{children}</body>
    </html>
  );
}
