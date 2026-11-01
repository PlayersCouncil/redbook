<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the FOSOAuthServerBundle tables that Version20261031000000 kept as
 * legacy_fos_oauth_*. Red Book has no OAuth2 clients or tokens from before
 * the move to league/oauth2-server-bundle, so there is nothing to keep.
 */
final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the legacy FOSOAuthServerBundle tables.';
    }

    public function up(Schema $schema): void
    {
        // Tokens and codes first: they reference the client table
        $this->addSql('DROP TABLE IF EXISTS legacy_fos_oauth_access_token, legacy_fos_oauth_auth_code, legacy_fos_oauth_refresh_token');
        $this->addSql('DROP TABLE IF EXISTS legacy_fos_oauth_client');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The FOSOAuthServerBundle tables are not recreated.');
    }
}
