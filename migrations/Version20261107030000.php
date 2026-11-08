<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A decklist's own framing for its thumbnail card (Ketura, 2026-11-07), set with the
 * publish form's crop editor: one, for the last card adjusted.
 */
final class Version20261107030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck.thumbnail_crop';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck ADD thumbnail_crop JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck DROP thumbnail_crop');
    }
}
