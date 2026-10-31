<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serialization;

use ApiPlatform\Hydra\Serializer\CollectionNormalizer;
use ApiPlatform\JsonLd\ContextBuilder;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\Symfony\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

final class HydraCollectionContextTest extends TestCase
{
    public function testCollectionTermsResolveToHydraVocabulary(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [Yaml::parseFile(__DIR__.'/../../../config/packages/api_platform.yaml', Yaml::PARSE_CONSTANT)['api_platform']]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/docs.jsonld');
        $builder = new ContextBuilder(
            $this->createStub(ResourceNameCollectionFactoryInterface::class),
            $this->createStub(ResourceMetadataCollectionFactoryInterface::class),
            $this->createStub(PropertyNameCollectionFactoryInterface::class),
            $this->createStub(PropertyMetadataFactoryInterface::class),
            $urls,
        );
        $resolver = $this->createStub(ResourceClassResolverInterface::class);
        $resolver->method('getResourceClass')->willReturn(self::class);
        $iris = $this->createStub(IriConverterInterface::class);
        $iris->method('getIriFromResource')->willReturn('/garage/vehicles');
        $normalizer = new CollectionNormalizer($builder, $resolver, $iris, ['hydra_prefix' => $config['serializer']['hydra_prefix']]);
        $document = $normalizer->normalize([], 'jsonld', [
            'resource_class' => self::class,
            'operation' => new GetCollection(),
            'jsonld_has_context' => true,
        ]);
        $context = $builder->getBaseContext();
        $expand = static function (string $term) use ($context): string {
            if (str_contains($term, ':')) {
                [$prefix, $name] = explode(':', $term, 2);

                return $context[$prefix].$name;
            }

            return $context[$term] ?? $context['@vocab'].$term;
        };

        self::assertSame('http://www.w3.org/ns/hydra/core#Collection', $expand($document['@type']));
        $expandedProperties = array_map($expand, array_values(array_filter(array_keys($document), static fn (string $key): bool => !str_starts_with($key, '@'))));
        self::assertContains('http://www.w3.org/ns/hydra/core#member', $expandedProperties);
        self::assertContains('http://www.w3.org/ns/hydra/core#totalItems', $expandedProperties);
    }
}
