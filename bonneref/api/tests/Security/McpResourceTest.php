<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\McpResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class McpResourceTest extends TestCase
{
    public function testThePublicOriginComesFirstAndUrisAreNormalised(): void
    {
        $resource = new McpResource('HTTPS://Localhost:443/', ['', ' https://garage.soyuka.me/mcp/ ', 'http://localhost:80/mcp'], '/mcp');

        $this->assertSame(['https://localhost/mcp', 'https://garage.soyuka.me/mcp', 'http://localhost/mcp'], $resource->all());
    }

    public function testMetadataFollowsTheOriginTheClientReached(): void
    {
        $resource = new McpResource('https://localhost', ['https://garage.soyuka.me/mcp'], '/mcp');

        $this->assertSame('https://garage.soyuka.me/mcp', $resource->for(Request::create('https://garage.soyuka.me/.well-known/oauth-protected-resource')));
        $this->assertSame('https://localhost/mcp', $resource->for(Request::create('https://unknown.example/.well-known/oauth-protected-resource')));
    }

    public function testARelativeAliasIsAConfigurationError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new McpResource('https://localhost', ['/mcp'], '/mcp');
    }
}
