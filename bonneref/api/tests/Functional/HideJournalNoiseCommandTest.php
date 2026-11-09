<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class HideJournalNoiseCommandTest extends AppTestCase
{
    public function testItListsNoiseWithoutChangingAnythingByDefault(): void
    {
        $this->seedRows();

        $tester = $this->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('3', $tester->getDisplay());
        $this->assertSame([], $this->hidden());
    }

    public function testApplyHidesOnlyPathLikeAndNumericFinderRows(): void
    {
        $this->seedRows();

        $this->execute(['--apply' => true]);

        $this->assertEqualsCanonicalizing(['100259', '/vehicles/XB301ZB', '/cars/16182/part-categories/100259/narrowed'], $this->hidden());
    }

    private function seedRows(): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        foreach ([['100259', TurnSurface::Finder], ['/vehicles/XB301ZB', TurnSurface::Finder], ['/cars/16182/part-categories/100259/narrowed', TurnSurface::Finder], ['filtre à huile', TurnSurface::Finder], ['2008 Peugeot', TurnSurface::Finder]] as [$instruction, $surface]) {
            $turn = new AgentTurn(self::TEST_OWNER, 'sess', $instruction, 'ctx', 'anthropic', 'claude-sonnet-5-5', true);
            $turn->setSurface($surface);
            $turn->finish(AgentTurnStatus::Completed, null);
            $dm->persist($turn);
        }
        $dm->flush();
        $dm->clear();
    }

    /**
     * @return list<string>
     */
    private function hidden(): array
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $dm->clear();

        return array_values(array_map(
            static fn (AgentTurn $t): string => $t->getInstruction(),
            array_filter($dm->getRepository(AgentTurn::class)->findBy(['owner' => self::TEST_OWNER]), static fn (AgentTurn $t): bool => $t->isHiddenFromHistory()),
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(static::bootKernel()))->find('app:journal:hide-noise'));
        $tester->execute($options);

        return $tester;
    }
}
