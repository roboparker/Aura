<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runtime, admin-managed configuration for the social/enterprise sign-in
 * providers (#267). Stored as a single JSON blob in `app_setting` (key
 * `sso_config`) so admins can change it without a redeploy — this replaces the
 * old env-based config. Secret fields (client secret, Apple key) are encrypted
 * at rest via {@see TwoFactorSecretCipher}; only "hasSecret" flags are ever
 * exposed on reads.
 *
 * Instance-level for now; the blob is the seam for later per-plan / per-space
 * (enterprise-controlled) provider config.
 */
final class SsoConfig
{
    public const SETTING_NAME = 'sso_config';

    /**
     * Provider spec: which non-secret fields it carries, which secret fields it
     * carries, and the field set required for it to count as "configured".
     *
     * @var array<string, array{fields: list<string>, secrets: list<string>, required: list<string>}>
     */
    public const PROVIDERS = [
        'google' => ['fields' => [], 'secrets' => ['clientSecret'], 'required' => ['clientId', 'clientSecret']],
        'microsoft' => ['fields' => ['tenant'], 'secrets' => ['clientSecret'], 'required' => ['clientId', 'clientSecret']],
        'github' => ['fields' => [], 'secrets' => ['clientSecret'], 'required' => ['clientId', 'clientSecret']],
        'facebook' => ['fields' => [], 'secrets' => ['clientSecret'], 'required' => ['clientId', 'clientSecret']],
        'apple' => ['fields' => ['teamId', 'keyId'], 'secrets' => ['key'], 'required' => ['clientId', 'teamId', 'keyId', 'key']],
        'okta' => ['fields' => ['issuer'], 'secrets' => ['clientSecret'], 'required' => ['clientId', 'clientSecret', 'issuer']],
        'auth0' => ['fields' => ['issuer'], 'secrets' => ['clientSecret'], 'required' => ['clientId', 'clientSecret', 'issuer']],
    ];

    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly TwoFactorSecretCipher $cipher,
    ) {
    }

    /** @return array<string, array<array-key, mixed>> the raw blob (secrets still encrypted) */
    private function blob(): array
    {
        $row = $this->settings->findOneByName(self::SETTING_NAME);
        $value = $row?->getValue();
        if (!is_array($value)) {
            return [];
        }
        /** @var array<string, array<array-key, mixed>> $out */
        $out = [];
        foreach ($value as $provider => $config) {
            if (is_string($provider) && is_array($config)) {
                $out[$provider] = $config;
            }
        }

        return $out;
    }

    /** Slugs that are enabled AND have every required field set. */
    /** @return list<string> */
    public function enabledProviders(): array
    {
        $blob = $this->blob();
        $out = [];
        foreach (self::PROVIDERS as $provider => $spec) {
            $config = $blob[$provider] ?? null;
            if (!is_array($config) || true !== ($config['enabled'] ?? false)) {
                continue;
            }
            $ok = true;
            foreach ($spec['required'] as $field) {
                $v = $config[$field] ?? '';
                if (!is_string($v) || '' === $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /**
     * Full config for one provider with secrets DECRYPTED — for the client
     * factory when signing in. Null unless the provider is enabled AND fully
     * configured.
     *
     * @return array<array-key, mixed>|null
     */
    public function resolve(string $provider): ?array
    {
        if (!in_array($provider, $this->enabledProviders(), true)) {
            return null;
        }

        return $this->decrypted($provider);
    }

    /** Whether a provider has every required field (ignores the enable toggle). */
    public function isConfigured(string $provider): bool
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return false;
        }
        $config = $this->blob()[$provider] ?? [];
        foreach (self::PROVIDERS[$provider]['required'] as $field) {
            $v = $config[$field] ?? '';
            if (!is_string($v) || '' === $v) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decrypted credentials for a configured provider regardless of the login
     * toggle — used by OAuth account connections (#581), which reuse the same
     * app credentials with broader scopes.
     *
     * @return array<array-key, mixed>|null
     */
    public function credentials(string $provider): ?array
    {
        return $this->isConfigured($provider) ? $this->decrypted($provider) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decrypted(string $provider): array
    {
        $config = $this->blob()[$provider] ?? [];
        foreach (self::PROVIDERS[$provider]['secrets'] as $secret) {
            $enc = $config[$secret] ?? null;
            $config[$secret] = is_string($enc) ? ($this->cipher->decrypt($enc) ?? '') : '';
        }

        return $config;
    }

    /**
     * Admin-facing view: no secrets, just presence flags.
     *
     * @return array<string, array<string, mixed>>
     */
    public function redactedAll(): array
    {
        $blob = $this->blob();
        $out = [];
        foreach (self::PROVIDERS as $provider => $spec) {
            $config = $blob[$provider] ?? [];
            $row = ['provider' => $provider, 'enabled' => true === ($config['enabled'] ?? false)];
            $row['clientId'] = is_string($config['clientId'] ?? null) ? $config['clientId'] : '';
            foreach ($spec['fields'] as $field) {
                $row[$field] = is_string($config[$field] ?? null) ? $config[$field] : '';
            }
            foreach ($spec['secrets'] as $secret) {
                $row['has' . ucfirst($secret)] = is_string($config[$secret] ?? null) && '' !== $config[$secret];
            }
            $out[$provider] = $row;
        }

        return $out;
    }

    /**
     * Merge an admin update for one provider. Non-secret fields overwrite;
     * secret fields are only replaced when a non-empty new value is supplied
     * (so re-saving the form without re-typing a secret keeps it).
     *
     * @param array<array-key, mixed> $input
     */
    public function update(string $provider, array $input): void
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return;
        }
        $spec = self::PROVIDERS[$provider];
        $blob = $this->blob();
        $config = $blob[$provider] ?? [];

        $config['enabled'] = true === ($input['enabled'] ?? false);
        $config['clientId'] = is_string($input['clientId'] ?? null) ? trim($input['clientId']) : '';
        foreach ($spec['fields'] as $field) {
            $config[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : ($config[$field] ?? '');
        }
        foreach ($spec['secrets'] as $secret) {
            $new = $input[$secret] ?? null;
            if (is_string($new) && '' !== trim($new)) {
                $config[$secret] = $this->cipher->encrypt(trim($new));
            }
        }

        $blob[$provider] = $config;
        $row = $this->settings->findOneByName(self::SETTING_NAME) ?? new AppSetting(self::SETTING_NAME);
        $row->setValue($blob);
        $this->em->persist($row);
        $this->em->flush();
    }
}
