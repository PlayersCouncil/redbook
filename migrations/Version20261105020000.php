<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\PublishedDeck;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The cultures a published deck's thumbnail shows (Ketura, 2026-11-05), picked from the draw
 * deck's card counts by side so that Gollum can stand on either side. Worked out here for the
 * decks already published; PublishedDeck::updateSizes() keeps them from now on.
 */
final class Version20261105020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck.thumbnail_cultures, for the decks already published too';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE published_deck ADD thumbnail_cultures JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT s.published_deck_id AS deck, c.side, cu.code, cu.name, SUM(s.quantity) AS n
            FROM published_deck_slot s
            JOIN card c ON c.id = s.card_id
            JOIN culture cu ON cu.id = c.culture_id
            WHERE s.role = 'draw' AND c.side IS NOT NULL
            GROUP BY s.published_deck_id, c.side, cu.code, cu.name
            SQL);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['deck']][$row['side']][$row['code']] = ['name' => $row['name'], 'count' => (int) $row['n']];
        }
        foreach ($counts as $deck => $bySide) {
            $this->connection->executeStatement(
                'UPDATE published_deck SET thumbnail_cultures = ? WHERE id = ?',
                [json_encode(PublishedDeck::pickThumbnailCultures($bySide)), $deck]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck DROP thumbnail_cultures');
    }
}
