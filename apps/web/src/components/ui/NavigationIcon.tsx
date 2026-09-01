import type { SVGProps } from "react";

export type NavigationIconName =
  | "dashboard"
  | "approval"
  | "sales"
  | "product"
  | "planning"
  | "production"
  | "scan"
  | "purchasing"
  | "receiving"
  | "inventory"
  | "packing"
  | "shipping"
  | "subcon"
  | "quality"
  | "finance"
  | "reports"
  | "master"
  | "admin"
  | "menu"
  | "chevron"
  | "collapse"
  | "logout";

const paths: Record<NavigationIconName, React.ReactNode> = {
  dashboard: <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></>,
  approval: <><path d="M9 11l2 2 4-4" /><path d="M9 4h6l1 2h3v15H5V6h3l1-2z" /></>,
  sales: <><path d="M4 5h16v14H4z" /><path d="M8 9h8M8 13h5" /></>,
  product: <><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z" /><path d="M4.5 7.5L12 12l7.5-4.5M12 12v9" /></>,
  planning: <><path d="M5 4v3M19 4v3M4 9h16v11H4z" /><path d="M8 13h3M8 17h6" /></>,
  production: <><path d="M3 21V9l6 3V8l6 4V5h6v16H3z" /><path d="M7 17h2M13 17h2M18 9h3" /></>,
  scan: <><path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4" /><path d="M7 12h10" /></>,
  purchasing: <><path d="M3 5h2l2 11h10l2-8H6" /><circle cx="9" cy="20" r="1" /><circle cx="17" cy="20" r="1" /></>,
  receiving: <><path d="M4 4h16v16H4z" /><path d="M12 7v9M8 12l4 4 4-4" /></>,
  inventory: <><path d="M4 7l8-4 8 4-8 4-8-4z" /><path d="M4 7v10l8 4 8-4V7M12 11v10" /></>,
  packing: <><path d="M5 7h14v14H5zM8 3h8l3 4H5l3-4z" /><path d="M9 12h6" /></>,
  shipping: <><path d="M3 6h11v11H3zM14 10h4l3 4v3h-7z" /><circle cx="7" cy="19" r="2" /><circle cx="18" cy="19" r="2" /></>,
  subcon: <><circle cx="8" cy="8" r="3" /><circle cx="17" cy="8" r="3" /><path d="M3 20c0-4 2-6 5-6s5 2 5 6M12 20c0-3 2-5 5-5s4 2 4 5" /></>,
  quality: <><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z" /><path d="M9 12l2 2 4-5" /></>,
  finance: <><path d="M4 9h16M6 9v9M10 9v9M14 9v9M18 9v9M3 20h18M12 3l9 4H3l9-4z" /></>,
  reports: <><path d="M5 3h14v18H5z" /><path d="M9 16v-3M12 16V9M15 16v-5" /></>,
  master: <><circle cx="9" cy="8" r="3" /><path d="M3 20c0-4 2-7 6-7s6 3 6 7M16 5h5M18.5 2.5v5M16 13h5M16 17h5" /></>,
  admin: <><path d="M12 3l2 2 3-.5.5 3L20 9l-1.5 2.5.5 3-3 .5-2 2-2-2-3 .5-.5-3L6 9l1.5-2.5-.5-3 3-.5 2-2z" /><circle cx="12" cy="10" r="3" /></>,
  menu: <path d="M4 7h16M4 12h16M4 17h16" />,
  chevron: <path d="M9 6l6 6-6 6" />,
  collapse: <><path d="M9 6l-6 6 6 6M21 4v16" /></>,
  logout: <><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9" /></>,
};

interface NavigationIconProps extends SVGProps<SVGSVGElement> {
  name: NavigationIconName;
}

export function NavigationIcon({ name, className = "", ...props }: NavigationIconProps) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={`size-[18px] shrink-0 ${className}`}
      {...props}
    >
      {paths[name]}
    </svg>
  );
}
