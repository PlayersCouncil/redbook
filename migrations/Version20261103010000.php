<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historical Deck Information on decks (the deckbuilder's collapsed section).
 */
final class Version20261103010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historical Deck Information on decks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD historic JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP historic');
    }
}
