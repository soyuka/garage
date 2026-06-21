<?php

namespace App;

use App\DependencyInjection\Compiler\AgentMaxToolCallsPass;
use App\Mcp\EnableMcpAppsExtensionPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new EnableMcpAppsExtensionPass());
        // Runs in the default (before-optimization) phase, after ResolveChildDefinitionsPass has
        // flattened ai.tool.agent_processor.garage — so its args 0-4 exist and we can append arg 5.
        $container->addCompilerPass(new AgentMaxToolCallsPass());
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
