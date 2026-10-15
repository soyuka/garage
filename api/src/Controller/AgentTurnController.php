<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\FinderIntent;
use App\Agent\Finder\FinderVehicle;
use App\Agent\Finder\InvalidTurnImage;
use App\Agent\Finder\PartPhotoHints;
use App\Agent\Finder\TurnImage;
use App\Agent\Finder\TurnImageDecoder;
use App\Agent\Finder\TurnImageStash;
use App\Agent\TurnSurface;
use App\I18n\Locale;
use App\Message\RunAgentTurn;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enqueues one agent turn and acks. The turn itself runs in a `messenger:consume` worker (see
 * {@see \App\MessageHandler\RunAgentTurnHandler}) — never in this request thread, because the
 * agent's multi-round-trip tool loop (persist → catalog → web_search → plan_maintenance → render
 * intents) routinely outruns the request's max_execution_time and was being killed mid-turn with a
 * MaxExecutionTimeError, leaving the work half-done.
 *
 * The HTTP contract stays a bare 202 ack: there is no chat transcript and no action list in the
 * body. The dashboard updates progressively from the render intents the worker streams to the
 * caller's per-session Mercure topic; domain writes fan out on public vehicle topics for free.
 *
 * The session topic is built from the configured public base URL (DEFAULT_URI), the SAME origin
 * {@see \App\Mercure\DomainPublisher} derives the vehicle topics from and the dashboard subscribes
 * to (`${VITE_API_BASE}/agent/sessions/<id>`). NOT the request host: behind the reverse proxy
 * `Request::getSchemeAndHttpHost()` can report the internal scheme/host (e.g. http://php), which
 * would publish render intents to a topic no dashboard listens on.
 */
final class AgentTurnController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly Security $security,
        private readonly TurnImageDecoder $images,
        private readonly TurnImageStash $imageStash,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $baseUri,
    ) {
    }

    #[Route('/agent/turn', name: 'agent_turn', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = \is_array($payload) ? $payload : [];

        $instruction = trim((string) ($payload['instruction'] ?? ''));
        $sessionId = trim((string) ($payload['sessionId'] ?? ''));

        if ('' === $instruction || '' === $sessionId) {
            return new JsonResponse(['error' => 'instruction and sessionId are required'], Response::HTTP_BAD_REQUEST);
        }

        $surface = TurnSurface::tryFrom((string) ($payload['surface'] ?? TurnSurface::Garage->value));
        if (null === $surface) {
            return new JsonResponse(['error' => 'surface must be one of: garage, finder'], Response::HTTP_BAD_REQUEST);
        }

        $vehicle = null;
        $intent = FinderIntent::Text;
        $images = [];
        if (TurnSurface::Finder === $surface) {
            if (null !== ($payload['vehicle'] ?? null)) {
                $vehicle = FinderVehicle::fromPayload(\is_array($payload['vehicle']) ? $payload['vehicle'] : []);
                if (null === $vehicle) {
                    return new JsonResponse(['error' => 'vehicle needs a carId (and a PC/CV carType); omit it to let the agent identify the car'], Response::HTTP_BAD_REQUEST);
                }
            }
            $intent = FinderIntent::tryFrom((string) ($payload['intent'] ?? FinderIntent::Text->value));
            if (null === $intent) {
                return new JsonResponse(['error' => 'intent must be one of: text, reference, photo'], Response::HTTP_BAD_REQUEST);
            }
            try {
                $images = $this->images->decode($payload['images'] ?? null);
            } catch (InvalidTurnImage $e) {
                return new JsonResponse(['error' => $e->getMessage()], $e->status);
            }
        }

        // The firewall (access_control ^/agent/turn) guarantees an authenticated user here, so the
        // owner is always present — the turn never runs unowned.
        $this->bus->dispatch(new RunAgentTurn(
            instruction: $instruction,
            sessionTopic: rtrim($this->baseUri, '/').'/agent/sessions/'.$sessionId,
            owner: $this->security->getUser()?->getUserIdentifier() ?? '',
            selectedId: trim((string) ($payload['selectedId'] ?? '')),
            summary: trim((string) ($payload['summary'] ?? '')),
            pendingContext: trim((string) ($payload['pendingContext'] ?? '')),
            // Not a payload field: the browser already announces its language on the request, so the
            // dashboard's choice arrives for free (framework.yaml `set_locale_from_accept_language`).
            locale: Locale::resolve($request->getLocale())->value,
            surface: $surface,
            vehicle: $vehicle,
            intent: $intent,
            photoHints: TurnSurface::Finder === $surface ? PartPhotoHints::fromPayload($payload['photoHints'] ?? null) : null,
            imageStash: [] === $images ? null : $this->imageStash->put($images),
            images: array_map(static fn (TurnImage $i): array => $i->shape(), $images),
            buildLabel: TurnSurface::Finder === $surface ? BuildLabelHints::fromPayload($payload['buildLabel'] ?? null) : null,
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
