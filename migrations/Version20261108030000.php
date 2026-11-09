<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Decks (Ketura, 2026-11-08): how each came to be, for its history; and deleted ones kept,
 * when published, so Recreate can bring them back.
 */
final class Version20261108030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'deck.origin, deck.date_deleted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck ADD origin JSON DEFAULT NULL, ADD date_deleted DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP origin, DROP date_deleted');
    }
}
