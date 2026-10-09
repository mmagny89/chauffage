<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\Weekday;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Les températures visées de toute la semaine : un TargetsType par jour.
 * Données : tableau « clé du jour » => TargetsInput.
 *
 * @extends AbstractType<array<string, \App\Dto\TargetsInput>>
 */
final class WeekTargetsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (Weekday::cases() as $day) {
            $builder->add($day->key(), TargetsType::class, [
                'label' => $day->label(),
                'property_path' => '['.$day->key().']',
            ]);
        }
    }
}
