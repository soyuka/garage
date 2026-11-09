# MCP: Exposing Your API to AI Agents

API Platform integrates with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) to
expose your API as tools and resources that AI agents (LLMs) can discover and interact with.

MCP defines a standard way for AI models to discover available tools, understand their input
schemas, and invoke them. API Platform leverages its existing metadata system — state processors,
validation, serialization — to turn your PHP classes into MCP-compliant tool definitions.

> **Note:** The MCP integration is marked `@experimental`. The API may change between minor
> releases.

## Installation

### Installing on Symfony

Install `api-platform/mcp` and the [MCP Bundle](https://github.com/symfony/ai):

```console
composer require api-platform/mcp symfony/mcp-bundle
```

### Installing on Laravel

Install `api-platform/mcp` and the [MCP Bundle](https://github.com/symfony/ai):

```console
composer require api-platform/mcp symfony/mcp-bundle
```

## Configuring the MCP Server

### Configuring Symfony

Enable the MCP server and configure the transport in your Symfony configuration:

```yaml
# config/packages/mcp.yaml
mcp:
    client_transports:
        http: true
        stdio: false
    http:
        path: "/mcp"
        session:
            store: "file"
            directory: "%kernel.cache_dir%/mcp"
            ttl: 3600
```

You can also configure API Platform's MCP integration in `api_platform.yaml`:

```yaml
# config/packages/api_platform.yaml
api_platform:
    mcp:
        enabled: true # default: true
        format: jsonld # default: 'jsonld'
```

The `format` option sets the serialization format used for MCP tool structured content. It must be a
format registered in `api_platform.formats` (e.g. `jsonld`, `json`, `jsonapi`). The default `jsonld`
produces rich semantic output with `@context`, `@id`, and `@type` fields.

### Configuring Laravel

MCP is enabled by default in the Laravel configuration:

```php
// config/api-platform.php
return [
    // ...
    'mcp' => [
        'enabled' => true,
    ],
];
```

The MCP endpoint is automatically registered at `/mcp`.

## Architecture

API Platform registers its own `Handler` with the MCP SDK. The handler's `supports()` method returns
`true` only for tools and resources that are registered through API Platform metadata. If a
requested tool or resource is not found in the API Platform registry, the handler returns `false`
and the MCP SDK proceeds through its own handler chain.

This means you can register both API Platform MCP tools and native `mcp/sdk` tools on the same
server — they coexist without conflict.

## Declaring MCP Tools

MCP tools let AI agents invoke operations on your API. The primary pattern uses `#[McpTool]` as a
class attribute: the class properties define the tool's input schema, and a
[state processor](state-processors.md) handles the command.

This follows a CQRS-style approach: tools receive input from AI agents and process it through your
application logic.

### Simple Tool

```php
<?php
// api/src/ApiResource/ProcessMessage.php with Symfony or app/ApiResource/ProcessMessage.php with Laravel
namespace App\ApiResource;

use ApiPlatform\Metadata\McpTool;

#[McpTool(
    name: 'process_message',
    description: 'Process a message with priority',
    processor: [self::class, 'process']
)]
class ProcessMessage
{
    public function __construct(
        private string $message,
        private int $priority = 1,
    ) {}

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public static function process($data): mixed
    {
        $data->setMessage('Processed: ' . $data->getMessage());
        $data->setPriority($data->getPriority() + 10);

        return $data;
    }
}
```

The class properties (`$message`, `$priority`) become the tool's `inputSchema`. When an AI agent
calls this tool, API Platform deserializes the input into a `ProcessMessage` instance and passes it
to the processor. The returned object is serialized back as structured content.

You can also use a [dedicated state processor service](state-processors.md) instead of a static
method — any callable or service class implementing `ProcessorInterface` works.

### Using a Separate Input DTO

When the tool's input schema should differ from the class itself, use the `input` option to specify
a separate DTO:

```php
<?php
namespace App\Dto;

class SearchQuery
{
    public string $search;
}
```

```php
<?php
namespace App\ApiResource;

use ApiPlatform\Metadata\McpTool;
use App\Dto\SearchQuery;
use App\State\SearchBooksProcessor;

#[McpTool(
    name: 'search_books',
    description: 'Search books by keyword',
    input: SearchQuery::class,
    processor: SearchBooksProcessor::class,
)]
class BookSearchResult
{
    public int $id;
    public string $title;
    public string $isbn;
}
```

Here, `SearchQuery` defines the tool's `inputSchema` (what the AI agent sends), while
`BookSearchResult` defines the output structure. The processor receives a `SearchQuery` instance and
returns the result:

```php
<?php
namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Book;
use Doctrine\Persistence\ManagerRegistry;

class SearchBooksProcessor implements ProcessorInterface
{
    public function __construct(private readonly ManagerRegistry $managerRegistry) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?iterable
    {
        return $this->managerRegistry->getRepository(Book::class)->findAll();
    }
}
```

### Returning a Collection with McpToolCollection

Use `McpToolCollection` instead of `McpTool` when a tool returns a collection of items. It extends
`McpTool` and implements `CollectionOperationInterface`, which signals to the serializer and schema
factory that the output is a list.

```php
<?php
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpToolCollection;
use App\Dto\SearchQuery;
use App\State\SearchBooksProcessor;

#[ApiResource(
    operations: [],
    mcp: [
        'list_books' => new McpToolCollection(
            description: 'List Books',
            input: SearchQuery::class,
            processor: SearchBooksProcessor::class,
            structuredContent: true,
        ),
    ]
)]
class Book
{
    public ?int $id = null;
    public ?string $title = null;
    public ?string $isbn = null;
}
```

When `structuredContent: true`, the structured content response includes `@context`,
`hydra:totalItems`, and a `hydra:member` array containing the serialized items. The output schema
published to MCP clients reflects this collection structure.

### Returning Custom Results

By default, tool results are serialized using API Platform's [serialization](serialization.md)
system with structured content (JSON). If you need full control over the response, return a
`CallToolResult` directly from your processor and set `structuredContent: false`:

```php
<?php
namespace App\ApiResource;

use ApiPlatform\Metadata\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

#[McpTool(
    name: 'generate_report',
    description: 'Generate a markdown report',
    processor: [self::class, 'process'],
    structuredContent: false
)]
class Report
{
    public function __construct(
        private string $title,
        private string $content,
    ) {}

    // getters and setters...

    public static function process($data): CallToolResult
    {
        $markdown = "# {$data->getTitle()}\n\n{$data->getContent()}";

        return new CallToolResult(
            [new TextContent($markdown)],
            false
        );
    }
}
```

Setting `structuredContent: false` disables the automatic JSON serialization. When returning a
`CallToolResult`, the response is sent as-is to the AI agent.

## Using McpTool with ApiResource

The standalone `#[McpTool]` class attribute is convenient for simple tools, but you can also declare
MCP tools inside an `#[ApiResource]` attribute using the `mcp` parameter. This is the appropriate
pattern when:

- The class should not expose any HTTP endpoints (`operations: []`)
- You need to combine multiple MCP operations on a single class
- You need fine-grained control that is cleaner to express at the resource level

The `mcp` parameter accepts an associative array of `McpTool` or `McpResource` instances, keyed by
the operation name. The array key is the tool name — the `name` parameter inside `new McpTool(...)`
is redundant when using this pattern and should be omitted. Setting `operations: []` means no HTTP
routes are registered for the class — it exists solely as an MCP tool definition.

### Simple Tool with a Dedicated Processor

```php
<?php
// api/src/ApiResource/ReadHydraResource.php with Symfony or app/ApiResource/ReadHydraResource.php with Laravel
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\State\ReadHydraResourceProcessor;

#[ApiResource(
    operations: [],
    mcp: [
        'read_hydra_resource' => new McpTool(
            description: 'Navigate to a Hydra API resource by URI.',
            processor: ReadHydraResourceProcessor::class,
            structuredContent: false,
        ),
    ],
)]
class ReadHydraResource
{
    public string $uri;
}
```

The class properties define the tool's `inputSchema`. The processor receives a `ReadHydraResource`
instance and returns the result. Because `structuredContent: false` is set, the processor can return
a `CallToolResult` directly, bypassing automatic JSON serialization.

### Customizing Property Schemas with ApiProperty

Some LLM providers reject JSON Schema union types such as `["array", "null"]` that PHP nullable
types produce by default. Use `#[ApiProperty(schema: [...])]` to override the generated schema for a
specific property:

```php
<?php
// api/src/ApiResource/InvokeHydraOperation.php with Symfony or app/ApiResource/InvokeHydraOperation.php with Laravel
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\State\InvokeHydraOperationProcessor;

#[ApiResource(
    operations: [],
    mcp: [
        'invoke_hydra_operation' => new McpTool(
            description: 'Execute a state-changing operation on a Hydra API resource.',
            processor: InvokeHydraOperationProcessor::class,
            structuredContent: false,
        ),
    ],
)]
class InvokeHydraOperation
{
    public string $uri;
    public string $method;
    #[ApiProperty(schema: ['type' => 'object', 'description' => 'JSON payload for the request'])]
    public ?array $payload = null;
}
```

Without the `#[ApiProperty]` override, `?array $payload` would generate
`{"type": ["array", "null"]}`. The explicit schema replaces it with `{"type": "object", ...}`, which
all major LLM providers accept.

## Validation

The MCP SDK already validates tool inputs against the JSON Schema at the transport level (types,
required fields, etc.). API Platform's own validation pipeline is disabled by default for MCP tools.
Set `validate: true` to enable business-level validation — constraints like email format, string
length, or custom rules that go beyond structural schema checks.

On Symfony, use [Symfony Validator constraints](../symfony/validation.md):

```php
<?php
namespace App\ApiResource;

use ApiPlatform\Metadata\McpTool;
use Symfony\Component\Validator\Constraints as Assert;

#[McpTool(
    name: 'submit_contact',
    description: 'Submit a contact form',
    processor: [self::class, 'process'],
    validate: true  // Must be explicitly enabled for MCP tools
)]
class ContactForm
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    private ?string $name = null;

    #[Assert\NotNull]
    #[Assert\Email]
    private ?string $email = null;

    #[Assert\Positive]
    private ?int $age = null;

    // getters, setters, and processor...
}
```

On Laravel, use [validation rules](../laravel/validation.md):

```php
#[McpTool(
    name: 'submit_contact',
    description: 'Submit a contact form',
    processor: [self::class, 'process'],
    validate: true,  // Must be explicitly enabled for MCP tools
    rules: [
        'name' => 'required|min:3|max:50',
        'email' => 'required|email',
    ]
)]
```

## Declaring MCP Resources

MCP resources expose read-only content that AI agents can retrieve — documentation, configuration,
reference data, etc. Use the `McpResource` attribute with a [state provider](state-providers.md):

```php
<?php
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpResource;

#[ApiResource(
    operations: [],
    mcp: [
        'api_docs' => new McpResource(
            uri: 'resource://my-app/documentation',
            name: 'App-Documentation',
            description: 'Application API documentation',
            mimeType: 'text/markdown',
            provider: [self::class, 'provide']
        ),
    ]
)]
class Documentation
{
    public function __construct(
        private string $content,
        private string $uri,
    ) {}

    // getters and setters...

    public static function provide(): self
    {
        return new self(
            content: '# My API Documentation\n\nWelcome to the API.',
            uri: 'resource://my-app/documentation'
        );
    }
}
```

The `uri` must be unique across the MCP server and follows the `resource://` URI scheme.

## Securing the MCP Server with OAuth2 (Symfony)

The MCP endpoint is plain HTTP (JSON-RPC over the `http` transport), so it can be secured upstream
with the [Symfony Security component](https://symfony.com/doc/current/security.html) — exactly like
any other API Platform route. **There is no need for an MCP-specific security layer**: everything is
already in `symfony/security-bundle`. You protect `/mcp` with a firewall, and you expose the small
amount of OAuth discovery metadata MCP clients expect.

The flow follows the MCP
[authorization spec](https://modelcontextprotocol.io/specification/draft/basic/authorization), which
builds on
[OAuth 2.0 Protected Resource Metadata (RFC 9728)](https://www.rfc-editor.org/rfc/rfc9728):

1. The MCP client calls `/mcp` without a token and receives a `401` with a `WWW-Authenticate` header
   pointing to the protected-resource metadata document.
2. The client fetches `/.well-known/oauth-protected-resource`, which lists the authorization
   server(s) (e.g. Keycloak) protecting the resource.
3. The client runs the OAuth flow against that authorization server, obtains an access token, and
   retries `/mcp` with `Authorization: Bearer <token>`.
4. The Symfony firewall validates the token through its access-token handler and resolves a user.

This needs four pieces: a firewall on `/mcp`, an access-token handler, a public metadata endpoint,
and a `WWW-Authenticate` challenge on `401` responses.

### Firewall and Access Token Handler

Protect `/mcp` (and your API) with a stateless firewall using Symfony's
[access token authenticator](https://symfony.com/doc/current/security/access_token.html). Any OpenID
Connect provider works — the example below uses Keycloak with the built-in `oidc` token handler,
which validates the JWT locally via the provider's discovery document:

```yaml
# config/packages/security.yaml
security:
    providers:
        # A custom provider that turns validated token claims into a user (see below)
        oidc:
            id: App\Security\KeycloakUserProvider

    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false

        # RFC 9728 metadata must stay public so a client holding a stale/expired token can still
        # discover the authorization server instead of getting a 401 from the access_token handler.
        oauth_metadata:
            pattern: ^/\.well-known/oauth-protected-resource$
            security: false

        api_and_mcp:
            pattern: ^/(api|mcp)
            stateless: true
            provider: oidc
            access_token:
                token_handler:
                    oidc:
                        discovery:
                            base_uri: "%env(KEYCLOAK_URL)%/realms/%env(KEYCLOAK_REALM)%/"
                            cache:
                                id: cache.app
                        audience: "%env(KEYCLOAK_CLIENT_ID)%"
                        issuers:
                            - "%env(KEYCLOAK_PUBLIC_URL)%/realms/%env(KEYCLOAK_REALM)%"
                        algorithms:
                            - RS256
```

> **Note:** Use a separate `security: false` firewall for the metadata endpoint. Putting it on the
> protected firewall would reject a client that arrives with an expired token before it ever sees
> the discovery document.

If your authorization server uses opaque tokens (no locally verifiable JWT), use the `oauth2` token
handler with the provider's introspection endpoint instead:

```yaml
api_and_mcp:
    pattern: ^/(api|mcp)
    stateless: true
    provider: oidc
    access_token:
        token_handler:
            oauth2: "%env(OIDC_SERVER_URI)%/realms/%env(OIDC_SERVER_REALM)%/protocol/openid-connect/introspect"
```

```yaml
# config/packages/framework.yaml — scoped client carrying the introspection credentials
framework:
    http_client:
        scoped_clients:
            oauth2.introspect.client:
                base_uri: "%env(OIDC_SERVER_URI)%/realms/%env(OIDC_SERVER_REALM)%/protocol/openid-connect/introspect"
                auth_basic: "%env(OIDC_CLIENT_ID)%:%env(OIDC_CLIENT_SECRET)%"
```

```yaml
# config/services.yaml — inject the scoped client into the built-in OAuth2 token handler
services:
    security.access_token_handler.api:
        class: Symfony\Component\Security\Http\AccessToken\OAuth2\Oauth2TokenHandler
        arguments:
            $client: "@oauth2.introspect.client"
```

### User Provider

The token handler validates the token and hands the claims to a user provider implementing
`AttributesBasedUserProviderInterface`. Map the claims you need (identifier, email, roles) to your
own user object:

```php
<?php
namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @implements AttributesBasedUserProviderInterface<KeycloakUser>
 */
final class KeycloakUserProvider implements AttributesBasedUserProviderInterface
{
    private const array ROLE_MAP = [
        'Admin' => 'ROLE_ADMIN',
        'User' => 'ROLE_USER',
    ];

    /**
     * @param array<string, mixed> $attributes
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): KeycloakUser
    {
        $roles = ['ROLE_USER'];
        foreach ($attributes['realm_access']['roles'] ?? [] as $role) {
            if (isset(self::ROLE_MAP[$role])) {
                $roles[] = self::ROLE_MAP[$role];
            }
        }

        $email = $attributes['email'] ?? throw new BadCredentialsException('Missing required JWT claim: email');

        return new KeycloakUser($identifier, $email, array_values(array_unique($roles)));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return KeycloakUser::class === $class;
    }
}
```

Roles resolved here drive API Platform's [security expressions](../symfony/security.md) on your
operations and MCP tools (e.g. `security: "is_granted('ROLE_ADMIN')"`).

### Protected Resource Metadata Endpoint

Expose `/.well-known/oauth-protected-resource` (RFC 9728) so MCP clients can discover which
authorization server protects `/mcp`. A small invokable controller is enough:

```php
<?php
namespace App\Controller\Mcp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ProtectedResourceMetadataController
{
    public function __construct(
        #[Autowire(env: 'KEYCLOAK_PUBLIC_URL')]
        private string $keycloakPublicUrl,
        #[Autowire(env: 'KEYCLOAK_REALM')]
        private string $keycloakRealm,
    ) {
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'mcp_oauth_protected_resource_metadata', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $issuer = rtrim($this->keycloakPublicUrl, '/').'/realms/'.$this->keycloakRealm;

        return new JsonResponse([
            'resource' => $request->getSchemeAndHttpHost().'/mcp',
            'authorization_servers' => [$issuer],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
```

This endpoint is required by
[`modelcontextprotocol/inspector`](https://github.com/modelcontextprotocol/inspector) and other
compliant clients to bootstrap the OAuth flow.

### WWW-Authenticate Challenge

To complete the discovery loop, an unauthorized `/mcp` response must carry a `WWW-Authenticate`
header pointing at the metadata document (RFC 9728 §5.1). A response listener adds it on `401`
responses scoped to `/mcp`:

```php
<?php
namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class McpProtectedResourceChallengeListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (Response::HTTP_UNAUTHORIZED !== $response->getStatusCode()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ('/mcp' !== $path && !str_starts_with($path, '/mcp/')) {
            return;
        }

        $existing = $response->headers->get('WWW-Authenticate');
        // The same 401 can pass through kernel.response twice (error sub-request then main request).
        if (null !== $existing && str_contains($existing, 'resource_metadata=')) {
            return;
        }

        $metadataUrl = $event->getRequest()->getSchemeAndHttpHost().'/.well-known/oauth-protected-resource';
        // Append to any existing Bearer challenge (e.g. error="invalid_token") rather than replacing it,
        // so the upstream auth error detail survives (RFC 9728 §5.1).
        $response->headers->set('WWW-Authenticate', null !== $existing
            ? $existing.',resource_metadata="'.$metadataUrl.'"'
            : \sprintf('Bearer resource_metadata="%s"', $metadataUrl));
    }
}
```

With these four pieces in place, an MCP client connecting to `/mcp` is transparently redirected
through the OAuth flow, and every tool invocation runs as an authenticated Symfony user — so your
existing `security` expressions, voters, and role hierarchy apply to MCP tools without any
MCP-specific security code.

### Advertising OAuth2 in OpenAPI (optional)

If you also expose the same API over HTTP and want Swagger UI / the OpenAPI document to offer the
OAuth2 flow, decorate the OpenAPI factory to register the security scheme:

```php
<?php
namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\OAuthFlow;
use ApiPlatform\OpenApi\Model\OAuthFlows;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SecuritySchemeOpenApiDecorator implements OpenApiFactoryInterface
{
    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
        #[Autowire(env: 'KEYCLOAK_PUBLIC_URL')]
        private readonly string $keycloakPublicUrl,
        #[Autowire(env: 'KEYCLOAK_REALM')]
        private readonly string $keycloakRealm,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $tokenUrl = \sprintf(
            '%s/realms/%s/protocol/openid-connect/token',
            rtrim($this->keycloakPublicUrl, '/'),
            $this->keycloakRealm
        );

        $scheme = new SecurityScheme(
            type: 'oauth2',
            flows: new OAuthFlows(
                password: new OAuthFlow(tokenUrl: $tokenUrl, scopes: new \ArrayObject([])),
            ),
        );

        $schemes = $openApi->getComponents()->getSecuritySchemes() ?? new \ArrayObject();
        $schemes['oauth2'] = $scheme;

        return $openApi
            ->withComponents($openApi->getComponents()->withSecuritySchemes($schemes))
            ->withSecurity([['oauth2' => []]]);
    }
}
```

See the [OpenAPI documentation](openapi.md) for more on customizing security schemes.

## McpTool Options

The `McpTool` attribute accepts all standard [operation options](operations.md) plus:

| Option               | Description                                                               |
| -------------------- | ------------------------------------------------------------------------- |
| `name`               | Tool name exposed to AI agents (defaults to the class short name)         |
| `description`        | Human-readable description of the tool (defaults to class DocBlock)       |
| `structuredContent`  | Whether to include JSON structured content in responses (default: `true`) |
| `input`              | A separate DTO class to use as the tool's input schema                    |
| `output`             | A separate DTO class to use as the tool's output representation           |
| `inputFormats`       | Serialization formats for deserializing the tool input (e.g. `['json']`)  |
| `outputFormats`      | Serialization formats for structured content (e.g. `['jsonld']`)          |
| `contentNegotiation` | Whether to enable HTTP content negotiation (default: `false` for MCP)     |
| `validate`           | Whether to run the validation pipeline (default: `false` for MCP)         |
| `annotations`        | MCP tool annotations describing behavior hints                            |
| `icons`              | List of icon URLs representing the tool                                   |
| `meta`               | Arbitrary metadata                                                        |
| `rules`              | Laravel validation rules (Laravel only)                                   |

## McpResource Options

The `McpResource` attribute accepts all standard [operation options](operations.md) plus:

| Option               | Description                                                                |
| -------------------- | -------------------------------------------------------------------------- |
| `uri`                | Unique URI identifying this resource (required, uses `resource://` scheme) |
| `name`               | Human-readable name for the resource                                       |
| `description`        | Description of the resource (defaults to class DocBlock)                   |
| `structuredContent`  | Whether to include JSON structured content (default: `true`)               |
| `contentNegotiation` | Whether to enable HTTP content negotiation (default: `false` for MCP)      |
| `mimeType`           | MIME type of the resource content                                          |
| `size`               | Size in bytes, if known                                                    |
| `annotations`        | MCP resource annotations                                                   |
| `icons`              | List of icon URLs                                                          |
| `meta`               | Arbitrary metadata                                                         |

## Make knowledge tools (this app)

This file is the API Platform MCP reference; the app's own brand-knowledge tools are listed here.
Both are read-only, owner-independent, and reach the in-app agent automatically through `McpToolbox`.

| Tool                 | Purpose                                                                                                   |
| -------------------- | --------------------------------------------------------------------------------------------------------- |
| `decode_build_code`  | `make` + `code` (OPR / DAM / APV) or `date` (YYYY-MM-DD) → organisation number, date, plant, caveats, sources. PSA (Peugeot, Citroën, DS) only; decoders implement `App\Knowledge\BuildCode\BuildCodeDecoder` and are collected by tag. |
| `get_make_knowledge` | `make` → topic list; `make` + `topic` → full sourced entry. Global data (the one exception to owner tenancy). |

The knowledge is versioned in `api/config/knowledge/*.yaml` and loaded by
`bin/console app:make-knowledge:sync` (upsert by makes + topic, removes what disappeared). There is no write tool.

## Vehicle fact tools (this app)

Facts (build date, paint code, tyre size, oil grade/capacity, part references…) have their own write tools;
`plan_maintenance` no longer takes them. Both address the vehicle by its `@id` (as `update_vehicle` does), are
owner-scoped, and reach the in-app agent through `McpToolbox` untouched. A bad argument or an unknown `@id` is an
`isError` tool result whose text opens with `invalid_input:` / `not_found:`, never a JSON-RPC error. The write path is `App\Maintenance\VehicleFactWriter`,
shared with the dashboard's `POST|DELETE /garage/vehicles/{id}/facts`.

| Tool                   | Purpose                                                                                                   |
| ---------------------- | --------------------------------------------------------------------------------------------------------- |
| `set_vehicle_facts`    | `vehicle` + `facts[]{key, value, unit?, source, url?}`. A key already on the vehicle is replaced. Returns the stored facts and `shadowed` (keys where a verified catalog value, a bill part or an owner edit still wins the projection). |
| `remove_vehicle_facts` | `vehicle` + `keys[]` → `removed` / `notFound`. Soft-delete (tombstone), writing the key again restores it. |

Keys are free-form `snake_case`, subject first then aspect (`oil_type`, `oil_capacity`, `coolant_type`,
`paint_code`, `tires_front_dimensions`; a part reference is `{part}_ref`, which is also what `VehicleFactsProjector`
derives from logged bills). `source` is one of `user`, `web`, `llm`, `motul`; `partsapi`, `siv` and `log` are
reserved, so `verified` is derived and never supplied. Facts land as attributes on the vehicle's `user_facts`
carrier item (off the checklist).
