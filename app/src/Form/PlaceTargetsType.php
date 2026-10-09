<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\PlaceTargetsInput;
use App\Entity\Place;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Les températures visées de chaque pièce. Données : tableau « clé de la pièce » => PlaceTargetsInput.
 *
 * @extends AbstractType<array<string, PlaceTargetsInput>>
 */
final class PlaceTargetsType extends AbstractType
{
    /**
     * Nom du champ d'une pièce dans le formulaire.
     */
    public static function fieldName(Place $place): string
    {
        return 'p'.$place->getId();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($options['places'] as $place) {
            $builder->add(self::fieldName($place), PlaceSlotTargetsType::class, [
                'label' => $place->getName(),
                'property_path' => '['.self::fieldName($place).']',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['places' => []]);
        $resolver->setAllowedTypes('places', 'array');
    }
}
