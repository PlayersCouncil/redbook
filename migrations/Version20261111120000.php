<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Several starting fellowships, each named (Ketura, 2026-11-11): a deck's one list of starting
 * companions becomes one unnamed group, [{name, codes}] (App\Classes\StartingGroups reads the
 * old form too, so nothing breaks if a row is missed). And the bid takes 100 characters, room
 * for "1 if X, otherwise 4 if Y".
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Starting fellowships as named groups; starting_bid 100 characters (deck, published_deck)';
    }

    public function up(Schema $schema): void
    {
        foreach (['deck', 'published_deck'] as $table) {
            $this->addSql("ALTER TABLE $table CHANGE starting_bid starting_bid VARCHAR(100) DEFAULT NULL");
            $this->addSql("UPDATE $table SET starting_fellowship = NULL WHERE starting_fellowship IS NOT NULL AND JSON_LENGTH(starting_fellowship) = 0");
            $this->addSql("UPDATE $table SET starting_fellowship = JSON_ARRAY(JSON_OBJECT('name', '', 'codes', JSON_EXTRACT(starting_fellowship, '$')))
                WHERE starting_fellowship IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(starting_fellowship, '$[0]')) = 'STRING'");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['deck', 'published_deck'] as $table) {
            // back to one list: the first group's companions (any others are lost)
            $this->addSql("UPDATE $table SET starting_fellowship = JSON_EXTRACT(starting_fellowship, '$[0].codes')
                WHERE starting_fellowship IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(starting_fellowship, '$[0]')) = 'OBJECT'");
            $this->addSql("ALTER TABLE $table CHANGE starting_bid starting_bid VARCHAR(30) DEFAULT NULL");
        }
    }
}
