<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Libraries take likes (Cheers), favourites and comments (Ketura, 2026-11-09)
 */
final class Version20261109040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Library likes, favourites and comments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE library_vote (library_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_FCDF4F09FE2541D7 (library_id), INDEX IDX_FCDF4F09A76ED395 (user_id), PRIMARY KEY(library_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE library_favorite (library_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4E667FCDFE2541D7 (library_id), INDEX IDX_4E667FCDA76ED395 (user_id), PRIMARY KEY(library_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE library_comment (id INT AUTO_INCREMENT NOT NULL, library_id INT NOT NULL, user_id INT NOT NULL, text LONGTEXT NOT NULL, date_creation DATETIME NOT NULL, is_hidden TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_EEB5EA5CFE2541D7 (library_id), INDEX IDX_EEB5EA5CA76ED395 (user_id), INDEX library_comment_library_idx (library_id, date_creation), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE library_vote ADD CONSTRAINT FK_FCDF4F09FE2541D7 FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_vote ADD CONSTRAINT FK_FCDF4F09A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_favorite ADD CONSTRAINT FK_4E667FCDFE2541D7 FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_favorite ADD CONSTRAINT FK_4E667FCDA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_comment ADD CONSTRAINT FK_EEB5EA5CFE2541D7 FOREIGN KEY (library_id) REFERENCES library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_comment ADD CONSTRAINT FK_EEB5EA5CA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library ADD nb_votes INT DEFAULT 0 NOT NULL, ADD nb_favorites INT DEFAULT 0 NOT NULL, ADD nb_comments INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_vote DROP FOREIGN KEY FK_FCDF4F09FE2541D7');
        $this->addSql('ALTER TABLE library_vote DROP FOREIGN KEY FK_FCDF4F09A76ED395');
        $this->addSql('ALTER TABLE library_favorite DROP FOREIGN KEY FK_4E667FCDFE2541D7');
        $this->addSql('ALTER TABLE library_favorite DROP FOREIGN KEY FK_4E667FCDA76ED395');
        $this->addSql('ALTER TABLE library_comment DROP FOREIGN KEY FK_EEB5EA5CFE2541D7');
        $this->addSql('ALTER TABLE library_comment DROP FOREIGN KEY FK_EEB5EA5CA76ED395');
        $this->addSql('DROP TABLE library_vote');
        $this->addSql('DROP TABLE library_favorite');
        $this->addSql('DROP TABLE library_comment');
        $this->addSql('ALTER TABLE library DROP nb_votes, DROP nb_favorites, DROP nb_comments');
    }
}
