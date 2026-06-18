<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617221309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add usage_value and usage_unit columns to vehicle for update_vehicle usage readings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vehicle ADD usage_value INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD usage_unit VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vehicle DROP usage_value');
        $this->addSql('ALTER TABLE vehicle DROP usage_unit');
    }
}
