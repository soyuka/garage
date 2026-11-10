import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { highlightTerms } from '../scene/documentViewer';
import type { DocScanHit } from '../types';
import { PAGE_SKELETON, SEARCH_LOG, decodeText, hitBoxes, hitText, markTerms, searchTimeline, type MarkedSegment, type PageBlock, type SearchTimeline } from './docScan';
import type { DocSearch } from './view';

type Vars = CSSProperties & Record<`--${string}`, string>;

const place = (b: PageBlock): Vars => ({ left: `${b.x}%`, top: `${b.y}%`, width: `${b.w}%`, height: `${b.h}%` });
const at = (ms: number | undefined): Vars => ({ '--at': `${ms ?? 0}ms` });
/** ~0.6 mono characters per percent of page width at the frame's usual size; CSS clips what overflows. */
const charsFor = (b: PageBlock) => Math.round(b.w * 0.6);
const cleanSnippet = (s: string) => s.replace(/\s+/g, ' ').trim();

/**
 * The documentation search log (DESIGN.md § Motion): the box page on the left types each query, sweeps
 * and lights one box per hit; the log on the right keeps every search of the turn. No PDF is loaded.
 */
export function SearchLog({
  searches,
  settled,
  reduced,
  onOpenHit,
}: {
  searches: DocSearch[];
  settled: { key: number; hit: number } | null;
  reduced: boolean;
  onOpenHit: (hit: DocScanHit, query: string) => void;
}) {
  const t = useT().workshop.documents.log;
  const current = searches[searches.length - 1];
  const timeline = useMemo(() => (current ? searchTimeline(current.query, current.hits.length, reduced) : null), [current, reduced]);
  const log = useRef<HTMLDivElement>(null);

  useEffect(() => {
    log.current?.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: 'auto' });
  }, [searches.length]);

  if (!current || !timeline) return null;
  return (
    <section className={`w-slog${reduced ? ' is-still' : ''}`} aria-label={t.label}>
      <SearchFrame key={current.key} search={current} timeline={timeline} picked={settled?.key === current.key ? settled.hit : -1} reduced={reduced} />
      <div className="w-slog-log" role="log" aria-live="polite" ref={log}>
        {searches.map((s) => (
          <SearchBlock
            key={s.key}
            search={s}
            timeline={s === current ? timeline : null}
            picked={settled?.key === s.key ? settled.hit : -1}
            onOpenHit={onOpenHit}
          />
        ))}
      </div>
    </section>
  );
}

function SearchFrame({ search, timeline, picked, reduced }: { search: DocSearch; timeline: SearchTimeline; picked: number; reduced: boolean }) {
  const t = useT().workshop.documents.log;
  const terms = useMemo(() => highlightTerms(search.query), [search.query]);
  const boxes = hitBoxes(search.hits.length);
  const none = search.hits.length === 0;
  return (
    <div className={`w-slog-frame${none ? ' is-none' : ''}`} aria-hidden="true" style={{ '--type': `${timeline.typeMs}ms`, '--sweep-at': `${timeline.sweepAt}ms` } as Vars}>
      <div className="w-slog-field">
        <span className="w-slog-prompt">›</span>
        <TypedQuery text={search.query} ms={timeline.typeMs} />
        <span className="w-slog-caret" />
      </div>
      {none && (
        <p className="w-slog-none" style={at(timeline.typeMs)}>
          {t.results(0)}
        </p>
      )}
      <div className="w-slog-page">
        {PAGE_SKELETON.map((b, i) => (
          <span key={i} className={`w-slog-box w-slog-box--${b.kind}`} style={place(b)} />
        ))}
        {!reduced && (
          <div className="w-slog-sweep">
            <span className="w-slog-sweepline" />
          </div>
        )}
        {boxes.map((b, i) => {
          const hit = search.hits[i];
          return (
            <span key={i} className={`w-slog-hit${i === picked ? ' is-picked' : ''}`} style={{ ...place(b), ...at(timeline.lights[i]) }}>
              <DecodeText final={hitText(hit.pageLabel ?? t.page(hit.page), cleanSnippet(hit.snippet), terms, charsFor(b))} terms={terms} delay={timeline.lights[i] ?? 0} reduced={reduced} />
            </span>
          );
        })}
      </div>
    </div>
  );
}

function SearchBlock({
  search,
  timeline,
  picked,
  onOpenHit,
}: {
  search: DocSearch;
  /** set on the newest search only: its rows appear with their boxes */
  timeline: SearchTimeline | null;
  picked: number;
  onOpenHit: (hit: DocScanHit, query: string) => void;
}) {
  const t = useT().workshop.documents.log;
  const terms = useMemo(() => highlightTerms(search.query), [search.query]);
  return (
    <div className={`w-slog-block${timeline ? ' is-current' : ''}`}>
      <p className="w-slog-q">
        <span className="w-slog-qtext">{`› ${search.query}`}</span>
        <span className="w-slog-count" style={at(timeline?.typeMs)}>
          {t.results(search.hits.length)}
        </span>
      </p>
      {search.hits.length > 0 && (
        <ul className="w-slog-rows">
          {search.hits.map((hit, i) => (
            <li key={`${hit.documentId}:${hit.page}`} className="w-slog-li" style={at(timeline ? (timeline.lights[i] ?? timeline.doneAt) : 0)}>
              <button type="button" className={`w-slog-row${i === picked ? ' is-picked' : ''}`} aria-current={i === picked || undefined} onClick={() => onOpenHit(hit, search.query)}>
                <span className="w-slog-src">{hit.sourceRef ?? hit.title}</span>
                <span className="w-slog-pg">{hit.pageLabel ?? t.page(hit.page)}</span>
                <span className="w-slog-snip">
                  <Marked segments={markTerms(cleanSnippet(hit.snippet), terms)} />
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Marked({ segments }: { segments: MarkedSegment[] }) {
  return (
    <>
      {segments.map((s, i) =>
        s.mark ? (
          <mark key={i} className="w-slog-mark">
            {s.text}
          </mark>
        ) : (
          s.text
        ),
      )}
    </>
  );
}

/** Types `text` into its own text node over `ms`: a leaf, so the frame does not re-render per character. */
function TypedQuery({ text, ms }: { text: string; ms: number }) {
  const ref = useRef<HTMLSpanElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const chars = [...text];
    if (ms <= 0) {
      el.textContent = text;
      return;
    }
    el.textContent = '';
    const start = performance.now();
    let raf = 0;
    const tick = (now: number) => {
      const n = Math.min(chars.length, Math.floor(((now - start) / ms) * chars.length));
      el.textContent = chars.slice(0, n).join('');
      if (n < chars.length) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [text, ms]);
  return <span ref={ref} className="w-slog-typed" />;
}

/** The box's text resolving from random glyphs, left to right, once its box lights; marked when settled. */
function DecodeText({ final, terms, delay, reduced }: { final: string; terms: string[]; delay: number; reduced: boolean }) {
  const ref = useRef<HTMLSpanElement>(null);
  const [done, setDone] = useState(reduced);

  useEffect(() => {
    if (reduced) {
      setDone(true);
      return;
    }
    let raf = 0;
    const timer = window.setTimeout(() => {
      const start = performance.now();
      const tick = (now: number) => {
        const p = (now - start) / SEARCH_LOG.decodeMs;
        if (p >= 1) {
          setDone(true);
          return;
        }
        if (ref.current) ref.current.textContent = decodeText(final, p, Math.random);
        raf = requestAnimationFrame(tick);
      };
      raf = requestAnimationFrame(tick);
    }, delay);
    return () => {
      window.clearTimeout(timer);
      cancelAnimationFrame(raf);
    };
  }, [final, delay, reduced]);

  if (done) {
    return (
      <span key="done" className="w-slog-text">
        <Marked segments={markTerms(final, terms)} />
      </span>
    );
  }
  return <span key="decoding" ref={ref} className="w-slog-text" />;
}
