<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Self-service preferences for the current user. Lives outside the generic
 * `Patch` operation on User so we can keep the allowed-keys + enum
 * validation tight without spreading serializer groups across the entity.
 *
 * Partial updates are merged over the existing preference object so the
 * client only has to send the keys that changed.
 */
class UserPreferencesController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/me/preferences', name: 'me_preferences_get', methods: ['GET'])]
    public function get(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        return $this->json($user->getPreferences());
    }

    #[Route('/me/preferences', name: 'me_preferences_patch', methods: ['PATCH'])]
    public function patch(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        // `array_is_list` catches the JSON-array case (e.g. `["a","b"]`)
        // that decodes to an array but isn't a key→value object.
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->json(['error' => 'Body must be a JSON object.'], 400);
        }

        $current = $user->getPreferences();
        $next = $current;

        foreach ($payload as $key => $value) {
            if (!array_key_exists($key, User::DEFAULT_PREFERENCES)) {
                return $this->json([
                    'error' => sprintf('Unknown preference key: %s.', $key),
                ], 422);
            }

            $error = $this->validateValue($key, $value);
            if (null !== $error) {
                return $this->json(['error' => $error], 422);
            }
            $next[$key] = $value;
        }

        $user->setPreferences($next);
        $this->em->flush();

        return $this->json($user->getPreferences());
    }

    /**
     * Returns the validation error message if `$value` is invalid for `$key`,
     * or null when the value is acceptable. Keys are guaranteed by the caller
     * to be one of the known preference fields.
     */
    private function validateValue(string $key, mixed $value): ?string
    {
        return match ($key) {
            'theme' => is_string($value) && in_array($value, User::ALLOWED_THEMES, true)
                ? null
                : 'theme must be one of: light, dark, system.',
            'timezone' => is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true)
                ? null
                : 'timezone must be a valid IANA time-zone identifier.',
            'notificationFrequency' => is_string($value) && in_array($value, User::ALLOWED_FREQUENCIES, true)
                ? null
                : 'notificationFrequency must be one of: realtime, hourly, daily.',
            'emailNotificationsEnabled', 'pushNotificationsEnabled' => is_bool($value)
                ? null
                : sprintf('%s must be a boolean.', $key),
            'notificationMatrix' => $this->validateMatrix($value),
            'emailDigest' => $this->validateDigest($value),
            'quietHours' => $this->validateQuietHours($value),
            default => sprintf('Unknown preference key: %s.', $key),
        };
    }

    private function validateMatrix(mixed $value): ?string
    {
        if (!is_array($value)) {
            return 'notificationMatrix must be an object.';
        }
        foreach ($value as $row => $channels) {
            if (!is_string($row) || !in_array($row, User::NOTIFICATION_MATRIX_ROWS, true)) {
                return sprintf('Unknown notification row: %s.', is_string($row) ? $row : gettype($row));
            }
            if (!is_array($channels) || !is_bool($channels['inApp'] ?? null) || !is_bool($channels['email'] ?? null)) {
                return sprintf('notificationMatrix.%s must be {inApp: bool, email: bool}.', $row);
            }
        }
        return null;
    }

    private function validateDigest(mixed $value): ?string
    {
        if (!is_array($value)) {
            return 'emailDigest must be an object.';
        }
        $mode = $value['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, ['realtime', 'hourly', 'daily'], true)) {
            return 'emailDigest.mode must be one of: realtime, hourly, daily.';
        }
        $hour = $value['hour'] ?? null;
        if (!is_int($hour) || $hour < 0 || $hour > 23) {
            return 'emailDigest.hour must be an integer between 0 and 23.';
        }
        return null;
    }

    private function validateQuietHours(mixed $value): ?string
    {
        if (!is_array($value)) {
            return 'quietHours must be an object.';
        }
        if (!is_bool($value['enabled'] ?? null)) {
            return 'quietHours.enabled must be a boolean.';
        }
        foreach (['start', 'end'] as $bound) {
            $time = $value[$bound] ?? null;
            if (!is_string($time) || 1 !== preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                return sprintf('quietHours.%s must be a HH:MM time.', $bound);
            }
        }
        return null;
    }
}
