export type EntryMode = 'plate' | 'vin' | 'card';

const MODES: { id: EntryMode; label: string }[] = [
  { id: 'plate', label: 'Plaque' },
  { id: 'vin', label: 'VIN' },
  { id: 'card', label: 'Carte grise' },
];

interface Props {
  value: EntryMode | null;
  onChange: (mode: EntryMode) => void;
  label: string;
}

export function Segmented({ value, onChange, label }: Props) {
  return (
    <div className="seg" role="group" aria-label={label}>
      {MODES.map((m) => (
        <button key={m.id} type="button" className={value === m.id ? 'on' : undefined} aria-pressed={value === m.id} onClick={() => onChange(m.id)}>
          {m.label}
        </button>
      ))}
    </div>
  );
}
