<?php

declare(strict_types=1);

namespace App\Agent\Journal;

/**
 * The finder hash path a journal row reopens ("p/<plate>[/c/<categoryId>[/g/<productGroupId>][/r/<clusterId>/<selectionId>]]" or
 * "k/<carId>-<carType>[/c/<categoryId>[/g/<productGroupId>][/r/<clusterId>/<selectionId>]]", without the "#/"), and the vehicle it names.
 *
 * @phpstan-type JournalVehicle array{plate: ?string, carId: ?int, carType: ?string, make: ?string, model: ?string, engine: ?string}
 */
final readonly class TurnRoute
{
    /**
     * @param JournalVehicle|null $vehicle
     */
    public function __construct(
        public ?string $route = null,
        public ?array $vehicle = null,
    ) {
    }

    /**
     * @param array<string, mixed> $search the row's search hash (kind search)
     */
    public static function forSearch(array $search): self
    {
        return self::build(self::vehicle($search), self::int($search['categoryId'] ?? null), self::int($search['productGroupId'] ?? null));
    }

    /**
     * @param array<string, mixed>|null                                                   $pageVehicle the vehicle the page sent with the turn
     * @param list<array{name: string, arguments: array<string, mixed>, result?: string}> $toolCalls   in call order
     */
    public static function forAgent(?array $pageVehicle, array $toolCalls): self
    {
        $vehicle = null === $pageVehicle ? null : self::vehicle($pageVehicle);
        $categoryId = null;
        $productGroupId = null;
        $selection = null;
        foreach ($toolCalls as $call) {
            if ('set_vehicle' === $call['name']) {
                $vehicle = self::vehicle($call['arguments']) ?? $vehicle;
            } elseif ('show_parts' === $call['name']) {
                $result = json_decode($call['result'] ?? '', true);
                if (\is_array($result) && (true === ($result['isError'] ?? null) || (isset($result['status']) && 'shown' !== $result['status']))) {
                    continue;
                }
                $shown = self::int($call['arguments']['categoryId'] ?? null);
                if (null === $shown) {
                    continue;
                }
                $categoryId = $shown;
                $productGroupId = self::int($result['productGroup']['id'] ?? null) ?? self::int($call['arguments']['productGroupId'] ?? null);
                $selection = self::selection($result);
            }
        }

        return self::build($vehicle, $categoryId, $productGroupId, $selection);
    }

    /**
     * @param JournalVehicle|null                       $vehicle
     * @param array{clusterId: string, id: string}|null $selection
     */
    private static function build(?array $vehicle, ?int $categoryId, ?int $productGroupId = null, ?array $selection = null): self
    {
        if (null === $vehicle) {
            return new self();
        }

        $route = null !== $vehicle['plate']
            ? 'p/'.rawurlencode($vehicle['plate'])
            : 'k/'.$vehicle['carId'].'-'.($vehicle['carType'] ?? 'PC');

        if (null !== $categoryId) {
            $route .= '/c/'.$categoryId
                .(null === $productGroupId ? '' : '/g/'.$productGroupId)
                .(null === $selection ? '' : '/r/'.rawurlencode($selection['clusterId']).'/'.$selection['id']);
        }

        return new self($route, $vehicle);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return JournalVehicle|null
     */
    private static function vehicle(array $data): ?array
    {
        $plate = self::text($data['plate'] ?? null);
        $plate = null === $plate ? null : strtoupper((string) preg_replace('/\s+/u', '', $plate));
        $carId = self::int($data['carId'] ?? null);
        if (null === $plate && null === $carId) {
            return null;
        }

        return [
            'plate' => $plate,
            'carId' => $carId,
            'carType' => self::text($data['carType'] ?? null),
            'make' => self::text($data['make'] ?? null),
            'model' => self::text($data['model'] ?? null),
            'engine' => self::text($data['engine'] ?? null),
        ];
    }

    /**
     * @return array{clusterId: string, id: string}|null
     */
    private static function selection(mixed $result): ?array
    {
        if (!\is_array($result)) {
            return null;
        }
        $clusterId = self::text($result['selected'] ?? null);
        $id = $result['selectionId'] ?? null;
        if (null === $clusterId || !\is_string($id) || 1 !== preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id)) {
            return null;
        }

        return ['clusterId' => $clusterId, 'id' => $id];
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) && $value > 0 ? $value : null;
    }
}
