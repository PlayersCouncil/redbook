<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Official libraries copied from a user's (Ketura, 2026-11-09): who curated it, and the library
 * it was copied from
 */
final class Version20261109060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'library.curator_id, library.promoted_from_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library ADD curator_id INT DEFAULT NULL, ADD promoted_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE library ADD CONSTRAINT FK_A18098BC733D5B5D FOREIGN KEY (curator_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE library ADD CONSTRAINT FK_A18098BC45493805 FOREIGN KEY (promoted_from_id) REFERENCES library (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A18098BC733D5B5D ON library (curator_id)');
        $this->addSql('CREATE INDEX IDX_A18098BC45493805 ON library (promoted_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library DROP FOREIGN KEY FK_A18098BC733D5B5D');
        $this->addSql('ALTER TABLE library DROP FOREIGN KEY FK_A18098BC45493805');
        $this->addSql('DROP INDEX IDX_A18098BC733D5B5D ON library');
        $this->addSql('DROP INDEX IDX_A18098BC45493805 ON library');
        $this->addSql('ALTER TABLE library DROP curator_id, DROP promoted_from_id');
    }
}
