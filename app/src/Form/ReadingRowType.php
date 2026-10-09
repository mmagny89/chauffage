<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ReadingRowInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ReadingRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('place', TextType::class, [
                'label' => 'Lieu',
                'attr' => ['list' => 'place-suggestions', 'maxlength' => 80, 'autocomplete' => 'off', 'placeholder' => 'Salon, chambre…'],
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
            ->add('indoor', NumberType::class, [
                'label' => 'Température intérieure (°C)',
                'html5' => true,
                'scale' => 1,
                'attr' => ['step' => '0.1', 'inputmode' => 'decimal'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReadingRowInput::class]);
    }
}
