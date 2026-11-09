import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { useT } from '../i18n/LocaleProvider';
import type { Iri, VehicleSummary } from '../types';
import { ChevronDown, Grid, Moon, Sun } from './Icons';
import { displayName } from './genericBody';
import { nextJobOf } from './modules';
import { StateShape } from './StateMark';
import type { Theme } from './theme';
import { JournalButton, PreferencesMenu, type AudioPrefs } from './Preferences';
import { SECTIONS, type Section } from './view';

export function vehicleName(v: Pick<VehicleSummary, 'make' | 'model'>): string {
  return displayName(v);
}

function VehicleSwitcher({ vehicles, selected, onSelect }: { vehicles: VehicleSummary[]; selected: VehicleSummary | null; onSelect: (iri: Iri) => void }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const button = useRef<HTMLButtonElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const root = useRef<HTMLDivElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    list.current?.focus();
    const onDown = (e: MouseEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    window.addEventListener('mousedown', onDown);
    return () => window.removeEventListener('mousedown', onDown);
  }, [open]);

  const openList = () => {
    const i = selected ? vehicles.findIndex((v) => v['@id'] === selected['@id']) : 0;
    setActive(Math.max(0, i));
    setOpen(true);
  };
  const choose = (i: number) => {
    const v = vehicles[i];
    setOpen(false);
    button.current?.focus();
    if (v) onSelect(v['@id']);
  };
  const onListKey = (e: KeyboardEvent<HTMLUListElement>) => {
    if (e.key === 'ArrowDown') setActive((i) => Math.min(vehicles.length - 1, i + 1));
    else if (e.key === 'ArrowUp') setActive((i) => Math.max(0, i - 1));
    else if (e.key === 'Home') setActive(0);
    else if (e.key === 'End') setActive(vehicles.length - 1);
    else if (e.key === 'Enter' || e.key === ' ') choose(active);
    else if (e.key === 'Escape' || e.key === 'Tab') {
      setOpen(false);
      if (e.key === 'Escape') button.current?.focus();
      return;
    } else return;
    e.preventDefault();
    e.stopPropagation();
  };

  const selectedJob = selected ? nextJobOf(selected) : null;
  return (
    <div className="w-switcher" ref={root}>
      <button
        ref={button}
        type="button"
        className="w-switcher-button"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={`${id}-list`}
        aria-label={t.workshop.switcherLabel}
        onClick={() => (open ? setOpen(false) : openList())}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown' && !open) {
            e.preventDefault();
            openList();
          }
        }}
      >
        {selected ? (
          <>
            {selectedJob && <StateShape state={selectedJob.state} />}
            <span className="w-switcher-name">{vehicleName(selected)}</span>
            {selected.plate && <span className="w-data w-switcher-plate">{selected.plate}</span>}
          </>
        ) : (
          <span className="w-switcher-name w-muted">{t.workshop.switcherNone}</span>
        )}
        <ChevronDown />
      </button>
      {open && (
        <ul
          ref={list}
          id={`${id}-list`}
          className="w-switcher-list"
          role="listbox"
          tabIndex={-1}
          aria-label={t.workshop.switcherList}
          aria-activedescendant={`${id}-opt-${active}`}
          onKeyDown={onListKey}
        >
          {vehicles.map((v, i) => {
            const job = nextJobOf(v);
            return (
              <li
                key={v['@id']}
                id={`${id}-opt-${i}`}
                role="option"
                aria-selected={selected?.['@id'] === v['@id']}
                className={`w-switcher-option${i === active ? ' is-active' : ''}`}
                onMouseEnter={() => setActive(i)}
                onClick={() => choose(i)}
              >
                <StateShape state={job?.state ?? 'unknown'} />
                <span className="w-switcher-name">{vehicleName(v)}</span>
                <span className="w-data w-switcher-plate">{v.plate ?? ''}</span>
                <span className="w-switcher-job">{job ? job.label : t.workshop.home.noJob}</span>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

export function SectionTabs({ section, onSection, idPrefix }: { section: Section; onSection: (s: Section) => void; idPrefix: string }) {
  const t = useT();
  const refs = useRef<Array<HTMLButtonElement | null>>([]);
  const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
    const i = SECTIONS.indexOf(section);
    let next = -1;
    if (e.key === 'ArrowRight') next = (i + 1) % SECTIONS.length;
    else if (e.key === 'ArrowLeft') next = (i - 1 + SECTIONS.length) % SECTIONS.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = SECTIONS.length - 1;
    if (next < 0) return;
    e.preventDefault();
    onSection(SECTIONS[next]);
    refs.current[next]?.focus();
  };
  return (
    <div className="w-tabs" role="tablist" aria-label={t.workshop.sectionsLabel} onKeyDown={onKey}>
      {SECTIONS.map((s, i) => (
        <button
          key={s}
          ref={(el) => {
            refs.current[i] = el;
          }}
          type="button"
          role="tab"
          id={`${idPrefix}-tab-${s}`}
          aria-selected={section === s}
          aria-controls={`${idPrefix}-plane-${s}`}
          tabIndex={section === s ? 0 : -1}
          className="w-tab"
          onClick={() => onSection(s)}
        >
          <span className="w-data w-tab-key" aria-hidden="true">
            {i + 1}
          </span>
          {t.workshop.section[s]}
        </button>
      ))}
    </div>
  );
}

export function TopBar({
  vehicles,
  selected,
  onSelect,
  section,
  onSection,
  theme,
  onTheme,
  idPrefix,
  homeHref,
  voice,
  onVoice,
  audio,
}: {
  vehicles: VehicleSummary[];
  selected: VehicleSummary | null;
  onSelect: (iri: Iri) => void;
  section: Section | null;
  onSection: (s: Section) => void;
  theme: Theme;
  onTheme: () => void;
  idPrefix: string;
  homeHref: string;
  voice: string | null;
  onVoice: (id: string | null) => void;
  audio?: AudioPrefs;
}) {
  const t = useT();
  return (
    <header className="w-top">
      <a className="w-wordmark" href={homeHref}>
        <span className="w-wordmark-plate" aria-hidden="true" />
        {t.workshop.wordmark}
      </a>
      <a className={`w-parc-link${selected ? '' : ' is-current'}`} href={homeHref} aria-current={selected ? undefined : 'page'}>
        <Grid />
        <span className="w-parc-text">{t.workshop.parc}</span>
      </a>
      <VehicleSwitcher vehicles={vehicles} selected={selected} onSelect={onSelect} />
      {section && <SectionTabs section={section} onSection={onSection} idPrefix={idPrefix} />}
      <div className="w-top-tools">
        <JournalButton />
        <PreferencesMenu voice={voice} onVoice={onVoice} audio={audio} />
      </div>
      <button
        type="button"
        className="w-icon-button w-theme"
        onClick={onTheme}
        aria-label={theme === 'dark' ? t.workshop.themeToLight : t.workshop.themeToDark}
        title={theme === 'dark' ? t.workshop.themeToLight : t.workshop.themeToDark}
      >
        {theme === 'dark' ? <Sun /> : <Moon />}
      </button>
    </header>
  );
}
