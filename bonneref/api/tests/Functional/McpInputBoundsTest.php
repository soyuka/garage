<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class McpInputBoundsTest extends AppTestCase
{
    use McpClientTrait;

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('oversizedInputs')]
    public function testOversizedInputIsRejectedAsInvalidInput(string $tool, array $arguments): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $content = $this->outcome($this->call($client, $sessionId, 'tools/call', [
            'name' => $tool,
            'arguments' => $arguments,
        ], 2));

        $this->assertSame('invalid_input', $content['error'] ?? null, json_encode($content, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function oversizedInputs(): iterable
    {
        yield 'list_articles: too many categories' => ['list_articles', ['carId' => 1, 'categoryIds' => range(1, 9)]];
        yield 'list_articles: too many brands' => ['list_articles', ['carId' => 1, 'categoryIds' => [1], 'brands' => array_map(static fn (int $i): string => 'B'.$i, range(1, 11))]];
        yield 'list_articles: brand too long' => ['list_articles', ['carId' => 1, 'categoryIds' => [1], 'brands' => [str_repeat('X', 41)]]];
        yield 'compare_brands: too many brands' => ['compare_brands', ['brands' => array_map(static fn (int $i): string => 'B'.$i, range(1, 11))]];
        yield 'compare_brands: brand too long' => ['compare_brands', ['brands' => [str_repeat('X', 41)]]];
        yield 'list_makes: search too long' => ['list_makes', ['search' => str_repeat('a', 65)]];
        yield 'list_makes: negative offset' => ['list_makes', ['offset' => -1]];
        yield 'list_models: search too long' => ['list_models', ['makeId' => 1, 'search' => str_repeat('a', 65)]];
        yield 'list_models: negative offset' => ['list_models', ['makeId' => 1, 'offset' => -5]];
        yield 'list_part_categories: search too long' => ['list_part_categories', ['carId' => 1, 'search' => str_repeat('a', 65)]];
        yield 'list_part_categories: negative offset' => ['list_part_categories', ['carId' => 1, 'offset' => -1]];
        yield 'list_cars: search too long' => ['list_cars', ['makeId' => 1, 'modelId' => 1, 'search' => str_repeat('a', 65)]];
        yield 'narrow_parts: make too long' => ['narrow_parts', ['carId' => 1, 'categoryId' => 1, 'make' => str_repeat('M', 41)]];
        yield 'lookup_plate: too long' => ['lookup_plate', ['plate' => str_repeat('A', 17)]];
        yield 'lookup_plate: not a plate charset' => ['lookup_plate', ['plate' => 'AB<123>CD']];
        yield 'decode_vin: too long' => ['decode_vin', ['vin' => str_repeat('A', 25)]];
        yield 'decode_vin: not a vin charset' => ['decode_vin', ['vin' => 'VF1;DROP TABLE x;1234']];
        yield 'decode_build_code: make too long' => ['decode_build_code', ['make' => str_repeat('P', 33), 'code' => '10263']];
        yield 'decode_build_code: code too long' => ['decode_build_code', ['make' => 'PEUGEOT', 'code' => str_repeat('1', 25)]];
        yield 'decode_build_code: date too long' => ['decode_build_code', ['make' => 'PEUGEOT', 'date' => str_repeat('2', 11)]];
    }

    #[DataProvider('oversizedQueries')]
    public function testOversizedHttpQueryIsRejected(string $uri): void
    {
        static::createClient()->request('GET', $uri);

        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oversizedQueries(): iterable
    {
        $long = str_repeat('a', 65);

        yield 'makes search' => ['/makes?search='.$long];
        yield 'part categories search' => ['/cars/1/part-categories?search='.$long];
        yield 'cars search' => ['/makes/1/models/1/cars?search='.$long];
        yield 'narrowed make' => ['/cars/1/part-categories/1/narrowed?make='.str_repeat('M', 41)];
    }
}
