import { describe, expect, it } from 'vitest';
import { appRoute } from './appRoute';

describe('appRoute', () => {
  it('serves the live garage on the parc and the vehicle route, in every build', () => {
    for (const dev of [true, false]) {
      expect(appRoute('/', dev)).toBe('workshop');
      expect(appRoute('/v/espace-01J', dev)).toBe('workshop');
      expect(appRoute('/legacy', dev)).toBe('legacy');
    }
  });

  it.each(['/dev', '/dev/workshop', '/dev/workshop/x', '/auth', '/noyau', '/atelier', '/scene', '/parc'])('keeps the playground %s out of a production build', (path) => {
    expect(appRoute(path, false)).toBe('notFound');
  });

  it.each(['/dev', '/dev/workshop', '/auth', '/noyau'])('serves the playground %s in dev', (path) => {
    expect(appRoute(path, true)).toBe('dev');
  });

  it('reports an unknown path as not found', () => {
    expect(appRoute('/nope', true)).toBe('notFound');
  });
});
