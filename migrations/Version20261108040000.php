<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cards any version will do for (Ketura, 2026-11-08): "any Frodo", "Legolas (any)", "any site 3"
 */
final class Version20261108040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'deck_slot.generic, published_deck_slot.generic';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_slot ADD generic TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE published_deck_slot ADD generic TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_slot DROP generic');
        $this->addSql('ALTER TABLE published_deck_slot DROP generic');
    }
}
