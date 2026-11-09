<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

use App\Agent\Journal\ModelUsage;
use App\Security\SpendRecorder;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * The schema is hand-written rather than derived from a DTO so that nothing about the holder
 * (section C) can ever be requested, and so the model call needs no object denormalisation.
 */
final readonly class AnthropicRegistrationDocumentReader implements RegistrationDocumentReader
{
    public const array FIELDS = ['A', 'B', 'D.1', 'D.2', 'D.2.1', 'D.3', 'E', 'J.1', 'K', 'P.1', 'P.2', 'P.3', 'P.6'];

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You read French vehicle registration certificates (certificat d'immatriculation, "carte grise") and extract technical fields only.

        PRIVACY, absolute rule: ignore section C entirely (every C.* field: holder name, first name, address, company). Do not read it, do not transcribe it, do not mention it, even if it is perfectly legible. Also ignore any other personal data on the document.

        Extract exactly these fields, each as a string copied from the document, or null when absent or unreadable. Never guess.
        - A: registration plate
        - B: date of first registration, converted to ISO yyyy-mm-dd
        - D.1: make
        - D.2: type / variant / version
        - D.2.1: national type identification code (CNIT)
        - D.3: commercial name
        - E: VIN (vehicle identification number)
        - J.1: national vehicle category (genre national)
        - K: type approval number
        - P.1: engine capacity in cm3
        - P.2: net maximum power in kW
        - P.3: fuel / energy type
        - P.6: national administrative power (puissance fiscale)

        Set legible to false when the image is not a registration certificate or too blurry, cropped or dark to read reliably. Use warnings only with these codes, as many as apply, and nothing else: blurry, cropped, dark, glare (photo quality), plate_doubtful, vin_doubtful, date_doubtful (a field you read but doubt). Never write free text in warnings.
        PROMPT;

    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        // One vision call each: charged to the owner's daily budget like an agent turn.
        private ?SpendRecorder $spend = null,
    ) {
    }

    public function read(string $content, string $mimeType): array
    {
        $file = 'application/pdf' === $mimeType ? new Document($content, $mimeType) : new Image($content, $mimeType);

        try {
            $result = $this->platform->invoke(
                $this->model,
                new MessageBag(
                    Message::forSystem(self::SYSTEM_PROMPT),
                    Message::ofUser(new Text('Extract the fields from this document.'), $file),
                ),
                ['response_format' => $this->responseFormat()],
            );
            $decoded = $result->asObject();
            $this->spend?->record('registration_document', $this->model, ModelUsage::fromAnthropic($result->getRawResult()->getData()));
        } catch (ExceptionInterface $e) {
            throw new RegistrationDocumentUnreadable('The extraction model call failed: '.$e::class, 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new RegistrationDocumentUnreadable('The extraction model did not return a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        $properties = array_fill_keys(self::FIELDS, ['type' => ['string', 'null']]);
        $properties['legible'] = ['type' => 'boolean'];
        $properties['warnings'] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => RegistrationWarning::modelCodes()]];

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'registration_document',
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
