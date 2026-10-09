<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un jour de relevés : une date et au moins un lieu.
 */
final class DayReadingsInput
{
    #[Assert\NotNull(message: 'Indiquez la date.')]
    public ?\DateTimeImmutable $date = null;

    /**
     * @var list<ReadingRowInput>
     */
    #[Assert\Count(min: 1, minMessage: 'Renseignez au moins un lieu pour ce jour.')]
    #[Assert\Valid]
    public array $rows = [];

    #[Assert\Callback]
    public function rejectDuplicateRows(ExecutionContextInterface $context): void
    {
        $seen = [];
        foreach ($this->rows as $index => $row) {
            $name = $row->placeName();
            if (null === $name || null === $row->time) {
                continue;
            }

            $key = mb_strtolower($name).'|'.$row->time->format('H:i');
            if (isset($seen[$key])) {
                $context->buildViolation('Ce lieu apparaît déjà à la même heure dans ce formulaire.')
                    ->atPath(\sprintf('rows[%d].time', $index))
                    ->addViolation();
            }
            $seen[$key] = true;
        }
    }
}
