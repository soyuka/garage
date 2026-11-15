// A URL from the server or a model (a fact's source, a web part's page) is untrusted: as an anchor's href a
// `javascript:`/`data:` URL would run script on click, and React does not block it. Only http(s) makes a link;
// anything else draws none. Same rule as dashboard/src/safeHref.ts.
export function safeHref(url: string | null | undefined): string | undefined {
  return url && /^\s*https?:\/\//i.test(url) ? url : undefined;
}
