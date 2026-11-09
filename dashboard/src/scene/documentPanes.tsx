import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { PDFDocumentProxy, PDFPageProxy, RenderTask } from 'pdfjs-dist';
import { useT } from '../i18n/LocaleProvider';
import { fetchDocumentPages } from '../garage';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, FitScreen, FitWidth, Minus, Plus } from '../workshop/Icons';
import { loadPdfJs } from './pdfjs';
import {
  clampPage,
  findMatches,
  fitWidthScale,
  highlightTerms,
  imageFitScale,
  labelForPage,
  layoutPages,
  type Match,
  outputScale,
  type PageLabel,
  type PageSize,
  pageAt,
  pagesToRender,
  resolvePageInput,
  segmentsByItem,
  zoomStep,
} from './documentViewer';

// The viewer's panes, shared by the legacy overlay (DocumentViewer.tsx) and the workshop's Documents stage.
// No stylesheet is imported here: each caller brings its own and names its classes through `skin`.

/** `prefix` names every element class (`${prefix}-toolbar`, `${prefix}-hit`…); `button` is the retry button's class. */
export interface DocSkin {
  prefix: string;
  button: string;
}

const PAGE_GAP = 12;
const SIDE_GUTTER = 32;
const MAX_CANVAS_PIXELS = 16_777_216;
const INDEX_BATCH = 25;


export function Failure({ message, onRetry, skin }: { message: string; onRetry: () => void; skin: DocSkin }) {
  const t = useT();
  return (
    <div className={`${skin.prefix}-state ${skin.prefix}-error`} role="alert">
      <p>{t.viewer.failed(message)}</p>
      <button type="button" className={skin.button} onClick={onRetry}>
        {t.viewer.retry}
      </button>
    </div>
  );
}

// ── PDF ─────────────────────────────────────────────────────────────────────────

/** The current find hit, addressed by page so it survives pages being indexed out of order. */
interface MatchRef {
  page: number;
  local: number;
}

function itemStrings(items: readonly unknown[]): string[] {
  const out: string[] = [];
  for (const item of items) {
    const str = (item as { str?: unknown }).str;
    if (typeof str === 'string') out.push(str);
  }
  return out;
}

export function PdfPane({ blob, initialPage, highlight, pagesUrl, onRetry, skin }: { blob: Blob; initialPage?: number; highlight?: string; pagesUrl?: string; onRetry: () => void; skin: DocSkin }) {
  const t = useT();
  const scroller = useRef<HTMLDivElement>(null);
  const findInput = useRef<HTMLInputElement>(null);
  const [pdf, setPdf] = useState<PDFDocumentProxy | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sizes, setSizes] = useState<PageSize[]>([]);
  const [zoom, setZoom] = useState(1);
  const [box, setBox] = useState({ width: 0, height: 0 });
  const [scrollTop, setScrollTop] = useState(0);
  const [texts, setTexts] = useState<Map<number, string[]>>(new Map());
  const [query, setQuery] = useState(highlight ?? '');
  const [current, setCurrent] = useState<MatchRef | null>(null);
  const [reveal, setReveal] = useState(0);
  const [pageField, setPageField] = useState('');
  const [invalidPage, setInvalidPage] = useState(false);
  const [labels, setLabels] = useState<PageLabel[]>([]);
  const textCache = useRef(new Map<number, Promise<string[]>>());
  const touched = useRef(false);

  useEffect(() => {
    if (!pagesUrl) return;
    const abort = new AbortController();
    fetchDocumentPages(pagesUrl, abort.signal)
      .then(setLabels)
      .catch(() => undefined);
    return () => abort.abort();
  }, [pagesUrl]);

  useEffect(() => {
    let cancelled = false;
    let task: { destroy: () => Promise<void> } | null = null;
    let opened: PDFDocumentProxy | null = null;
    (async () => {
      const lib = await loadPdfJs();
      const data = new Uint8Array(await blob.arrayBuffer());
      if (cancelled) return;
      const loading = lib.getDocument({ data });
      task = loading;
      opened = await loading.promise;
      const first = await opened.getPage(1);
      const vp = first.getViewport({ scale: 1 });
      if (cancelled) return;
      setSizes(Array.from({ length: opened.numPages }, () => ({ width: vp.width, height: vp.height })));
      setPdf(opened);
    })().catch((e: unknown) => {
      if (!cancelled) setError((e as Error).message);
    });
    return () => {
      cancelled = true;
      void task?.destroy();
    };
  }, [blob]);

  const numPages = sizes.length;
  const startPage = clampPage(initialPage ?? 1, Math.max(1, numPages));

  const pageText = useCallback(
    (n: number): Promise<string[]> => {
      if (!pdf) return Promise.resolve([]);
      let p = textCache.current.get(n);
      if (!p) {
        p = pdf
          .getPage(n)
          .then((page) => page.getTextContent())
          .then((content) => itemStrings(content.items));
        textCache.current.set(n, p);
      }
      return p;
    },
    [pdf],
  );

  // Index every page's text in the background, from the opening page outward, so the find counter and
  // prev/next cover the whole document and not only the pages scrolled past.
  useEffect(() => {
    if (!pdf) return;
    let cancelled = false;
    const order = [...Array.from({ length: pdf.numPages - startPage + 1 }, (_, i) => startPage + i), ...Array.from({ length: startPage - 1 }, (_, i) => i + 1)];
    (async () => {
      let batch = new Map<number, string[]>();
      const flush = () => {
        const done = batch;
        batch = new Map();
        setTexts((prev) => new Map([...prev, ...done]));
      };
      for (const [i, n] of order.entries()) {
        batch.set(n, await pageText(n));
        if (cancelled) return;
        if (i < 3 || batch.size >= INDEX_BATCH) flush();
      }
      if (batch.size > 0) flush();
    })().catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [pdf, pageText, startPage]);

  useLayoutEffect(() => {
    const el = scroller.current;
    if (!el) return;
    const measure = () => setBox({ width: el.clientWidth, height: el.clientHeight });
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [pdf]);

  const fit = fitWidthScale(sizes[0]?.width ?? 0, box.width, SIDE_GUTTER);
  const scale = fit * zoom;
  const layout = useMemo(() => layoutPages(sizes, scale, PAGE_GAP), [sizes, scale]);
  const contentWidth = Math.max(box.width, Math.max(0, ...layout.widths) + SIDE_GUTTER);
  const visible = useMemo(() => new Set(pagesToRender(layout, scrollTop, box.height, box.height)), [layout, scrollTop, box.height]);
  const currentPage = numPages > 0 ? pageAt(layout, scrollTop, box.height) : 1;
  const pageLabel = labelForPage(labels, currentPage);

  const scrollToPage = useCallback(
    (n: number) => {
      const el = scroller.current;
      if (!el || numPages === 0) return;
      el.scrollTop = layout.tops[clampPage(n, numPages) - 1] - PAGE_GAP;
    },
    [layout, numPages],
  );

  // Land on the requested page once the first layout exists — an instant jump, never a smooth scroll.
  const landed = useRef(false);
  useLayoutEffect(() => {
    if (landed.current || numPages === 0 || box.width === 0) return;
    landed.current = true;
    scrollToPage(startPage);
  }, [numPages, box.width, scrollToPage, startPage]);

  // A zoom keeps the reader where they were: same relative position in the stack.
  const lastTotal = useRef(0);
  useLayoutEffect(() => {
    const el = scroller.current;
    if (el && lastTotal.current > 0 && lastTotal.current !== layout.total) {
      el.scrollTop = (el.scrollTop / lastTotal.current) * layout.total;
    }
    lastTotal.current = layout.total;
  }, [layout.total]);

  const terms = useMemo(() => highlightTerms(query), [query]);
  // A 1 000-page manual is indexed in batches; each page is folded once per query, not once per batch.
  const matchCache = useRef(new WeakMap<string[], { key: string; matches: Match[] }>());
  const matchesByPage = useMemo(() => {
    const out = new Map<number, Match[]>();
    if (terms.length === 0) return out;
    const key = terms.join('\0');
    for (const [n, strings] of texts) {
      let hit = matchCache.current.get(strings);
      if (hit?.key !== key) {
        hit = { key, matches: findMatches(strings, terms) };
        matchCache.current.set(strings, hit);
      }
      const m = hit.matches;
      if (m.length > 0) out.set(n, m);
    }
    return out;
  }, [texts, terms]);
  const pagesWithMatches = useMemo(() => [...matchesByPage.keys()].sort((a, b) => a - b), [matchesByPage]);
  const total = pagesWithMatches.reduce((sum, n) => sum + (matchesByPage.get(n)?.length ?? 0), 0);
  const indexing = numPages > 0 && texts.size < numPages;
  const ordinal = current
    ? pagesWithMatches.filter((n) => n < current.page).reduce((sum, n) => sum + (matchesByPage.get(n)?.length ?? 0), 0) + current.local + 1
    : 0;

  // The opening hit: the first match on (or after) the page the agent cited, revealed once — unless the
  // operator has already started moving through the document.
  const autoSelected = useRef(false);
  useEffect(() => {
    if (autoSelected.current || touched.current || terms.length === 0) return;
    const hit = pagesWithMatches.find((n) => n >= startPage);
    if (hit === undefined) return;
    autoSelected.current = true;
    setCurrent({ page: hit, local: 0 });
    setReveal((r) => r + 1);
  }, [pagesWithMatches, startPage, terms.length]);

  useEffect(() => {
    setCurrent((c) => (c && (matchesByPage.get(c.page)?.length ?? 0) > c.local ? c : null));
  }, [matchesByPage]);

  const step = (dir: 1 | -1) => {
    touched.current = true;
    if (pagesWithMatches.length === 0) return;
    let next: MatchRef;
    if (!current) {
      const from = pagesWithMatches.find((n) => n >= currentPage) ?? pagesWithMatches[0];
      next = { page: from, local: dir > 0 ? 0 : (matchesByPage.get(from)?.length ?? 1) - 1 };
    } else {
      const count = matchesByPage.get(current.page)?.length ?? 0;
      const local = current.local + dir;
      if (local >= 0 && local < count) next = { page: current.page, local };
      else {
        const at = pagesWithMatches.indexOf(current.page);
        const page = pagesWithMatches[(at + dir + pagesWithMatches.length) % pagesWithMatches.length];
        next = { page, local: dir > 0 ? 0 : (matchesByPage.get(page)?.length ?? 1) - 1 };
      }
    }
    setCurrent(next);
    setReveal((r) => r + 1);
    if (!visible.has(next.page)) scrollToPage(next.page);
  };

  const gotoPage = (n: number) => {
    touched.current = true;
    scrollToPage(n);
  };

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
        e.preventDefault();
        findInput.current?.focus();
        findInput.current?.select();
        return;
      }
      const inField = e.target instanceof HTMLInputElement;
      if (inField) return;
      if (e.key === 'PageDown' || e.key === 'PageUp') {
        e.preventDefault();
        gotoPage(currentPage + (e.key === 'PageDown' ? 1 : -1));
      }
    };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
  });

  const onScroll = () => {
    const el = scroller.current;
    if (el) setScrollTop(el.scrollTop);
  };

  if (error) {
    return <Failure message={error} onRetry={onRetry} skin={skin} />;
  }

  const zoomTo = (next: number) => {
    touched.current = true;
    setZoom(next);
  };

  return (
    <>
      <div className={`${skin.prefix}-toolbar`} role="toolbar" aria-label={t.viewer.toolbar}>
        <span className={`${skin.prefix}-group`}>
          <span className={`${skin.prefix}-of`} aria-hidden="true">{t.viewer.pagePrefix}</span>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => gotoPage(currentPage - 1)} disabled={currentPage <= 1} aria-label={t.viewer.prevPage}>
            <ChevronLeft />
          </button>
          <input
            className={`${skin.prefix}-page`}
            aria-invalid={invalidPage || undefined}
            value={pageField !== '' ? pageField : String(currentPage)}
            onFocus={(e) => {
              setPageField(String(currentPage));
              e.target.select();
            }}
            onChange={(e) => {
              setInvalidPage(false);
              setPageField(e.target.value);
            }}
            onBlur={() => {
              setPageField('');
              setInvalidPage(false);
            }}
            onKeyDown={(e) => {
              if (e.key !== 'Enter') return;
              const target = resolvePageInput(pageField, labels, numPages);
              if (target === null) {
                setInvalidPage(true);
                return;
              }
              gotoPage(target);
              setPageField('');
              e.currentTarget.blur();
            }}
            aria-label={t.viewer.pageInput}
          />
          <span className={`${skin.prefix}-of`}>{numPages > 0 ? t.viewer.ofTotal(numPages) : ''}</span>
          {pageLabel && <span className={`${skin.prefix}-label`}>{`· ${pageLabel}`}</span>}
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => gotoPage(currentPage + 1)} disabled={currentPage >= numPages} aria-label={t.viewer.nextPage}>
            <ChevronRight />
          </button>
        </span>
        <span className={`${skin.prefix}-group`}>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => zoomTo(zoomStep(zoom, -1))} aria-label={t.viewer.zoomOut}>
            <Minus />
          </button>
          <span className={`${skin.prefix}-of`}>{Math.round(scale * 100)} %</span>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => zoomTo(zoomStep(zoom, 1))} aria-label={t.viewer.zoomIn}>
            <Plus />
          </button>
          <button type="button" className={`${skin.prefix}-btn ${skin.prefix}-fit`} onClick={() => zoomTo(1)} aria-pressed={zoom === 1} aria-label={t.viewer.fitWidth} title={t.viewer.fitWidth}>
            <FitWidth />
          </button>
        </span>
        <span className={`${skin.prefix}-group ${skin.prefix}-find`}>
          <input
            ref={findInput}
            className={`${skin.prefix}-findinput`}
            type="search"
            value={query}
            placeholder={t.viewer.findPlaceholder}
            aria-label={t.viewer.find}
            onChange={(e) => {
              touched.current = true;
              setQuery(e.target.value);
              setCurrent(null);
            }}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                step(e.shiftKey ? -1 : 1);
              }
            }}
          />
          <span className={`${skin.prefix}-of`} aria-live="polite">
            {terms.length > 0 ? `${t.viewer.matches(ordinal, total)}${indexing ? ` · ${t.viewer.indexing}` : ''}` : ''}
          </span>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => step(-1)} disabled={total === 0} aria-label={t.viewer.prevMatch}>
            <ArrowUp />
          </button>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => step(1)} disabled={total === 0} aria-label={t.viewer.nextMatch}>
            <ArrowDown />
          </button>
        </span>
      </div>

      <div
        ref={scroller}
        className={`${skin.prefix}-scroller`}
        tabIndex={0}
        onScroll={onScroll}
        onWheel={() => (touched.current = true)}
        onPointerDown={() => (touched.current = true)}
      >
        {!pdf && <p className={`${skin.prefix}-state`} role="status">{t.viewer.loading}</p>}
        {pdf && (
          <div className={`${skin.prefix}-stack`} style={{ height: layout.total, width: contentWidth }}>
            {layout.tops.map((top, i) => {
              const n = i + 1;
              return (
                <PdfPage
                  key={n}
                  pdf={pdf}
                  number={n}
                  scale={scale}
                  top={top}
                  left={Math.max(SIDE_GUTTER / 2, (contentWidth - layout.widths[i]) / 2)}
                  width={layout.widths[i]}
                  height={layout.heights[i]}
                  live={visible.has(n)}
                  matches={matchesByPage.get(n)}
                  currentLocal={current?.page === n ? current.local : -1}
                  reveal={current?.page === n ? reveal : 0}
                  label={t.viewer.pageAria(n)}
                  skin={skin}
                  onSize={(size) =>
                    setSizes((prev) => (prev[i] && (Math.abs(prev[i].width - size.width) > 0.5 || Math.abs(prev[i].height - size.height) > 0.5) ? prev.map((s, j) => (j === i ? size : s)) : prev))
                  }
                />
              );
            })}
          </div>
        )}
      </div>
    </>
  );
}

function PdfPage({
  pdf,
  number,
  scale,
  top,
  left,
  width,
  height,
  live,
  matches,
  currentLocal,
  reveal,
  label,
  onSize,
  skin,
}: {
  pdf: PDFDocumentProxy;
  number: number;
  scale: number;
  top: number;
  left: number;
  width: number;
  height: number;
  live: boolean;
  matches: Match[] | undefined;
  currentLocal: number;
  reveal: number;
  label: string;
  onSize: (size: PageSize) => void;
  skin: DocSkin;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const textHost = useRef<HTMLDivElement>(null);
  const [layer, setLayer] = useState<{ divs: HTMLElement[]; strings: string[] } | null>(null);
  const onSizeRef = useRef(onSize);
  onSizeRef.current = onSize;

  useEffect(() => {
    if (!live) {
      setLayer(null);
      return;
    }
    let cancelled = false;
    let renderTask: RenderTask | null = null;
    let textLayer: { cancel: () => void } | null = null;
    let page: PDFPageProxy | null = null;
    (async () => {
      const lib = await loadPdfJs();
      page = await pdf.getPage(number);
      if (cancelled) return;
      const base = page.getViewport({ scale: 1 });
      onSizeRef.current({ width: base.width, height: base.height });
      const viewport = page.getViewport({ scale });
      const el = canvas.current;
      const host = textHost.current;
      if (!el || !host) return;
      const dpr = outputScale(viewport.width, viewport.height, window.devicePixelRatio || 1, MAX_CANVAS_PIXELS);
      el.width = Math.floor(viewport.width * dpr);
      el.height = Math.floor(viewport.height * dpr);
      el.style.width = `${Math.floor(viewport.width)}px`;
      el.style.height = `${Math.floor(viewport.height)}px`;
      renderTask = page.render({ canvas: el, viewport, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : undefined });
      const content = await page.getTextContent();
      if (cancelled) return;
      host.replaceChildren();
      host.style.setProperty('--scale-factor', String(viewport.scale));
      host.style.setProperty('--user-unit', String(page.userUnit || 1));
      const tl = new lib.TextLayer({ textContentSource: content, container: host, viewport });
      textLayer = tl;
      await tl.render();
      if (cancelled) return;
      setLayer({ divs: tl.textDivs, strings: tl.textContentItemsStr });
      await renderTask.promise;
    })().catch((e: unknown) => {
      if ((e as { name?: string }).name !== 'RenderingCancelledException' && !cancelled) console.warn('pdf page render failed', e);
    });
    return () => {
      cancelled = true;
      renderTask?.cancel();
      textLayer?.cancel();
      page?.cleanup();
      textHost.current?.replaceChildren();
      const el = canvas.current;
      if (el) {
        el.width = 0;
        el.height = 0;
      }
    };
  }, [pdf, number, scale, live]);

  // Paint the find hits into the text layer: each run's text is rebuilt as plain text + <mark>s.
  useEffect(() => {
    if (!layer) return;
    const segments = matches ? segmentsByItem(matches, layer.strings.map((s) => s.length)) : new Map();
    let currentEl: HTMLElement | null = null;
    layer.divs.forEach((div, i) => {
      const str = layer.strings[i] ?? '';
      const segs = segments.get(i);
      if (!segs) {
        if (div.childElementCount > 0) div.textContent = str;
        return;
      }
      const parts: Node[] = [];
      let at = 0;
      for (const s of segs) {
        if (s.start > at) parts.push(document.createTextNode(str.slice(at, s.start)));
        const mark = document.createElement('mark');
        mark.className = s.match === currentLocal ? `${skin.prefix}-hit is-current` : `${skin.prefix}-hit`;
        mark.textContent = str.slice(s.start, s.end);
        if (s.match === currentLocal && !currentEl) currentEl = mark;
        parts.push(mark);
        at = s.end;
      }
      if (at < str.length) parts.push(document.createTextNode(str.slice(at)));
      div.replaceChildren(...parts);
    });
    if (reveal > 0 && currentEl) (currentEl as HTMLElement).scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
  }, [layer, matches, currentLocal, reveal, skin.prefix]);

  return (
    <div className={`${skin.prefix}-page-sheet`} style={{ top, left, width, height }} role="img" aria-label={label} data-page={number}>
      {live && (
        <>
          <canvas ref={canvas} className={`${skin.prefix}-canvas`} aria-hidden="true" />
          <div ref={textHost} className={`textLayer ${skin.prefix}-text`} />
        </>
      )}
    </div>
  );
}

// ── Image ───────────────────────────────────────────────────────────────────────

export function ImagePane({ blob, alt, skin, fitMode = 'contain' }: { blob: Blob; alt: string; skin: DocSkin; fitMode?: 'contain' | 'legible' }) {
  const t = useT();
  const scroller = useRef<HTMLDivElement>(null);
  const [src, setSrc] = useState<string | null>(null);
  const [natural, setNatural] = useState<PageSize | null>(null);
  const [box, setBox] = useState({ width: 0, height: 0 });
  const [zoom, setZoom] = useState(1);
  const drag = useRef<{ x: number; y: number; left: number; top: number } | null>(null);

  useEffect(() => {
    const url = URL.createObjectURL(blob);
    setSrc(url);
    return () => URL.revokeObjectURL(url);
  }, [blob]);

  useLayoutEffect(() => {
    const el = scroller.current;
    if (!el) return;
    const measure = () => setBox({ width: el.clientWidth, height: el.clientHeight });
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const fit = natural && box.width > 0 ? imageFitScale(natural, box, SIDE_GUTTER, fitMode) : 1;
  const w = natural ? Math.max(1, Math.round(natural.width * fit * zoom)) : undefined;
  const h = natural ? Math.max(1, Math.round(natural.height * fit * zoom)) : undefined;

  return (
    <>
      <div className={`${skin.prefix}-toolbar`} role="toolbar" aria-label={t.viewer.toolbar}>
        <span className={`${skin.prefix}-group`}>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => setZoom((z) => zoomStep(z, -1))} aria-label={t.viewer.zoomOut}>
            <Minus />
          </button>
          <span className={`${skin.prefix}-of`}>{Math.round(zoom * 100)} %</span>
          <button type="button" className={`${skin.prefix}-btn`} onClick={() => setZoom((z) => zoomStep(z, 1))} aria-label={t.viewer.zoomIn}>
            <Plus />
          </button>
          <button type="button" className={`${skin.prefix}-btn ${skin.prefix}-fit`} onClick={() => setZoom(1)} aria-pressed={zoom === 1} aria-label={t.viewer.fitImage} title={t.viewer.fitImage}>
            <FitScreen />
          </button>
        </span>
      </div>
      <div
        ref={scroller}
        className={`${skin.prefix}-scroller ${skin.prefix}-imagebox`}
        data-dragging={drag.current ? 'true' : undefined}
        tabIndex={0}
        onPointerDown={(e) => {
          const el = scroller.current;
          if (!el || e.button !== 0) return;
          drag.current = { x: e.clientX, y: e.clientY, left: el.scrollLeft, top: el.scrollTop };
          el.setPointerCapture(e.pointerId);
        }}
        onPointerMove={(e) => {
          const el = scroller.current;
          const d = drag.current;
          if (!el || !d) return;
          el.scrollLeft = d.left - (e.clientX - d.x);
          el.scrollTop = d.top - (e.clientY - d.y);
        }}
        onPointerUp={() => (drag.current = null)}
        onPointerCancel={() => (drag.current = null)}
      >
        <div className={`${skin.prefix}-imagestage`} style={w && h ? { width: Math.max(w + SIDE_GUTTER, box.width), height: Math.max(h + SIDE_GUTTER, box.height) } : undefined}>
          {src && (
            <img
              src={src}
              alt={alt}
              draggable={false}
              width={w}
              height={h}
              onLoad={(e) => setNatural({ width: e.currentTarget.naturalWidth, height: e.currentTarget.naturalHeight })}
            />
          )}
        </div>
      </div>
    </>
  );
}
