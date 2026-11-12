<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Datasets made elsewhere and shown as they are (Ketura, 2026-11-11): the format timeline
 */
final class Version20261111180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'dataset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE dataset (name VARCHAR(50) NOT NULL, data MEDIUMTEXT NOT NULL, schema_name VARCHAR(50) DEFAULT NULL, generated_at VARCHAR(40) DEFAULT NULL, date_import DATETIME NOT NULL, PRIMARY KEY(name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dataset');
    }
}
