<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Ui\DashboardChannel;
use App\Capability\Capabilities;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the dashboard the Mercure topics of its owner: `topic`, where PILOT intents land, and
 * `fleetTopic`, where vehicle writes push the read-model. For the pilot topic: so a render intent driven
 * from an external MCP host (Claude Code, Goose…) reaches the open workshop without the host knowing
 * anything about browser tabs. The dashboard subscribes to it next to its own per-tab turn session.
 *
 * Authenticated: the topic is a keyed digest of the owner (see {@see DashboardChannel}) and the hub
 * accepts anonymous subscribers, so handing it out is handing out the right to watch that owner's
 * dashboard traffic. The firewall guarantees the token, hence no null owner here.
 *
 * Also the dashboard's one boot-time read of what this deployment can do (`capabilities`, keyed by
 * {@see \App\Capability\Capability}): a feature whose provider key is absent is hidden or disabled
 * up front instead of failing on every request.
 */
final readonly class AgentLinkController
{
    public function __construct(
        private Security $security,
        private DashboardChannel $channel,
        private Capabilities $capabilities,
    ) {
    }

    #[Route('/agent/link', name: 'agent_link', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $owner = $this->security->getUser()?->getUserIdentifier() ?? '';

        return new JsonResponse([
            'topic' => $this->channel->pilotTopic($owner),
            'fleetTopic' => $this->channel->fleetTopic($owner),
            'capabilities' => $this->capabilities->all(),
        ]);
    }
}
