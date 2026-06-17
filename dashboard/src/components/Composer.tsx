import { useState } from 'react';
import type { SuggestionChip } from '../types';

interface Props {
  suggestions: SuggestionChip[];
  busy: boolean;
  onSend: (instruction: string) => void;
}

export function Composer({ suggestions, busy, onSend }: Props) {
  const [value, setValue] = useState('');

  const submit = () => {
    const text = value.trim();
    if (!text || busy) return;
    onSend(text);
    setValue('');
  };

  return (
    <div className="dock">
      <div className="copilote">
        <span className="lead">
          <span className="dot" />
          Copilote Atelier
        </span>
        <span style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-label)' }}>
          · suggère pièces, procédures et plans d'entretien
        </span>
        <span style={{ flex: 1 }} />
        {suggestions.map((c) => (
          <button key={c.label} className="chip" disabled={busy} onClick={() => onSend(c.label)}>
            {c.label}
          </button>
        ))}
      </div>
      <div className="composer">
        <input
          value={value}
          placeholder="Demandez au copilote — « pièces pour la vidange », « j'ai fait la vidange à 142 000 km »…"
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submit()}
        />
        <button className="send" disabled={busy || !value.trim()} onClick={submit} title="Envoyer">
          {busy ? '…' : '↑'}
        </button>
      </div>
    </div>
  );
}
