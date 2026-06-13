<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

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

            $error = $this->validateValue($key, $value, $user);
            if (null !== $error) {
                return $this->json(['error' => $error], 422);
            }
            // Preferences merge shallowly at the top level, so a partial
            // impersonationAccess patch (only the categories that changed)
            // would otherwise drop the rest. Merge it over the current full
            // matrix to keep unspecified categories intact.
            if ('impersonationAccess' === $key && is_array($value)) {
                $existing = is_array($current['impersonationAccess'] ?? null)
                    ? $current['impersonationAccess']
                    : [];
                $value = array_merge($existing, $value);
            }
            // Same shallow-merge guard for per-item overrides, but only at the
            // item-type level: a patch carrying `{ project: {...} }` replaces
            // the whole project map (so omitting an id removes that override)
            // while leaving the other item types intact.
            if ('impersonationItemAccess' === $key && is_array($value)) {
                $existing = is_array($current['impersonationItemAccess'] ?? null)
                    ? $current['impersonationItemAccess']
                    : [];
                $value = array_merge($existing, $value);
            }
            // `landing.spaceId` only carries meaning for the 'space'
            // page — normalise it away for the other destinations so a
            // stale id can't linger on the stored object.
            if ('landing' === $key && is_array($value)) {
                $value = [
                    'page' => $value['page'],
                    'spaceId' => 'space' === $value['page'] ? ($value['spaceId'] ?? null) : null,
                ];
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
    private function validateValue(string $key, mixed $value, User $user): ?string
    {
        return match ($key) {
            'timezone' => is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(), true)
                ? null
                : 'timezone must be a valid IANA time-zone identifier.',
            'notificationFrequency' => is_string($value) && in_array($value, User::ALLOWED_FREQUENCIES, true)
                ? null
                : 'notificationFrequency must be one of: realtime, hourly, daily.',
            'emailNotificationsEnabled', 'pushNotificationsEnabled', 'canBeImpersonated' => is_bool($value)
                ? null
                : sprintf('%s must be a boolean.', $key),
            'impersonationAccess' => $this->validateImpersonationAccess($value),
            'impersonationItemAccess' => $this->validateImpersonationItemAccess($value),
            'notificationMatrix' => $this->validateMatrix($value),
            'emailDigest' => $this->validateDigest($value),
            'quietHours' => $this->validateQuietHours($value),
            'landing' => $this->validateLanding($value, $user),
            default => sprintf('Unknown preference key: %s.', $key),
        };
    }

    /**
     * Validate the "Start page" preference. `page` is whitelisted; for the
     * 'space' destination a non-null `spaceId` must be a space the user
     * belongs to. Unknown and forbidden spaces are rejected identically
     * (404-shape) so the setting can't probe space existence.
     */
    private function validateLanding(mixed $value, User $user): ?string
    {
        if (!is_array($value)) {
            return 'landing must be an object.';
        }
        $page = $value['page'] ?? null;
        if (!is_string($page) || !in_array($page, User::LANDING_PAGES, true)) {
            return 'landing.page must be one of: tasks, notifications, spaces, space.';
        }
        if ('space' !== $page) {
            return null;
        }
        $spaceId = $value['spaceId'] ?? null;
        // null = the personal "Private" space; no lookup needed.
        if (null === $spaceId) {
            return null;
        }
        $invalid = 'landing.spaceId must reference a space you belong to.';
        if (!is_string($spaceId) || !Uuid::isValid($spaceId)) {
            return $invalid;
        }
        $space = $this->em->getRepository(Space::class)->find(Uuid::fromString($spaceId));
        if (null === $space || !$space->hasMember($user)) {
            return $invalid;
        }
        return null;
    }

    /**
     * Validate the per-category impersonation access matrix. Accepts a
     * partial object (only the categories being changed); each value must be
     * a known category mapped to one of the access levels.
     */
    private function validateImpersonationAccess(mixed $value): ?string
    {
        if (!is_array($value)) {
            return 'impersonationAccess must be an object.';
        }
        foreach ($value as $category => $level) {
            if (!is_string($category) || !in_array($category, User::IMPERSONATION_CATEGORIES, true)) {
                return sprintf(
                    'Unknown impersonation category: %s.',
                    is_string($category) ? $category : gettype($category),
                );
            }
            if (!is_string($level) || !in_array($level, User::IMPERSONATION_LEVELS, true)) {
                return sprintf('impersonationAccess.%s must be one of: none, view, edit.', $category);
            }
        }
        return null;
    }

    /**
     * Validate per-item impersonation overrides: { itemType: { uuid: level } }.
     * Item type must be addressable; keys must be UUIDs; levels are the same
     * none/view/edit set. A patch may carry only the types being changed.
     */
    private function validateImpersonationItemAccess(mixed $value): ?string
    {
        if (!is_array($value)) {
            return 'impersonationItemAccess must be an object.';
        }
        foreach ($value as $type => $items) {
            if (!is_string($type) || !in_array($type, User::IMPERSONATION_ITEM_TYPES, true)) {
                return sprintf(
                    'Unknown impersonation item type: %s.',
                    is_string($type) ? $type : gettype($type),
                );
            }
            if (!is_array($items)) {
                return sprintf('impersonationItemAccess.%s must be an object.', $type);
            }
            foreach ($items as $id => $level) {
                if (!is_string($id) || !Uuid::isValid($id)) {
                    return sprintf('impersonationItemAccess.%s keys must be item UUIDs.', $type);
                }
                if (!is_string($level) || !in_array($level, User::IMPERSONATION_LEVELS, true)) {
                    return sprintf('impersonationItemAccess.%s.%s must be one of: none, view, edit.', $type, $id);
                }
            }
        }
        return null;
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
