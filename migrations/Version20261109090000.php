<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A deck's own thumbnail (Ketura, 2026-11-09): a card and a framing for it, chosen on the
 * deckbuilder's Notes tab, which publishing starts the decklist's from
 */
final class Version20261109090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'deck.thumbnail_card_id, deck.thumbnail_crop';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE deck ADD thumbnail_card_id INT DEFAULT NULL, ADD thumbnail_crop JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        $this->addSql('ALTER TABLE deck ADD CONSTRAINT FK_4FAC36376868F159 FOREIGN KEY (thumbnail_card_id) REFERENCES card (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4FAC36376868F159 ON deck (thumbnail_card_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deck DROP FOREIGN KEY FK_4FAC36376868F159');
        $this->addSql('DROP INDEX IDX_4FAC36376868F159 ON deck');
        $this->addSql('ALTER TABLE deck DROP thumbnail_card_id, DROP thumbnail_crop');
    }
}
