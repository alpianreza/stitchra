import type { ReactNode } from "react";
import { OutputAuthorityPanel } from "./output-authority-panel";

export default async function ProductionOrderLayout({ children, params }: { children: ReactNode; params: Promise<{ id: string }> }) {
  const { id } = await params;

  return (
    <div className="space-y-4">
      {children}
      <OutputAuthorityPanel productionOrderId={id} />
    </div>
  );
}
