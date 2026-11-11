<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The moderation log (Ketura, 2026-11-11): deleting someone else's comment or decklist needs a
 * reason, kept here with who did it and what it was
 */
final class Version20261111140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'moderation_action';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE moderation_action (id INT AUTO_INCREMENT NOT NULL, actor_id INT DEFAULT NULL, owner_id INT DEFAULT NULL, action VARCHAR(40) NOT NULL, subject_type VARCHAR(20) NOT NULL, subject_id INT NOT NULL, summary VARCHAR(255) NOT NULL, reason LONGTEXT NOT NULL, date_creation DATETIME NOT NULL, INDEX IDX_B05D812810DAF24A (actor_id), INDEX IDX_B05D81287E3C61F9 (owner_id), INDEX moderation_action_subject_idx (subject_type, subject_id), INDEX moderation_action_date_idx (date_creation), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D812810DAF24A FOREIGN KEY (actor_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D81287E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_action');
    }
}
