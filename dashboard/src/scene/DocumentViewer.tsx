import './shopping.css';
import './documentviewer.css';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { PDFDocumentProxy, PDFPageProxy, RenderTask } from 'pdfjs-dist';
import { useT } from '../i18n/LocaleProvider';
import { fetchDocumentBlob } from '../garage';
import type { DocumentSpec } from '../types';
import { loadPdfJs } from './pdfjs';
import {
  clampPage,
  findMatches,
  fitWidthScale,
  highlightTerms,
  layoutPages,
  type Match,
  outputScale,
  type PageSize,
  pageAt,
  pagesToRender,
  segmentsByItem,
  viewerKind,
  zoomStep,
} from './documentViewer';

// The document viewer (`showDocument`, CHOREOGRAPHY row of 2026-10-05): a CENTRED surface on the
// shopping list's shell, stacked above whatever opened it. Bytes always come through `authFetch` —
// the file routes are owner-gated.

const PAGE_GAP = 12;
const SIDE_GUTTER = 32;
const MAX_CANVAS_PIXELS = 16_777_216;
const INDEX_BATCH = 25;
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select, [href], [tabindex]:not([tabindex="-1"])';

type Load = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; blob: Blob };

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.rel = 'noopener';
  document.body.append(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

export function DocumentViewer({ doc, reduced = false, onClose }: { doc: DocumentSpec; reduced?: boolean; onClose: () => void }) {
  const t = useT();
  const dialog = useRef<HTMLElement>(null);
  const [attempt, setAttempt] = useState(0);
  const [load, setLoad] = useState<Load>({ status: 'loading' });
  const kind = viewerKind(doc.contentType);

  useEffect(() => {
    const abort = new AbortController();
    setLoad({ status: 'loading' });
    fetchDocumentBlob(doc.url, abort.signal)
      .then((blob) => setLoad({ status: 'ready', blob }))
      .catch((e: unknown) => {
        if (!abort.signal.aborted) setLoad({ status: 'error', message: (e as Error).message });
      });
    return () => abort.abort();
  }, [doc.url, attempt]);

  // One Escape = one step back: the viewer is the innermost layer, so it swallows the key before the
  // documentation panel / Le Dossier listeners (window, bubble phase) can close what is underneath.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      e.stopPropagation();
      e.preventDefault();
      onClose();
    };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
  }, [onClose]);

  useEffect(() => {
    dialog.current?.focus();
  }, []);

  const trapTab = (e: React.KeyboardEvent) => {
    if (e.key !== 'Tab' || !dialog.current) return;
    const items = [...dialog.current.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => el.offsetParent !== null);
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    if (e.shiftKey && (active === first || active === dialog.current)) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  };

  const download = () => {
    if (load.status === 'ready') saveBlob(load.blob, doc.filename);
  };

  return (
    <div className="at-shop-overlay at-dv-overlay" data-reduced={reduced ? 'true' : undefined} onClick={onClose}>
      <section
        ref={dialog}
        className="at-shop at-dv glass"
        role="dialog"
        aria-modal="true"
        aria-label={t.viewer.dialog(doc.title)}
        tabIndex={-1}
        onKeyDown={trapTab}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="at-shop-head at-dv-head">
          <h2 className="at-dv-title" title={doc.filename}>
            {doc.title}
          </h2>
          <button type="button" className="at-shop-shop at-dv-download" onClick={download} disabled={load.status !== 'ready'} aria-label={t.viewer.downloadAria(doc.filename)}>
            {t.viewer.download} ↓
          </button>
          <button type="button" className="at-shop-close" onClick={onClose} aria-label={t.viewer.close}>
            ✕
          </button>
        </header>

        {load.status === 'loading' && <p className="at-dv-state" role="status">{t.viewer.loading}</p>}
        {load.status === 'error' && <Failure message={load.message} onRetry={() => setAttempt((n) => n + 1)} />}
        {load.status === 'ready' && kind === 'pdf' && (
          <PdfPane key={attempt} blob={load.blob} initialPage={doc.page} highlight={doc.highlight} onRetry={() => setAttempt((n) => n + 1)} />
        )}
        {load.status === 'ready' && kind === 'image' && <ImagePane blob={load.blob} alt={t.viewer.imageAlt(doc.title)} />}
        {load.status === 'ready' && kind === 'other' && (
          <div className="at-dv-card">
            <p>{t.viewer.unsupported}</p>
            <button type="button" className="at-shop-shop" onClick={download} aria-label={t.viewer.downloadAria(doc.filename)}>
              {t.viewer.download} ↓ <span className="at-dv-filename">{doc.filename}</span>
            </button>
          </div>
        )}
      </section>
    </div>
  );
}

function Failure({ message, onRetry }: { message: string; onRetry: () => void }) {
  const t = useT();
  return (
    <div className="at-dv-state at-dv-error" role="alert">
      <p>{t.viewer.failed(message)}</p>
      <button type="button" className="at-shop-shop" onClick={onRetry}>
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

function PdfPane({ blob, initialPage, highlight, onRetry }: { blob: Blob; initialPage?: number; highlight?: string; onRetry: () => void }) {
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
  const textCache = useRef(new Map<number, Promise<string[]>>());
  const touched = useRef(false);

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
    return <Failure message={error} onRetry={onRetry} />;
  }

  const zoomTo = (next: number) => {
    touched.current = true;
    setZoom(next);
  };

  return (
    <>
      <div className="at-dv-toolbar" role="toolbar" aria-label={t.viewer.toolbar}>
        <span className="at-dv-group">
          <span className="at-dv-of" aria-hidden="true">{t.viewer.pagePrefix}</span>
          <button type="button" className="at-dv-btn" onClick={() => gotoPage(currentPage - 1)} disabled={currentPage <= 1} aria-label={t.viewer.prevPage}>
            ‹
          </button>
          <input
            className="at-dv-page"
            inputMode="numeric"
            value={pageField !== '' ? pageField : String(currentPage)}
            onFocus={(e) => {
              setPageField(String(currentPage));
              e.target.select();
            }}
            onChange={(e) => setPageField(e.target.value.replace(/[^0-9]/g, ''))}
            onBlur={() => setPageField('')}
            onKeyDown={(e) => {
              if (e.key !== 'Enter') return;
              gotoPage(Number(pageField));
              setPageField('');
              e.currentTarget.blur();
            }}
            aria-label={t.viewer.pageInput}
          />
          <span className="at-dv-of">{numPages > 0 ? t.viewer.ofTotal(numPages) : ''}</span>
          <button type="button" className="at-dv-btn" onClick={() => gotoPage(currentPage + 1)} disabled={currentPage >= numPages} aria-label={t.viewer.nextPage}>
            ›
          </button>
        </span>
        <span className="at-dv-group">
          <button type="button" className="at-dv-btn" onClick={() => zoomTo(zoomStep(zoom, -1))} aria-label={t.viewer.zoomOut}>
            −
          </button>
          <span className="at-dv-of">{Math.round(scale * 100)} %</span>
          <button type="button" className="at-dv-btn" onClick={() => zoomTo(zoomStep(zoom, 1))} aria-label={t.viewer.zoomIn}>
            +
          </button>
          <button type="button" className="at-dv-btn at-dv-fit" onClick={() => zoomTo(1)} aria-pressed={zoom === 1} aria-label={t.viewer.fitWidth} title={t.viewer.fitWidth}>
            ↔
          </button>
        </span>
        <span className="at-dv-group at-dv-find">
          <input
            ref={findInput}
            className="at-dv-findinput"
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
          <span className="at-dv-of" aria-live="polite">
            {terms.length > 0 ? `${t.viewer.matches(ordinal, total)}${indexing ? ` · ${t.viewer.indexing}` : ''}` : ''}
          </span>
          <button type="button" className="at-dv-btn" onClick={() => step(-1)} disabled={total === 0} aria-label={t.viewer.prevMatch}>
            ↑
          </button>
          <button type="button" className="at-dv-btn" onClick={() => step(1)} disabled={total === 0} aria-label={t.viewer.nextMatch}>
            ↓
          </button>
        </span>
      </div>

      <div
        ref={scroller}
        className="at-dv-scroller"
        tabIndex={0}
        onScroll={onScroll}
        onWheel={() => (touched.current = true)}
        onPointerDown={() => (touched.current = true)}
      >
        {!pdf && <p className="at-dv-state" role="status">{t.viewer.loading}</p>}
        {pdf && (
          <div className="at-dv-stack" style={{ height: layout.total, width: contentWidth }}>
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
        mark.className = s.match === currentLocal ? 'at-dv-hit is-current' : 'at-dv-hit';
        mark.textContent = str.slice(s.start, s.end);
        if (s.match === currentLocal && !currentEl) currentEl = mark;
        parts.push(mark);
        at = s.end;
      }
      if (at < str.length) parts.push(document.createTextNode(str.slice(at)));
      div.replaceChildren(...parts);
    });
    if (reveal > 0 && currentEl) (currentEl as HTMLElement).scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
  }, [layer, matches, currentLocal, reveal]);

  return (
    <div className="at-dv-page-sheet" style={{ top, left, width, height }} role="img" aria-label={label} data-page={number}>
      {live && (
        <>
          <canvas ref={canvas} className="at-dv-canvas" aria-hidden="true" />
          <div ref={textHost} className="textLayer at-dv-text" />
        </>
      )}
    </div>
  );
}

// ── Image ───────────────────────────────────────────────────────────────────────

function ImagePane({ blob, alt }: { blob: Blob; alt: string }) {
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

  const fit = natural && box.width > 0 ? Math.min((box.width - SIDE_GUTTER) / natural.width, (box.height - SIDE_GUTTER) / natural.height) : 1;
  const w = natural ? Math.max(1, Math.round(natural.width * fit * zoom)) : undefined;
  const h = natural ? Math.max(1, Math.round(natural.height * fit * zoom)) : undefined;

  return (
    <>
      <div className="at-dv-toolbar" role="toolbar" aria-label={t.viewer.toolbar}>
        <span className="at-dv-group">
          <button type="button" className="at-dv-btn" onClick={() => setZoom((z) => zoomStep(z, -1))} aria-label={t.viewer.zoomOut}>
            −
          </button>
          <span className="at-dv-of">{Math.round(zoom * 100)} %</span>
          <button type="button" className="at-dv-btn" onClick={() => setZoom((z) => zoomStep(z, 1))} aria-label={t.viewer.zoomIn}>
            +
          </button>
          <button type="button" className="at-dv-btn at-dv-fit" onClick={() => setZoom(1)} aria-pressed={zoom === 1} aria-label={t.viewer.fitImage} title={t.viewer.fitImage}>
            ⤢
          </button>
        </span>
      </div>
      <div
        ref={scroller}
        className="at-dv-scroller at-dv-imagebox"
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
        <div className="at-dv-imagestage" style={w && h ? { width: Math.max(w + SIDE_GUTTER, box.width), height: Math.max(h + SIDE_GUTTER, box.height) } : undefined}>
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
