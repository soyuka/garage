import { useEffect } from 'react';
import type { Toast, Tone } from '../types';

const LED: Record<Tone, string> = {
  info: 'var(--mpa-accent)',
  ok: 'var(--mpa-ok)',
  warn: 'var(--mpa-warn)',
  alarm: 'var(--mpa-alarm)',
};

interface Props {
  toasts: Toast[];
  onDismiss: (id: number) => void;
}

export function Toasts({ toasts, onDismiss }: Props) {
  useEffect(() => {
    if (toasts.length === 0) return;
    const last = toasts[toasts.length - 1];
    const timer = setTimeout(() => onDismiss(last.id), 4000);
    return () => clearTimeout(timer);
  }, [toasts, onDismiss]);

  return (
    <div className="toasts">
      {toasts.map((t) => (
        <div key={t.id} className="toast" onClick={() => onDismiss(t.id)}>
          <span className="led" style={{ background: LED[t.tone] }} />
          {t.message}
        </div>
      ))}
    </div>
  );
}
