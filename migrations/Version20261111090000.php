<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comments as threads (Ketura, 2026-11-11): decklists' and libraries' in one table (library
 * comments move over from library_comment, which goes), replies, the text as written, edits
 * with their history, deleting that keeps replies in place, Cheers and fire, reports; and
 * users' avatars.
 */
final class Version20261111090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Threaded comments (comment.library_id, parent_id, text_md, edits, deletes, cheers), comment_revision, comment_reaction, comment_report, user avatar; library_comment merged into comment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment ADD deleted_by_id INT DEFAULT NULL, ADD library_id INT DEFAULT NULL, ADD parent_id INT DEFAULT NULL, ADD text_md LONGTEXT DEFAULT NULL, ADD date_edited DATETIME DEFAULT NULL, ADD date_deleted DATETIME DEFAULT NULL, ADD nb_cheers INT DEFAULT 0 NOT NULL, ADD nb_fires INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CC76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CFE2541D7 FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C727ACA70 FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9474526CC76F1F52 ON comment (deleted_by_id)');
        $this->addSql('CREATE INDEX IDX_9474526CFE2541D7 ON comment (library_id)');
        $this->addSql('CREATE INDEX comment_parent_idx ON comment (parent_id)');

        // libraries' comments join the decklists' (their ids change; nothing links to them yet)
        $this->addSql('INSERT INTO comment (user_id, library_id, text, date_creation, is_hidden) SELECT user_id, library_id, text, date_creation, is_hidden FROM library_comment ORDER BY id');
        $this->addSql('DROP TABLE library_comment');

        $this->addSql("CREATE TABLE comment_revision (id INT AUTO_INCREMENT NOT NULL, comment_id INT NOT NULL, user_id INT DEFAULT NULL, text_md LONGTEXT DEFAULT NULL, text LONGTEXT NOT NULL, date_creation DATETIME NOT NULL, INDEX IDX_702776CAF8697D13 (comment_id), INDEX IDX_702776CAA76ED395 (user_id), INDEX comment_revision_comment_idx (comment_id, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE comment_revision ADD CONSTRAINT FK_702776CAF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_revision ADD CONSTRAINT FK_702776CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE comment_reaction (id INT AUTO_INCREMENT NOT NULL, comment_id INT NOT NULL, user_id INT NOT NULL, fire TINYINT(1) DEFAULT 0 NOT NULL, date_creation DATETIME NOT NULL, INDEX IDX_B99364F1F8697D13 (comment_id), INDEX comment_reaction_user_idx (user_id), UNIQUE INDEX comment_reaction_user (comment_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE comment_reaction ADD CONSTRAINT FK_B99364F1F8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_reaction ADD CONSTRAINT FK_B99364F1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE comment_report (id INT AUTO_INCREMENT NOT NULL, comment_id INT NOT NULL, user_id INT NOT NULL, resolved_by_id INT DEFAULT NULL, reason LONGTEXT NOT NULL, date_creation DATETIME NOT NULL, date_resolved DATETIME DEFAULT NULL, INDEX IDX_E3C2F96F8697D13 (comment_id), INDEX IDX_E3C2F96A76ED395 (user_id), INDEX IDX_E3C2F966713A32B (resolved_by_id), INDEX comment_report_open_idx (date_resolved, date_creation), UNIQUE INDEX comment_report_user (comment_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96F8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F966713A32B FOREIGN KEY (resolved_by_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql("ALTER TABLE user ADD avatar_card_id INT DEFAULT NULL, ADD avatar_culture_id INT DEFAULT NULL, ADD avatar_crop JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6499CA295FE FOREIGN KEY (avatar_card_id) REFERENCES card (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D6491B8D6CE1 FOREIGN KEY (avatar_culture_id) REFERENCES culture (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D6499CA295FE ON user (avatar_card_id)');
        $this->addSql('CREATE INDEX IDX_8D93D6491B8D6CE1 ON user (avatar_culture_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Library comments were merged into comment; restore from a backup instead.');
    }
}
