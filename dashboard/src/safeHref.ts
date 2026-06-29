// A fact's `url` is agent- and web-search-influenced, so it is untrusted: rendering it straight into
// an anchor's href would let a `javascript:`/`data:` URL run script on click (XSS). Only emit the
// href when it carries an http(s) scheme; anything else collapses to no link. Re-authored fresh.
export function safeHref(url: string | null | undefined): string | undefined {
  return url && /^\s*https?:\/\//i.test(url) ? url : undefined;
}
