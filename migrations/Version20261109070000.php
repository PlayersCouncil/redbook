<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Library history (Ketura, 2026-11-09): a revision for each change, with who made it, how,
 * the library's contents after it, and what changed
 */
final class Version20261109070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'library_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE library_revision (id INT AUTO_INCREMENT NOT NULL, library_id INT NOT NULL, user_id INT DEFAULT NULL, kind VARCHAR(20) NOT NULL, snapshot JSON NOT NULL COMMENT '(DC2Type:json)', changes JSON DEFAULT NULL COMMENT '(DC2Type:json)', date_creation DATETIME NOT NULL, INDEX IDX_4BC0E4D8FE2541D7 (library_id), INDEX IDX_4BC0E4D8A76ED395 (user_id), INDEX library_revision_library_idx (library_id, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE library_revision ADD CONSTRAINT FK_4BC0E4D8FE2541D7 FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_revision ADD CONSTRAINT FK_4BC0E4D8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE library_revision');
    }
}
