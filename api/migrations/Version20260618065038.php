<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618065038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create planned_maintenance: the open-task checklist per vehicle (intended work + its parts), no UNIQUE on (vehicle, type) — dedup is logical in the planner.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE planned_maintenance (id UUID NOT NULL, type VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, label VARCHAR(255) DEFAULT NULL, target_usage_value INT DEFAULT NULL, target_usage_unit VARCHAR(255) DEFAULT NULL, due_date DATE DEFAULT NULL, parts JSON NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, added_by VARCHAR(255) DEFAULT NULL, owner VARCHAR(255) DEFAULT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D7E1BE00545317D1 ON planned_maintenance (vehicle_id)');
        $this->addSql('CREATE INDEX idx_planned_vehicle_status ON planned_maintenance (vehicle_id, status)');
        $this->addSql('ALTER TABLE planned_maintenance ADD CONSTRAINT FK_D7E1BE00545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planned_maintenance DROP CONSTRAINT FK_D7E1BE00545317D1');
        $this->addSql('DROP TABLE planned_maintenance');
    }
}
