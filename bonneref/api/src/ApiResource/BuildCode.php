<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\Dto\DecodeBuildCodeInput;
use App\State\DecodeBuildCodeProcessor;

#[ApiResource(
    mcp: [
        'decode_build_code' => new McpTool(
            title: 'Decode Build Code (OPR / DAM / APV)',
            description: 'Deterministically decode a manufacturer build code into a production DATE (and plant), or turn a date into the code. This is how to resolve parts-catalogue splits such as "jusqu\'à n° d\'organisation 10310 / à partir de 10311": decode the car\'s own code, then compare it with the threshold (or decode the threshold to see which date it means). PSA (Peugeot, Citroën, DS): the OPR / numéro d\'organisation is a production-day counter (date = 1976-11-08 + OPR days); a DAM is the OPR + 2-digit plant code; an APV/PR is the DAM + 5 more characters. On PSA cars the DAM is printed on the door-pillar tyre-pressure sticker (e.g. "982488 / 331 / EWPA" — EWPA is the paint code); the riveted constructor plate of 2000s cars (VIN, weights) carries no OPR, and the VIN cannot yield it. Pass make plus EITHER code (any of OPR/DAM/APV, spaces and dropped leading zero tolerated) OR date (YYYY-MM-DD) for the reverse lookup. Returns status "decoded" with organisationNumber (5-digit string), date, plantCode/plantName (null when unknown), codeKind, sequence, caveats and sources — surface the caveats (the date may be the order date, not the build date) when a decision hangs on a few days. An unparseable code or date is an invalid_input error, a make without a decoder an unsupported_make error. Read-only, owner-independent. See get_make_knowledge for the background articles.',
            input: DecodeBuildCodeInput::class,
            structuredContent: false,
            processor: DecodeBuildCodeProcessor::class,
            annotations: ['readOnlyHint' => true],
        ),
    ],
)]
final class BuildCode
{
}
