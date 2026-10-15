import type { FinderFrame } from './types';

// `?demo` only: an ILLUSTRATIVE agent turn (synthetic frames, not a recording) for the PSA build-code
// case, so the question-above-the-composer and the generic placeholder can be seen without a paid
// turn. Its references are labelled DÉMO on purpose: nothing here is catalogue data.
export const isDemoTurn = (text: string): boolean => /rotule/i.test(text);

const cluster = (id: string, number: string, applicability: { name: string; values: string[] }) => ({
  id,
  fingerprint: {},
  oeNumbers: [],
  representative: { artId: id === 'k1' ? 1 : 2, brand: 'DÉMO', number },
  variants: [{ artId: id === 'k1' ? 1 : 2, brand: 'DÉMO', number, tier: 1 }],
  articleCount: 1,
  applicability: [applicability],
});

export function demoTurn(): { frame: FinderFrame; at: number }[] {
  return [
    { at: 200, frame: { type: 'turn', phase: 'start' } },
    { at: 500, frame: { type: 'status', message: 'Identification du véhicule…' } },
    { at: 1100, frame: { type: 'setVehicle', carId: 16182, carType: 'PC', make: 'PEUGEOT', model: '307', engine: '1.4 (75 ch)', plate: null } },
    { at: 1500, frame: { type: 'status', message: 'Affinage des rotules axiales…' } },
    {
      at: 2300,
      frame: {
        type: 'showParts',
        categoryId: 100198,
        categoryLabel: 'Rotule axiale',
        rationale: 'Scénario de démonstration : deux pièces, séparées par une rupture de fabrication.',
        narrowed: {
          carId: 16182,
          categoryId: 100198,
          clusters: [
            cluster('k1', 'DÉMO-A', { name: 'N° d’organisation jusqu’à', values: ['DÉMO 1'] }),
            cluster('k2', 'DÉMO-B', { name: 'N° d’organisation à partir de', values: ['DÉMO 2'] }),
          ],
          totalArticles: 2,
          totalBrands: 1,
          unclassifiedCount: 0,
          differsOn: [],
          narrowed: true,
          degradedReason: null,
          upstreamCalls: 0,
          fallbackBrands: [],
        },
      },
    },
    {
      at: 2700,
      frame: {
        type: 'askQuestion',
        question: 'Quel est le code de fabrication de la voiture ? C’est le numéro d’organisation imprimé sur la vignette du montant de porte, souvent après « DAM ».',
        options: [{ label: 'Je ne le trouve pas', value: 'je ne trouve pas le code de fabrication' }],
        context: 'rotule axiale 307 : deux pièces selon le n° d’organisation ; code de fabrication ?',
      },
    },
    { at: 2900, frame: { type: 'turn', phase: 'end' } },
  ];
}
