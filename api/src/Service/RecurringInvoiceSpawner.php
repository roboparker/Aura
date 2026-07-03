<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clones each due recurring-template invoice (#445) into a fresh draft — copying
 * client, currency, tax, notes, and line items (but not the source's recurrence
 * or its lines' time-entry links) — then advances the template's next issue date
 * by one interval. One clone + one advance per template per run; a template
 * still due after that is picked up on the next daily run.
 */
final class RecurringInvoiceSpawner
{
    private const BASIS_POINTS = 10000;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function spawnDue(\DateTimeImmutable $today): int
    {
        $spawned = 0;
        foreach ($this->invoices->findDueRecurring($today) as $template) {
            $step = $template->recurrenceStep();
            if (null === $step) {
                continue;
            }

            $clone = (new Invoice())
                ->setSpace($template->getSpace())
                ->setClient($template->getClient())
                ->setCurrency($template->getCurrency())
                ->setTaxRate($template->getTaxRate())
                ->setNotes($template->getNotes())
                ->setCreatedBy($template->getCreatedBy());

            $subtotal = 0;
            foreach ($template->getLineItems() as $line) {
                $clone->addLineItem(
                    (new InvoiceLineItem())
                        ->setDescription($line->getDescription())
                        ->setQuantity($line->getQuantity())
                        ->setUnitAmount($line->getUnitAmount())
                        ->setAmount($line->getAmount())
                        ->setPosition($line->getPosition()),
                );
                $subtotal += $line->getAmount();
            }
            $tax = (int) round($subtotal * $template->getTaxRate() / self::BASIS_POINTS);
            $clone->setSubtotal($subtotal)->setTaxAmount($tax)->setTotal($subtotal + $tax);
            $this->em->persist($clone);

            $next = $template->getNextIssueDate();
            if (null !== $next) {
                $template->setNextIssueDate($next->modify($step));
            }
            ++$spawned;
        }

        if ($spawned > 0) {
            $this->em->flush();
        }

        return $spawned;
    }
}
