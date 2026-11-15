<?php

declare(strict_types=1);

namespace App\PhotoIntake;

use App\Agent\Journal\ModelUsage;
use App\RegistrationDocument\AnthropicRegistrationDocumentReader;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use App\RegistrationDocument\RegistrationWarning;
use App\Security\SpendRecorder;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Hand-written schema, like {@see AnthropicRegistrationDocumentReader}: the holder section (C) has no
 * slot, so it can never be requested.
 */
final readonly class AnthropicPhotoIntakeReader implements PhotoIntakeReader
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You sort a photo sent to a car parts finder and read only what the finder needs.

        kind:
        - "carte_grise": a French vehicle registration certificate (certificat d'immatriculation).
        - "part": a vehicle part, its packaging or its label.
        - "build_label": a manufacturer label stuck or riveted on the car body, typically on the door pillar or door edge (PSA "plaque constructeur", tyre-pressure sticker, paint/build vignette).
        - "other": anything else.

        For a carte_grise, extract the technical fields below, each copied from the document, or null when absent or unreadable. Never guess.
        PRIVACY, absolute rule: ignore section C entirely (every C.* field: holder name, first name, address, company). Do not read it, do not transcribe it, do not mention it, even if it is perfectly legible. Also ignore any other personal data.
        - A: registration plate
        - B: date of first registration, converted to ISO yyyy-mm-dd
        - D.1: make
        - D.2: type / variant / version
        - D.2.1: national type identification code (CNIT)
        - D.3: commercial name
        - E: VIN
        - J.1: national vehicle category
        - K: type approval number
        - P.1: engine capacity in cm3
        - P.2: net maximum power in kW
        - P.3: fuel / energy type
        - P.6: national administrative power
        For any other kind, every field is null.

        For a part, fill part: familyGuess (the part family in French, e.g. "filtre à huile", "disque de frein"), printedReferences (every reference or part number printed or stamped on the part or its label, copied character for character, spaces and punctuation included; only what is legible, never a guess), brandGuess (the brand printed on it, or null). For any other kind, part has null/empty values.

        For a build_label, fill buildLabel: printedCodes (every code printed on the label, each copied verbatim, character for character, one entry per printed code or line; only what is legible, never a guess), opr (on a Peugeot / Citroën / DS label: the OPR, also called "numéro d'organisation", a 4 or 5 digit production-day counter, often printed right after "DAM" or "OPR", e.g. "DAM 12345", or a DAM such as "1234588" whose first 4-5 digits are the OPR followed by a 2-digit plant code; copy those digits exactly, or null when absent, unreadable or when you are not sure which code it is). Paint codes and tyre pressures are not needed. Never guess, never compute. For any other kind, buildLabel has null/empty values.

        Set legible to false when the photo is too blurry, cropped or dark to read reliably. Use warnings only with these codes, as many as apply, and nothing else: blurry, cropped, dark, glare (photo quality), plate_doubtful, vin_doubtful, date_doubtful (a field you read but doubt). Never write free text in warnings.
        PROMPT;

    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        // One vision call each: charged to the owner's daily budget like an agent turn.
        private ?SpendRecorder $spend = null,
    ) {
    }

    public function read(string $content, string $mimeType): array
    {
        try {
            $result = $this->platform->invoke(
                $this->model,
                new MessageBag(
                    Message::forSystem(self::SYSTEM_PROMPT),
                    Message::ofUser(new Text('Sort this photo and read it.'), new Image($content, $mimeType)),
                ),
                ['response_format' => $this->responseFormat()],
            );
            // Billed as soon as the response is back, whether or not it parses.
            $this->spend?->record('photo_intake', $this->model, ModelUsage::fromAnthropic($result->getRawResult()->getData()));
            $decoded = $result->asObject();
        } catch (ExceptionInterface $e) {
            throw new RegistrationDocumentUnreadable('The photo intake model call failed: '.$e::class, 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new RegistrationDocumentUnreadable('The photo intake model did not return a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        $properties = ['kind' => ['type' => 'string', 'enum' => ['carte_grise', 'part', 'build_label', 'other']]];
        $properties += array_fill_keys(AnthropicRegistrationDocumentReader::FIELDS, ['type' => ['string', 'null']]);
        $properties['part'] = [
            'type' => 'object',
            'properties' => [
                'familyGuess' => ['type' => ['string', 'null']],
                'printedReferences' => ['type' => 'array', 'items' => ['type' => 'string']],
                'brandGuess' => ['type' => ['string', 'null']],
            ],
            'required' => ['familyGuess', 'printedReferences', 'brandGuess'],
            'additionalProperties' => false,
        ];
        $properties['buildLabel'] = [
            'type' => 'object',
            'properties' => [
                'opr' => ['type' => ['string', 'null']],
                'printedCodes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['opr', 'printedCodes'],
            'additionalProperties' => false,
        ];
        $properties['legible'] = ['type' => 'boolean'];
        $properties['warnings'] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => RegistrationWarning::modelCodes()]];

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'photo_intake',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array_keys($properties),
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
