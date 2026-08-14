import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Stitchra ERP",
  description: "Apparel Manufacturing Management System",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id">
      <body>{children}</body>
    </html>
  );
}
