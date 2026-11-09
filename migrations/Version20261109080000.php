<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Organization accounts (Ketura, 2026-11-09): Decipher's, the PC's; nobody logs in as one
 */
final class Version20261109080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.organization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD organization TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP organization');
    }
}
