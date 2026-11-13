<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use App\Catalog\Narrowing\ApplicabilityScope;
use App\Catalog\Narrowing\ArticleNarrower;
use App\Entity\AgentTurn;
use App\Entity\FinderSelection;
use App\I18n\LocaleContext;
use App\Repository\AgentTurnRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Uid\Ulid;

/**
 * What a follow-up turn continues, rebuilt on the server: the question the previous turn asked and the
 * web finds it showed come from that turn's journal (owner-scoped), the catalogue result on screen is
 * narrowed again from its ids. The page names things, it never writes the prompt.
 */
final readonly class FinderFollowUp
{
    private const array RENDER_TOOLS = ['ask_question', 'show_parts', 'show_web_part'];

    private const int MAX_PENDING_LENGTH = 600;

    private const int MAX_OE = 3;

    private const string CLUSTER_ID = '/^c\d{1,3}$/';

    private const array CHOSEN_BY = [
        'answer' => 'choisie par la réponse à la question',
        'user' => "choisie par l'utilisateur, non confirmée par le catalogue",
    ];

    public function __construct(
        private AgentTurnRepository $turns,
        private ArticleNarrower $narrower,
        private DocumentManager $dm,
        private LocaleContext $locale,
    ) {
    }

    /**
     * @return array{summary: string, pendingContext: string}
     *
     * @throws InvalidTurnInput
     */
    public function resolve(string $owner, mixed $previousTurnId, mixed $onScreen, ?FinderVehicle $vehicle): array
    {
        $previous = null === $previousTurnId ? null : $this->previousTurn($owner, $previousTurnId);
        $screen = null === $onScreen ? null : $this->onScreen($onScreen, $vehicle);

        $pending = null === $previous ? '' : self::pendingQuestion($previous);
        if ('' === $pending && null !== $screen) {
            $pending = $screen['pending'];
        }
        $summary = $screen['summary'] ?? (null === $previous ? '' : self::webFinds($previous));

        return ['summary' => $summary, 'pendingContext' => mb_substr($pending, 0, self::MAX_PENDING_LENGTH)];
    }

    private function previousTurn(string $owner, mixed $id): AgentTurn
    {
        $turn = \is_string($id) && Ulid::isValid($id) ? $this->turns->findOneForOwner(Ulid::fromString($id), $owner) : null;

        return $turn ?? throw new InvalidTurnInput('previousTurnId is not one of your turns');
    }

    /**
     * The question stays pending only when asking it was the last thing the turn drew.
     */
    private static function pendingQuestion(AgentTurn $turn): string
    {
        $last = null;
        foreach ($turn->getToolCalls() as $call) {
            if (\in_array($call->getName(), self::RENDER_TOOLS, true)) {
                $last = $call;
            }
        }
        if ('ask_question' !== $last?->getName()) {
            return '';
        }
        $arguments = $last->getArguments();
        $question = \is_string($arguments['question'] ?? null) ? trim($arguments['question']) : '';
        $context = \is_string($arguments['context'] ?? null) ? trim($arguments['context']) : '';

        return trim(('' === $question ? '' : '« '.$question.' » ').$context);
    }

    private static function webFinds(AgentTurn $turn): string
    {
        $finds = [];
        foreach ($turn->getToolCalls() as $call) {
            $a = $call->getArguments();
            if ('show_web_part' === $call->getName() && \is_string($a['reference'] ?? null)) {
                $finds[] = \sprintf('%s : %s %s (source web, hors catalogue)', self::scalar($a['categoryLabel'] ?? null), self::scalar($a['brand'] ?? null), $a['reference']);
            }
        }

        return implode(' ; ', $finds);
    }

    /**
     * @return array{summary: string, pending: string}
     */
    private function onScreen(mixed $payload, ?FinderVehicle $vehicle): array
    {
        if (null === $vehicle) {
            throw new InvalidTurnInput('onScreen needs the vehicle it was narrowed for');
        }
        $categoryId = \is_array($payload) ? ($payload['categoryId'] ?? null) : null;
        $productGroupId = \is_array($payload) ? ($payload['productGroupId'] ?? null) : null;
        $clusterId = \is_array($payload) ? ($payload['clusterId'] ?? null) : null;
        if (!\is_int($categoryId) || $categoryId <= 0 || !(null === $productGroupId || (\is_int($productGroupId) && $productGroupId > 0))) {
            throw new InvalidTurnInput('onScreen needs an integer categoryId (and productGroupId)');
        }
        if (null !== $clusterId && (!\is_string($clusterId) || 1 !== preg_match(self::CLUSTER_ID, $clusterId))) {
            throw new InvalidTurnInput('onScreen.clusterId must be a cluster id such as "c1"');
        }

        $narrowed = $this->narrower->narrow($vehicle->carId, $vehicle->carType, $categoryId, $vehicle->make, $productGroupId)->toArray();
        if ($narrowed['groupRequired'] || [] === $narrowed['clusters']) {
            throw new InvalidTurnInput('onScreen is not a catalogue result for this vehicle');
        }
        if (null !== $clusterId && !\in_array($clusterId, array_column($narrowed['clusters'], 'id'), true)) {
            throw new InvalidTurnInput(\sprintf('onScreen.clusterId %s is not a cluster of this result', $clusterId));
        }
        $chosen = null === $clusterId ? null : $this->chosenBy($payload, $vehicle, $categoryId, $clusterId);

        $several = \count($narrowed['clusters']) > 1;
        $scope = ApplicabilityScope::from($narrowed['applicabilityScope']);
        $groupId = $narrowed['productGroup']['id'] ?? $productGroupId;
        $count = \count($narrowed['clusters']);
        $plural = $count > 1 ? 's' : '';
        $text = \sprintf(
            '%s (categoryId %d%s) : %d pièce%s distincte%s%s.',
            $narrowed['productGroup']['name'] ?? '',
            $categoryId,
            null === $groupId ? '' : ', productGroupId '.$groupId,
            $count,
            $plural,
            $plural,
            null === $chosen ? '' : \sprintf(', %s : [%s]', $chosen, $clusterId),
        );
        foreach ($narrowed['clusters'] as $c) {
            $parts = [\sprintf('[%s] %s %s', $c['id'], $c['representative']['brand'], $c['representative']['number'])];
            $oe = array_map(static fn (array $r): string => $r['brand'].' '.$r['number'], \array_slice($c['oeNumbers'], 0, self::MAX_OE));
            if ([] !== $oe) {
                $parts[] = 'OE '.implode(', ', $oe);
            }
            if ($several) {
                $parts[] = 'applicabilité : '.ClusterApplicability::line($c, $scope, $this->locale->current());
            }
            $text .= ' '.implode(' — ', $parts).'.';
        }
        $differs = array_map(FinderRenderTools::differsLine(...), $narrowed['differsOn']);
        if ([] !== $differs) {
            $text .= ' Diffèrent par : '.implode(' ; ', $differs).'.';
        }

        $pending = $several && null === $chosen
            ? 'question affichée : choisir la variante'.([] === $narrowed['differsOn'] ? '' : ' selon '.implode(', ', array_column($narrowed['differsOn'], 'label')))
            : '';

        return ['summary' => $text, 'pending' => $pending];
    }

    private function chosenBy(mixed $payload, FinderVehicle $vehicle, int $categoryId, string $clusterId): string
    {
        $by = \is_array($payload) ? ($payload['chosenBy'] ?? 'user') : 'user';
        if (isset(self::CHOSEN_BY[$by])) {
            return self::CHOSEN_BY[$by];
        }
        $id = \is_array($payload) ? ($payload['selectionId'] ?? null) : null;
        $selection = 'agent' === $by && \is_string($id) && Ulid::isValid($id) ? $this->dm->find(FinderSelection::class, $id) : null;
        if (!$selection instanceof FinderSelection || $selection->carId !== $vehicle->carId || $selection->categoryId !== $categoryId || $selection->clusterId !== $clusterId) {
            throw new InvalidTurnInput('onScreen.chosenBy is answer, user, or agent with the selectionId of that variant');
        }

        return \sprintf("retenue d'après %s, indication", $selection->basis);
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
