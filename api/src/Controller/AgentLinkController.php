<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\Ui\DashboardChannel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the dashboard the Mercure topic its owner's PILOT intents land on, so a render intent driven
 * from an external MCP host (Claude Code, Goose…) reaches the open workshop without the host knowing
 * anything about browser tabs. The dashboard subscribes to it next to its own per-tab turn session.
 *
 * Authenticated: the topic is a keyed digest of the owner (see {@see DashboardChannel}) and the hub
 * accepts anonymous subscribers, so handing it out is handing out the right to watch that owner's
 * dashboard traffic. The firewall guarantees the token, hence no null owner here.
 */
final readonly class AgentLinkController
{
    public function __construct(
        private Security $security,
        private DashboardChannel $channel,
    ) {
    }

    #[Route('/agent/link', name: 'agent_link', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'topic' => $this->channel->pilotTopic($this->security->getUser()?->getUserIdentifier() ?? ''),
        ]);
    }
}
