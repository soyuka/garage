<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\GetMakeKnowledgeInput;
use App\State\GetMakeKnowledgeProcessor;

#[ApiResource(
    mcp: [
        'get_make_knowledge' => new McpTool(
            title: 'Make Knowledge',
            description: 'Read curated, sourced knowledge about a vehicle MAKE (not one vehicle): how its identification and build codes work, where to find them on the car, factory codes, what a VIN can and cannot tell. Call it with make alone to list the available topics (topic + title), then again with make + topic for the full entry (markdown body, sources with URLs, verified flag, updatedAt). Consult it before guessing how a manufacturer-specific code works — e.g. PSA (Peugeot, Citroën, DS) "numéro d\'organisation" / OPR / DAM, which catalogues use to split parts. For turning such a code into a date use decode_build_code. A make with no entries, or an unknown topic, is a not_found error (an unknown topic lists the topics that do exist). Global reference data, identical for every garage; read-only.',
            input: GetMakeKnowledgeInput::class,
            structuredContent: false,
            processor: GetMakeKnowledgeProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class MakeKnowledgeTool
{
}
