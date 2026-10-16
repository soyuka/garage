import { describe, expect, it } from 'vitest';
import { tokenEndpointFor } from './auth';

describe('tokenEndpointFor', () => {
  it('posts straight to an https issuer', () => {
    expect(tokenEndpointFor('https://auth.bonneref.fr', 'https://bonneref.fr')).toBe(
      'https://auth.bonneref.fr/api/login/oauth/access_token',
    );
  });

  it('keeps the same-origin proxy for an http issuer', () => {
    expect(tokenEndpointFor('http://casdoor:8000', 'https://localhost')).toBe(
      'https://localhost/casdoor/api/login/oauth/access_token',
    );
  });
});
