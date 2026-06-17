import React from 'react';

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  variant?: 'neutral' | 'verified';
  /** Show a leading ✓ (typically with variant="verified"). */
  check?: boolean;
}

/** A small metadata tag — neutral by default, or a green "verified" treatment. */
export function Badge({ variant = 'neutral', check = false, children, style, ...rest }: BadgeProps) {
  const verified = variant === 'verified';
  return (
    <span
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 6,
        fontSize: 13, fontWeight: 500, fontFamily: 'var(--mpa-font)',
        borderRadius: 'var(--mpa-radius-sm)', padding: '6px 11px',
        background: verified ? 'var(--mpa-ok-tint)' : 'rgba(255,255,255,0.05)',
        color: verified ? 'var(--mpa-ok-text)' : 'var(--mpa-text-3)',
        border: verified ? '1px solid rgba(52,211,153,0.25)' : '1px solid var(--mpa-border-strong)',
        ...style,
      }}
      {...rest}
    >
      {check ? <span style={{ color: 'var(--mpa-ok)' }}>✓</span> : null}
      {children}
    </span>
  );
}
