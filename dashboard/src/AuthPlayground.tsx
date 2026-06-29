import { useState, type ReactNode } from 'react';
import { API, authFetch, getIdentity, isAuthenticated, login, logout } from './auth';

// Stage-C slice-1 dev surface (#/auth). Exercises the full PKCE round-trip and — crucially —
// gate-verifies auth with a real WRITE (POST /garage/vehicles → 201), not a read: reads work without
// a Bearer and give false confidence. A blank-vehicle create is the cheapest authenticated 2xx.
export function AuthPlayground(): ReactNode {
  const [result, setResult] = useState<string>('');
  const id = getIdentity();
  const authed = isAuthenticated();

  const testWrite = async () => {
    setResult('POST /garage/vehicles …');
    try {
      const res = await authFetch(`${API}/garage/vehicles`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
        body: '{}',
      });
      const text = await res.text();
      let iri = '';
      try {
        iri = (JSON.parse(text)['@id'] as string) ?? '';
      } catch {
        /* non-JSON body */
      }
      setResult(`status ${res.status}${iri ? ` · créé ${iri}` : ` · ${text.slice(0, 200)}`}`);
    } catch (e) {
      setResult(`erreur réseau : ${(e as Error).message}`);
    }
  };

  return (
    <main style={{ fontFamily: 'monospace', padding: '2rem', lineHeight: 1.7, color: '#e7e3da', background: '#131210', minHeight: '100vh' }}>
      <p>
        <a href="#/dev" style={{ color: '#67e8f9' }}>
          ← dev index
        </a>
      </p>
      <h1 style={{ fontSize: '1.1rem', letterSpacing: '0.04em' }}>AUTH · slice 1 — PKCE + gate-verify</h1>
      <p style={{ opacity: 0.7 }}>
        état : <strong>{authed ? 'connecté' : 'anonyme'}</strong>
        {authed && (id.name || id.email || id.sub) ? ` · ${id.name ?? ''} ${id.email ?? ''} ${id.sub ?? ''}`.trim() : ''}
      </p>
      <div style={{ display: 'flex', gap: '0.75rem', margin: '1rem 0', flexWrap: 'wrap' }}>
        <button type="button" onClick={() => void login()} style={btn}>
          Se connecter (redirect Casdoor)
        </button>
        <button type="button" onClick={() => logout()} style={btn} disabled={!authed}>
          Se déconnecter
        </button>
        <button type="button" onClick={() => void testWrite()} style={{ ...btn, borderColor: '#4ade80', color: '#4ade80' }}>
          Test d’écriture (POST /garage/vehicles)
        </button>
      </div>
      {result && (
        <pre data-testid="write-result" style={{ background: '#181611', padding: '0.75rem', borderRadius: 5, whiteSpace: 'pre-wrap' }}>
          {result}
        </pre>
      )}
      <hr style={{ opacity: 0.2, margin: '1.5rem 0' }} />
      <p style={{ opacity: 0.55, fontSize: '0.8rem' }}>
        Le test d’écriture prouve le Bearer : 201 = jeton accepté. Sans jeton, l’écriture renvoie 401 →
        authFetch purge le jeton et relance la connexion.
      </p>
    </main>
  );
}

const btn: React.CSSProperties = {
  appearance: 'none',
  cursor: 'pointer',
  padding: '0.5rem 0.9rem',
  borderRadius: 5,
  border: '1px solid #67e8f9',
  background: 'transparent',
  color: '#67e8f9',
  fontFamily: 'monospace',
  fontSize: '0.85rem',
};
