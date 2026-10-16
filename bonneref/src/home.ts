// Public home copy: `.impeccable/review/homepage-copy.md` (direction 2, particuliers first).
export const HOME = {
  headline: 'La réf. d’origine et ses équivalences.',
  lede: 'Saisissez la plaque ou le modèle, puis la pièce. La pièce s’affiche avec le numéro du constructeur, ses dimensions et les autres marques qui la fabriquent.',
  ledeSignedOut: 'Connectez-vous pour chercher une pièce. Voici ce que vous obtenez\u00a0: le numéro du constructeur, les dimensions et les autres marques qui la fabriquent.',
  placeholder: 'plaquettes de frein clio 3…',
} as const;

export interface Starter {
  kind: 'text' | 'photo';
  label: string;
}

export const STARTERS: readonly Starter[] = [
  { kind: 'text', label: 'filtre à huile mazda 6 2.0 2005' },
  { kind: 'text', label: 'rotule axiale peugeot 307' },
  { kind: 'photo', label: 'Photo de la carte grise' },
];
