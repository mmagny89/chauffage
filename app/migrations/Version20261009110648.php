<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009110648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE household ADD setup_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Les foyers déjà configurés (une ville et au moins un lieu) n'ont pas à refaire la mise en route.
        $this->addSql('UPDATE household SET setup_completed_at = NOW() WHERE latitude IS NOT NULL AND EXISTS (SELECT 1 FROM place p WHERE p.household_id = household.id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE household DROP setup_completed_at');
    }
}
