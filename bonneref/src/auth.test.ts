import { describe, expect, it } from 'vitest';
import { TOKEN_ENDPOINT } from './auth';

describe('TOKEN_ENDPOINT', () => {
  it('posts straight to the https issuer', () => {
    expect(TOKEN_ENDPOINT).toBe('https://auth.bonneref.fr/api/login/oauth/access_token');
  });
});
