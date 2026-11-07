<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lines of versions, withdrawing, and kept descriptions (Ketura, 2026-11-07).
 * Decklists published from the same private deck become one line, numbered by publish
 * date; every other decklist, archive decks included, is a line of one.
 */
final class Version20261107010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'decklist_line, published_deck line/version/withdrawn/date_published, published_deck_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE decklist_line (id INT AUTO_INCREMENT NOT NULL, date_creation DATETIME NOT NULL, origin_id INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE published_deck ADD line_id INT DEFAULT NULL, ADD version_number INT DEFAULT NULL, ADD withdrawn TINYINT(1) DEFAULT 0 NOT NULL, ADD date_published DATETIME DEFAULT NULL');

        // one line per private deck published from, and one per decklist with none;
        // origin_id is the line's first decklist, there only while the lines are filled
        $this->addSql('INSERT INTO decklist_line (date_creation, origin_id)
            SELECT MIN(p.date_creation), MIN(p.id) FROM published_deck p
            GROUP BY COALESCE(CONCAT(\'deck\', p.parent_deck_id), CONCAT(\'list\', p.id))');
        $this->addSql('UPDATE published_deck p
            JOIN published_deck o ON (p.parent_deck_id IS NOT NULL AND o.parent_deck_id = p.parent_deck_id) OR (p.parent_deck_id IS NULL AND o.id = p.id)
            JOIN decklist_line l ON l.origin_id = o.id
            SET p.line_id = l.id');
        $this->addSql('UPDATE published_deck p
            JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY line_id ORDER BY date_creation, id) AS n FROM published_deck) x ON x.id = p.id
            SET p.version_number = x.n');
        $this->addSql('UPDATE published_deck p SET p.date_published = p.date_creation WHERE NOT EXISTS (SELECT 1 FROM published_deck_source s WHERE s.published_deck_id = p.id)');

        $this->addSql('ALTER TABLE decklist_line DROP origin_id');
        $this->addSql('ALTER TABLE published_deck DROP version');
        $this->addSql('ALTER TABLE published_deck CHANGE version_number version INT NOT NULL, CHANGE line_id line_id INT NOT NULL');
        $this->addSql('ALTER TABLE published_deck ADD CONSTRAINT FK_PUBLISHED_DECK_LINE FOREIGN KEY (line_id) REFERENCES decklist_line (id)');
        $this->addSql('CREATE INDEX IDX_7B3A07E64D7B7542 ON published_deck (line_id)');
        $this->addSql('CREATE UNIQUE INDEX published_deck_line_version ON published_deck (line_id, version)');

        $this->addSql('CREATE TABLE published_deck_revision (id INT AUTO_INCREMENT NOT NULL, published_deck_id INT NOT NULL, name VARCHAR(60) NOT NULL, description_md LONGTEXT DEFAULT NULL, date_replaced DATETIME NOT NULL, INDEX IDX_6DBCA6CA4263262C (published_deck_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE published_deck_revision ADD CONSTRAINT FK_PUBLISHED_DECK_REVISION_DECK FOREIGN KEY (published_deck_id) REFERENCES published_deck (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('the old version labels are gone');
    }
}
