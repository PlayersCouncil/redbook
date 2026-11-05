<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** A published deck's thumbnail card (Ketura, 2026-11-05) */
final class Version20261105010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'published_deck.thumbnail_card_id: the card a deck\'s thumbnail shows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck ADD thumbnail_card_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE published_deck ADD CONSTRAINT FK_7B3A07E66868F159 FOREIGN KEY (thumbnail_card_id) REFERENCES card (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7B3A07E66868F159 ON published_deck (thumbnail_card_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck DROP FOREIGN KEY FK_7B3A07E66868F159');
        $this->addSql('DROP INDEX IDX_7B3A07E66868F159 ON published_deck');
        $this->addSql('ALTER TABLE published_deck DROP thumbnail_card_id');
    }
}
