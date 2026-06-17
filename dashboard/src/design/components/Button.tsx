import React from 'react';

type Variant = 'primary' | 'secondary' | 'ghost';
type Size = 'sm' | 'md' | 'lg';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** Visual emphasis. One primary (cyan) per view; secondary/ghost for the rest. */
  variant?: Variant;
  /** Control height: sm 30 / md 34 / lg 38. */
  size?: Size;
  /** Optional leading icon node. */
  icon?: React.ReactNode;
}

const SIZES: Record<Size, { height: number; fontSize: number; padding: string }> = {
  sm: { height: 30, fontSize: 12, padding: '0 12px' },
  md: { height: 34, fontSize: 13, padding: '0 14px' },
  lg: { height: 38, fontSize: 14, padding: '0 18px' },
};

const VARIANTS: Record<Variant, React.CSSProperties> = {
  primary: { background: 'var(--mpa-accent)', color: 'var(--mpa-on-accent)', border: '1px solid transparent' },
  secondary: { background: 'rgba(255,255,255,0.05)', color: 'var(--mpa-text-3)', border: '1px solid var(--mpa-border-strong)' },
  ghost: { background: 'transparent', color: 'var(--mpa-muted)', border: '1px solid transparent' },
};

/** The action control. One primary (cyan) per view; secondary/ghost for the rest. */
export function Button({ variant = 'primary', size = 'md', icon, disabled = false, children, style, ...rest }: ButtonProps) {
  const sz = SIZES[size];
  const vr = VARIANTS[variant];
  return (
    <button
      type="button"
      disabled={disabled}
      style={{
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 7,
        height: sz.height, padding: sz.padding, fontSize: sz.fontSize, fontWeight: 600,
        fontFamily: 'var(--mpa-font)', borderRadius: 'var(--mpa-radius-sm)',
        whiteSpace: 'nowrap', cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.45 : 1,
        transition: 'background var(--mpa-transition-fast), color var(--mpa-transition-fast), border-color var(--mpa-transition-fast)',
        ...vr, ...style,
      }}
      {...rest}
    >
      {icon ? <span style={{ display: 'inline-flex' }}>{icon}</span> : null}
      {children}
    </button>
  );
}
