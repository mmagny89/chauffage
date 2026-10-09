<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ReadingRowInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
            ->add('place', ChoiceType::class, [
                'label' => 'Lieu',
                'choices' => $options['place_choices'],
                'placeholder' => 'Choisir un lieu',
                'choice_translation_domain' => false,
                'attr' => ['data-place-target' => 'select', 'data-action' => 'change->place#toggle'],
            ])
            ->add('customPlace', TextType::class, [
                'label' => 'Nom du lieu',
                'required' => false,
                'attr' => ['maxlength' => 80, 'autocomplete' => 'off', 'placeholder' => 'Atelier, chambre 2…'],
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
        $resolver->setDefaults(['data_class' => ReadingRowInput::class, 'place_choices' => []]);
        $resolver->setAllowedTypes('place_choices', 'array');
    }
}
