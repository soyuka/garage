<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;

final class AgentModelCatalogTest extends AppTestCase
{
    public function testSonnet55IsRegisteredAndCarriesBetweenToolsThinking(): void
    {
        static::createClient();
        $catalog = static::getContainer()->get('ai.platform.model_catalog.anthropic');

        $model = $catalog->getModel('claude-sonnet-5-5?max_tokens=8192&thinking[type]=between_tools');

        self::assertInstanceOf(Claude::class, $model);
        self::assertSame('claude-sonnet-5-5', $model->getName());
        self::assertSame(['max_tokens' => 8192, 'thinking' => ['type' => 'between_tools']], $model->getOptions());
    }

    public function testHaiku55IsRegisteredAndCarriesEffort(): void
    {
        static::createClient();
        $catalog = static::getContainer()->get('ai.platform.model_catalog.anthropic');

        $model = $catalog->getModel('claude-haiku-5-5?max_tokens=8192&output_config[effort]=low');

        self::assertInstanceOf(Claude::class, $model);
        self::assertSame('claude-haiku-5-5', $model->getName());
        self::assertSame(['max_tokens' => 8192, 'output_config' => ['effort' => 'low']], $model->getOptions());
    }

    public function testDefaultCatalogEntriesSurviveTheAdditionalModel(): void
    {
        static::createClient();
        $catalog = static::getContainer()->get('ai.platform.model_catalog.anthropic');

        self::assertSame('claude-haiku-4-5', $catalog->getModel('claude-haiku-4-5')->getName());
    }
}
