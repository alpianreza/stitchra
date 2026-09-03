import type { ReactNode } from "react";
import { BackflushPanel } from "./backflush-panel";
import { OperationalIntegrityPanel } from "./operational-integrity-panel";
import { OutputAuthorityPanel } from "./output-authority-panel";

export default async function ProductionOrderLayout({ children, params }: { children: ReactNode; params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <div className="space-y-4">{children}<BackflushPanel productionOrderId={id}/><OutputAuthorityPanel productionOrderId={id}/><OperationalIntegrityPanel productionOrderId={id}/></div>;
}
