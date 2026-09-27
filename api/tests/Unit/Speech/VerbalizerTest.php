<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\I18n\Locale;
use App\Speech\Verbalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The voice reads the printed string verbatim, symbols and all — « 250 347 km » comes out as two
 * numbers and « · » chains as one run-on (operator decision 2026-09-05, docs/voice.md). This is the
 * deterministic safety net: {@see Verbalizer} rewrites what a voice needs, never what a reader sees.
 */
final class VerbalizerTest extends TestCase
{
    #[DataProvider('french')]
    public function testFrench(string $text, string $expected): void
    {
        $this->assertSame($expected, (new Verbalizer())->verbalize($text, Locale::Fr));
    }

    /** @return iterable<string, array{string, string}> */
    public static function french(): iterable
    {
        yield 'date, grouped km, currency, brackets, dash' => [
            "Dernière vidange : 6 juillet 2026 à 250 347 km (MANNOL Energy 5W-30 + filtres, 53,20 €). Dépenses 2026 enregistrées : 53,20 € — les autres visites n'ont pas de montant saisi.",
            "Dernière vidange : six juillet deux mille vingt-six à deux cent cinquante mille trois cent quarante-sept kilomètres, MANNOL Energy 5W-30 plus filtres, cinquante-trois euros vingt. Dépenses deux mille vingt-six enregistrées : cinquante-trois euros vingt, les autres visites n'ont pas de montant saisi.",
        ];

        yield 'middle-dot chain, plus, brackets, dash before a digit' => [
            "Dépenses TORO 246-H — 1 visite enregistrée · Total dépensé : 280,49 € · Postes principaux : pneus + chambres (commande pneus), vidange complète (huile + filtres), robinetterie essence, batterie · Prochain gros poste : montage des pneus avant (tâche ouverte)",
            'Dépenses TORO 246-H, 1 visite enregistrée. Total dépensé : deux cent quatre-vingts euros quarante-neuf. Postes principaux : pneus plus chambres, commande pneus, vidange complète, huile plus filtres, robinetterie essence, batterie. Prochain gros poste : montage des pneus avant, tâche ouverte',
        ];

        yield 'inches, trailing bracket with no following punctuation' => [
            '1 visite · 280,49 € TTC (pièces, 5 factures) · Retour pneus 15" annulé (0 €)',
            '1 visite. deux cent quatre-vingts euros quarante-neuf TTC, pièces, cinq factures. Retour pneus quinze pouces annulé, zéro euro',
        ];

        yield 'litres, dash before a lowercase word, brackets' => [
            'Huile moteur : 4,3 L — viscosité 5W-30 (ACEA A3/B3 ou A5/B5)',
            'Huile moteur : quatre virgule trois litres, viscosité 5W-30, ACEA A3/B3 ou A5/B5',
        ];

        yield 'plain text is untouched' => [
            'Je consulte le catalogue',
            'Je consulte le catalogue',
        ];

        /**
         * The trailing « … » reaches the voice VERBATIM, and that is the decision — see the long note in
         * {@see \App\Speech\Verbalizer::rewriteSeparators()}. It is a prosody cue the model obeys with
         * ~0.3–0.5 s of trail-off, which is the bridge into the next line now that the seam is gapless
         * (CHOREOGRAPHY § "The seam between two lines is gapless"). It was briefly rewritten to a full
         * stop on 2026-09-11, while the seam was still a hole; this golden pins the restoration, so that
         * a future reader finding the measurement in docs/voice.md does not "fix" it back without also
         * reading why it came back.
         */
        yield 'a trailing ellipsis is left for the model to trail off on' => [
            'Je consulte le catalogue…',
            'Je consulte le catalogue…',
        ];

        /** The ASCII spelling is NOT the same thing — it collapses to a full stop, as it always has. */
        yield 'an ASCII ellipsis still collapses to a single full stop' => [
            'Ouverture du dossier... lecture des données',
            'Ouverture du dossier. lecture des données',
        ];

        yield 'a plus with no surrounding spaces stays inside the token' => [
            'Filtre HERTH+BUSS J1313021',
            'Filtre HERTH+BUSS J1313021',
        ];

        /** Feminine units need « une », not the masculine "un" `NumberFormatter` spells by default. */
        yield 'one hour and one minute take the feminine article' => [
            "Contrôle : 1 h d'immobilisation, 1 min de diagnostic",
            "Contrôle : une heure d'immobilisation, une minute de diagnostic",
        ];

        /** Zero and one euro stay grammatically singular; two and up take the plural. */
        yield 'currency edge values: zero and one euro' => [
            'Reste 1 € sur la carte, 0 € en espèces, 2 € en jetons',
            'Reste un euro sur la carte, zéro euro en espèces, deux euros en jetons',
        ];

        yield 'grades dimensions and unhyphenated identifiers are never mistaken for part references' => [
            "Huile 5W-30, ampoule 246-H, filtre J1313021, référence XB-301-ZB, pneu 16x650-8, indice A3/B3, lot 01M1SM29",
            "Huile 5W-30, ampoule 246-H, filtre J1313021, référence iks bé, trois zéro un, zède bé, pneu 16x650-8, indice A3/B3, lot 01M1SM29",
        ];

        yield 'hyphenated part references become distinct French letter and digit groups' => [
            'Références B593-18-840A et B3C8-18-840.',
            'Références bé cinq neuf trois, dix-huit, huit quatre zéro a et bé trois cé huit, dix-huit, huit quatre zéro.',
        ];

        /**
         * Only the numeral one carries gender in French ("un"/"une") — every other number is
         * invariant, so a bare 1 next to a non-unit noun is left as the digit rather than spelled with
         * the wrong gender ("un visite"). The voice already reads a lone "1" correctly against its
         * noun.
         */
        yield 'a bare one before a non-unit noun stays a digit, the rest still spells' => [
            '1 visite · 280,49 € TTC',
            '1 visite. deux cent quatre-vingts euros quarante-neuf TTC',
        ];

        yield 'a bare one alone stays a digit' => [
            '1 visite enregistrée',
            '1 visite enregistrée',
        ];

        /** Rule 3 already turned this "1" into the gendered word before rule 7 ever sees it. */
        yield 'a unit with a known gender still gets the spelled-out feminine article' => [
            '1 h',
            'une heure',
        ];

        /** A decimal never faces the gender question — the value read aloud is "un virgule cinq", not the bare digit. */
        yield 'a decimal is still fully spelled out, litres included' => [
            '1,5 L',
            'un virgule cinq litres',
        ];
    }

    #[DataProvider('english')]
    public function testEnglish(string $text, string $expected): void
    {
        $this->assertSame($expected, (new Verbalizer())->verbalize($text, Locale::En));
    }

    /** @return iterable<string, array{string, string}> */
    public static function english(): iterable
    {
        yield 'euro sign written BEFORE the amount' => [
            'Last oil change: 6 July 2026 at 250 347 km (MANNOL Energy 5W-30 + filters, €53.20).',
            'Last oil change: six July two thousand twenty-six at two hundred fifty thousand three hundred forty-seven kilometres, MANNOL Energy 5W-30 plus filters, fifty-three euros twenty.',
        ];

        yield 'hyphenated part references become distinct English letter and digit groups' => [
            'References B593-18-840A and B3C8-18-840.',
            'References bee five nine three, eighteen, eight four zero ay and bee three cee eight, eighteen, eight four zero.',
        ];

        /**
         * English inflects only for 1 ("zero euros", "one euro", "two euros") — unlike French, where
         * 0 stays singular too. Deliberately different rule, not a mirror of the French test above.
         */
        yield 'currency edge values: only one is singular in English' => [
            'Balance: €1 on the card, €0 in cash, €280 in tokens',
            'Balance: one euro on the card, zero euros in cash, two hundred eighty euros in tokens',
        ];

        /** English has no gender ambiguity, but rule 7 keeps the same bare-one exception for symmetry. */
        yield 'a bare one before a non-unit noun stays a digit' => [
            '1 visit',
            '1 visit',
        ];
    }
}
