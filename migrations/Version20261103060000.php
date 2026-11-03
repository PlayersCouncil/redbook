<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Notes tab (Ketura, 2026-11-03): starting notes (the bid and free text) and card
 * annotations, on decks and published decks alike.
 */
final class Version20261103060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Starting bid, starting notes and card annotations on decks and published decks';
    }

    public function up(Schema $schema): void
    {
        foreach (['deck', 'published_deck'] as $table) {
            $this->addSql("ALTER TABLE $table ADD starting_bid VARCHAR(30) DEFAULT NULL, ADD starting_notes LONGTEXT DEFAULT NULL, ADD annotations JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['deck', 'published_deck'] as $table) {
            $this->addSql("ALTER TABLE $table DROP starting_bid, DROP starting_notes, DROP annotations");
        }
    }
}
