<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A library's own thumbnail (Ketura, 2026-11-08)
 */
final class Version20261109020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'library.thumbnail_card_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library ADD thumbnail_card_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE library ADD CONSTRAINT FK_A18098BC6868F159 FOREIGN KEY (thumbnail_card_id) REFERENCES card (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A18098BC6868F159 ON library (thumbnail_card_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library DROP FOREIGN KEY FK_A18098BC6868F159');
        $this->addSql('DROP INDEX IDX_A18098BC6868F159 ON library');
        $this->addSql('ALTER TABLE library DROP thumbnail_card_id');
    }
}
