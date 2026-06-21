// A fact's `url` is agent- and web-search-influenced, so it is untrusted: rendering it straight
// into an anchor's href allows a `javascript:`/`data:` URL to run script on click (XSS). Only emit
// the href when it carries an http(s) scheme; anything else collapses to no link.
export function safeHref(url: string | null | undefined): string | undefined {
  return url && /^\s*https?:\/\//i.test(url) ? url : undefined;
}
