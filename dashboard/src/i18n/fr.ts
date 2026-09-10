import { NBSP } from '../format';
import type { MaintenanceHealth, ServiceAction } from '../types';

// The French catalog — the app's original voice, moved here VERBATIM. Nothing in this file is a new
// wording: if a string reads differently from how the Atelier read before internationalisation, that
// is a bug, not an improvement.
//
// `en.ts` is typed as `Messages = typeof fr`, so this file is the shape as well as the content: add a
// key here and the compiler demands it there. Entries that take arguments are functions, which is how
// plurals and interpolation stay the catalog's business rather than the caller's.

// No `as const`: the values must widen to `string`, or every French wording becomes a literal TYPE
// that the English catalog is then required to match exactly. The shape is what en.ts must mirror,
// not the words.
export const fr = {
  /** Grayscale-legible status tags — the signal is never conveyed by colour alone (§8). */
  signal: {
    late: 'EN RETARD',
    soon: 'BIENTÔT',
    ok: 'OK',
    untracked: 'NON SUIVI',
  },

  forecast: {
    /** No axis is honestly precise here, so the bare word carries it (no false precision, §5). */
    overdue: 'DÉPASSÉ',
    /** The unit for an overdue DAY count; the sentence around it is format.ts's formatOverdue. */
    dayUnit: (days: number): string => (days > 1 ? 'jours' : 'jour'),
    /** A remaining-distance horizon, e.g. « dans 6 000 km ». */
    inReading: (reading: string) => `dans ${reading}`,
    /** Mid-sentence form of "no due date" (a caption, not a heading). */
    noDueDateLower: 'sans échéance',
    noDueDate: 'Sans échéance',
    neverLogged: 'jamais consigné — depuis quand ?',
    none: 'Aucune échéance',
  },

  health: {
    green: 'Sain',
    amber: 'Service à prévoir',
    red: 'En retard',
    unknown: 'Non suivi',
  } satisfies Record<MaintenanceHealth['status'], string>,

  task: {
    /** "<service> — <the operator's own words>", when their words do not already name the service. */
    titleWithLabel: (service: string, label: string) => `${service} — ${label}`,
    atUsage: (reading: string) => `À ${reading}`,
    beforeDate: (date: string) => `Avant le ${date}`,
  },

  schedule: {
    verb: {
      change: 'changer',
      replace: 'remplacer',
      clean: 'nettoyer',
      inspect: 'inspecter',
    } satisfies Record<ServiceAction, string>,
    ruleTitle: (head: string, component: string) => `${head} — ${component}`,
    everyUsage: (reading: string) => `tous les ${reading}`,
    everyMonths: (months: number) => `tous les ${months}${NBSP}mois`,
    firstAt: (reading: string) => `d’abord à ${reading}`,
    noCadence: 'cadence non définie',
  },

  scene: {
    data: 'Données',
    history: 'Historique',
    close: 'Fermer',
    emptyFleet: 'Parc vide — ajoutez un véhicule.',
    freeView: 'Vue libre — revenir à la vue d’ensemble',
    freeViewShort: 'vue libre',
    speak: "Parlez à l'atelier",
    speakPlaceholder: "Parlez à l'atelier…",
  },

  proj: {
    /** Shared across every projection: the mark saying Le Noyau placed this panel. */
    placedByNoyau: 'placé par Le Noyau',
    specs: 'Spécifications',
    closeSpecs: 'Fermer les spécifications',
    verified: 'vérifié',
    unverified: '· non vérifié',
    partsFor: (service: string) => `Pièces — ${service}`,
    partsHead: (service: string) => `PIÈCES · ${service}`,
    closeParts: 'Fermer les pièces',
    closeCarnet: 'Fermer le carnet',
    closeWorklist: 'Fermer la liste',
    closeShopping: 'Fermer les pièces à commander',
    addToTask: 'Ajouter à la tâche →',
    carnet: 'Carnet · dépenses',
    carnetHead: 'CARNET · DÉPENSES',
    withoutCost: (n: number) => `, ${n} sans coût`,
    /** The visit ring's key — the operator asked why the bars are there (nothing on screen said so). */
    ringKey: 'une barre par visite · hauteur = coût · fil de fer = coût inconnu · la plus récente près de vous',
    worklist: 'À faire',
    worklistHead: 'À FAIRE',
    neverLogged: 'jamais consigné — depuis quand ?',
    refCount: (n: number) => ` · ${n} référence${n > 1 ? 's' : ''}`,
    shopping: 'Pièces à commander',
    refAndLineCount: (refs: number, lines: number) => `${refs} référence${refs > 1 ? 's' : ''} · ${lines} poste${lines > 1 ? 's' : ''}`,
    unpriced: (n: number) => `${n} référence${n > 1 ? 's' : ''} sans prix connu`,
    backToOverview: 'Revenir à la vue d’ensemble',
    seeOnCar: 'Voir cette zone sur le véhicule',
    searchOn: (ref: string, shop: string) => `Chercher « ${ref} » sur ${shop}`,
  },

  carnet: {
    title: 'Carnet d’entretien',
  },

  parc: {
    unnamed: 'Véhicule sans nom',
    usage: 'Kilométrage',
    nothingDue: 'Aucune échéance',
    addAria: 'Ajouter un véhicule au parc',
    add: 'Ajouter un véhicule',
    empty: 'Aucun véhicule dans le parc.',
    landmark: 'Le parc',
  },

  cockpit: {
    health: 'Santé',
    plate: '+ plaque',
    variant: '+ variante',
    engineCode: '+ code moteur',
    editModel: 'Modifier le modèle',
  },

  rail: {
    title: 'Entretien',
    lateCount: (n: number) => `${n} en retard`,
    cancel: 'Annuler',
    addTask: '+ Tâche',
    archiveDone: (n: number) => `Archiver les terminées (${n})`,
    empty: 'Rien à suivre pour l’instant.',
    now: 'Maintenant',
    nextVisit: 'Prochaine visite',
    later: 'Plus tard',
    laterCount: (n: number) => `Plus tard · ${n}`,
    laterFrom: (when: string) => `à partir de ${when}`,
    laterServices: (n: number) => `${n} service${n > 1 ? 's' : ''}`,
    untracked: 'non suivi',
    hideClosed: 'Masquer les tâches classées',
    showClosed: (n: number) => `Voir les tâches terminées et retirées${n > 0 ? ` (${n})` : ''}`,
    planThis: 'Planifier cette échéance',
    planAria: (label: string) => `${label} — planifier`,
    backToOverview: 'Revenir à la vue d’ensemble',
    seeRegionOnCar: (region: string) => `Voir « ${region} » sur le véhicule`,
    cardAria: (caption: string, action: string) => `${caption} — ${action}`,
    backToOverviewLower: 'revenir à la vue d’ensemble',
    seeRegionOnCarLower: (region: string) => `voir « ${region} » sur le véhicule`,
    restoreTask: 'Restaurer la tâche',
    reopenTask: 'Rouvrir la tâche',
    markDone: 'Marquer comme fait',
    editTask: 'Modifier la tâche',
    edit: 'Modifier',
    dismissTask: 'Retirer la tâche',
    restore: 'Restaurer',
    remove: 'Retirer',
    seeRefs: (n: number, task: string) => `Voir les ${n} références de « ${task} »`,
    refShort: (n: number) => `${n} réf.`,
    typePlaceholder: 'type (ex. brake_pads)',
    typeLabel: 'Type de service',
    labelPlaceholder: 'libellé (optionnel)',
    labelAria: 'Libellé',
    targetPlaceholder: (unit: string) => `à … ${unit}`,
    targetAria: 'Échéance kilométrage',
    dueDateAria: 'Échéance date',
    add: 'Ajouter',
    save: 'Enregistrer',
    refPlaceholder: 'réf.',
    refAria: 'Référence',
    brandPlaceholder: 'marque',
    brandAria: 'Marque',
    partLabelPlaceholder: 'libellé',
    partLabelAria: 'Libellé de la pièce',
    qtyPlaceholder: 'qté',
    qtyAria: 'Quantité',
    removePart: 'Retirer cette pièce',
    addPart: '+ Pièce',
  },

  /** The teardown a region-grouped visit shares, in the operator's words. */
  region: {
    hood: 'Moteur',
    windshield: 'Poste de conduite',
    frontAxle: 'Train avant',
    rearAxle: 'Train arrière',
    bay: 'Périphériques',
  },

  atelier: {
    turnStalled: 'Le tour ne répond plus — réessayez.',
    answerDropped: (dropped: string) => `Réponse abandonnée : le véhicule n’est pas arrivé (${dropped}).`,
    requestFailed: (msg: string) => `Échec de la requête : ${msg}`,
    addFailed: (msg: string) => `Échec de l’ajout : ${msg}`,
    planFailed: (msg: string) => `Échec de la planification : ${msg}`,
    createFailed: (msg: string) => `Échec de la création : ${msg}`,
    updateFailed: (msg: string) => `Échec de la mise à jour : ${msg}`,
    deleteFailed: (msg: string) => `Échec de la suppression : ${msg}`,
    loadFailed: (msg: string) => `Échec du chargement : ${msg}`,
    /** Prefixes handed to taskWrite, which appends `: <error>` itself. */
    failUpdate: 'Échec de la mise à jour',
    failAdd: 'Échec de l’ajout',
    failDelete: 'Échec de la suppression',
    failArchive: 'Échec de l’archivage',
    overdueConcern: (service: string) => `${service} en retard`,
    ctaOptions: (labels: string) => `Options : ${labels}`,
    loadError: 'Erreur de chargement',
    retry: 'Réessayer',
    backToParc: '← Le Parc',
    vehicleNotFound: 'Véhicule introuvable — ',
    backToParcInline: 'retour au parc',
    loadingVehicle: 'Chargement du véhicule…',
    loadingParc: 'Chargement du parc…',
    cancelTurn: 'Interrompre le tour',
    signedIn: 'connecté',
    dossierTitle: 'Dossier du véhicule',
    dossierAria: 'Ouvrir le dossier du véhicule',
    dossier: 'dossier',
    journalTitle: "Journal de l'agent",
    journalAria: "Ouvrir le journal de l'agent",
    journal: 'journal',
    signOut: 'Déconnexion',
  },

  /** The wayfinder chips: a label, the two narration beats it plays, and what it says when it fails. */
  chip: {
    data: 'Données',
    dataSteps: ['ouverture du dossier…', 'lecture des données véhicule…'] as [string, string],
    dataFail: 'données indisponibles',
    // The closing sentence (CHOREOGRAPHY § "What a finished action says"): names the vehicle when it
    // has a usable name, else the generic form (closingLine.ts picks between the two).
    dataResult: (name: string) => `Voici les données collectées sur la ${name}. Cherchez-vous quelque chose en particulier ?`,
    dataResultGeneric: 'Voici les données collectées sur ce véhicule. Cherchez-vous quelque chose en particulier ?',
    history: 'Historique',
    historySteps: ['ouverture du carnet…', 'relecture des visites…'] as [string, string],
    historyFail: 'carnet indisponible',
    historyResult: 'Voici l’historique des visites.',
    fluids: 'Capacités fluides',
    fluidsSteps: ['consultation des capacités…', 'lecture des specs fluides…'] as [string, string],
    fluidsFail: 'le catalogue ne répond pas',
    fluidsEmpty: 'aucune capacité fluide connue pour ce véhicule',
    fluidsResult: 'Voici les capacités de fluides.',
    parts: 'Pièces à commander',
    partsSteps: ['relecture des tâches ouvertes…', 'rassemblement des références…'] as [string, string],
    partsFail: 'pièces indisponibles',
    partsResult: 'Voici les pièces à commander.',
    spec: 'Fiche technique',
    specSteps: ['lecture de la carte grise…', 'préparation de la fiche technique…'] as [string, string],
    specFail: "l'immatriculation ne répond pas",
    specEmpty: 'aucune donnée SIV pour cette plaque',
    specResult: 'Voici la fiche technique.',
    carnet: 'Carnet · dépenses',
    carnetSteps: ['lecture du carnet…', 'calcul des dépenses…'] as [string, string],
    carnetFail: 'carnet indisponible',
    carnetResult: 'Voici le récapitulatif des dépenses.',
    // The agent chip (CHOREOGRAPHY § "Asking the agent from the UI") — the label is UI copy, the
    // instruction is the prompt actually posted to the agent (postTurn), not shown on screen.
    summariseSpending: 'Résumer les dépenses',
    summariseSpendingInstruction:
      "résume les dépenses d'entretien de ce véhicule : les postes principaux, le total, et le prochain gros poste à prévoir",
  },

  dossier: {
    panel: 'Dossier du véhicule',
    title: 'Dossier',
    close: 'Fermer',
    saveFailed: (msg: string) => `Échec de l’enregistrement : ${msg}`,
    updateFailed: (msg: string) => `Échec de la mise à jour : ${msg}`,
    deleteFailed: (msg: string) => `Échec de la suppression : ${msg}`,
    billOpenFailed: (msg: string) => `Échec de l’ouverture de la facture : ${msg}`,
    billAddFailed: (msg: string) => `Échec de l’ajout de la facture : ${msg}`,
    scheduleLoadFailed: (msg: string) => `Échec du chargement du programme : ${msg}`,
    pastServices: 'Entretiens passés',
    cancel: 'Annuler',
    addService: '+ Entretien',
    noServices: 'Aucun entretien enregistré.',
    confirmDeleteRecord: (title: string, date: string) => `Supprimer l’entretien « ${title} » du ${date} ?`,
    editService: 'Modifier l’entretien',
    edit: 'Modifier',
    deleteService: 'Supprimer l’entretien',
    delete: 'Supprimer',
    confirmDeleteBill: (filename: string) => `Supprimer la facture « ${filename} » ?`,
    removeBill: (filename: string) => `Supprimer la facture ${filename}`,
    addBill: '+ facture',
    visitLabelPlaceholder: 'Libellé de la visite (ex. Révision annuelle)',
    visitLabelAria: 'Libellé de la visite',
    dateAria: 'Date de l’entretien',
    readingPlaceholder: 'relevé',
    readingAria: 'Relevé',
    unitAria: 'Unité du relevé',
    costPlaceholder: 'coût €',
    costAria: 'Coût en euros',
    performerPlaceholder: 'effectué par',
    performerAria: 'Effectué par',
    notesPlaceholder: 'notes',
    notesAria: 'Notes',
    typePlaceholder: 'type',
    typeAria: 'Type de service',
    serviceLabelPlaceholder: 'libellé (optionnel)',
    serviceLabelAria: 'Libellé du service',
    refPlaceholder: 'réf.',
    refAria: 'Référence',
    brandPlaceholder: 'marque',
    brandAria: 'Marque',
    partLabelPlaceholder: 'libellé',
    partLabelAria: 'Libellé de la pièce',
    qtyPlaceholder: 'qté',
    qtyAria: 'Quantité',
    unitPricePlaceholder: '€',
    unitPriceAria: 'Prix unitaire en euros',
    removeService: 'Retirer ce service',
    deleteShort: 'Supprimer',
    removeShort: 'Retirer',
    actionAria: 'Action',
    removePart: 'Retirer cette pièce',
    remove: 'Retirer',
    addPart: '+ pièce',
    save: 'Enregistrer',
    add: 'Ajouter',
    schedule: 'Programme d’entretien',
    addRule: '+ Règle',
    loading: 'Chargement…',
    noRules: 'Aucune règle d’entretien. Ajoutez-en une pour piloter les prévisions.',
    editRule: (title: string) => `Modifier ${title}`,
    removeRule: (title: string) => `Retirer ${title}`,
    verifiedSource: 'Source vérifiée (catalogue)',
    source: (name: string) => `Source : ${name}`,
    componentPlaceholder: 'composant (optionnel)',
    componentAria: 'Composant',
    everyUsagePlaceholder: 'tous les …',
    everyUsageAria: 'Cadence kilométrage',
    cadenceUnitAria: 'Unité de cadence',
    everyMonthsPlaceholder: 'tous les … mois',
    everyMonthsAria: 'Cadence mois',
    firstAtPlaceholder: '1ʳᵉ fois à …',
    firstAtAria: 'Premier intervalle',
    needCadence: 'Indiquez au moins une cadence',
    fix: 'Corriger',
  },

  noyau: {
    /**
     * Last-resort fallback only (CHOREOGRAPHY § "What a finished action says", removed 2026-09-04): a
     * turn that ends having narrated nothing at all. Every documented case supplies its own sentence
     * instead — a deterministic wayfinder's closing line, or a real agent turn's own `toast`.
     */
    pendingDecisions: (n: number) => `${n} décision(s) en attente — ouvrir « à décider »`,
    /** "Le Noyau" is the core's NAME — it stays French in both catalogs, like `proj.placedByNoyau`. */
    orbAria: (phase: string, detail: string) => `Le Noyau — ${phase}${detail}`,
    pilot: 'pilote',
    pilotWaiting: 'en attente',
    signal: { green: 'vert', amber: 'ambre', red: 'rouge' } as Record<string, string>,
  },

  /** The opening ticker lines (CHOREOGRAPHY § "The opening — the workshop speaks first"). */
  greeting: {
    none: 'Sur quelle voiture travaille-t-on aujourd’hui ?',
    vehicle: (name: string) => `${name} sélectionnée. Que souhaitez-vous savoir ?`,
  },

  /** The retained toast cards — the chrome around an answer that stays until the operator says "vu". */
  notice: {
    tone: { info: 'note', ok: 'fait', warn: 'attention', alarm: 'alerte' } as Record<string, string>,
    seen: 'vu',
    dismiss: (text: string) => `Fermer : ${text}`,
    /** The provenance stamp: this card is a sentence written THEN; the Data panel is the reading NOW. */
    stamp: (age: string) => `${age} · voir Données`,
  },

  /** The proposal card raised from Le Noyau's cradle. */
  cta: {
    later: 'Plus tard',
    dismiss: 'Fermer la proposition',
  },

  misc: {
    agentWritten: "écrit par l'agent",
    addToTask: 'Ajouter à la tâche',
    vehicleSilhouette: 'Silhouette du véhicule',
    noData: 'Aucune donnée',
    closeSchedule: 'Fermer l’échéancier',
    noServiceLogged: 'Aucun entretien enregistré.',
  },

  /** The exploded-diagram part names. Locale-neutral geometry, localised captions. */
  assembly: {
    engineBlock: 'Bloc moteur',
    oilFilter: 'Filtre à huile',
    oilCircuit: 'Circuit d’huile',
    sparkPlugs: 'Bougies',
    coolantCircuit: 'Circuit de refroidissement',
    airFilterHousing: 'Boîtier de filtre à air',
    fuelFilter: 'Filtre à carburant',
    fuelLine: 'Ligne carburant',
    battery: 'Batterie',
    cabinFilter: 'Filtre d’habitacle',
    cockpit: 'Poste de conduite',
    wiperLinkage: 'Tringlerie d’essuie-glace',
    brakeDisc: 'Disque de frein',
    brakePads: 'Plaquettes',
    brakeHose: 'Flexible de frein',
    tyre: 'Pneumatique',
    rearDisc: 'Disque arrière',
    rearPads: 'Plaquettes arrière',
  },

  journal: {
    title: "Journal de l'agent",
    refresh: 'Rafraîchir',
    close: 'Fermer',
    loadFailed: 'Échec du chargement :',
    loading: 'Chargement…',
    empty: 'Aucun tour enregistré.',
    loadingDetail: 'Chargement du détail…',
    model: 'Modèle',
    vehicle: 'Véhicule',
    error: 'Erreur',
    none: 'Aucun',
    llmContext: 'Contexte LLM',
    noCalls: 'Aucune requête capturée (stream).',
    finalAnswer: 'Réponse finale',
    rawData: (n: number) => `Données brutes (${n})`,
  },

  inbox: {
    title: 'À décider',
    kindNotify: 'à voir',
    kindQuestion: 'question',
    kindReview: 'à revoir',
    justNow: "à l'instant",
    secondsAgo: (s: number) => `il y a ${s}s`,
    minutesAgo: (m: number) => `il y a ${m} min`,
    hoursAgo: (h: number) => `il y a ${h} h`,
    empty: 'Rien à décider.',
    muteVoice: 'Couper la voix',
    unmuteVoice: 'Lire les réponses à voix haute',
    voiceOn: 'voix',
    voiceOff: 'muet',
    plan: 'planifier ○',
    seen: 'vu',
  },

  credits: {
    title: 'Crédits des modèles 3D',
  /** The voice-select control beside the audition toggle (Preferences.tsx `VoiceSelect`). */
  pref: {
    voice: 'Voix',
    voiceDefault: 'voix par défaut',
  },

    short: 'crédits',
    heading: 'Modèles 3D',
    close: 'Fermer les crédits',
  },

  /** The SHORT verb on a running tool-step chip — quieter than the narration line it echoes. */
  chipVerb: {
    motulOil: 'huile Motul…',
    parts: 'recherche pièces…',
    plate: 'plaque…',
    planning: 'planification…',
    forecast: 'prévision…',
    carnet: 'lecture carnet…',
    webSearch: 'recherche web…',
    updating: 'mise à jour…',
    recording: 'enregistrement…',
    garage: 'garage…',
  },

  truth: {
    panel: 'Fiche véhicule',
    plate: '+ plaque',
    editPlate: "Modifier la plaque d'immatriculation",
    make: 'Marque',
    model: 'Modèle',
    editMake: 'Modifier la marque',
    editModel: 'Modifier le modèle',
    year: '+ année',
    trim: '+ finition',
    engine: '+ moteur',
    kType: '+ kType',
    editYear: "Modifier l'année",
    editTrim: 'Modifier la finition',
    editEngine: 'Modifier le code moteur',
    editKType: 'Modifier le kType TecDoc',
    usage: 'Kilométrage',
    state: 'État',
    overdueCount: (n: string) => `${n} en retard`,
    soonCount: (n: string) => `${n} bientôt`,
    lastService: 'Dernière intervention',
    perSystem: 'Échéances par système',
    planService: (service: string) => `Planifier ${service} →`,
    confirmDelete: (name: string) => `Retirer « ${name} » du parc ?`,
    thisVehicle: 'ce véhicule',
    delete: 'Retirer du parc',
  },

  facts: {
    title: 'Spécifications',
    empty: 'Aucune spécification enregistrée.',
    verifiedSource: 'Source vérifiée (catalogue)',
    source: (name: string) => `Source : ${name}`,
    edit: (label: string) => `Modifier ${label}`,
    remove: (label: string) => `Retirer ${label}`,
    removeShort: 'Retirer',
    keyPlaceholder: 'clé (ex. oil_type)',
    keyLabel: 'Clé de la spécification',
    valuePlaceholder: 'valeur',
    valueLabel: 'Valeur de la spécification',
    unitPlaceholder: 'unité',
    unitLabel: 'Unité (optionnelle)',
    add: 'Ajouter',
    unplaced: 'sans emplacement',
    region: {
      hood: 'Capot',
      windshield: 'Pare-brise',
      bay: 'Baie moteur',
      frontAxle: 'Train avant',
      rearAxle: 'Train arrière',
    },
  },

  usage: {
    placeholder: '+ relevé',
    edit: 'Modifier le kilométrage',
    unitLabel: 'Unité du relevé',
  },

  plan: {
    title: "Plan d'entretien",
    empty: 'Aucune échéance prévue.',
    /** Prefix for the coverage-gap line; the service list follows. */
    untracked: 'Non suivi :',
  },

  record: {
    /** A visit covering several services has no single service to name it by. */
    genericVisit: 'Entretien',
  },

  fluid: {
    /** Fallback name for a fill volume the catalogue gave no usable title. */
    fallbackLabel: 'Fluide',
  },

  /** The French carte grise (SIV) — a French administrative document, read in either language. */
  siv: {
    energy: {
      essence: 'Essence',
      diesel: 'Diesel',
      electric: 'Électrique',
      hybrid: 'Hybride',
      pluginHybrid: 'Hybride rechargeable',
      lpg: 'GPL',
      cng: 'GNV',
      e85: 'Superéthanol',
    },
    gearbox: {
      manual: 'Manuelle',
      automatic: 'Automatique',
    },
    field: {
      energy: 'Énergie',
      power: 'Puissance',
      displacement: 'Cylindrée',
      cylinders: 'Cylindres',
      gearbox: 'Boîte',
      seats: 'Places',
      weight: 'Poids',
      firstRegistered: 'Mise en circulation',
      taxHorsepower: 'Puissance fiscale',
    },
    unit: {
      seats: 'places',
      /** Administrative French horsepower — a tax band on the carte grise, not a power reading. */
      taxHorsepower: 'CV',
    },
  },
};
