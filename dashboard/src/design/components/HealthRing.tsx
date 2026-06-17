export interface HealthRingProps {
  /** Score 0–100. */
  value?: number;
  /** Pixel diameter. */
  size?: number;
  /** Arc thickness. */
  stroke?: number;
  /** Caption under the numeral; pass "" to hide. */
  label?: string;
  tone?: 'ok' | 'warn' | 'alarm';
}

const TONE = { ok: 'var(--mpa-ok)', warn: 'var(--mpa-warn)', alarm: 'var(--mpa-alarm)' };

/** Circular score gauge (0–100) with a centred numeral. Tone sets the arc color. */
export function HealthRing({ value = 0, size = 148, stroke = 10, label = 'Indice de santé', tone = 'ok' }: HealthRingProps) {
  const v = Math.max(0, Math.min(100, value));
  const r = (size - stroke) / 2 - 6;
  const c = 2 * Math.PI * r;
  const off = c * (1 - v / 100);
  const color = TONE[tone];
  const cx = size / 2;
  return (
    <div style={{ position: 'relative', width: size, height: size, fontFamily: 'var(--mpa-font)' }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
        <circle cx={cx} cy={cx} r={r} fill="none" stroke="rgba(255,255,255,0.07)" strokeWidth={stroke} />
        <circle cx={cx} cy={cx} r={r} fill="none" stroke={color} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={off} transform={`rotate(-90 ${cx} ${cx})`} />
      </svg>
      <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
        <span style={{ fontSize: Math.round(size * 0.27), fontWeight: 600, letterSpacing: '-0.02em', lineHeight: 1, color: 'var(--mpa-text)', fontVariantNumeric: 'tabular-nums' }}>{v}</span>
        {label ? <span style={{ fontSize: 12, color: 'var(--mpa-muted)', marginTop: 4 }}>{label}</span> : null}
      </div>
    </div>
  );
}
