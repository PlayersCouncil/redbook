<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FormatGuesser's guess, kept with seeded decklists whose batch gave no format.
 */
final class Version20261102172120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Red Book's format guess for archived decklists";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decklist_source ADD format_guess JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decklist_source DROP format_guess');
    }
}
