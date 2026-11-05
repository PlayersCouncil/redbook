<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fires (Ketura, 2026-11-04): a liker's super-upvote on a published deck, kept per user in
 * the fire table and counted in published_deck.nb_fires.
 */
final class Version20261104000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fires on published decks: the fire table and published_deck.nb_fires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fire (published_deck_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_58DE09CF4263262C (published_deck_id), INDEX IDX_58DE09CFA76ED395 (user_id), PRIMARY KEY(published_deck_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fire ADD CONSTRAINT FK_58DE09CF4263262C FOREIGN KEY (published_deck_id) REFERENCES published_deck (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fire ADD CONSTRAINT FK_58DE09CFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE published_deck ADD nb_fires INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fire');
        $this->addSql('ALTER TABLE published_deck DROP nb_fires');
    }
}
