<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Draw deck sizes per side on published decks (Ketura, 2026-11-05), for the decklists page's
 * 30/30 column and its whole deck / fragment filters. Counted here for the decks already
 * published; PublishedDeck::updateSizes() keeps them from now on.
 */
final class Version20261105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Free Peoples and Shadow draw deck sizes on published decks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck ADD nb_free_peoples SMALLINT DEFAULT 0 NOT NULL, ADD nb_shadow SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE published_deck d JOIN (
                SELECT s.published_deck_id AS id,
                    SUM(CASE WHEN c.side = 'free_peoples' THEN s.quantity ELSE 0 END) AS fp,
                    SUM(CASE WHEN c.side = 'shadow' THEN s.quantity ELSE 0 END) AS sh
                FROM published_deck_slot s JOIN card c ON c.id = s.card_id
                WHERE s.role = 'draw'
                GROUP BY s.published_deck_id
            ) x ON x.id = d.id
            SET d.nb_free_peoples = x.fp, d.nb_shadow = x.sh
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck DROP nb_free_peoples, DROP nb_shadow');
    }
}
