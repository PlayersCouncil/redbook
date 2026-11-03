<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historic accounts, display names, the archive review queue (Ketura, 2026-11-02).
 *
 * Archive authors become user accounts that can't log in (historic); a person claims
 * one through historic_claim, which replaces the per-decklist decklist_claim.
 * Published decks get a review status for the historians.
 */
final class Version20261103030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historic accounts and display names; archive review status; historic_claim replaces decklist_claim';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD merged_into_id INT DEFAULT NULL, ADD display_name VARCHAR(100) DEFAULT NULL, ADD historic TINYINT(1) DEFAULT 0 NOT NULL, ADD historic_key VARCHAR(255) DEFAULT NULL, ADD historic_source VARCHAR(50) DEFAULT NULL, ADD aliases JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649285E57BA FOREIGN KEY (merged_into_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649ABC99533 ON user (historic_key)');
        $this->addSql('CREATE INDEX IDX_8D93D649285E57BA ON user (merged_into_id)');

        $this->addSql('ALTER TABLE published_deck ADD reviewed_by_id INT DEFAULT NULL, ADD review_status VARCHAR(12) DEFAULT NULL, ADD reviewed_at DATETIME DEFAULT NULL, ADD review_flags JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE published_deck ADD CONSTRAINT FK_7B3A07E6FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7B3A07E6FC6B21F1 ON published_deck (reviewed_by_id)');
        $this->addSql('CREATE INDEX published_deck_review_idx ON published_deck (review_status, reviewed_at)');
        // decks already seeded from the archive wait for review like newly imported ones
        $this->addSql('UPDATE published_deck d JOIN published_deck_source s ON s.published_deck_id = d.id SET d.review_status = \'unverified\'');

        $this->addSql('CREATE TABLE historic_claim (id INT AUTO_INCREMENT NOT NULL, historic_user_id INT NOT NULL, user_id INT NOT NULL, decided_by_id INT DEFAULT NULL, message LONGTEXT DEFAULT NULL, status VARCHAR(10) NOT NULL, date_creation DATETIME NOT NULL, date_decision DATETIME DEFAULT NULL, INDEX IDX_EDA680457C76EC8C (historic_user_id), INDEX IDX_EDA68045A76ED395 (user_id), INDEX IDX_EDA68045E26B496B (decided_by_id), INDEX historic_claim_status_idx (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE historic_claim ADD CONSTRAINT FK_EDA680457C76EC8C FOREIGN KEY (historic_user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE historic_claim ADD CONSTRAINT FK_EDA68045A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE historic_claim ADD CONSTRAINT FK_EDA68045E26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL');
        // claims were on single decklists owned by the old "archive" account; they don't carry over
        $this->addSql('DROP TABLE decklist_claim');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE decklist_claim (id INT AUTO_INCREMENT NOT NULL, published_deck_id INT NOT NULL, user_id INT NOT NULL, decided_by_id INT DEFAULT NULL, message LONGTEXT DEFAULT NULL, status VARCHAR(10) NOT NULL, date_creation DATETIME NOT NULL, date_decision DATETIME DEFAULT NULL, INDEX IDX_E65F9D6E4263262C (published_deck_id), INDEX IDX_E65F9D6EA76ED395 (user_id), INDEX IDX_E65F9D6EE26B496B (decided_by_id), INDEX decklist_claim_status_idx (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE decklist_claim ADD CONSTRAINT FK_E65F9D6EF4E9531B FOREIGN KEY (published_deck_id) REFERENCES published_deck (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_claim ADD CONSTRAINT FK_E65F9D6EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_claim ADD CONSTRAINT FK_E65F9D6EE26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('DROP TABLE historic_claim');

        $this->addSql('ALTER TABLE published_deck DROP FOREIGN KEY FK_7B3A07E6FC6B21F1');
        $this->addSql('DROP INDEX IDX_7B3A07E6FC6B21F1 ON published_deck');
        $this->addSql('DROP INDEX published_deck_review_idx ON published_deck');
        $this->addSql('ALTER TABLE published_deck DROP reviewed_by_id, DROP review_status, DROP reviewed_at, DROP review_flags');

        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649285E57BA');
        $this->addSql('DROP INDEX UNIQ_8D93D649ABC99533 ON user');
        $this->addSql('DROP INDEX IDX_8D93D649285E57BA ON user');
        $this->addSql('ALTER TABLE user DROP merged_into_id, DROP display_name, DROP historic, DROP historic_key, DROP historic_source, DROP aliases');
    }
}
