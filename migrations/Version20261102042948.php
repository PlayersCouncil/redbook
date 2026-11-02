<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102042948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOTR cards: card fields, cultures, card versions, errata batches; set kinds.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE card_version (
              id INT AUTO_INCREMENT NOT NULL,
              card_id INT NOT NULL,
              batch_id INT DEFAULT NULL,
              wiki_id VARCHAR(30) NOT NULL,
              revision SMALLINT NOT NULL,
              issuer VARCHAR(50) DEFAULT NULL,
              release_date DATE DEFAULT NULL,
              fields JSON NOT NULL COMMENT '(DC2Type:json)',
              image_url VARCHAR(255) DEFAULT NULL,
              INDEX IDX_E80659B54ACC9A20 (card_id),
              INDEX IDX_E80659B5F39EBE7A (batch_id),
              UNIQUE INDEX card_version_wiki_idx (wiki_id),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE culture (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(30) NOT NULL,
              name VARCHAR(50) NOT NULL,
              side VARCHAR(20) DEFAULT NULL,
              UNIQUE INDEX culture_code_idx (code),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE errata_batch (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(40) NOT NULL,
              issuer VARCHAR(50) NOT NULL,
              release_date DATE NOT NULL,
              name VARCHAR(100) DEFAULT NULL,
              UNIQUE INDEX errata_batch_code_idx (code),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              card_version
            ADD
              CONSTRAINT FK_E80659B54ACC9A20 FOREIGN KEY (card_id) REFERENCES card (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              card_version
            ADD
              CONSTRAINT FK_E80659B5F39EBE7A FOREIGN KEY (batch_id) REFERENCES errata_batch (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              card
            ADD
              errata_of_id INT DEFAULT NULL,
            ADD
              culture_id INT DEFAULT NULL,
            ADD
              kind VARCHAR(10) DEFAULT 'card' NOT NULL,
            ADD
              source VARCHAR(10) DEFAULT 'wiki' NOT NULL,
            ADD
              subtitle VARCHAR(100) DEFAULT NULL,
            ADD
              side VARCHAR(20) DEFAULT NULL,
            ADD
              twilight SMALLINT DEFAULT NULL,
            ADD
              strength_mod VARCHAR(10) DEFAULT NULL,
            ADD
              vitality SMALLINT DEFAULT NULL,
            ADD
              vitality_mod VARCHAR(10) DEFAULT NULL,
            ADD
              resistance SMALLINT DEFAULT NULL,
            ADD
              resistance_mod VARCHAR(10) DEFAULT NULL,
            ADD
              site_number SMALLINT DEFAULT NULL,
            ADD
              site_number_mod VARCHAR(10) DEFAULT NULL,
            ADD
              shadow_number SMALLINT DEFAULT NULL,
            ADD
              site_block VARCHAR(30) DEFAULT NULL,
            ADD
              signet VARCHAR(30) DEFAULT NULL,
            ADD
              can_bear_ring TINYINT(1) DEFAULT NULL,
            ADD
              subtypes VARCHAR(255) DEFAULT NULL,
            ADD
              wiki_id VARCHAR(30) DEFAULT NULL,
            ADD
              coll_info VARCHAR(20) DEFAULT NULL,
            ADD
              alternates JSON DEFAULT NULL COMMENT '(DC2Type:json)',
            ADD
              translations JSON DEFAULT NULL COMMENT '(DC2Type:json)',
            DROP
              cost,
            DROP
              quantity,
            DROP
              income,
            DROP
              initiative,
            DROP
              claim,
            DROP
              reserve,
            DROP
              deck_limit,
            DROP
              designer,
            DROP
              traits,
            DROP
              illustrator,
            DROP
              is_loyal,
            DROP
              is_military,
            DROP
              is_intrigue,
            DROP
              is_power,
            DROP
              octgn_id,
            DROP
              is_multiple,
            DROP
              errataed,
            CHANGE
              code code VARCHAR(20) NOT NULL,
            CHANGE
              name name VARCHAR(100) NOT NULL,
            CHANGE
              text text LONGTEXT DEFAULT NULL,
            CHANGE
              flavor flavor LONGTEXT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              card
            ADD
              CONSTRAINT FK_161498D3B0E2C07F FOREIGN KEY (errata_of_id) REFERENCES card (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              card
            ADD
              CONSTRAINT FK_161498D3B108249D FOREIGN KEY (culture_id) REFERENCES culture (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_161498D3B0E2C07F ON card (errata_of_id)');
        $this->addSql('CREATE INDEX IDX_161498D3B108249D ON card (culture_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              pack
            ADD
              kind VARCHAR(20) DEFAULT 'decipher' NOT NULL,
            ADD
              wiki_set VARCHAR(10) DEFAULT NULL,
            ADD
              abbreviation VARCHAR(20) DEFAULT NULL,
            DROP
              cgdb_id
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE card DROP FOREIGN KEY FK_161498D3B108249D');
        $this->addSql('ALTER TABLE card_version DROP FOREIGN KEY FK_E80659B54ACC9A20');
        $this->addSql('ALTER TABLE card_version DROP FOREIGN KEY FK_E80659B5F39EBE7A');
        $this->addSql('DROP TABLE card_version');
        $this->addSql('DROP TABLE culture');
        $this->addSql('DROP TABLE errata_batch');
        $this->addSql('ALTER TABLE card DROP FOREIGN KEY FK_161498D3B0E2C07F');
        $this->addSql('DROP INDEX IDX_161498D3B0E2C07F ON card');
        $this->addSql('DROP INDEX IDX_161498D3B108249D ON card');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              card
            ADD
              cost VARCHAR(2) DEFAULT NULL,
            ADD
              quantity SMALLINT NOT NULL,
            ADD
              income SMALLINT DEFAULT NULL,
            ADD
              initiative SMALLINT DEFAULT NULL,
            ADD
              claim SMALLINT DEFAULT NULL,
            ADD
              reserve SMALLINT DEFAULT NULL,
            ADD
              deck_limit SMALLINT DEFAULT NULL,
            ADD
              designer TEXT DEFAULT NULL,
            ADD
              traits VARCHAR(255) NOT NULL,
            ADD
              is_loyal TINYINT(1) NOT NULL,
            ADD
              is_military TINYINT(1) NOT NULL,
            ADD
              is_intrigue TINYINT(1) NOT NULL,
            ADD
              is_power TINYINT(1) NOT NULL,
            ADD
              octgn_id VARCHAR(255) DEFAULT NULL,
            ADD
              is_multiple TINYINT(1) NOT NULL,
            ADD
              errataed TINYINT(1) NOT NULL,
            DROP
              errata_of_id,
            DROP
              culture_id,
            DROP
              kind,
            DROP
              source,
            DROP
              subtitle,
            DROP
              side,
            DROP
              twilight,
            DROP
              strength_mod,
            DROP
              vitality,
            DROP
              vitality_mod,
            DROP
              resistance,
            DROP
              resistance_mod,
            DROP
              site_number,
            DROP
              site_number_mod,
            DROP
              shadow_number,
            DROP
              site_block,
            DROP
              signet,
            DROP
              can_bear_ring,
            DROP
              wiki_id,
            DROP
              coll_info,
            DROP
              alternates,
            DROP
              translations,
            CHANGE
              code code VARCHAR(255) NOT NULL,
            CHANGE
              name name VARCHAR(50) NOT NULL,
            CHANGE
              text text LONGTEXT NOT NULL,
            CHANGE
              flavor flavor LONGTEXT NOT NULL,
            CHANGE
              subtypes illustrator VARCHAR(255) DEFAULT NULL
        SQL);
        $this->addSql('ALTER TABLE pack ADD cgdb_id INT DEFAULT NULL, DROP kind, DROP wiki_set, DROP abbreviation');
    }
}
