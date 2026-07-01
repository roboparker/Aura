<?php

namespace App\Controller;

use App\Ical\CalendarFeedBuilder;
use App\Service\CalendarFeedTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public, token-authenticated read-only iCal feed (#calendar-sync). A
 * calendar client (Google / Outlook / Apple) subscribes to
 * `GET /calendar/feed/{token}.ics`; there is no session — the unguessable
 * token in the URL is the sole credential, resolved sha256-hashed like the
 * password-reset / export tokens.
 *
 * An unknown or malformed token returns 404 (never distinguishing "no such
 * token" from "revoked"), so the endpoint can't be used to probe which feeds
 * exist. A valid token yields a `text/calendar` document of the owner's
 * due-dated tasks.
 */
class CalendarFeedController extends AbstractController
{
    public function __construct(
        private readonly CalendarFeedTokenManager $tokens,
        private readonly CalendarFeedBuilder $builder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        '/calendar/feed/{token}.ics',
        name: 'calendar_feed',
        requirements: ['token' => '[A-Za-z0-9]+'],
        methods: ['GET'],
    )]
    public function __invoke(string $token, Request $request): Response
    {
        $record = $this->tokens->resolve($token);
        if (null === $record) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $ical = $this->builder->build($record->getUser(), $request->getSchemeAndHttpHost());

        // Best-effort "last synced" stamp; never let it break serving the feed.
        $record->markAccessed();
        $this->em->flush();

        $response = new Response($ical);
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->headers->set('Content-Disposition', 'inline; filename="madori.ics"');
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');

        return $response;
    }
}
