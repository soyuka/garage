import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties } from 'react';
import '@fontsource/zen-kaku-gothic-new/latin-400.css';
import '@fontsource/zen-kaku-gothic-new/latin-500.css';
import '@fontsource/zen-kaku-gothic-new/latin-700.css';
import '@fontsource/zen-kaku-gothic-new/latin-ext-400.css';
import '@fontsource/zen-kaku-gothic-new/latin-ext-500.css';
import '@fontsource/zen-kaku-gothic-new/latin-ext-700.css';
import '@fontsource/jetbrains-mono/latin-400.css';
import '@fontsource/jetbrains-mono/latin-500.css';
import '../tokens/atelier.css';
import './tokens.css';
import './workshop.css';
import { Canvas } from '@react-three/fiber';
import { View } from '@react-three/drei';
import { useT } from '../i18n/LocaleProvider';
import type { Notice, NoyauPhase } from '../noyau/state';
import { regionForType } from '../scene/anchors';
import type { Capabilities, CtaAction, DocumentSpec, Iri, VehicleBundle, VehicleSummary } from '../types';
import { CtaTray } from './CtaTray';
import { Dock } from './Dock';
import { NARRATION_ID } from '../noyau/voice';
import { Notices, Ticker } from './Notices';
import { dockScope, scopeInstruction } from './dock';
import { kmTrack, type Tick } from './kmTrack';
import { isArchived } from './archive';
import { deriveModules, type DocRow, type JobItem } from './modules';
import { ParcMosaic } from './ParcMosaic';
import { CaracteristiquesPlane, EntretienPlane } from './Planes';
import { DocStage, DocumentsPlane, rowToSpec } from './Documents';
import { OpenEditor } from './Editors';
import { EditContext, type EditHost, type EditorTarget, type WorkshopEdits } from './edits';
import { PiecesPlane } from './Pieces';
import type { AudioPrefs } from './Preferences';
import { ProjectionSlot } from './ProjectionSlot';
import { PartsLive } from './PartView';
import type { CommandStatus } from './runningAction';
import type { Theme } from './theme';
import { TopBar } from './TopBar';
import { trackTicks, TrackStrip } from './TrackStrip';
import { SECTIONS, type Section, type ViewAction, type ViewState } from './view';

export interface WorkshopViewProps {
  vehicles: VehicleSummary[];
  bundles: Record<Iri, VehicleBundle>;
  /** null = the parc home. */
  selectedIri: Iri | null;
  loadingParc: boolean;
  parcError?: string | null;
  /** A car route whose bundle has not arrived (or failed). */
  vehicleState: 'ready' | 'loading' | 'missing' | { error: string };
  view: ViewState;
  onView: (action: ViewAction) => void;
  status: CommandStatus;
  /** The noyau phase the dock orb follows. */
  phase: NoyauPhase;
  onListening?: (active: boolean) => void;
  onSubmit: (text: string) => void;
  onCancel: () => void;
  onSelectVehicle: (iri: Iri) => void;
  onAction: (action: CtaAction) => void;
  theme: Theme;
  onTheme: () => void;
  reduced: boolean;
  now: Date;
  homeHref: string;
  onPlan?: (job: JobItem) => void;
  hrefFor: (iri: Iri) => string;
  notices?: Notice[];
  narration?: string | null;
  speakingId?: string | null;
  onDismissNotice?: (id: string) => void;
  voice?: string | null;
  onVoice?: (id: string | null) => void;
  /** Hand edits; absent → no edit entry points. */
  edits?: WorkshopEdits;
  /** Sound and voice on/off, shown in the preferences menu. */
  audio?: AudioPrefs;
  /** What the server can do (`GET /agent/link`); absent → everything assumed on. */
  capabilities?: Capabilities;
  /** The car stage's ring has closed on a region the agent (or a click) pointed at. */
  onPoint?: () => void;
}

const noVoice = () => undefined;

function typingTarget(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  if (!el) return false;
  return el.isContentEditable || el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT';
}

export function WorkshopView(props: WorkshopViewProps) {
  const t = useT();
  const id = useId().replace(/:/g, '');
  const { view, onView, selectedIri, bundles } = props;
  const bundle = selectedIri ? (bundles[selectedIri] ?? null) : null;
  const selected = selectedIri ? (props.vehicles.find((v) => v['@id'] === selectedIri) ?? bundle?.vehicle ?? null) : null;
  const command = useRef<HTMLInputElement>(null);
  const [viewer, setViewer] = useState<{ doc: DocumentSpec; key: number } | null>(null);
  const viewerKey = useRef(0);
  const [tickId, setTickId] = useState<string | null>(null);

  const modules = useMemo(() => (bundle ? deriveModules(bundle, props.now) : null), [bundle, props.now]);
  const track = useMemo(() => (bundle ? kmTrack(bundle, props.now) : null), [bundle, props.now]);
  const ticks = useMemo(() => (track ? trackTicks(track) : []), [track]);

  useEffect(() => setTickId(null), [selectedIri]);

  // One editor at a time, in the right-hand column of the section it was opened from.
  const [editor, setEditor] = useState<{ target: EditorTarget; section: Section; key: number } | null>(null);
  const editorKey = useRef(0);
  const returnTo = useRef<HTMLElement | null>(null);
  const sectionRef = useRef(view.section);
  sectionRef.current = view.section;
  useEffect(() => setEditor(null), [selectedIri]);
  const { edits } = props;
  const host = useMemo<EditHost | null>(
    () =>
      edits
        ? {
            edits,
            open: (target) => {
              returnTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
              editorKey.current += 1;
              setEditor({ target, section: sectionRef.current, key: editorKey.current });
            },
            close: () => {
              setEditor(null);
              const back = returnTo.current;
              window.requestAnimationFrame(() => {
                if (back?.isConnected) back.focus();
              });
            },
          }
        : null,
    [edits],
  );

  // A document opens inside the Documents panel when a car is open; on the parc it keeps its overlay.
  const openDocument = useCallback(
    (doc: DocumentSpec) => {
      viewerKey.current += 1;
      setViewer({ doc, key: viewerKey.current });
      onView({ type: 'endScan' });
      if (selectedIri) onView({ type: 'section', section: 'documents' });
    },
    [onView, selectedIri],
  );
  const lastDocKey = useRef(-1);
  useEffect(() => {
    if (view.projection?.kind !== 'document' || !view.document || lastDocKey.current === view.projectionKey) return;
    lastDocKey.current = view.projectionKey;
    viewerKey.current += 1;
    setViewer({ doc: view.document, key: viewerKey.current });
  }, [view.projection, view.document, view.projectionKey]);
  useEffect(() => setViewer(null), [selectedIri]);
  const openRow = useCallback((row: DocRow) => openDocument(rowToSpec(row)), [openDocument]);

  const selectTick = useCallback(
    (tick: Tick) => {
      setTickId(tick.id);
      if (tick.types[0]) onView({ type: 'focusRegion', region: regionForType(tick.types[0]) });
    },
    [onView],
  );

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        command.current?.focus();
        return;
      }
      if (e.key === 'Escape' && document.activeElement === command.current) {
        command.current?.blur();
        return;
      }
      if (e.metaKey || e.ctrlKey || e.altKey || typingTarget(e.target) || (viewer && !bundle)) return;
      if (e.key === '/') {
        e.preventDefault();
        command.current?.focus();
        return;
      }
      if (!bundle) return;
      const n = Number(e.key);
      if (n >= 1 && n <= SECTIONS.length) {
        onView({ type: 'section', section: SECTIONS[n - 1] });
        return;
      }
      if ((e.key === 'j' || e.key === 'k') && ticks.length > 0 && view.section === 'entretien') {
        const i = ticks.findIndex((tk) => tk.id === tickId);
        const next = i < 0 ? (e.key === 'j' ? 0 : ticks.length - 1) : Math.min(ticks.length - 1, Math.max(0, i + (e.key === 'j' ? 1 : -1)));
        selectTick(ticks[next]);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [bundle, onView, selectTick, tickId, ticks, view.section, viewer]);

  const onSection = useCallback((section: Section) => onView({ type: 'section', section }), [onView]);

  let body;
  if (!selectedIri) {
    body = <ParcMosaic vehicles={props.vehicles} bundles={bundles} now={props.now} loading={props.loadingParc} error={props.parcError ?? null} hrefFor={props.hrefFor} />;
  } else if (!bundle || !modules || !track) {
    const vs = props.vehicleState;
    const text = vs === 'missing' ? t.atelier.vehicleNotFound + t.atelier.backToParcInline : typeof vs === 'object' ? t.atelier.loadFailed(vs.error) : t.atelier.loadingVehicle;
    body = (
      <main className="w-blank">
        <p className="w-muted">{text}</p>
        {vs === 'missing' && (
          <a className="w-link" href={props.homeHref}>
            {t.workshop.parc}
          </a>
        )}
      </main>
    );
  } else {
    const slot = (live: boolean) => (
      <ProjectionSlot
        view={view}
        bundle={bundle}
        modules={modules}
        onClose={() => onView({ type: 'dismissProjection' })}
        onOpenDocument={openDocument}
        actionsDisabled={props.status.running}
        onPlan={props.onPlan}
        live={live}
        onView={onView}
      />
    );
    body = (
      <main className={`w-car-page${isArchived(bundle.vehicle) ? ' is-archived' : ''}`}>
        <div className={`w-stage w-stage--${view.section}`}>
          {SECTIONS.map((s) => {
            const front = s === view.section;
            const planeProps = { slot: slot(front), bundle, modules, view, onOpenRow: openRow, onView };
            const editorNode = editor && editor.section === s ? <OpenEditor key={editor.key} target={editor.target} bundle={bundle} onOpenBill={openDocument} /> : null;
            return (
              <section key={s} id={`${id}-plane-${s}`} role="tabpanel" aria-labelledby={`${id}-tab-${s}`} className={`w-plane${front ? ' is-front' : ''}`}>
                <PartsLive.Provider value={front}>
                  {s === 'entretien' && <EntretienPlane {...planeProps} active={front} reduced={props.reduced} editor={editorNode} onPoint={props.onPoint} />}
                  {s === 'pieces' && (
                    <PiecesPlane
                      slot={planeProps.slot}
                      bundle={bundle}
                      modules={modules}
                      view={view}
                      active={front}
                      reduced={props.reduced}
                      actionsDisabled={props.status.running}
                      onPlan={props.onPlan}
                      editor={editorNode}
                    />
                  )}
                  {s === 'documents' && <DocumentsPlane bundle={bundle} modules={modules} viewer={front ? viewer : null} active={front} scan={view.scan} reduced={props.reduced} onOpenRow={openRow} />}
                  {s === 'caracteristiques' && <CaracteristiquesPlane {...planeProps} />}
                </PartsLive.Provider>
              </section>
            );
          })}
        </div>
        <Canvas className="w-parts-canvas" frameloop="always" dpr={[1, 1.5]} gl={{ antialias: true, alpha: true }} style={{ position: 'fixed', inset: 0, pointerEvents: 'none' }}>
          <View.Port />
        </Canvas>
        {view.section === 'entretien' && <TrackStrip track={track} selectedId={tickId} onSelect={selectTick} />}
      </main>
    );
  }

  const scope = dockScope(bundle ? view.section : null);
  const { onSubmit } = props;
  const submit = useCallback((text: string) => onSubmit(scopeInstruction(scope, text, t.workshop.command.searchDocuments)), [onSubmit, scope, t]);
  const dockArea = useRef<HTMLDivElement>(null);
  const [dockSpace, setDockSpace] = useState(68);
  useLayoutEffect(() => {
    const el = dockArea.current;
    if (!el) return;
    const measure = () => setDockSpace(Math.ceil(el.getBoundingClientRect().height));
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  return (
    <EditContext.Provider value={host}>
      <div className="w-root" style={{ '--w-dock-space': `${dockSpace}px` } as CSSProperties}>
        <TopBar
          vehicles={props.vehicles}
          selected={selected}
          onSelect={props.onSelectVehicle}
          section={bundle ? view.section : null}
          onSection={onSection}
          theme={props.theme}
          onTheme={props.onTheme}
          idPrefix={id}
          homeHref={props.homeHref}
          voice={props.voice ?? null}
          onVoice={props.onVoice ?? noVoice}
          audio={props.audio}
        />
        {body}
        <div className="w-dock-area" ref={dockArea}>
          {view.proposal && <CtaTray proposal={view.proposal} onAction={props.onAction} onDismiss={() => onView({ type: 'clearProposal' })} disabled={props.status.running} />}
          <Ticker narration={props.narration ?? null} speaking={props.speakingId === NARRATION_ID} />
          <Dock
            ref={command}
            status={props.status}
            scope={scope}
            phase={props.phase}
            reduced={props.reduced}
            onSubmit={submit}
            onCancel={props.onCancel}
            onListening={props.onListening}
            agentAvailable={props.capabilities?.agent ?? true}
            dictationAvailable={props.capabilities?.dictation ?? true}
          />
        </div>
        <Notices notices={props.notices ?? []} speakingId={props.speakingId ?? null} onDismiss={props.onDismissNotice ?? noVoice} />
        {viewer && !bundle && (
          <div className="w-doc-overlay">
            <DocStage key={viewer.key} doc={viewer.doc} kind={null} onClose={() => setViewer(null)} />
          </div>
        )}
      </div>
    </EditContext.Provider>
  );
}
