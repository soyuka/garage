<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Exception\InvalidToolInputException;
use App\Garage\ToolError;
use App\Mcp\DomainErrorHandler;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\Assert;

final class DomainErrorHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function invalidArguments(): iterable
    {
        try {
            Assert::inArray('loud', ['info', 'ok']);
        } catch (\InvalidArgumentException $e) {
            yield 'webmozart assertion' => [$e, 'Expected one of: "info", "ok". Got: "loud". Fix these arguments and call again.'];
        }
        yield 'constraint violation' => [new InvalidToolInputException('dueDate: This value is not a valid date.'), 'dueDate: This value is not a valid date. Fix these arguments and call again.'];
    }

    #[DataProvider('invalidArguments')]
    public function testAnInvalidArgumentIsAnInvalidInputToolError(\Throwable $thrown, string $reason): void
    {
        $request = (new CallToolRequest('focus_view', []))->withId(7);

        $response = $this->handler($thrown)->handle($request, $this->createStub(SessionInterface::class));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(ToolError::class, $response->result);
        $this->assertSame('invalid_input', $response->result->status);
        $this->assertSame($reason, $response->result->reason);
    }

    public function testAnUnexpectedFailureStaysLoud(): void
    {
        $this->expectException(\LogicException::class);

        $this->handler(new \LogicException('bug'))->handle((new CallToolRequest('focus_view', []))->withId(7), $this->createStub(SessionInterface::class));
    }

    private function handler(\Throwable $thrown): DomainErrorHandler
    {
        return new DomainErrorHandler(new class($thrown) implements RequestHandlerInterface {
            public function __construct(private readonly \Throwable $thrown)
            {
            }

            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): Response|Error
            {
                throw $this->thrown;
            }
        });
    }
}
