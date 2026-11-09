<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Libraries (Ketura, 2026-11-08): collections of published decklists with commentary
 */
final class Version20261109010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'library and library_entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE library (id INT AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)', public_id INT DEFAULT NULL, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, name_canonical VARCHAR(255) NOT NULL, description_md LONGTEXT DEFAULT NULL, description_html LONGTEXT DEFAULT NULL, notes_md LONGTEXT DEFAULT NULL, notes_html LONGTEXT DEFAULT NULL, tags VARCHAR(4000) DEFAULT NULL, visibility VARCHAR(10) NOT NULL, official TINYINT(1) DEFAULT 0 NOT NULL, date_creation DATETIME NOT NULL, date_update DATETIME NOT NULL, date_public DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A18098BCD17F50A6 (uuid), UNIQUE INDEX UNIQ_A18098BCB5B48B91 (public_id), INDEX IDX_A18098BCA76ED395 (user_id), INDEX library_visibility_idx (visibility, date_update), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE library_entry (id INT AUTO_INCREMENT NOT NULL, library_id INT NOT NULL, published_deck_id INT NOT NULL, position INT NOT NULL, annotation_md LONGTEXT DEFAULT NULL, annotation_html LONGTEXT DEFAULT NULL, date_added DATETIME NOT NULL, INDEX IDX_18844F5FFE2541D7 (library_id), INDEX IDX_18844F5F4263262C (published_deck_id), UNIQUE INDEX library_entry_decklist (library_id, published_deck_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE library ADD CONSTRAINT FK_A18098BCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_entry ADD CONSTRAINT FK_18844F5FFE2541D7 FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_entry ADD CONSTRAINT FK_18844F5F4263262C FOREIGN KEY (published_deck_id) REFERENCES published_deck (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE library_entry');
        $this->addSql('DROP TABLE library');
    }
}
