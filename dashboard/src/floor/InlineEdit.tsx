import { useEffect, useRef, useState } from 'react';

// The Atelier click-to-edit primitive (#9 vehicle CRUD). At rest the value reads as plain glass text;
// a click swaps in an input (auto-selected) that commits on Enter or blur and cancels on Escape.
// onCommit fires ONLY when the trimmed draft changed, so an accidental click never issues a no-op
// write. The parent owns coercion (coerceInt / emptyToNull) and the PATCH. Re-authored fresh in the
// Atelier idiom — no import from the old dashboard's InlineEdit.

interface InlineEditProps {
  value: string;
  placeholder?: string;
  ariaLabel: string;
  className?: string;
  /** The trimmed draft, fired only when it differs from the current value. */
  onCommit: (next: string) => void;
}

export function InlineEdit({ value, placeholder, ariaLabel, className, onCommit }: InlineEditProps) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const ref = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (editing) ref.current?.select();
  }, [editing]);
  // Re-sync when the underlying value changes (a live re-read, a Mercure cascade) — but NOT while the
  // user is mid-edit, or a concurrent same-field frame (another tab / the agent merges into the open
  // vehicle without remounting this input) would silently stomp the in-progress draft.
  useEffect(() => {
    if (!editing) setDraft(value);
  }, [value, editing]);

  const commit = () => {
    setEditing(false);
    if (draft.trim() !== value.trim()) onCommit(draft.trim());
  };

  if (editing) {
    return (
      <input
        ref={ref}
        className={`at-inline-input${className ? ` ${className}` : ''}`}
        value={draft}
        aria-label={ariaLabel}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') commit();
          else if (e.key === 'Escape') {
            setDraft(value);
            setEditing(false);
          }
        }}
      />
    );
  }

  return (
    <button type="button" className={`at-inline${className ? ` ${className}` : ''}`} aria-label={ariaLabel} onClick={() => setEditing(true)}>
      {value || <span className="at-inline-empty">{placeholder}</span>}
    </button>
  );
}
