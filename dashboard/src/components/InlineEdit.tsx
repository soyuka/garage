import { useEffect, useRef, useState } from 'react';

interface Props {
  value: string;
  placeholder?: string;
  type?: 'text' | 'number';
  ariaLabel: string;
  className?: string;
  onCommit: (next: string) => void;
}

/**
 * Click-to-edit text/number field. Shows the value as a button; clicking swaps in an input that
 * commits on Enter or blur and cancels on Escape. Only fires onCommit when the value actually
 * changed, so an accidental click never issues a no-op write.
 */
export function InlineEdit({ value, placeholder, type = 'text', ariaLabel, className, onCommit }: Props) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const ref = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (editing) ref.current?.select();
  }, [editing]);
  useEffect(() => setDraft(value), [value]);

  const commit = () => {
    setEditing(false);
    if (draft.trim() !== value.trim()) onCommit(draft.trim());
  };

  if (editing) {
    return (
      <input
        ref={ref}
        className={`inline-edit-input ${className ?? ''}`}
        type={type}
        value={draft}
        aria-label={ariaLabel}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') commit();
          if (e.key === 'Escape') {
            setDraft(value);
            setEditing(false);
          }
        }}
      />
    );
  }

  return (
    <button type="button" className={`inline-edit ${className ?? ''}`} aria-label={ariaLabel} onClick={() => setEditing(true)}>
      {value || <span className="inline-edit-empty">{placeholder}</span>}
    </button>
  );
}
