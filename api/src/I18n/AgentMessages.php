<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Everything the agent says to the USER, in both languages.
 *
 * A hand-written catalog rather than symfony/translation, deliberately: this repo's composer setup
 * resolves two local path repositories under ~/forks that are not visible inside the php container,
 * so `composer require` cannot run here at all (verified 2026-08-17 — it aborts on
 * `PathRepository … does not exist` and reverts). The surface is a dozen sentences with one consumer
 * each; a typed catalog costs less than a dependency this repo cannot currently install, and it
 * mirrors `dashboard/src/i18n/` so both halves of the app read the same way.
 *
 * NOT in here: tool descriptions, tool-result `hint` strings and the system prompt. Those are
 * MODEL-facing, they are English already, and the model reading English while answering the user in
 * French is exactly what it does today.
 *
 * No per-tool step sentences: since 2026-11-09 the ticker carries only what the model chose to say,
 * and a tool step reaches the dashboard as its key alone ({@see \App\Agent\Ui\StepNarrator}).
 */
final readonly class AgentMessages
{
    private function __construct(
        public string $noOp,
        public string $notConverged,
        public string $cancelled,
    ) {
    }

    public static function for(Locale $locale): self
    {
        return match ($locale) {
            Locale::Fr => self::french(),
            Locale::En => self::english(),
        };
    }

    private static function french(): self
    {
        return new self(
            noOp: "Je n'ai pas pu traiter ta demande. Reformule ou précise ce que tu veux enregistrer.",
            notConverged: "Je n'ai pas pu terminer cette demande. Reformule ou précise ta question.",
            cancelled: 'Demande interrompue.',
        );
    }

    private static function english(): self
    {
        return new self(
            noOp: 'I could not handle that. Rephrase it, or say more precisely what you want recorded.',
            notConverged: 'I could not finish that. Rephrase it, or narrow the question.',
            cancelled: 'Request stopped.',
        );
    }
}
