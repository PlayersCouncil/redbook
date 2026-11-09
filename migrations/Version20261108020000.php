<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deck and decklist numbering (Ketura, 2026-11-08): a decklist's detail edits are patches
 * (2.0.1), recorded with what they changed and who made them; a published deck is X.0,
 * X being its latest decklist's version, and its private revisions X.1, X.2.
 */
final class Version20261108020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck.patch; published_deck_revision patch, changed, editor; decks renumbered after their publications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck ADD patch INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE published_deck_revision ADD editor_id INT DEFAULT NULL, ADD patch INT DEFAULT NULL, ADD changed JSON DEFAULT NULL, CHANGE name name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE published_deck_revision ADD CONSTRAINT FK_6DBCA6CA6995AC4C FOREIGN KEY (editor_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6DBCA6CA6995AC4C ON published_deck_revision (editor_id)');
        /*
         * Publishing used to make a deck (X+1).1 and every save count up; now it's X.0, X the
         * decklist's version, and only saves that change something count. So a deck counts
         * down one: X.1, just published, becomes X.0. An archive deck's saves are historians'
         * corrections, which don't count.
         */
        $this->addSql(<<<'SQL'
            UPDATE deck d
            JOIN (
                SELECT p.parent_deck_id AS deck_id, p.version
                FROM published_deck p
                WHERE p.id = (
                    SELECT p2.id FROM published_deck p2
                    WHERE p2.parent_deck_id = p.parent_deck_id
                    ORDER BY p2.date_creation DESC, p2.id DESC
                    LIMIT 1
                )
            ) latest ON latest.deck_id = d.id
            JOIN user u ON u.id = d.user_id
            SET d.major_version = latest.version,
                d.minor_version = IF(u.historic = 1, 0, GREATEST(d.minor_version - 1, 0))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck_revision DROP FOREIGN KEY FK_6DBCA6CA6995AC4C');
        $this->addSql('DROP INDEX IDX_6DBCA6CA6995AC4C ON published_deck_revision');
        $this->addSql('ALTER TABLE published_deck_revision DROP editor_id, DROP patch, DROP changed, CHANGE name name VARCHAR(60) NOT NULL');
        $this->addSql('ALTER TABLE published_deck DROP patch');
    }
}
