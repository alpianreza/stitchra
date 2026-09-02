import type { ReactNode } from "react";
import { CommercialFulfillmentPanel } from "./commercial-fulfillment-panel";

export default function ShipmentsLayout({ children }: { children: ReactNode }) {
  return <div className="space-y-4">{children}<CommercialFulfillmentPanel /></div>;
}
