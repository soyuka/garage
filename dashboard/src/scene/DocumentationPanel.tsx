import './shopping.css';
import './documentation.css';
import { useRef, useState } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatBytes, formatDate } from '../format';
import { deleteMaintenanceAttachment, deleteVehicleDocument, uploadVehicleDocument } from '../garage';
import type { DocumentSpec, VehicleDocumentKind } from '../types';
import type { DocRow, Documentation } from './documentation';

// « Documentation » — the vehicle's paper (`focusView {panel:'docs'}`). A CENTRED surface on the shopping
// list's shell (same overlay, glass, head, unfurling groups):
// every row is acted on (open, delete) and the surface ends in an upload form, which a ~270px column
// cannot carry. Closes on scrim click, ✕, or Escape (SceneView owns Escape for any focus).

const UPLOAD_KINDS: readonly VehicleDocumentKind[] = ['quote', 'manual', 'technical_note', 'other'];

export interface DocumentationHandlers {
  vehicleIri: string;
  /** Re-read the open bundle after a write (the vehicle domain frame does it too; this is the instant path). */
  onChanged: () => void;
  onError: (message: string) => void;
  /** Open a row in the document viewer, stacked above this panel (closing it returns here). */
  onOpenDocument: (doc: DocumentSpec) => void;
}

export function DocumentationPanel({
  model,
  handlers,
  reduced = false,
  onDismiss,
}: {
  model: Documentation;
  handlers?: DocumentationHandlers;
  reduced?: boolean;
  onDismiss?: () => void;
}) {
  const [locale] = useLocale();
  const t = useT();

  const open = (row: DocRow) =>
    handlers?.onOpenDocument({ url: row.url, title: row.title, contentType: row.contentType, filename: row.filename, pagesUrl: row.pagesUrl });
  const remove = (row: DocRow) => {
    if (!handlers || !window.confirm(t.docs.confirmRemove(row.title))) return;
    const op = row.remove.type === 'document' ? deleteVehicleDocument(handlers.vehicleIri, row.remove.id) : deleteMaintenanceAttachment(row.remove.url);
    op.then(handlers.onChanged).catch((e) => handlers.onError(t.docs.deleteFailed((e as Error).message)));
  };

  return (
    <div className="at-shop-overlay at-docs-overlay" data-reduced={reduced ? 'true' : undefined} onClick={onDismiss}>
      <section className="at-shop at-docs glass" role="dialog" aria-modal="true" aria-label={t.docs.title} onClick={(e) => e.stopPropagation()}>
        <header className="at-shop-head">
          <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
          <h2>{t.docs.title}</h2>
          <span className="at-shop-count">{t.docs.count(model.count)}</span>
          {onDismiss && (
            <button type="button" className="at-shop-close" onClick={onDismiss} aria-label={t.docs.close}>
              ✕
            </button>
          )}
        </header>

        <div className="at-shop-body">
          {model.unavailable && <p className="at-docs-note">{t.docs.unavailable}</p>}
          {model.groups.length === 0 && !model.unavailable && <p className="at-docs-note">{t.docs.empty}</p>}
          {model.groups.map((group, gi) => (
            <div key={group.kind} className="at-shop-group" style={reduced ? undefined : { animationDelay: `${Math.min(gi, 4) * 70 + 50}ms` }}>
              <div className="at-shop-job">{t.docs.groups[group.kind]}</div>
              <ul className="at-shop-rows" role="list">
                {group.rows.map((row) => (
                  <li className="at-shop-row at-docs-row" key={row.key}>
                    <span className="at-docs-main">
                      <span className="at-docs-title">{row.title}</span>
                      <span className="at-docs-meta">
                        {[row.sourceRef, row.taskLabel ? t.docs.forTask(row.taskLabel) : null, row.date ? formatDate(row.date, locale) : null]
                          .filter(Boolean)
                          .join(' · ')}
                      </span>
                    </span>
                    <span className="at-docs-size">
                      {row.pageCount ? `${t.docs.pages(row.pageCount)} · ` : ''}
                      {formatBytes(row.size, locale)}
                    </span>
                    <button type="button" className="at-shop-shop" onClick={() => open(row)} disabled={!handlers} aria-label={t.docs.openAria(row.title)}>
                      {t.docs.open}
                    </button>
                    {handlers && (
                      <button type="button" className="at-docs-del" onClick={() => remove(row)} aria-label={t.docs.remove(row.title)} title={t.dossier.deleteShort}>
                        ×
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        {handlers && <UploadForm model={model} handlers={handlers} />}
      </section>
    </div>
  );
}

function UploadForm({ model, handlers }: { model: Documentation; handlers: DocumentationHandlers }) {
  const t = useT();
  const fileInput = useRef<HTMLInputElement>(null);
  const [kind, setKind] = useState<VehicleDocumentKind>('manual');
  const [title, setTitle] = useState('');
  const [sourceRef, setSourceRef] = useState('');
  const [taskId, setTaskId] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file || busy) return;
    setBusy(true);
    uploadVehicleDocument(handlers.vehicleIri, { file, kind, title, sourceRef, plannedTaskId: taskId || null })
      .then(() => {
        setFile(null);
        setTitle('');
        setSourceRef('');
        setTaskId('');
        if (fileInput.current) fileInput.current.value = '';
        handlers.onChanged();
      })
      .catch((err) => handlers.onError(t.docs.uploadFailed((err as Error).message)))
      .finally(() => setBusy(false));
  };

  return (
    <form className="at-shop-foot at-docs-form" onSubmit={submit} aria-label={t.docs.add}>
      <select className="at-docs-input" value={kind} onChange={(e) => setKind(e.target.value as VehicleDocumentKind)} aria-label={t.docs.kindAria}>
        {UPLOAD_KINDS.map((k) => (
          <option key={k} value={k}>
            {t.docs.groups[k]}
          </option>
        ))}
      </select>
      <input className="at-docs-input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t.docs.titlePlaceholder} aria-label={t.docs.titleAria} />
      <input className="at-docs-input" value={sourceRef} onChange={(e) => setSourceRef(e.target.value)} placeholder={t.docs.sourceRefPlaceholder} aria-label={t.docs.sourceRefAria} />
      {model.linkableTasks.length > 0 && (
        <select className="at-docs-input" value={taskId} onChange={(e) => setTaskId(e.target.value)} aria-label={t.docs.taskAria}>
          <option value="">{t.docs.noTask}</option>
          {model.linkableTasks.map((task) => (
            <option key={task.id} value={task.id}>
              {task.label}
            </option>
          ))}
        </select>
      )}
      <button type="button" className="at-docs-file" onClick={() => fileInput.current?.click()} title={file?.name}>
        {file?.name ?? t.docs.chooseFile}
      </button>
      <input ref={fileInput} type="file" accept="application/pdf,image/png,image/jpeg" hidden onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      <button type="submit" className="at-shop-shop at-docs-submit" disabled={!file || busy}>
        {busy ? t.docs.uploading : t.docs.upload}
      </button>
      <span className="at-docs-hint">{t.docs.billsHint}</span>
    </form>
  );
}
