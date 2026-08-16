// C5 — deciding whether the projector layer is on. Pure core; the DOM write is one line at the edge.
//
// Two sources, in priority order, because they answer different needs: the URL is how a human checks
// the layer in one keystroke during a rehearsal, and localStorage is how `mpa stage up` arms the
// kiosk browser once and has it survive every reload of the talk.

export const STAGE_KEY = 'mpa.stage';

/** Truthy spellings accepted from either source. `?stage=0` explicitly turns it OFF, overriding a
 * stored value — otherwise a laptop armed for the stage could not be used at a desk without clearing
 * storage, which is exactly the wrong thing to be doing five minutes before a talk. */
export function stageEnabled(search: string, stored: string | null): boolean {
  const param = new URLSearchParams(search).get('stage');
  if (param !== null) return param === '' || param === '1' || param === 'true';
  return stored === '1' || stored === 'true';
}

/** Read both sources and stamp `data-stage` on <html>. Idempotent; safe to call before first paint. */
export function applyStageLayer(win: Window = window): void {
  let stored: string | null = null;
  try {
    stored = win.localStorage.getItem(STAGE_KEY);
  } catch {
    stored = null; // a locked-down profile must not stop the app booting
  }
  const on = stageEnabled(win.location.search, stored);
  win.document.documentElement.toggleAttribute('data-stage', on);
}
