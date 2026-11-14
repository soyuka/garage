import type { ReactNode } from 'react';

// One stroke weight (1.5 on a 16 grid), square caps — drawn for this system, never glyphs.
function Icon({ children, size = 16, stroke = 1.5 }: { children: ReactNode; size?: number; stroke?: number }) {
  return (
    <svg className="w-icon" width={size} height={size} viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="square" aria-hidden="true" focusable="false">
      {children}
    </svg>
  );
}

export function ChevronDown() {
  return (
    <Icon>
      <path d="M4 6l4 4 4-4" />
    </Icon>
  );
}

export function Sun() {
  return (
    <Icon>
      <circle cx="8" cy="8" r="2.75" />
      <path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1" />
    </Icon>
  );
}

export function Moon() {
  return (
    <Icon>
      <path d="M12.5 10.2A5 5 0 015.8 3.5a5 5 0 106.7 6.7z" />
    </Icon>
  );
}

export function Prompt() {
  return (
    <Icon>
      <path d="M3 4.5l3.5 3.5L3 11.5M8.5 12h4.5" />
    </Icon>
  );
}

export function Send() {
  return (
    <Icon>
      <path d="M2.5 8h10M9 4.5L12.5 8 9 11.5" />
    </Icon>
  );
}

export function Stop() {
  return (
    <Icon>
      <rect x="4.25" y="4.25" width="7.5" height="7.5" />
    </Icon>
  );
}

export function Play() {
  return (
    <Icon size={14} stroke={1.3}>
      <path d="M5 3.5v9l7-4.5z" strokeLinejoin="round" />
    </Icon>
  );
}

export function Pause() {
  return (
    <Icon size={14} stroke={1.3}>
      <path d="M5.5 3.5v9M10.5 3.5v9" />
    </Icon>
  );
}

export function Close() {
  return (
    <Icon>
      <path d="M4 4l8 8M12 4l-8 8" />
    </Icon>
  );
}

export function Grid() {
  return (
    <Icon>
      <path d="M2.5 2.5h4.5v4.5H2.5zM9 2.5h4.5v4.5H9zM2.5 9h4.5v4.5H2.5zM9 9h4.5v4.5H9z" />
    </Icon>
  );
}

export function FileDoc() {
  return (
    <Icon>
      <path d="M3.5 1.75h6l3 3v9.5h-9z" />
      <path d="M9.5 1.75v3h3M5.5 8.5h5M5.5 11h5" />
    </Icon>
  );
}

export function Paperclip() {
  return (
    <Icon>
      <path d="M10.75 5v6.25a2.75 2.75 0 01-5.5 0V4a1.75 1.75 0 013.5 0v6.75a.75.75 0 01-1.5 0V5" />
    </Icon>
  );
}

export function Open() {
  return (
    <Icon>
      <path d="M6.5 3H3v10h10V9.5M9 2.75h4.25V7M13 3L7.5 8.5" />
    </Icon>
  );
}

export function Mic() {
  return (
    <Icon>
      <path d="M6 2.5h4v6.5H6zM3.5 7.5v1.5a4.5 4.5 0 0 0 9 0V7.5M8 13.5v1.5" />
    </Icon>
  );
}

export function ChevronLeft() {
  return (
    <Icon>
      <path d="M10 4L6 8l4 4" />
    </Icon>
  );
}

export function ChevronRight() {
  return (
    <Icon>
      <path d="M6 4l4 4-4 4" />
    </Icon>
  );
}

export function ChevronUp() {
  return (
    <Icon>
      <path d="M4 10l4-4 4 4" />
    </Icon>
  );
}

export function Minus() {
  return (
    <Icon>
      <path d="M3.5 8h9" />
    </Icon>
  );
}

export function Plus() {
  return (
    <Icon>
      <path d="M3.5 8h9M8 3.5v9" />
    </Icon>
  );
}

export function FitWidth() {
  return (
    <Icon>
      <path d="M2.5 3v10M13.5 3v10M5 8h6M6.5 6.5L5 8l1.5 1.5M9.5 6.5L11 8l-1.5 1.5" />
    </Icon>
  );
}

export function FitScreen() {
  return (
    <Icon>
      <path d="M2.5 6V2.5H6M10 2.5h3.5V6M13.5 10v3.5H10M6 13.5H2.5V10" />
    </Icon>
  );
}

export function ArrowUp() {
  return (
    <Icon>
      <path d="M8 13V3M4.5 6.5L8 3l3.5 3.5" />
    </Icon>
  );
}

export function ArrowDown() {
  return (
    <Icon>
      <path d="M8 3v10M4.5 9.5L8 13l3.5-3.5" />
    </Icon>
  );
}
