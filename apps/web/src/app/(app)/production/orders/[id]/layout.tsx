import type { ReactNode } from "react";
import { OutputAuthorityPanel } from "./output-authority-panel";

export default function ProductionOrderLayout({ children, params }: { children: ReactNode; params: { id: string } }) {
  return (
    <div className="space-y-4">
      {children}
      <OutputAuthorityPanel productionOrderId={params.id} />
    </div>
  );
}
