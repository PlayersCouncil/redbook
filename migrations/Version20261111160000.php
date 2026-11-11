<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cheers, fire and crown (Ketura, 2026-11-11): one level per user on each decklist, library and
 * comment (1 Cheers, 2 on fire, 3 crowned) in place of the decklists' fire table and the
 * comments' fire flag; libraries can be set on fire and crowned too. Everything starts with its
 * author's own Cheers, so each gets one now (no reputation for it).
 */
final class Version20261111160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reaction levels (cheers, fire, crown); authors\' own cheers';
    }

    public function up(Schema $schema): void
    {
        // decklists: the fire table becomes level 2 in vote
        $this->addSql('ALTER TABLE vote ADD level SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE vote v JOIN fire f ON f.published_deck_id = v.published_deck_id AND f.user_id = v.user_id SET v.level = 2');
        $this->addSql('DROP TABLE fire');
        $this->addSql('ALTER TABLE published_deck ADD nb_crowns INT DEFAULT 0 NOT NULL');
        // libraries
        $this->addSql('ALTER TABLE library_vote ADD level SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE library ADD nb_fires INT DEFAULT 0 NOT NULL, ADD nb_crowns INT DEFAULT 0 NOT NULL');
        // comments: the fire flag becomes level 2
        $this->addSql('ALTER TABLE comment_reaction ADD level SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE comment_reaction SET level = 2 WHERE fire = 1');
        $this->addSql('ALTER TABLE comment_reaction DROP fire');
        $this->addSql('ALTER TABLE comment ADD nb_crowns INT DEFAULT 0 NOT NULL');

        // the authors' own Cheers: counted first, for those without one, then added
        $this->addSql('UPDATE published_deck d SET d.nb_votes = d.nb_votes + 1 WHERE NOT EXISTS (SELECT 1 FROM vote v WHERE v.published_deck_id = d.id AND v.user_id = d.user_id)');
        $this->addSql('INSERT IGNORE INTO vote (published_deck_id, user_id, level) SELECT id, user_id, 1 FROM published_deck');
        $this->addSql('UPDATE library l SET l.nb_votes = l.nb_votes + 1 WHERE NOT EXISTS (SELECT 1 FROM library_vote v WHERE v.library_id = l.id AND v.user_id = l.user_id)');
        $this->addSql('INSERT IGNORE INTO library_vote (library_id, user_id, level) SELECT id, user_id, 1 FROM library');
        $this->addSql('UPDATE comment c SET c.nb_cheers = c.nb_cheers + 1 WHERE NOT EXISTS (SELECT 1 FROM comment_reaction r WHERE r.comment_id = c.id AND r.user_id = c.user_id)');
        $this->addSql('INSERT IGNORE INTO comment_reaction (comment_id, user_id, level, date_creation) SELECT id, user_id, 1, date_creation FROM comment');
    }

    public function down(Schema $schema): void
    {
        // the authors' own Cheers out again, then the levels back to fires
        $this->addSql('DELETE r FROM comment_reaction r JOIN comment c ON c.id = r.comment_id AND c.user_id = r.user_id');
        $this->addSql('UPDATE comment c SET c.nb_cheers = (SELECT COUNT(*) FROM comment_reaction r WHERE r.comment_id = c.id)');
        $this->addSql('DELETE v FROM library_vote v JOIN library l ON l.id = v.library_id AND l.user_id = v.user_id');
        $this->addSql('UPDATE library l SET l.nb_votes = (SELECT COUNT(*) FROM library_vote v WHERE v.library_id = l.id)');
        $this->addSql('DELETE v FROM vote v JOIN published_deck d ON d.id = v.published_deck_id AND d.user_id = v.user_id');
        $this->addSql('UPDATE published_deck d SET d.nb_votes = (SELECT COUNT(*) FROM vote v WHERE v.published_deck_id = d.id)');

        $this->addSql('ALTER TABLE comment DROP nb_crowns');
        $this->addSql('ALTER TABLE comment_reaction ADD fire TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE comment_reaction SET fire = 1 WHERE level >= 2');
        $this->addSql('ALTER TABLE comment_reaction DROP level');
        $this->addSql('ALTER TABLE library DROP nb_fires, DROP nb_crowns');
        $this->addSql('ALTER TABLE library_vote DROP level');
        $this->addSql('ALTER TABLE published_deck DROP nb_crowns');
        $this->addSql('CREATE TABLE fire (published_deck_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_58DE09CF4263262C (published_deck_id), INDEX IDX_58DE09CFA76ED395 (user_id), PRIMARY KEY(published_deck_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fire ADD CONSTRAINT FK_58DE09CF4263262C FOREIGN KEY (published_deck_id) REFERENCES published_deck (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fire ADD CONSTRAINT FK_58DE09CFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO fire (published_deck_id, user_id) SELECT published_deck_id, user_id FROM vote WHERE level >= 2');
        $this->addSql('ALTER TABLE vote DROP level');
    }
}
