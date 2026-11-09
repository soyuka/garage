<?php

declare(strict_types=1);

namespace App\Capability;

/**
 * An optional, paid provider-backed feature. Configured or not is decided by {@see Capabilities}; an
 * unconfigured one is removed (tool not advertised, endpoint answering 503 without calling the
 * vendor, dashboard control hidden), never left to fail on every request.
 */
enum Capability: string
{
    /** The in-app garage agent (Anthropic). */
    case Agent = 'agent';
    /** The Atelier's spoken notices (ElevenLabs TTS). */
    case Voice = 'voice';
    /** The dock's dictation (ElevenLabs Scribe STT). */
    case Dictation = 'dictation';
    /** The agent's `web_search` tool (the WEB_SEARCH_BACKEND provider). */
    case WebSearch = 'web_search';
}
