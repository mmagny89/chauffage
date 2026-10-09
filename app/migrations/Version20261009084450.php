<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009084450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_heating_target_household_slot');
        // Les températures visées existantes valaient pour tous les jours : on les recopie sur les sept.
        $this->addSql('ALTER TABLE heating_target ADD day_of_week SMALLINT DEFAULT NULL');
        $this->addSql('UPDATE heating_target SET day_of_week = 1');
        $this->addSql('INSERT INTO heating_target (household_id, slot, temperature, day_of_week) SELECT t.household_id, t.slot, t.temperature, d.day FROM heating_target t CROSS JOIN generate_series(2, 7) AS d(day) WHERE t.day_of_week = 1');
        $this->addSql('ALTER TABLE heating_target ALTER day_of_week SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_heating_target_household_day_slot ON heating_target (household_id, day_of_week, slot)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_heating_target_household_day_slot');
        $this->addSql('DELETE FROM heating_target WHERE day_of_week <> 1');
        $this->addSql('ALTER TABLE heating_target DROP day_of_week');
        $this->addSql('CREATE UNIQUE INDEX uniq_heating_target_household_slot ON heating_target (household_id, slot)');
    }
}
