import { useEffect, useRef, useState } from 'react';
import { getIdentity, logout } from '../auth';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { JournalPanel } from '../JournalPanel';
import { fetchVoices } from '../noyau/speechVoices';
import { LocaleToggle, VoiceSelect } from '../Preferences';
import { Credits } from '../scene/Attribution';
import type { SpeechVoice } from '../types';

export function JournalButton() {
  const t = useT();
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" className="w-button w-top-button" onClick={() => setOpen(true)} title={t.atelier.journalTitle} aria-label={t.atelier.journalAria}>
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path
            d="M3 2.8c0-.44.36-.8.8-.8h7.9c.44 0 .8.36.8.8v10.9l-1.9-1.1-1.9 1.1-1.9-1.1-1.9 1.1-1.9-1.1V2.8z"
            stroke="currentColor"
            strokeWidth="1.3"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
          <path d="M5.3 5.4h5.4M5.3 7.8h5.4M5.3 10.2h3.2" stroke="currentColor" strokeWidth="1.1" strokeLinecap="round" />
        </svg>
        {t.atelier.journal}
      </button>
      <JournalPanel open={open} onClose={() => setOpen(false)} />
    </>
  );
}

export interface AudioPrefs {
  sound: boolean;
  onSound: (on: boolean) => void;
  voiceOn: boolean;
  onVoiceOn: (on: boolean) => void;
  /** False when the server has no TTS key (`capabilities.voice`): the switch shows off and disabled. */
  voiceAvailable: boolean;
}

function PrefToggle({ label, checked, onChange, disabledReason }: { label: string; checked: boolean; onChange: (on: boolean) => void; disabledReason?: string }) {
  return (
    <label className="w-pref-toggle" title={disabledReason}>
      <span>{label}</span>
      <input type="checkbox" role="switch" checked={checked && !disabledReason} disabled={!!disabledReason} onChange={(e) => onChange(e.target.checked)} />
    </label>
  );
}

export function PreferencesMenu({ voice, onVoice, audio }: { voice: string | null; onVoice: (id: string | null) => void; audio?: AudioPrefs }) {
  const t = useT();
  const [locale] = useLocale();
  const identity = getIdentity();
  const [open, setOpen] = useState(false);
  const [voices, setVoices] = useState<SpeechVoice[]>([]);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);

  const voiceAvailable = audio?.voiceAvailable ?? true;
  useEffect(() => {
    if (!voiceAvailable) {
      setVoices([]);
      return;
    }
    let live = true;
    void fetchVoices().then((list) => live && setVoices(list));
    return () => {
      live = false;
    };
  }, [locale, voiceAvailable]);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      setOpen(false);
      button.current?.focus();
    };
    window.addEventListener('mousedown', onDown);
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('mousedown', onDown);
      window.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="w-prefs" ref={root}>
      <button ref={button} type="button" className="w-button w-top-button" aria-haspopup="dialog" aria-expanded={open} title={t.workshop.prefsLabel} onClick={() => setOpen((o) => !o)}>
        <span className="w-prefs-name">{identity.name ?? identity.email ?? t.atelier.signedIn}</span>
      </button>
      {open && (
        <div className="w-prefs-pop" role="dialog" aria-label={t.workshop.prefsLabel}>
          <LocaleToggle />
          {audio && <PrefToggle label={t.workshop.prefsSound} checked={audio.sound} onChange={audio.onSound} />}
          {audio && (
            <PrefToggle
              label={t.workshop.prefsVoice}
              checked={audio.voiceOn}
              onChange={audio.onVoiceOn}
              disabledReason={audio.voiceAvailable ? undefined : t.workshop.prefsVoiceUnavailable}
            />
          )}
          {voices.length > 0 && <VoiceSelect voices={voices} value={voice} onChange={onVoice} />}
          <Credits />
          <button
            type="button"
            className="w-button"
            onClick={() => {
              setOpen(false);
              logout();
            }}
          >
            {t.atelier.signOut}
          </button>
        </div>
      )}
    </div>
  );
}
