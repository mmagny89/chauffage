<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ReadingSessionInput;
use App\Entity\HeatingTarget;
use App\Entity\Place;
use App\Entity\Reading;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Formulaire de relevé : un champ de température intérieure, obligatoire, par lieu déclaré.
 *
 * @extends AbstractType<\App\Dto\ReadingSessionInput>
 */
final class ReadingSessionType extends AbstractType
{
    /**
     * Nom du champ d'un lieu dans le formulaire (et clé dans ReadingSessionInput::$indoor).
     */
    public static function fieldName(Place $place): string
    {
        return 'p'.$place->getId();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label' => 'Jour',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('time', TimeType::class, [
                'label' => 'Heure de la prise',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('outdoor', NumberType::class, [
                'label' => 'Température extérieure (°C)',
                'html5' => true,
                'scale' => 1,
                'attr' => ['step' => '0.1', 'inputmode' => 'decimal'],
            ])
        ;

        $indoor = $builder->create('indoor', FormType::class, ['label' => false, 'error_bubbling' => false]);
        foreach ($options['places'] as $place) {
            $indoor->add(self::fieldName($place), NumberType::class, [
                'label' => $place->getName(),
                'property_path' => '['.self::fieldName($place).']',
                'html5' => true,
                'scale' => 1,
                'invalid_message' => 'Saisissez une température en degrés.',
                'attr' => ['step' => '0.1', 'inputmode' => 'decimal'],
                'constraints' => [
                    new NotNull(message: 'Indiquez la température de ce lieu.'),
                    new Range(min: Reading::INDOOR_MIN, max: Reading::INDOOR_MAX, notInRangeMessage: 'La température intérieure doit être comprise entre {{ min }} et {{ max }} °C.'),
                ],
            ]);
        }
        $builder->add($indoor);

        // Facultatif : la consigne réglée si l'on allume le chauffage dans ce lieu juste après le relevé.
        $heating = $builder->create('heating', FormType::class, ['label' => false, 'error_bubbling' => false, 'property_path' => 'setpoints']);
        foreach ($options['places'] as $place) {
            $heating->add(self::fieldName($place), NumberType::class, [
                'label' => $place->getName(),
                'required' => false,
                'property_path' => '['.self::fieldName($place).']',
                'html5' => true,
                'scale' => 1,
                'invalid_message' => 'Saisissez une température en degrés.',
                'attr' => ['step' => '0.5', 'inputmode' => 'decimal'],
                'constraints' => [
                    new Range(min: HeatingTarget::MIN, max: HeatingTarget::MAX, notInRangeMessage: 'La consigne doit être comprise entre {{ min }} et {{ max }} °C.'),
                ],
            ]);
        }
        $builder->add($heating);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReadingSessionInput::class, 'places' => []]);
        $resolver->setAllowedTypes('places', 'array');
    }
}
