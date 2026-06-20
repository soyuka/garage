import React from 'react';

type Tone = 'default' | 'accent' | 'ok' | 'warn' | 'alarm';

export interface MetricStatProps extends React.HTMLAttributes<HTMLDivElement> {
  label?: string;
  value: React.ReactNode;
  unit?: string;
  tone?: Tone;
  /** lg = 44px (KPI), md = 32px (secondary). */
  size?: 'lg' | 'md';
}

const TONE: Record<Tone, string> = {
  default: 'var(--mpa-text)',
  accent: 'var(--mpa-accent)',
  ok: 'var(--mpa-ok)',
  warn: 'var(--mpa-warn)',
  alarm: 'var(--mpa-alarm)',
};

/** A labelled metric readout: caption + large tabular number + unit. KPI-strip building block. */
export function MetricStat({ label, value, unit, tone = 'default', size = 'lg', style, ...rest }: MetricStatProps) {
  const big = size === 'lg' ? 44 : 32;
  return (
    <div style={{ fontFamily: 'var(--mpa-font)', fontVariantNumeric: 'tabular-nums', ...style }} {...rest}>
      {label ? <div style={{ fontSize: 13, color: 'var(--mpa-muted)', marginBottom: 12 }}>{label}</div> : null}
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 7 }}>
        <span style={{ fontSize: big, fontWeight: 500, letterSpacing: '-0.02em', lineHeight: 1, color: TONE[tone] }}>{value}</span>
        {unit ? <span style={{ fontSize: 16, color: 'var(--mpa-muted)' }}>{unit}</span> : null}
      </div>
    </div>
  );
}
