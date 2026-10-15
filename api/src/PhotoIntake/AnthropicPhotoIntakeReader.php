<?php

declare(strict_types=1);

namespace App\PhotoIntake;

use App\RegistrationDocument\AnthropicRegistrationDocumentReader;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
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

        Set legible to false when the photo is too blurry, cropped or dark to read reliably. Use warnings for short remarks about doubtful readings; never put personal data in a warning.
        PROMPT;

    public function __construct(
        private PlatformInterface $platform,
        private string $model,
    ) {
    }

    public function read(string $content, string $mimeType): array
    {
        try {
            $text = $this->platform->invoke(
                $this->model,
                new MessageBag(
                    Message::forSystem(self::SYSTEM_PROMPT),
                    Message::ofUser(new Text('Sort this photo and read it.'), new Image($content, $mimeType)),
                ),
                ['response_format' => $this->responseFormat()],
            )->asText();
        } catch (ExceptionInterface $e) {
            throw new RegistrationDocumentUnreadable('The photo intake model call failed: '.$e::class, 0, $e);
        }

        $decoded = json_decode($text, true);
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
        $properties = ['kind' => ['type' => 'string', 'enum' => ['carte_grise', 'part', 'other']]];
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
        $properties['legible'] = ['type' => 'boolean'];
        $properties['warnings'] = ['type' => 'array', 'items' => ['type' => 'string']];

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
