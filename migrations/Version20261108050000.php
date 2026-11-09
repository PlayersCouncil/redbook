<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A decklist derived from several decks (Ketura, 2026-11-08): the one precedent becomes a list
 */
final class Version20261108050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck_precedent, from published_deck.precedent_published_deck_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE published_deck_precedent (published_deck_id INT NOT NULL, precedent_id INT NOT NULL, INDEX IDX_41CE47084263262C (published_deck_id), INDEX IDX_41CE47084F6564F9 (precedent_id), PRIMARY KEY(published_deck_id, precedent_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE published_deck_precedent ADD CONSTRAINT FK_41CE47084263262C FOREIGN KEY (published_deck_id) REFERENCES published_deck (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE published_deck_precedent ADD CONSTRAINT FK_41CE47084F6564F9 FOREIGN KEY (precedent_id) REFERENCES published_deck (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO published_deck_precedent (published_deck_id, precedent_id) SELECT id, precedent_published_deck_id FROM published_deck WHERE precedent_published_deck_id IS NOT NULL AND precedent_published_deck_id <> id');
        $this->addSql('ALTER TABLE published_deck DROP FOREIGN KEY FK_ED030EC6C386FA95');
        $this->addSql('DROP INDEX IDX_7B3A07E6598FBA23 ON published_deck');
        $this->addSql('ALTER TABLE published_deck DROP precedent_published_deck_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck ADD precedent_published_deck_id INT DEFAULT NULL');
        $this->addSql('UPDATE published_deck d JOIN (SELECT published_deck_id, MIN(precedent_id) AS precedent_id FROM published_deck_precedent GROUP BY published_deck_id) p ON p.published_deck_id = d.id SET d.precedent_published_deck_id = p.precedent_id');
        $this->addSql('CREATE INDEX IDX_7B3A07E6598FBA23 ON published_deck (precedent_published_deck_id)');
        $this->addSql('ALTER TABLE published_deck ADD CONSTRAINT FK_ED030EC6C386FA95 FOREIGN KEY (precedent_published_deck_id) REFERENCES published_deck (id)');
        $this->addSql('DROP TABLE published_deck_precedent');
    }
}
