<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeded libraries (Ketura, 2026-11-09): where one came from, so a re-import updates it in place
 */
final class Version20261109050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'library.seed_source, library.seed_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library ADD seed_source VARCHAR(30) DEFAULT NULL, ADD seed_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX library_seed_idx ON library (seed_source, seed_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX library_seed_idx ON library');
        $this->addSql('ALTER TABLE library DROP seed_source, DROP seed_id');
    }
}
