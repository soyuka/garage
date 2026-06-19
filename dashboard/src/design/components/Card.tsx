import React from 'react';

export interface CardProps extends React.HTMLAttributes<HTMLElement> {
  /** Enable pointer-over lift + deeper shadow. */
  hover?: boolean;
  /** CSS padding value. Defaults to the large card padding (24px). */
  padding?: string | number;
  /** Element tag to render. */
  as?: keyof JSX.IntrinsicElements;
}

/** The base elevated surface — gradient fill, hairline border, soft shadow. */
export const Card = React.forwardRef<HTMLElement, CardProps>(function Card(
  { hover = false, padding = 'var(--mpa-pad-card-lg)', as = 'div', children, style, ...rest },
  ref,
) {
  const [lift, setLift] = React.useState(false);
  const El = as as React.ElementType;
  return (
    <El
      ref={ref}
      onMouseEnter={hover ? () => setLift(true) : undefined}
      onMouseLeave={hover ? () => setLift(false) : undefined}
      style={{
        background: 'var(--mpa-card)',
        border: '1px solid var(--mpa-border)',
        borderRadius: 'var(--mpa-radius-2xl)',
        padding,
        boxShadow: lift ? 'var(--mpa-shadow-hover)' : 'var(--mpa-shadow-card)',
        transform: lift ? 'var(--mpa-hover-lift)' : 'none',
        transition: 'transform var(--mpa-transition), box-shadow var(--mpa-transition)',
        ...style,
      }}
      {...rest}
    >
      {children}
    </El>
  );
});
