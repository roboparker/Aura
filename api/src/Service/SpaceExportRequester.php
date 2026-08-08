<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\SpaceExport;
use App\Entity\User;
use App\Message\GenerateSpaceExport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a space data export: insert the row, dispatch the build job.
 *
 * Extracted from {@see \App\Controller\SpaceExportController} so organization
 * deletion can archive every space through the same path rather than
 * re-implementing the insert-then-dispatch pair — the ordering matters (the
 * handler resolves the row by id, so it has to exist before the message is
 * sent) and is exactly the kind of detail a second copy gets wrong.
 *
 * Returns null when an export of that space is already in flight, which the
 * HTTP caller surfaces as a 409 and the deletion flow simply accepts.
 */
final class SpaceExportRequester
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    public function request(Space $space, User $requestedBy): ?SpaceExport
    {
        $inFlight = $this->em->getRepository(SpaceExport::class)->findOneBy([
            'space' => $space,
            'status' => [SpaceExport::STATUS_PENDING, SpaceExport::STATUS_PROCESSING],
        ]);
        if (null !== $inFlight) {
            return null;
        }

        $export = new SpaceExport($space, $requestedBy);
        $this->em->persist($export);
        $this->em->flush();

        $this->bus->dispatch(new GenerateSpaceExport((string) $export->getId()));

        return $export;
    }
}
