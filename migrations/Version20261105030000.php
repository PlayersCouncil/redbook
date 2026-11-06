<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Thumbnails' cultures again, now noting the side each was counted on (Ketura, 2026-11-05):
 * Gollum's colours and framing differ by side, a fragment's included.
 */
final class Version20261105030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck.thumbnail_cultures with the side of each culture';
    }

    public function up(Schema $schema): void
    {
        // nothing to change in the schema; postUp works the cultures out again
        $this->addSql('SELECT 1');
    }

    public function postUp(Schema $schema): void
    {
        Version20261105020000::fill($this->connection);
    }

    public function down(Schema $schema): void
    {
    }
}
