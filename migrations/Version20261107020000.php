<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A decklist's own tags (Ketura, 2026-11-07), taken from the deck it was published from;
 * they were read from that deck, so changing or deleting the deck changed them.
 */
final class Version20261107020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck.tags, from the deck each was published from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck ADD tags VARCHAR(4000) DEFAULT NULL');
        $this->addSql("UPDATE published_deck p JOIN deck d ON d.id = p.parent_deck_id SET p.tags = NULLIF(TRIM(d.tags), '')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck DROP tags');
    }
}
