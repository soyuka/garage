import { useState } from 'react';
import { safeHref } from '../safeHref';

// CC-BY attribution for the default blueprint meshes — required to ship. Each source is credited with
// title + author + license + a link to Sketchfab. Where the full model UID is known the link is the
// model page; otherwise (the docs truncate three UIDs) it is a title search that resolves to the model.
// Source data: theming/BLUEPRINT-PIPELINE.md §0 + the default-mesh table; DEV-PROMPT licensing note.

interface Credit {
  title: string;
  author: string;
  /** Full 32-hex Sketchfab UID when known → links the model page; absent → links a title search. */
  uid?: string;
}

const CREDITS: Credit[] = [
  { title: '2008 Renault Espace', author: 'tonielpro520', uid: 'b8e7c65711134fca865f635ccddafcf5' },
  { title: 'Mazda 6 Sedan 2011', author: 'niev', uid: '5db006da9f6d4b32b3186e1052a07bdf' },
  { title: 'Motorcycle', author: 'Silas6' },
  { title: 'European Delivery Van', author: 'evan.hiltz' },
  { title: 'Lawn Tractor', author: 'mirrol' },
];

export function creditUrl(c: Credit): string {
  return c.uid
    ? `https://sketchfab.com/3d-models/${c.uid}`
    : `https://sketchfab.com/search?type=models&q=${encodeURIComponent(c.title)}`;
}

export { CREDITS };

const chip: React.CSSProperties = {
  appearance: 'none',
  background: 'transparent',
  border: '1px solid var(--at-hairline)',
  color: 'var(--at-text-dim)',
  borderRadius: 5,
  padding: '2px 8px',
  cursor: 'pointer',
  font: 'inherit',
};

export function Credits() {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        type="button"
        className="at-identity-chip"
        title="Crédits des modèles 3D"
        aria-label="Crédits des modèles 3D"
        onClick={() => setOpen((o) => !o)}
      >
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="8" cy="8" r="6.3" stroke="currentColor" strokeWidth="1.3" />
          <path d="M8 7.2v4.2" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" />
          <circle cx="8" cy="4.9" r="0.15" fill="currentColor" stroke="currentColor" strokeWidth="1.1" />
        </svg>
        crédits
      </button>
      {open && (
        <div
          role="dialog"
          aria-label="Crédits des modèles 3D"
          style={{
            position: 'fixed',
            top: 52,
            right: 16,
            zIndex: 60,
            maxWidth: 360,
            padding: '14px 16px',
            borderRadius: 10,
            background: 'var(--at-glass, rgba(14,18,24,.92))',
            border: '1px solid var(--at-hairline)',
            color: 'var(--at-text-dim)',
            backdropFilter: 'blur(14px)',
            font: 'inherit',
            fontSize: 12,
            lineHeight: 1.5,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 8 }}>
            <strong style={{ color: 'var(--at-text)' }}>Modèles 3D</strong>
            <span style={{ flex: 1 }} />
            <button type="button" style={chip} aria-label="Fermer les crédits" onClick={() => setOpen(false)}>
              ✕
            </button>
          </div>
          <p style={{ margin: '0 0 8px' }}>Maquettes blueprint — sources Sketchfab, licence CC-BY :</p>
          <ul style={{ margin: 0, paddingLeft: 16 }}>
            {CREDITS.map((c) => (
              <li key={c.title} style={{ marginBottom: 4 }}>
                <a href={safeHref(creditUrl(c))} target="_blank" rel="noreferrer noopener" style={{ color: 'inherit', textDecoration: 'underline' }}>
                  {c.title}
                </a>{' '}
                — {c.author}, via Sketchfab, CC-BY
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  );
}
