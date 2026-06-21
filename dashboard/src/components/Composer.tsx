import { useState } from 'react';

interface Props {
  busy: boolean;
  onSend: (instruction: string) => void;
  onCancel: () => void;
}

export function Composer({ busy, onSend, onCancel }: Props) {
  const [value, setValue] = useState('');

  const submit = () => {
    const text = value.trim();
    if (!text || busy) return;
    onSend(text);
    setValue('');
  };

  return (
    <div className="dock">
      <div className="composer">
        <input
          value={value}
          placeholder="Demandez à l'assistant — « pièces pour la vidange », « j'ai fait la vidange à 142 000 km »…"
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submit()}
        />
        {busy ? (
          <button className="send send-stop" onClick={onCancel} title="Arrêter">
            ◼
          </button>
        ) : (
          <button className="send" disabled={!value.trim()} onClick={submit} title="Envoyer">
            ↑
          </button>
        )}
      </div>
    </div>
  );
}
