<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A historian's edit in a deck's history (Ketura, 2026-11-08): who made it, and what it changed
 */
final class Version20261108010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'deck_revision.editor_id, deck_revision.edit_summary';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_revision ADD editor_id INT DEFAULT NULL, ADD edit_summary JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE deck_revision ADD CONSTRAINT FK_DECK_REVISION_EDITOR FOREIGN KEY (editor_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3454A6EE6995AC4C ON deck_revision (editor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck_revision DROP FOREIGN KEY FK_DECK_REVISION_EDITOR');
        $this->addSql('DROP INDEX IDX_3454A6EE6995AC4C ON deck_revision');
        $this->addSql('ALTER TABLE deck_revision DROP editor_id, DROP edit_summary');
    }
}
