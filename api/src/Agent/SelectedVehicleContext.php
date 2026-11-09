<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Carries the vehicle the dashboard currently has SELECTED into the in-process MCP tool pipeline,
 * which does not thread it explicitly. The agent already sees the selected @id in its turn context,
 * but {@see \App\State\ListVehiclesProcessor} uses this to float that vehicle to the TOP of the parc
 * listing — the server-side echo of the dashboard's "selected/most-recent first" rail — so the model
 * works against the vehicle in focus without us forbidding it from creating another.
 *
 * Singleton in the consume worker, so {@see set()} (the selected @id from the turn message) is bound
 * per turn by {@see \App\MessageHandler\RunAgentTurnHandler}; null when no vehicle is selected.
 */
final class SelectedVehicleContext implements ResetInterface
{
    private ?string $iri = null;

    public function set(?string $iri): void
    {
        $this->iri = '' !== $iri ? $iri : null;
    }

    public function reset(): void
    {
        $this->iri = null;
    }

    public function current(): ?string
    {
        return $this->iri;
    }
}
