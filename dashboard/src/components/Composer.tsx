import { useState } from 'react';
import { StatusHeartbeat } from './StatusHeartbeat';

interface Props {
  busy: boolean;
  status: string[];
  onAdvanceStatus: () => void;
  onSend: (instruction: string) => void;
}

export function Composer({ busy, status, onAdvanceStatus, onSend }: Props) {
  const [value, setValue] = useState('');

  const submit = () => {
    const text = value.trim();
    if (!text || busy) return;
    onSend(text);
    setValue('');
  };

  return (
    <div className="dock">
      <div className="assistant">
        <StatusHeartbeat queue={status} busy={busy} onAdvance={onAdvanceStatus} />
      </div>
      <div className="composer">
        <input
          value={value}
          placeholder="Demandez à l'assistant — « pièces pour la vidange », « j'ai fait la vidange à 142 000 km »…"
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
