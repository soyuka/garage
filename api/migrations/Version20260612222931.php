<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612222931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce one fact per (vehicle, fact_key): UNIQUE index so the resolver updates in place instead of accumulating duplicate, shadowing facts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_vehicle_fact_key ON vehicle_fact (vehicle_id, fact_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_vehicle_fact_key');
    }
}
