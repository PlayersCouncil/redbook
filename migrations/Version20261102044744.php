<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gemp formats, refreshed hourly by app:formats:refresh.
 */
final class Version20261102044744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Gemp formats copy (gemp_format)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gemp_format (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, position INT NOT NULL, hall TINYINT(1) NOT NULL, playtest TINYINT(1) NOT NULL, retired TINYINT(1) NOT NULL, sites VARCHAR(50) DEFAULT NULL, minimum_deck_size SMALLINT NOT NULL, maximum_same_name SMALLINT NOT NULL, uses_maps TINYINT(1) NOT NULL, set_summary VARCHAR(255) DEFAULT NULL, sets JSON NOT NULL COMMENT \'(DC2Type:json)\', pc_errata TINYINT(1) NOT NULL, lists JSON NOT NULL COMMENT \'(DC2Type:json)\', raw JSON NOT NULL COMMENT \'(DC2Type:json)\', date_update DATETIME NOT NULL, UNIQUE INDEX gemp_format_code_idx (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gemp_format');
    }
}
