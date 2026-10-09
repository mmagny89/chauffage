<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\TargetsInput;
use App\Entity\HeatingTarget;
use App\Enum\DaySlot;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<\App\Dto\TargetsInput>
 */
final class TargetsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (DaySlot::cases() as $slot) {
            $builder->add($slot->value, NumberType::class, [
                'label' => $slot->label(),
                'invalid_message' => 'Saisissez une température en degrés.',
                'html5' => true,
                'scale' => 1,
                'attr' => ['step' => '0.5', 'min' => HeatingTarget::MIN, 'max' => HeatingTarget::MAX, 'inputmode' => 'decimal'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TargetsInput::class]);
    }
}
