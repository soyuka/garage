<?php

declare(strict_types=1);

namespace App\Speech;

use App\I18n\Locale;

/**
 * Rewrites a printed line for the VOICE only (operator decision 2026-09-05, docs/voice.md). The
 * dashboard never sees this output — {@see \App\Controller\SpeechController} calls it right before
 * handing the text to {@see SpeechClient}, so the toast card on screen stays byte-identical to what
 * the agent wrote.
 *
 * A pronunciation dictionary, not a paraphraser: it never adds content or reorders words, only
 * rewrites symbols, units, digit grouping and separators into something a voice reads as one clean
 * sentence instead of a run-on of disconnected tokens. The trigger was the spending summary reading
 * « · » chains as one breathless run-on and « 250 347 » as two separate numbers.
 *
 * Rule order is fixed and each rule depends on the text the previous one produced: digit grouping
 * must run before currency/units so a grouped number reads as one figure; brackets must run before
 * the final number spell-out because dashes and the middle dot can themselves leave stray spacing
 * that only the bracket clean-up pass (which runs over the WHOLE string, not just former bracket
 * positions) mops up; number spell-out (rule 7, added 2026-09-06) runs LAST of all, because every
 * earlier rule can leave a bare digit run behind (a unit's numeral, a date) that only reads well
 * once the punctuation around it has settled.
 */
final readonly class Verbalizer
{
    /** Plain space, NBSP, narrow NBSP, thin space — the separators a French number uses between groups. */
    private const string GROUP_SEPARATOR = '[ \x{00A0}\x{202F}\x{2009}]';

    /** A hyphenated SKU starts with a letter; this deliberately excludes grades (5W-30), dimensions and ratios. */
    private const string PART_REFERENCE = '/(?<![\p{L}\p{N}-])(?=[A-Z0-9-]*[A-Z])(?=[A-Z0-9-]*\d)[A-Z][A-Z0-9]*(?:-[A-Z0-9]+)+(?![\p{L}\p{N}-])/u';

    /** @var array<value-of<Locale>, array<string, string>> */
    private const array SPOKEN_LETTERS = [
        'fr' => [
            'A' => 'a', 'B' => 'bé', 'C' => 'cé', 'D' => 'dé', 'E' => 'e', 'F' => 'èf', 'G' => 'gé',
            'H' => 'ache', 'I' => 'i', 'J' => 'ji', 'K' => 'ka', 'L' => 'èl', 'M' => 'èm', 'N' => 'èn',
            'O' => 'o', 'P' => 'pé', 'Q' => 'ku', 'R' => 'èr', 'S' => 'ès', 'T' => 'té', 'U' => 'u',
            'V' => 'vé', 'W' => 'double vé', 'X' => 'iks', 'Y' => 'i grec', 'Z' => 'zède',
        ],
        'en' => [
            'A' => 'ay', 'B' => 'bee', 'C' => 'cee', 'D' => 'dee', 'E' => 'ee', 'F' => 'eff', 'G' => 'gee',
            'H' => 'aitch', 'I' => 'eye', 'J' => 'jay', 'K' => 'kay', 'L' => 'ell', 'M' => 'em', 'N' => 'en',
            'O' => 'oh', 'P' => 'pee', 'Q' => 'cue', 'R' => 'are', 'S' => 'ess', 'T' => 'tee', 'U' => 'you',
            'V' => 'vee', 'W' => 'double you', 'X' => 'ex', 'Y' => 'why', 'Z' => 'zee',
        ],
    ];

    /** @var array<value-of<Locale>, array<string, string>> */
    private const array SPOKEN_DIGITS = [
        'fr' => ['0' => 'zéro', '1' => 'un', '2' => 'deux', '3' => 'trois', '4' => 'quatre', '5' => 'cinq', '6' => 'six', '7' => 'sept', '8' => 'huit', '9' => 'neuf'],
        'en' => ['0' => 'zero', '1' => 'one', '2' => 'two', '3' => 'three', '4' => 'four', '5' => 'five', '6' => 'six', '7' => 'seven', '8' => 'eight', '9' => 'nine'],
    ];

    /**
     * fr units that are grammatically feminine — French agreement needs « une » rather than the
     * masculine default `NumberFormatter::SPELLOUT` gives "1" ("un"). English has no such split.
     *
     * @var list<string>
     */
    private const array FEMININE_UNITS_FR = ['min', 'h'];

    /**
     * @var array<value-of<Locale>, array<string, array{0: string, 1: string}>> unit token => [singular, plural],
     *                                                                          keyed by {@see Locale::value}
     */
    private const array UNITS = [
        'fr' => [
            'km' => ['kilomètre', 'kilomètres'],
            'mi' => ['mile', 'miles'],
            'min' => ['minute', 'minutes'],
            'ml' => ['millilitre', 'millilitres'],
            'mm' => ['millimètre', 'millimètres'],
            'cm' => ['centimètre', 'centimètres'],
            'kg' => ['kilo', 'kilos'],
            'kW' => ['kilowatt', 'kilowatts'],
            'Nm' => ['newton-mètre', 'newton-mètres'],
            'bar' => ['bar', 'bars'],
            'ch' => ['cheval', 'chevaux'],
            '°C' => ['degré', 'degrés'],
            'h' => ['heure', 'heures'],
            'L' => ['litre', 'litres'],
            'l' => ['litre', 'litres'],
            'm' => ['mètre', 'mètres'],
            'g' => ['gramme', 'grammes'],
            '%' => ['pour cent', 'pour cent'],
            '"' => ['pouce', 'pouces'],
        ],
        'en' => [
            'km' => ['kilometre', 'kilometres'],
            'mi' => ['mile', 'miles'],
            'min' => ['minute', 'minutes'],
            'ml' => ['millilitre', 'millilitres'],
            'mm' => ['millimetre', 'millimetres'],
            'cm' => ['centimetre', 'centimetres'],
            'kg' => ['kilo', 'kilos'],
            'kW' => ['kilowatt', 'kilowatts'],
            'Nm' => ['newton-metre', 'newton-metres'],
            'bar' => ['bar', 'bars'],
            'ch' => ['horsepower', 'horsepower'],
            '°C' => ['degree', 'degrees'],
            'h' => ['hour', 'hours'],
            'L' => ['litre', 'litres'],
            'l' => ['litre', 'litres'],
            'm' => ['metre', 'metres'],
            'g' => ['gram', 'grams'],
            '%' => ['percent', 'percent'],
            '"' => ['inch', 'inches'],
        ],
    ];

    public function verbalize(string $text, Locale $locale): string
    {
        $text = $this->groupDigits($text);
        $text = $this->spellCurrency($text, $locale);
        $text = $this->spellUnits($text, $locale);
        $text = $this->rewriteSeparators($text, $locale);
        $text = $this->rewriteBrackets($text);
        $text = $this->speakPartReferences($text, $locale);
        $text = $this->spellNumbers($text, $locale);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** « 250 347 » → « 250347 »; « 6 juillet 2026 » and « 5W-30 » have no run of exact-3 groups, so they pass through. */
    private function groupDigits(string $text): string
    {
        $pattern = '/\d{1,3}(?:'.self::GROUP_SEPARATOR.'\d{3})+/u';

        return preg_replace_callback(
            $pattern,
            static fn (array $m): string => preg_replace('/'.self::GROUP_SEPARATOR.'/u', '', $m[0]) ?? $m[0],
            $text,
        ) ?? $text;
    }

    /**
     * Both directions: fr writes the amount before « € », en writes it after. Spells the whole and
     * the cents SEPARATELY (« 53,20 € » → « cinquante-trois euros vingt », never « cinquante-trois
     * virgule deux euros » — `NumberFormatter` reading "53.20" as a bare float would drop the cents'
     * leading structure entirely) so rule 7 never has to see currency digits at all.
     */
    private function spellCurrency(string $text, Locale $locale): string
    {
        $formatter = new \NumberFormatter($locale->value, \NumberFormatter::SPELLOUT);
        $spell = function (array $m) use ($formatter, $locale): string {
            $whole = (int) $m[1];
            $spoken = $formatter->format($whole).' '.$this->euroWord($whole, $locale);

            if (isset($m[2]) && '' !== $m[2]) {
                $spoken .= ' '.$formatter->format((int) $m[2]);
            }

            return $spoken;
        };

        $text = preg_replace_callback('/(\d+)(?:[,.](\d+))?\s*€/u', $spell, $text) ?? $text;

        return preg_replace_callback('/€\s*(\d+)(?:[,.](\d+))?/u', $spell, $text) ?? $text;
    }

    /**
     * French agreement is singular for BOTH 0 and 1 (« zéro euro », « un euro »), plural from 2 up;
     * English only inflects for 1 ("zero euros", "one euro", "two euros") — a real difference between
     * the two catalogs, not an oversight.
     */
    private function euroWord(int $whole, Locale $locale): string
    {
        if (Locale::Fr === $locale) {
            return \in_array($whole, [0, 1], true) ? 'euro' : 'euros';
        }

        return 1 === $whole ? 'euro' : 'euros';
    }

    private function spellUnits(string $text, Locale $locale): string
    {
        $table = self::UNITS[$locale->value];

        // Longest token first: without this "min"/"mm" would never win against the bare "m"
        // alternative tried earlier in the same alternation.
        $tokens = array_keys($table);
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alternation = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tokens));

        $pattern = '/(\d+(?:[,.]\d+)?)'.self::GROUP_SEPARATOR.'?('.$alternation.')(?![\p{L}\p{N}])/u';

        return preg_replace_callback($pattern, function (array $m) use ($table, $locale): string {
            [$singular, $plural] = $table[$m[2]];

            if (!$this->isOne($m[1])) {
                return $m[1].' '.$plural;
            }

            // A feminine unit ("heure", "minute") needs « une », not the masculine "1" rule 7 would
            // otherwise spell — so the numeral is replaced with the gendered word right here, and
            // rule 7 never sees a "1" to get wrong.
            if (Locale::Fr === $locale && \in_array($m[2], self::FEMININE_UNITS_FR, true)) {
                return 'une '.$singular;
            }

            return $m[1].' '.$singular;
        }, $text) ?? $text;
    }

    private function isOne(string $value): bool
    {
        return 1.0 === (float) str_replace(',', '.', $value);
    }

    private function rewriteSeparators(string $text, Locale $locale): string
    {
        $text = str_replace('·', '.', $text);

        // « … » is deliberately LEFT for the model to interpret, and this is a decision with a history.
        //
        // It is a screen convention here — "this line is not the end" — carried by 28 French lines, all
        // of them steps, acknowledgements and loading notices; answers and greetings end in a full stop
        // or a question mark. The model reads it as a prosody cue and trails off: measured on the
        // shipped voice, « Je regarde ça… » runs 1.16 s with its last word ending at 0.68 s — 0.48 s of
        // breathy sustain — against 0.20 s for a line ending in a full stop. The words themselves are
        // identical either way (Scribe transcribes both the same, last word at the same timestamp), so
        // the ellipsis buys nothing but the tail.
        //
        // It was briefly rewritten to a full stop on 2026-09-11, when that tail was followed by a HOLE:
        // the next line only began once the previous had finished playing, so the operator heard the
        // sustain, then ~190 ms of nothing, then a cold start — « un euh entre les phrases ». The seam is
        // gapless since the same day (CHOREOGRAPHY § "The seam between two lines is gapless"), so the
        // sustain now runs straight into the following line, which is what a hesitation is for. Restored.
        //
        // What it still costs, stated plainly: a line carrying « … » that turns out to be LAST — a turn
        // ending on a step, or a successor whose body arrives late — trails into silence exactly as
        // before. That case is the residue of this decision, not an argument against it.

        // Only a dash WITH spaces on both sides is a clause break; one glued to its neighbours is a
        // hyphen-like compound (kept as-is — there is no such case in the goldens, but the rule says
        // "with spaces around it" for a reason).
        $text = preg_replace('/(?<=\s)[—–](?=\s*[\p{Ll}\d])/u', ',', $text) ?? $text;
        $text = preg_replace('/(?<=\s)[—–](?=\s*\p{Lu})/u', '.', $text) ?? $text;

        $text = preg_replace('/\s+\+\s+/u', ' plus ', $text) ?? $text;

        $and = Locale::Fr === $locale ? 'et' : 'and';
        $text = preg_replace('/(?<=\s)&(?=\s)/u', $and, $text) ?? $text;

        return $text;
    }

    /**
     * Runs over the WHOLE string, not just the positions that used to hold a bracket: a bracket
     * never had a space on its inner side (« (MANNOL »), so the comma it leaves behind is glued to
     * the next word, and the dash/middle-dot swaps above can leave a stray space on the wrong side
     * too. This is the one clean-up pass that fixes all of it at once.
     */
    private function rewriteBrackets(string $text): string
    {
        $text = str_replace(['(', ')'], ',', $text);

        $text = preg_replace('/,\s*\./u', '.', $text) ?? $text;
        $text = preg_replace('/\.\s*,/u', '.', $text) ?? $text;
        $text = preg_replace('/\s+([,.])/u', '$1', $text) ?? $text;
        $text = preg_replace('/,(?:\s*,)+/u', ',', $text) ?? $text;
        $text = preg_replace('/\.{2,}/u', '.', $text) ?? $text;

        // A decimal separator (« 53,20 ») is digit-punct-digit and must stay glued; any other comma
        // or period glued to the next word gets its space put back.
        $text = preg_replace_callback('/(.?)([,.])([^\s,.])/u', static function (array $m): string {
            if ('' !== $m[1] && ctype_digit($m[1]) && ctype_digit($m[3])) {
                return $m[0];
            }

            return $m[1].$m[2].' '.$m[3];
        }, $text) ?? $text;

        $text = preg_replace('/^\s*,\s*/u', '', $text) ?? $text;

        // Not in the written design, but symmetrical with "never leave a leading comma": a bracket
        // at the very end of the string (no closing punctuation follows it) would otherwise leave a
        // dangling trailing comma.
        return preg_replace('/\s*,\s*$/u', '', $text) ?? $text;
    }

    private function speakPartReferences(string $text, Locale $locale): string
    {
        return preg_replace_callback(self::PART_REFERENCE, function (array $match) use ($locale): string {
            return implode(', ', array_map(
                fn (string $group): string => $this->speakPartReferenceGroup($group, $locale),
                explode('-', $match[0]),
            ));
        }, $text) ?? $text;
    }

    private function speakPartReferenceGroup(string $group, Locale $locale): string
    {
        if (ctype_digit($group) && strlen($group) <= 2 && !str_starts_with($group, '0')) {
            return (new \NumberFormatter($locale->value, \NumberFormatter::SPELLOUT))->format((int) $group);
        }

        $letters = self::SPOKEN_LETTERS[$locale->value];
        $digits = self::SPOKEN_DIGITS[$locale->value];

        return implode(' ', array_map(static fn (string $character): string => $letters[$character] ?? $digits[$character], str_split($group)));
    }

    /**
     * Rule 7 (2026-09-05): spell out standalone numbers — « 250347 kilomètres » reads as one clean
     * figure only once the digits themselves are words, and a bare year or day number reads no
     * better than a grouped one. "Standalone" means a digit run (with at most one decimal separator)
     * touching neither a letter, a digit, a hyphen, an apostrophe nor a slash on either side — which
     * is exactly what keeps a reference such as « 5W-30 », « XB-301-ZB » or « J1313021 » untouched:
     * the voice spells an identifier letter by letter, which is the right read for a reference.
     *
     * Currency was already fully spelled out by {@see spellCurrency()} and never reaches here; a
     * unit's leftover numeral ("250347 kilomètres", "4,3 litres") does reach here, which is by
     * design — the unit word is a letter run, but it sits across a space, not touching the digit.
     *
     * A bare integer 1 is the one exception: only French inflects it for gender ("un"/"une"), so
     * spelling it here — blind to the noun that follows — is as likely to be wrong ("un visite") as
     * right; it is left as the digit "1" instead (the voice already reads a lone digit correctly).
     */
    private function spellNumbers(string $text, Locale $locale): string
    {
        $decimalSeparator = Locale::Fr === $locale ? ',' : '.';
        $formatter = new \NumberFormatter($locale->value, \NumberFormatter::SPELLOUT);
        $boundary = '[\p{L}\d\-\'\x{2019}\/]';
        $pattern = '/(?<!'.$boundary.')\d+(?:'.preg_quote($decimalSeparator, '/').'\d+)?(?!'.$boundary.')/u';

        return preg_replace_callback($pattern, static function (array $m) use ($formatter, $decimalSeparator): string {
            $isInteger = !str_contains($m[0], $decimalSeparator);

            // Bare-one exception, see class docblock above this method.
            if ($isInteger && 1 === (int) $m[0]) {
                return $m[0];
            }

            $normalized = str_replace($decimalSeparator, '.', $m[0]);
            $number = $isInteger ? (int) $normalized : (float) $normalized;

            return $formatter->format($number);
        }, $text) ?? $text;
    }
}
