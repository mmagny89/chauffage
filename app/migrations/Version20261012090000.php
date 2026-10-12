<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exposition des pièces : murs donnant sur l’extérieur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE place ADD exposures JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE place ALTER exposures DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place DROP exposures');
    }
}
