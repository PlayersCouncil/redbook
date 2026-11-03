<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keep the archive scraper's parse notes (issues, loosely matched lines) and the
 * related decks it found (same list or near copies elsewhere), for the review
 * tab and for merging decks later.
 */
final class Version20261103040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Archive parse notes and related decks on published_deck_source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck_source ADD parse JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD related JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck_source DROP parse, DROP related');
    }
}
