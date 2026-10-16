import { useEffect, useRef, useState } from 'react';
import { useToast } from './Toast';

export async function copy(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

const ICON = { width: 14, height: 14, viewBox: '0 0 16 16', fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true } as const;

export function CopyRef({ text, label }: { text: string; label?: string }) {
  const toast = useToast();
  const [done, setDone] = useState(false);
  const timer = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(timer.current), []);

  const onClick = async () => {
    if (!(await copy(text))) return toast('Copie impossible. Sélectionnez la référence.');
    setDone(true);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setDone(false), 1500);
  };

  return (
    <span className="copy-ref">
      <button type="button" className="copy-btn" aria-label={`Copier ${label ?? text}`} data-done={done || undefined} onClick={() => void onClick()}>
        {done ? (
          <svg {...ICON}>
            <path d="M3 8.5l3.2 3.2L13 4.8" />
          </svg>
        ) : (
          <svg {...ICON}>
            <rect x="5.5" y="5.5" width="8" height="8" rx="1.5" />
            <path d="M10.5 5.5V4A1.5 1.5 0 0 0 9 2.5H4A1.5 1.5 0 0 0 2.5 4V9A1.5 1.5 0 0 0 4 10.5h1.5" />
          </svg>
        )}
      </button>
      <span className="sr-only" role="status" aria-live="polite">
        {done ? 'Copié' : ''}
      </span>
    </span>
  );
}
