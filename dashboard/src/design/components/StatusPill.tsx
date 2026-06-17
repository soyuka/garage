import React from 'react';

type Status = 'ok' | 'soon' | 'overdue' | 'info';

export interface StatusPillProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** ok=green, soon=amber, overdue=red, info=cyan. */
  status?: Status;
  /** Render the leading dot (default true). */
  showDot?: boolean;
}

const MAP: Record<Status, { color: string; tint: string; label: string }> = {
  ok: { color: 'var(--mpa-ok)', tint: 'var(--mpa-ok-tint)', label: 'À jour' },
  soon: { color: 'var(--mpa-warn)', tint: 'var(--mpa-warn-tint)', label: 'Bientôt' },
  overdue: { color: 'var(--mpa-alarm)', tint: 'var(--mpa-alarm-tint)', label: 'En retard' },
  info: { color: 'var(--mpa-accent)', tint: 'var(--mpa-accent-tint)', label: 'Info' },
};

/** Meaning-locked status chip with a leading signal dot. Color never travels without the label. */
export function StatusPill({ status = 'ok', children, showDot = true, style, ...rest }: StatusPillProps) {
  const s = MAP[status];
  return (
    <span
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 6,
        fontSize: 11, fontWeight: 600, fontFamily: 'var(--mpa-font)',
        borderRadius: 'var(--mpa-radius-sm)', padding: '4px 9px',
        background: s.tint, color: s.color, ...style,
      }}
      {...rest}
    >
      {showDot ? <span style={{ width: 6, height: 6, borderRadius: 'var(--mpa-radius-pill)', background: s.color }} /> : null}
      {children || s.label}
    </span>
  );
}
