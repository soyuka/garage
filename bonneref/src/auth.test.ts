import { afterEach, describe, expect, it, vi } from 'vitest';
import { createTokenStore, TOKEN_ENDPOINT } from './auth';

describe('TOKEN_ENDPOINT', () => {
  it('posts straight to the https issuer', () => {
    expect(TOKEN_ENDPOINT).toBe('https://auth.bonneref.fr/api/login/oauth/access_token');
  });
});

class MemoryStorage {
  readonly items = new Map<string, string>();
  getItem(key: string): string | null {
    return this.items.get(key) ?? null;
  }
  setItem(key: string, value: string): void {
    this.items.set(key, value);
  }
  removeItem(key: string): void {
    this.items.delete(key);
  }
}

describe('createTokenStore', () => {
  it('keeps the token in memory, mirrored to the tab storage', () => {
    const storage = new MemoryStorage();
    const store = createTokenStore(storage);
    store.set({ accessToken: 'jwt', expiresAt: 42 });

    expect(store.get()).toEqual({ accessToken: 'jwt', expiresAt: 42 });
    expect(JSON.parse(storage.getItem('mpa.auth.token') ?? 'null')).toEqual({ accessToken: 'jwt', expiresAt: 42 });
    expect(createTokenStore(storage).get()).toEqual({ accessToken: 'jwt', expiresAt: 42 });
  });

  it('degrades to memory when the storage throws, and ignores a malformed entry', () => {
    const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); }, removeItem: () => undefined };
    const store = createTokenStore(broken);
    expect(store.get()).toBeNull();
    store.set({ accessToken: 'jwt', expiresAt: 42 });
    expect(store.get()?.accessToken).toBe('jwt');

    const storage = new MemoryStorage();
    storage.setItem('mpa.auth.token', '{"accessToken":3}');
    expect(createTokenStore(storage).get()).toBeNull();
  });
});

describe('token storage', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.resetModules();
  });

  it('never reads the token from localStorage, and wipes the copy older builds left there', async () => {
    const local = new MemoryStorage();
    local.setItem('mpa.auth.token', JSON.stringify({ accessToken: 'stale', expiresAt: Date.now() + 3_600_000 }));
    const session = new MemoryStorage();
    vi.stubGlobal('localStorage', local);
    vi.stubGlobal('sessionStorage', session);
    vi.resetModules();

    const auth = await import('./auth');

    expect(local.getItem('mpa.auth.token')).toBeNull();
    expect(auth.getToken()).toBeNull();
  });

  it('serves the tab token from sessionStorage', async () => {
    const session = new MemoryStorage();
    session.setItem('mpa.auth.token', JSON.stringify({ accessToken: 'tab', expiresAt: Date.now() + 3_600_000 }));
    vi.stubGlobal('localStorage', new MemoryStorage());
    vi.stubGlobal('sessionStorage', session);
    vi.resetModules();

    const auth = await import('./auth');

    expect(auth.getToken()).toBe('tab');
    expect(auth.isAuthenticated()).toBe(true);
  });
});
