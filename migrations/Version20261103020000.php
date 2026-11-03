<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plain names for the deck tables (Ketura, 2026-11-02): decklist is a published deck,
 * deckchange a deck revision. Renames in place, so the data stays.
 */
final class Version20261103020000 extends AbstractMigration
{
    private const TABLES = [
        'decklist' => 'published_deck',
        'decklistslot' => 'published_deck_slot',
        'decklist_culture' => 'published_deck_culture',
        'decklist_play' => 'published_deck_play',
        'decklist_source' => 'published_deck_source',
        'deckchange' => 'deck_revision',
        'deckslot' => 'deck_slot',
    ];

    /** [table (new name), old column, new column] */
    private const COLUMNS = [
        ['published_deck_slot', 'decklist_id', 'published_deck_id'],
        ['published_deck_culture', 'decklist_id', 'published_deck_id'],
        ['published_deck_play', 'decklist_id', 'published_deck_id'],
        ['published_deck_source', 'decklist_id', 'published_deck_id'],
        ['published_deck', 'precedent_decklist_id', 'precedent_published_deck_id'],
        ['deck', 'parent_decklist_id', 'parent_published_deck_id'],
        ['comment', 'decklist_id', 'published_deck_id'],
        ['favorite', 'decklist_id', 'published_deck_id'],
        ['vote', 'decklist_id', 'published_deck_id'],
        ['decklist_claim', 'decklist_id', 'published_deck_id'],
    ];

    /** [table (new name), old index, new index] */
    private const INDEXES = [
        ['published_deck', 'decklist_format_idx', 'published_deck_format_idx'],
        ['published_deck_culture', 'decklist_culture_culture_idx', 'published_deck_culture_culture_idx'],
        ['published_deck_play', 'decklist_play_date_idx', 'published_deck_play_date_idx'],
        ['published_deck_source', 'decklist_source_idx', 'published_deck_source_idx'],
        // Doctrine's generated names follow the table and column names
        ['deck', 'IDX_4FAC36379FC5416B', 'IDX_4FAC3637FD07DB48'],
        ['published_deck_culture', 'IDX_78C739BBF4E9531B', 'IDX_691A9AEB4263262C'],
        ['published_deck_culture', 'IDX_78C739BBB108249D', 'IDX_691A9AEBB108249D'],
        ['published_deck_play', 'IDX_6CB0F711F4E9531B', 'IDX_DCD8641C4263262C'],
        ['published_deck_play', 'IDX_6CB0F711A354F9DC', 'IDX_DCD8641CA354F9DC'],
        ['comment', 'IDX_9474526CF4E9531B', 'IDX_9474526C4263262C'],
        ['published_deck_slot', 'IDX_2071B1F4E9531B', 'IDX_2E5F9AC14263262C'],
        ['published_deck_slot', 'IDX_2071B14ACC9A20', 'IDX_2E5F9AC14ACC9A20'],
        ['deck_slot', 'IDX_5C5D6B9111948DC', 'IDX_90E96459111948DC'],
        ['deck_slot', 'IDX_5C5D6B94ACC9A20', 'IDX_90E964594ACC9A20'],
        ['published_deck', 'IDX_ED030EC6A76ED395', 'IDX_7B3A07E6A76ED395'],
        ['published_deck', 'IDX_ED030EC64448F8DA', 'IDX_7B3A07E64448F8DA'],
        ['published_deck', 'IDX_ED030EC67F958E5F', 'IDX_7B3A07E67F958E5F'],
        ['published_deck', 'IDX_ED030EC663513C9A', 'IDX_7B3A07E663513C9A'],
        ['published_deck', 'IDX_ED030EC6C386FA95', 'IDX_7B3A07E6598FBA23'],
        ['published_deck', 'IDX_ED030EC633D1A3E7', 'IDX_7B3A07E633D1A3E7'],
        ['favorite', 'IDX_68C58ED9F4E9531B', 'IDX_68C58ED94263262C'],
        ['vote', 'IDX_5A108564F4E9531B', 'IDX_5A1085644263262C'],
        ['deck_revision', 'IDX_B32E853111948DC', 'IDX_3454A6EE111948DC'],
        ['decklist_claim', 'IDX_E65F9D6EF4E9531B', 'IDX_E65F9D6E4263262C'],
    ];

    public function getDescription(): string
    {
        return 'Rename decklist tables to published_deck, deckchange to deck_revision, deckslot to deck_slot';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $old => $new) {
            $this->addSql("RENAME TABLE $old TO $new");
        }
        foreach (self::COLUMNS as [$table, $old, $new]) {
            $this->addSql("ALTER TABLE $table RENAME COLUMN $old TO $new");
        }
        foreach (self::INDEXES as [$table, $old, $new]) {
            $this->addSql("ALTER TABLE $table RENAME INDEX $old TO $new");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as [$table, $old, $new]) {
            $this->addSql("ALTER TABLE $table RENAME INDEX $new TO $old");
        }
        foreach (self::COLUMNS as [$table, $old, $new]) {
            $this->addSql("ALTER TABLE $table RENAME COLUMN $new TO $old");
        }
        foreach (array_reverse(self::TABLES, true) as $old => $new) {
            $this->addSql("RENAME TABLE $new TO $old");
        }
    }
}
