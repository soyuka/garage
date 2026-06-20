import { useEffect, useState } from 'react';

// Glyph alphabet the unsettled characters cycle through — kept to digits + tech punctuation so it
// reads as an instrument booting, not as random letters.
const GLYPHS = '0123456789#$%&*<>/\\=+-[]{}';

function prefersReduced(): boolean {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

interface Props {
  text: string;
  /** ms to wait before the reveal starts — stagger sibling items by passing index * step. */
  delay?: number;
  /** ms between frames. */
  tick?: number;
  /** frames each character stays scrambled before it locks. */
  hold?: number;
  className?: string;
}

/**
 * Renders `text` as if an instrument is powering on: random glyphs that settle into the real
 * characters left-to-right. Re-runs whenever `text` changes (a value update re-tunes). Honours
 * prefers-reduced-motion by showing the final text at once. The real text rides on aria-label so
 * screen readers never read the transient glyphs.
 */
export function Scramble({ text, delay = 0, tick = 30, hold = 2, className }: Props) {
  const [out, setOut] = useState(text);

  useEffect(() => {
    if (prefersReduced() || text === '') {
      setOut(text);
      return;
    }
    const scramble = (revealed: number): string => {
      let s = '';
      for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        s += ch === ' ' || i < revealed ? ch : GLYPHS[(Math.random() * GLYPHS.length) | 0];
      }
      return s;
    };
    // Show full glyphs immediately, so the slot is filled during the cascade delay rather than
    // flashing the previous/real value.
    setOut(scramble(0));
    let frame = 0;
    const total = text.length * hold;
    let tickId = 0;
    const run = () => {
      setOut(scramble(Math.floor(frame / hold)));
      if (frame++ < total) tickId = window.setTimeout(run, tick);
    };
    const startId = window.setTimeout(run, delay);
    return () => {
      window.clearTimeout(startId);
      window.clearTimeout(tickId);
    };
  }, [text, delay, tick, hold]);

  return (
    <span className={className} aria-label={text}>
      {out}
    </span>
  );
}
