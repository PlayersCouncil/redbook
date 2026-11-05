<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed-deck batches v2 (Ketura, 2026-11-04): the people behind an archive deck, its
 * language and starting quote; a play's pilot and its links into the event batch.
 */
final class Version20261104010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed batch v2: people, language and starting quote on sources; pilot and event links on plays';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck_play ADD pilot VARCHAR(255) DEFAULT NULL, ADD event_ref VARCHAR(255) DEFAULT NULL, ADD part_ref VARCHAR(255) DEFAULT NULL, ADD result_player VARCHAR(255) DEFAULT NULL');
        $this->addSql("ALTER TABLE published_deck_source ADD people JSON DEFAULT NULL COMMENT '(DC2Type:json)', ADD language VARCHAR(35) DEFAULT NULL, ADD starting_quote LONGTEXT DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE published_deck_play DROP pilot, DROP event_ref, DROP part_ref, DROP result_player');
        $this->addSql('ALTER TABLE published_deck_source DROP people, DROP language, DROP starting_quote');
    }
}
