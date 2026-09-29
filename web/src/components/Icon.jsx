const PATHS = {
  menu: <path d="M4 7h16M4 12h16M4 17h16" />,
  close: <path d="M6 6l12 12M18 6L6 18" />,
  refresh: (
    <>
      <path d="M20 11a8 8 0 0 0-14.3-4.9L4 8" />
      <path d="M4 4v4h4" />
      <path d="M4 13a8 8 0 0 0 14.3 4.9L20 16" />
      <path d="M20 20v-4h-4" />
    </>
  ),
  pulse: <path d="M3 12h4l2.5-6 5 12 2.5-6H21" />,
  checklist: <path d="M4 6.5l1.5 1.5L8 5.5M4 12.5l1.5 1.5L8 11.5M4 18.5l1.5 1.5L8 17.5M11 7h9M11 13h9M11 19h9" />,
  chart:<path d="M4 4v16h16M8 16v-4M12 16V8M16 16v-6" />,
  plug:<path d="M9 3.5v4M15 3.5v4M6.5 7.5h11v3a5.5 5.5 0 0 1-11 0zM12 16v4.5" />,
  sun: (
    <>
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4L6 18M18 6l1.4-1.4" />
    </>
  ),
  moon: <path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z" />,
  overview: (
    <>
      <rect x="3.5" y="3.5" width="7" height="7" rx="1" />
      <rect x="13.5" y="3.5" width="7" height="4" rx="1" />
      <rect x="13.5" y="10.5" width="7" height="10" rx="1" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1" />
    </>
  ),
  repo: <path d="M5 4.5h11.5a2 2 0 0 1 2 2v13H7a2 2 0 0 1-2-2zM5 17.5a2 2 0 0 1 2-2h11.5M9 8h6" />,
  runs: <path d="M4 6h10M4 12h16M4 18h7M17 4l3 2-3 2M14 16l3 2-3 2" />,
  findings: <path d="M5 21V4.5h11l-2 4 2 4H5" />,
  events: <path d="M4 13h4l2 3h4l2-3h4M6.5 5h11l2.5 8v6H4v-6z" />,
  digests: <path d="M7 3.5h7l4 4v13H7zM14 3.5v4h4M9.5 12h6M9.5 15.5h6" />,
  user: (
    <>
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5 20a7 7 0 0 1 14 0" />
    </>
  ),
  signout: <path d="M14 4.5h4.5v15H14M10 8l-4 4 4 4M6 12h9" />,
  offline: (
    <>
      <path d="M2 8.5a15 15 0 0 1 20 0M5.5 12a10 10 0 0 1 13 0M9 15.5a5 5 0 0 1 6 0" />
      <path d="M3 3l18 18" />
    </>
  ),
};

export function Icon({ name, size = 18 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {PATHS[name]}
    </svg>
  );
}
