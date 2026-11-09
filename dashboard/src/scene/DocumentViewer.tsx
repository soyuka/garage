import './shopping.css';
import './documentviewer.css';
import { useEffect, useRef, useState } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { fetchDocumentBlob } from '../garage';
import type { DocumentSpec } from '../types';
import { Failure, ImagePane, PdfPane, type DocSkin } from './documentPanes';
import { viewerKind } from './documentViewer';

// The document viewer (`showDocument`): a CENTRED surface on the
// shopping list's shell, stacked above whatever opened it. Bytes always come through `authFetch` —
// the file routes are owner-gated.

const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select, [href], [tabindex]:not([tabindex="-1"])';
const SKIN: DocSkin = { prefix: 'at-dv', button: 'at-shop-shop' };

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
        {load.status === 'error' && <Failure message={load.message} onRetry={() => setAttempt((n) => n + 1)} skin={SKIN} />}
        {load.status === 'ready' && kind === 'pdf' && (
          <PdfPane key={attempt} blob={load.blob} initialPage={doc.page} highlight={doc.highlight} pagesUrl={doc.pagesUrl} onRetry={() => setAttempt((n) => n + 1)} skin={SKIN} />
        )}
        {load.status === 'ready' && kind === 'image' && <ImagePane blob={load.blob} alt={t.viewer.imageAlt(doc.title)} skin={SKIN} />}
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
