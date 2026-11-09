<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

final class AgentModelCatalogTest extends AppTestCase
{
    public function testSonnet55IsRegisteredAndCarriesBetweenToolsThinking(): void
    {
        static::createClient();
        $catalog = static::getContainer()->get('ai.platform.model_catalog.anthropic');
        $this->assertInstanceOf(ModelCatalogInterface::class, $catalog);

        $model = $catalog->getModel('claude-sonnet-5-5?max_tokens=8192&thinking[type]=between_tools');

        $this->assertInstanceOf(Claude::class, $model);
        $this->assertSame('claude-sonnet-5-5', $model->getName());
        $this->assertSame(['max_tokens' => 8192, 'thinking' => ['type' => 'between_tools']], $model->getOptions());
    }

    public function testHaiku55IsRegisteredAndCarriesEffort(): void
    {
        static::createClient();
        $catalog = static::getContainer()->get('ai.platform.model_catalog.anthropic');
        $this->assertInstanceOf(ModelCatalogInterface::class, $catalog);

        $model = $catalog->getModel('claude-haiku-5-5?max_tokens=8192&output_config[effort]=low');

        $this->assertInstanceOf(Claude::class, $model);
        $this->assertSame('claude-haiku-5-5', $model->getName());
        $this->assertSame(['max_tokens' => 8192, 'output_config' => ['effort' => 'low']], $model->getOptions());
    }

    public function testDefaultCatalogEntriesSurviveTheAdditionalModel(): void
    {
        static::createClient();
        $catalog = static::getContainer()->get('ai.platform.model_catalog.anthropic');
        $this->assertInstanceOf(ModelCatalogInterface::class, $catalog);

        $this->assertSame('claude-haiku-4-5', $catalog->getModel('claude-haiku-4-5')->getName());
    }
}
