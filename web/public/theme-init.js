// Apply the saved theme before first paint to avoid a flash. A separate,
// render-blocking file (not inline) so the page can use a strict CSP.
try {
  var t = localStorage.getItem('keelwatch.theme');
  if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t;
} catch {
  // Storage blocked (private mode, policy): keep the system theme.
}
