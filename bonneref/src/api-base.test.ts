import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiUrl, garageUrl, hubDefault } from './api-base';

describe('api bases', () => {
  afterEach(() => vi.unstubAllEnvs());

  it('stays same-origin when nothing is configured', () => {
    expect(apiUrl('/makes')).toBe('/makes');
    expect(garageUrl('/garage/vehicles')).toBe('/garage/vehicles');
  });

  it('prefixes the finder, catalogue and agent calls with VITE_API_BASE', () => {
    vi.stubEnv('VITE_API_BASE', 'https://localhost:8444/');
    expect(apiUrl('/agent/turn')).toBe('https://localhost:8444/agent/turn');
  });

  it('sends garage calls to the same base unless VITE_GARAGE_API_BASE splits them', () => {
    vi.stubEnv('VITE_API_BASE', 'https://localhost:8444');
    expect(garageUrl('/garage/vehicles')).toBe('https://localhost:8444/garage/vehicles');

    vi.stubEnv('VITE_GARAGE_API_BASE', 'https://localhost');
    expect(garageUrl('/garage/vehicles')).toBe('https://localhost/garage/vehicles');
    expect(apiUrl('/makes')).toBe('https://localhost:8444/makes');
  });

  it('leaves an absolute URL untouched', () => {
    vi.stubEnv('VITE_API_BASE', 'https://localhost:8444');
    expect(apiUrl('https://elsewhere.test/x')).toBe('https://elsewhere.test/x');
  });

  it('points the Mercure hub at the finder API unless VITE_MERCURE_URL overrides it', () => {
    expect(hubDefault()).toBe('/.well-known/mercure');
    vi.stubEnv('VITE_API_BASE', 'https://localhost:8444');
    expect(hubDefault()).toBe('https://localhost:8444/.well-known/mercure');
    vi.stubEnv('VITE_MERCURE_URL', 'https://hub.test/.well-known/mercure');
    expect(hubDefault()).toBe('https://hub.test/.well-known/mercure');
  });
});
