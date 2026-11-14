import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate } from '../format';
import { Failure, ImagePane, PdfPane, type DocSkin } from '../scene/documentPanes';
import { viewerKind } from '../scene/documentViewer';
import { loadPdfJs } from '../scene/pdfjs';
import type { DocScanHit, DocumentSpec, Iri, VehicleBundle, VehicleDocumentKind } from '../types';
import { docBlob, docPreview } from './docBlob';
import { docKinds, openRowId, previewTitle, stripRows, type DocKind } from './docs';
import { acceptsBill, BILL_TYPES } from './editForms';
import { EditorFrame, ErrorLines, Field } from './Editors';
import { useEditHost, type WorkshopEdits } from './edits';
import { Close, FileDoc } from './Icons';
import { documentRows, type DocRow, type Modules } from './modules';
import { SearchLog } from './SearchLog';
import type { DocSearch } from './view';

const SKIN: DocSkin = { prefix: 'w-doc', button: 'w-button' };
const PREVIEW_W = 112;
/** Past this a preview would mean downloading a whole workshop manual just to draw its cover. */
const PREVIEW_MAX_BYTES = 25_000_000;
/** `App\Documentation\VehicleDocumentKind`, manual first: what an owner files most. */
const UPLOAD_KINDS: readonly VehicleDocumentKind[] = ['manual', 'technical_note', 'quote', 'other'];

type Load = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; blob: Blob };

export function rowToSpec(row: DocRow): DocumentSpec {
  return { url: row.url, title: row.title, contentType: row.contentType, filename: row.filename, pagesUrl: row.pagesUrl };
}

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

async function renderFirstPage(blob: Blob, width: number): Promise<string | null> {
  const lib = await loadPdfJs();
  const task = lib.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) });
  try {
    const pdf = await task.promise;
    const page = await pdf.getPage(1);
    const base = page.getViewport({ scale: 1 });
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const viewport = page.getViewport({ scale: (width / base.width) * dpr });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    await page.render({ canvas, viewport }).promise;
    return canvas.toDataURL('image/png');
  } finally {
    void task.destroy();
  }
}

export function DocumentsPlane({
  bundle,
  modules,
  viewer,
  active,
  searches,
  settled,
  reduced,
  onOpenRow,
  onOpenHit,
}: {
  bundle: VehicleBundle;
  modules: Modules;
  viewer: { doc: DocumentSpec; key: number } | null;
  active: boolean;
  searches: DocSearch[];
  settled: { key: number; hit: number } | null;
  reduced: boolean;
  onOpenRow: (row: DocRow) => void;
  onOpenHit: (hit: DocScanHit, query: string) => void;
}) {
  const t = useT();
  const rows = documentRows(bundle);
  const [kind, setKind] = useState<DocKind | null>(null);
  const shown = stripRows(rows, kind);
  const open = viewer?.doc ?? (rows[0] ? rowToSpec(rows[0]) : null);
  const logging = active && searches.length > 0;
  const log = logging ? <SearchLog searches={searches} settled={settled} reduced={reduced} onOpenHit={onOpenHit} /> : null;
  const stageKey = viewer ? `${viewer.key}` : (open?.url ?? '');
  const openId = openRowId(rows, open);
  const kinds = docKinds(rows);
  const strip = useRef<HTMLDivElement>(null);
  const edits = useEditHost()?.edits ?? null;
  const [adding, setAdding] = useState(false);
  const addLink = edits && !adding && (
    <button type="button" className="w-more w-docs-add" onClick={() => setAdding(true)}>
      {t.workshop.documents.upload.open}
    </button>
  );
  const addForm = edits && adding && <AddDocumentForm edits={edits} vehicleIri={bundle.vehicle['@id']} onClose={() => setAdding(false)} />;

  const onStripKey = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    const cards = Array.from(strip.current?.querySelectorAll<HTMLButtonElement>('.w-preview') ?? []);
    const at = cards.indexOf(document.activeElement as HTMLButtonElement);
    const next = cards[Math.min(cards.length - 1, Math.max(0, at + (e.key === 'ArrowRight' ? 1 : -1)))];
    next?.focus();
    next?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    e.preventDefault();
  };

  if (rows.length === 0) {
    return (
      <div className="w-docs-page">
        {log}
        <div className="w-docs-empty">
          <p className="w-muted">{modules.documentsUnavailable ? t.workshop.empty.documentsUnavailable : t.workshop.empty.documents}</p>
          {addLink}
        </div>
        {addForm}
      </div>
    );
  }

  return (
    <div className="w-docs-page">
      {log ?? (open && active && <DocStage key={stageKey} doc={open} kind={rows.find((r) => r.id === openId)?.kind ?? null} />)}
      {addForm}
      <section className="w-strip" aria-label={t.workshop.documents.strip}>
        <div className="w-strip-side">
          {kinds.length > 1 && (
            <div className="w-strip-kinds" role="group" aria-label={t.workshop.documents.kinds}>
              <button type="button" className={`w-chip${kind === null ? ' is-on' : ''}`} aria-pressed={kind === null} onClick={() => setKind(null)}>
                {t.workshop.documents.all} <span className="w-data">{rows.length}</span>
              </button>
              {kinds.map((k) => (
                <button key={k.kind} type="button" className={`w-chip${kind === k.kind ? ' is-on' : ''}`} aria-pressed={kind === k.kind} onClick={() => setKind(k.kind)}>
                  {t.workshop.docKind[k.kind]} <span className="w-data">{k.count}</span>
                </button>
              ))}
            </div>
          )}
          {addLink}
        </div>
        <div className="w-strip-row" ref={strip} onKeyDown={onStripKey}>
          {shown.map((row) => (
            <PreviewCard key={row.id} row={row} open={row.id === openId} live={active} onOpen={() => onOpenRow(row)} />
          ))}
        </div>
      </section>
    </div>
  );
}

function AddDocumentForm({ edits, vehicleIri, onClose }: { edits: WorkshopEdits; vehicleIri: Iri; onClose: () => void }) {
  const t = useT();
  const u = t.workshop.documents.upload;
  const fileInput = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [kind, setKind] = useState<VehicleDocumentKind>('manual');
  const [title, setTitle] = useState('');
  const [sourceRef, setSourceRef] = useState('');
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  const pick = (picked: File | null) => {
    if (picked && !acceptsBill(picked.type)) {
      setErrors([u.rejected(picked.name)]);
      return;
    }
    setErrors([]);
    setFile(picked);
  };
  const submit = (ev: FormEvent) => {
    ev.preventDefault();
    if (busy) return;
    if (!file) {
      setErrors([u.noFile]);
      return;
    }
    setErrors([]);
    setBusy(true);
    edits
      .uploadDocument(vehicleIri, { file, kind, title, sourceRef })
      .then(onClose)
      .catch((err: unknown) => {
        setErrors([u.failed(err instanceof Error ? err.message : String(err))]);
        setBusy(false);
      });
  };

  return (
    <div className="w-docs-addform">
      <EditorFrame title={u.heading} onClose={onClose}>
        <form className="w-edit-form" onSubmit={submit} noValidate>
          <div className="w-edit-grid">
            <Field label={u.file} className="w-field--wide">
              <button type="button" className="w-button w-docs-file" onClick={() => fileInput.current?.click()} title={file?.name} autoFocus>
                {file?.name ?? u.choose}
              </button>
              <input
                ref={fileInput}
                type="file"
                accept={BILL_TYPES.join(',')}
                hidden
                onChange={(ev) => {
                  pick(ev.target.files?.[0] ?? null);
                  ev.target.value = '';
                }}
              />
            </Field>
            <Field label={u.kind}>
              <select className="w-input" value={kind} onChange={(ev) => setKind(ev.target.value as VehicleDocumentKind)}>
                {UPLOAD_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {t.workshop.docKind[k]}
                  </option>
                ))}
              </select>
            </Field>
            <Field label={u.title} className="w-field--wide">
              <input className="w-input" value={title} onChange={(ev) => setTitle(ev.target.value)} />
            </Field>
            <Field label={u.sourceRef}>
              <input className="w-input w-input--data" placeholder={u.sourceRefHint} value={sourceRef} onChange={(ev) => setSourceRef(ev.target.value)} />
            </Field>
          </div>
          <ErrorLines errors={errors} />
          <div className="w-actions w-edit-actions">
            <button type="submit" className="w-button is-primary" disabled={busy}>
              {busy ? u.busy : u.submit}
            </button>
            <button type="button" className="w-button" onClick={onClose}>
              {t.workshop.edit.cancel}
            </button>
          </div>
        </form>
      </EditorFrame>
    </div>
  );
}

function PreviewCard({ row, open, live, onOpen }: { row: DocRow; open: boolean; live: boolean; onOpen: () => void }) {
  const t = useT();
  const [locale] = useLocale();
  const host = useRef<HTMLButtonElement>(null);
  const [src, setSrc] = useState<string | null>(null);
  const previewable = viewerKind(row.contentType) !== 'other' && (row.size ?? 0) <= PREVIEW_MAX_BYTES;

  useEffect(() => {
    const el = host.current;
    if (!el || !previewable || !live) return;
    let alive = true;
    const io = new IntersectionObserver((entries) => {
      if (!entries.some((e) => e.isIntersecting)) return;
      io.disconnect();
      docPreview(row.url, row.contentType, PREVIEW_W, renderFirstPage).then((url) => alive && setSrc(url));
    });
    io.observe(el);
    return () => {
      alive = false;
      io.disconnect();
    };
  }, [row.url, row.contentType, previewable, live]);

  return (
    <button ref={host} type="button" className={`w-preview${open ? ' is-open' : ''}`} aria-current={open || undefined} onClick={onOpen} title={row.title}>
      <span className="w-preview-page">{src ? <img src={src} alt="" draggable={false} /> : <FileDoc />}</span>
      <span className="w-preview-title">{previewTitle(row.title)}</span>
      <span className="w-preview-meta">
        <span>{t.workshop.docKind[row.kind]}</span>
        <span className="w-data">{formatDate(row.date, locale)}</span>
        {row.pageCount != null && row.pageCount > 1 && <span className="w-data">{t.workshop.documents.pages(row.pageCount)}</span>}
      </span>
    </button>
  );
}

export function DocStage({ doc, kind, onClose }: { doc: DocumentSpec; kind: DocKind | null; onClose?: () => void }) {
  const t = useT();
  const [attempt, setAttempt] = useState(0);
  const [load, setLoad] = useState<Load>({ status: 'loading' });
  const type = viewerKind(doc.contentType);

  useEffect(() => {
    let alive = true;
    setLoad({ status: 'loading' });
    docBlob(doc.url)
      .then((blob) => alive && setLoad({ status: 'ready', blob }))
      .catch((e: unknown) => alive && setLoad({ status: 'error', message: (e as Error).message }));
    return () => {
      alive = false;
    };
  }, [doc.url, attempt]);

  const download = () => {
    if (load.status === 'ready') saveBlob(load.blob, doc.filename);
  };

  return (
    <section className="w-docstage" aria-label={t.viewer.dialog(doc.title)}>
      <header className="w-docstage-head">
        <h2 className="w-docstage-title" title={doc.filename}>
          {doc.title}
        </h2>
        {kind && <span className="w-row-dim">{t.workshop.docKind[kind]}</span>}
        <button type="button" className="w-button" onClick={download} disabled={load.status !== 'ready'} aria-label={t.viewer.downloadAria(doc.filename)}>
          {t.viewer.download}
        </button>
        {onClose && (
          <button type="button" className="w-icon-button" onClick={onClose} aria-label={t.viewer.close} title={t.viewer.close}>
            <Close />
          </button>
        )}
      </header>
      {load.status === 'loading' && (
        <p className="w-doc-state" role="status">
          {t.viewer.loading}
        </p>
      )}
      {load.status === 'error' && <Failure message={load.message} onRetry={() => setAttempt((n) => n + 1)} skin={SKIN} />}
      {load.status === 'ready' && type === 'pdf' && (
        <PdfPane key={attempt} blob={load.blob} initialPage={doc.page} highlight={doc.highlight} pagesUrl={doc.pagesUrl} onRetry={() => setAttempt((n) => n + 1)} skin={SKIN} />
      )}
      {load.status === 'ready' && type === 'image' && <ImagePane blob={load.blob} alt={t.viewer.imageAlt(doc.title)} skin={SKIN} fitMode="legible" />}
      {load.status === 'ready' && type === 'other' && (
        <div className="w-doc-state">
          <p>{t.viewer.unsupported}</p>
        </div>
      )}
    </section>
  );
}
