<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Dispatch\TurnDispatcher;
use App\Agent\Ui\DashboardChannel;
use App\Bonneref\BonnerefToken;
use App\Capability\Capabilities;
use App\Capability\Capability;
use App\Dto\AgentTurnInput;
use App\I18n\Locale;
use App\Message\RunAgentTurn;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands one agent turn to the {@see TurnDispatcher} and acks. The turn itself runs in a
 * `messenger:consume` worker (see {@see \App\MessageHandler\RunAgentTurnHandler}), or, with
 * AGENT_TURN_DISPATCH=terminate, after this response is sent — never inside the request, because the
 * agent's multi-round-trip tool loop (persist → catalog → web_search → plan_maintenance → render
 * intents) routinely outruns the request's max_execution_time and was being killed mid-turn with a
 * MaxExecutionTimeError, leaving the work half-done.
 *
 * The HTTP contract stays a bare 202 ack: there is no chat transcript and no action list in the
 * body. The dashboard updates progressively from the render intents the worker streams to the
 * caller's per-session Mercure topic; domain writes go to the owner's fleet topic for free.
 *
 * The session topic is {@see DashboardChannel::sessionTopic()}: a keyed digest of (owner, sessionId)
 * on the configured public base URL (DEFAULT_URI, never the request host, which behind the proxy can
 * be http://php). The browser picks the session id, so the owner binding is what stops another owner
 * from streaming into, or watching, this tab's session. The dashboard learns the topic from
 * GET /agent/link?session=<id>.
 */
final readonly class AgentTurnController
{
    public function __construct(
        private TurnDispatcher $turns,
        private Security $security,
        private RateLimiterFactoryInterface $agentTurnLimiter,
        private RateLimiterFactoryInterface $agentTurnGlobalLimiter,
        private DashboardChannel $channel,
        private Capabilities $capabilities,
    ) {
    }

    #[Route('/agent/turn', name: 'agent_turn', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        AgentTurnInput $input,
    ): Response {
        // No Anthropic key: nothing would run the turn. Refused before the rate limiter spends a slot.
        if (!$this->capabilities->has(Capability::Agent)) {
            return new JsonResponse(['error' => 'agent not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // The firewall (access_control ^/agent/turn) guarantees an authenticated user here, so the
        // owner is always present — the turn never runs unowned.
        $owner = $this->security->getUser()?->getUserIdentifier() ?? '';

        // The owner's budget first, so one account cannot drain the global one past its own share.
        foreach ([$this->agentTurnLimiter->create($owner), $this->agentTurnGlobalLimiter->create('global')] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                return new JsonResponse(
                    ['error' => 'agent turn limit reached'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
                );
            }
        }

        $this->turns->dispatch(new RunAgentTurn(
            instruction: trim($input->instruction),
            sessionTopic: $this->channel->sessionTopic($owner, $input->sessionId),
            owner: $owner,
            selectedId: trim($input->selectedId ?? ''),
            summary: trim($input->summary ?? ''),
            pendingContext: trim($input->pendingContext ?? ''),
            // Not a payload field: the browser already announces its language on the request, so the
            // dashboard's choice arrives for free (framework.yaml `set_locale_from_accept_language`).
            locale: Locale::resolve($request->getLocale())->value,
            accessToken: BonnerefToken::fromRequest($request) ?? '',
        ));

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
