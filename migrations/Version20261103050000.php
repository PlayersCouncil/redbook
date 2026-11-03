<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Companions marked as the starting fellowship (Ketura, 2026-11-03), on decks and
 * published decks alike: a list of blueprint IDs.
 */
final class Version20261103050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Starting fellowship marks on decks and published decks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD starting_fellowship JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE published_deck ADD starting_fellowship JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP starting_fellowship');
        $this->addSql('ALTER TABLE published_deck DROP starting_fellowship');
    }
}
