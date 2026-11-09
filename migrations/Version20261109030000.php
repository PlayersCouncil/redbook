<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A library's thumbnail framed by hand, or a banner of its own cultures (Ketura, 2026-11-09)
 */
final class Version20261109030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'library.thumbnail_crop, library.thumbnail_cultures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library ADD thumbnail_crop JSON DEFAULT NULL, ADD thumbnail_cultures JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library DROP thumbnail_crop, DROP thumbnail_cultures');
    }
}
