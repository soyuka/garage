import { describe, expect, it } from 'vitest';
import { gateView } from './gate';
import { HOME } from './home';

// The decision takes no route on purpose: a deep link, a result route and the garage are all gated,
// and login() stores the hash it was clicked on so the visitor returns to it.
describe('gateView', () => {
  it('lets a logged-in visitor into the app', () => {
    expect(gateView({ authed: true, callback: false })).toBe('app');
  });

  it('gates the whole app when logged out', () => {
    expect(gateView({ authed: false, callback: false })).toBe('gate');
  });

  it('renders nothing while the OAuth callback is being exchanged, authed or not', () => {
    for (const authed of [true, false]) expect(gateView({ authed, callback: true })).toBe('wait');
  });
});

describe('signed-out lede', () => {
  it('does not ask a visitor who cannot type to type', () => {
    expect(HOME.ledeSignedOut).not.toMatch(/saisi|tap|écriv/i);
    expect(HOME.ledeSignedOut).toMatch(/connect/i);
  });
});
