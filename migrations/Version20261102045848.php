<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The LOTR deck model (phase3/03, decisions 0019-0023).
 */
final class Version20261102045848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "LOTR decks: slot roles, target format, effective date, Gemp validation, halves; decklist cultures, plays, archive sources and claims; restrictions dropped";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE decklist_claim (id INT AUTO_INCREMENT NOT NULL, decklist_id INT NOT NULL, user_id INT NOT NULL, decided_by_id INT DEFAULT NULL, message LONGTEXT DEFAULT NULL, status VARCHAR(10) NOT NULL, date_creation DATETIME NOT NULL, date_decision DATETIME DEFAULT NULL, INDEX IDX_E65F9D6EF4E9531B (decklist_id), INDEX IDX_E65F9D6EA76ED395 (user_id), INDEX IDX_E65F9D6EE26B496B (decided_by_id), INDEX decklist_claim_status_idx (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE decklist_culture (decklist_id INT NOT NULL, culture_id INT NOT NULL, card_count SMALLINT NOT NULL, INDEX IDX_78C739BBF4E9531B (decklist_id), INDEX IDX_78C739BBB108249D (culture_id), INDEX decklist_culture_culture_idx (culture_id, decklist_id), PRIMARY KEY(decklist_id, culture_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE decklist_play (id INT AUTO_INCREMENT NOT NULL, decklist_id INT NOT NULL, tier_id INT DEFAULT NULL, event_name VARCHAR(255) NOT NULL, event_date DATE DEFAULT NULL, placing VARCHAR(50) DEFAULT NULL, player_count SMALLINT DEFAULT NULL, format VARCHAR(50) DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, INDEX IDX_6CB0F711F4E9531B (decklist_id), INDEX IDX_6CB0F711A354F9DC (tier_id), INDEX decklist_play_date_idx (event_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE decklist_source (decklist_id INT NOT NULL, source VARCHAR(30) NOT NULL, source_id VARCHAR(100) NOT NULL, source_url VARCHAR(500) NOT NULL, archive_url VARCHAR(500) DEFAULT NULL, legacy_author VARCHAR(255) NOT NULL, posted_date DATE DEFAULT NULL, posted_date_text VARCHAR(10) DEFAULT NULL, format_as_written VARCHAR(100) DEFAULT NULL, raw_decklist LONGTEXT NOT NULL, unresolved JSON NOT NULL COMMENT \'(DC2Type:json)\', ratings JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', comments JSON NOT NULL COMMENT \'(DC2Type:json)\', batch VARCHAR(255) DEFAULT NULL, UNIQUE INDEX decklist_source_idx (source, source_id), PRIMARY KEY(decklist_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE decklist_claim ADD CONSTRAINT FK_E65F9D6EF4E9531B FOREIGN KEY (decklist_id) REFERENCES decklist (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_claim ADD CONSTRAINT FK_E65F9D6EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_claim ADD CONSTRAINT FK_E65F9D6EE26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE decklist_culture ADD CONSTRAINT FK_78C739BBF4E9531B FOREIGN KEY (decklist_id) REFERENCES decklist (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_culture ADD CONSTRAINT FK_78C739BBB108249D FOREIGN KEY (culture_id) REFERENCES culture (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_play ADD CONSTRAINT FK_6CB0F711F4E9531B FOREIGN KEY (decklist_id) REFERENCES decklist (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE decklist_play ADD CONSTRAINT FK_6CB0F711A354F9DC FOREIGN KEY (tier_id) REFERENCES tournament (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE decklist_source ADD CONSTRAINT FK_50CBB004F4E9531B FOREIGN KEY (decklist_id) REFERENCES decklist (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE restriction');
        $this->addSql('ALTER TABLE deck ADD shared TINYINT(1) DEFAULT 0 NOT NULL, ADD target_format VARCHAR(50) NOT NULL, ADD effective_date DATE NOT NULL, ADD validation JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD validated_at DATETIME DEFAULT NULL, ADD completeness VARCHAR(12) DEFAULT \'unvalidated\' NOT NULL, DROP problem');
        $this->addSql('ALTER TABLE decklist ADD half VARCHAR(12) DEFAULT NULL, ADD target_format VARCHAR(50) NOT NULL, ADD effective_date DATE NOT NULL, ADD validation JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD validated_at DATETIME DEFAULT NULL, ADD completeness VARCHAR(12) DEFAULT \'unvalidated\' NOT NULL');
        $this->addSql('CREATE INDEX decklist_format_idx ON decklist (target_format, completeness, half)');
        $this->addSql('ALTER TABLE decklistslot ADD role VARCHAR(12) DEFAULT \'draw\' NOT NULL');
        $this->addSql('ALTER TABLE deckslot ADD role VARCHAR(12) DEFAULT \'draw\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE restriction (code VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, effective_on DATETIME NOT NULL, issuer VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, card_set VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, contents JSON NOT NULL COMMENT \'(DC2Type:json)\', active TINYINT(1) NOT NULL, version VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, title VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, date_creation DATETIME NOT NULL, date_update DATETIME NOT NULL, PRIMARY KEY(code)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE decklist_claim DROP FOREIGN KEY FK_E65F9D6EF4E9531B');
        $this->addSql('ALTER TABLE decklist_claim DROP FOREIGN KEY FK_E65F9D6EA76ED395');
        $this->addSql('ALTER TABLE decklist_claim DROP FOREIGN KEY FK_E65F9D6EE26B496B');
        $this->addSql('ALTER TABLE decklist_culture DROP FOREIGN KEY FK_78C739BBF4E9531B');
        $this->addSql('ALTER TABLE decklist_culture DROP FOREIGN KEY FK_78C739BBB108249D');
        $this->addSql('ALTER TABLE decklist_play DROP FOREIGN KEY FK_6CB0F711F4E9531B');
        $this->addSql('ALTER TABLE decklist_play DROP FOREIGN KEY FK_6CB0F711A354F9DC');
        $this->addSql('ALTER TABLE decklist_source DROP FOREIGN KEY FK_50CBB004F4E9531B');
        $this->addSql('DROP TABLE decklist_claim');
        $this->addSql('DROP TABLE decklist_culture');
        $this->addSql('DROP TABLE decklist_play');
        $this->addSql('DROP TABLE decklist_source');
        $this->addSql('ALTER TABLE deck ADD problem VARCHAR(255) DEFAULT NULL, DROP shared, DROP target_format, DROP effective_date, DROP validation, DROP validated_at, DROP completeness');
        $this->addSql('ALTER TABLE decklistslot DROP role');
        $this->addSql('ALTER TABLE deckslot DROP role');
        $this->addSql('DROP INDEX decklist_format_idx ON decklist');
        $this->addSql('ALTER TABLE decklist DROP half, DROP target_format, DROP effective_date, DROP validation, DROP validated_at, DROP completeness');
    }
}
