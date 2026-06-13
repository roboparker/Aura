<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace ApiToken's coarse `scopes` allow-list with the unified
 * `access_policy` model (the same none/view/edit per-category + per-item shape
 * used for admin-impersonation consent). See App\Security\Access\AccessPolicy.
 *
 * The `access_policy` column is added in up() (deferred), then postUp()
 * converts each existing token's legacy scopes into a policy and drops the old
 * column — so the conversion runs while both columns exist:
 *   - []            → NULL  (unrestricted: acts as owner)
 *   - contains admin → NULL  (superset)
 *   - read:X / write:X → { categories: { X: view|edit } }
 */
final class Version20260613120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace api_token.scopes with api_token.access_policy (unified AccessPolicy).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD access_policy JSON DEFAULT NULL');
    }

    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, scopes FROM api_token');
        foreach ($rows as $row) {
            $id = $row['id'];
            $rawScopes = $row['scopes'];
            $scopes = is_string($rawScopes) ? json_decode($rawScopes, true) : $rawScopes;
            $policy = $this->scopesToPolicy(is_array($scopes) ? $scopes : []);
            $this->connection->executeStatement(
                'UPDATE api_token SET access_policy = :policy WHERE id = :id',
                [
                    'policy' => null === $policy ? null : json_encode($policy, JSON_THROW_ON_ERROR),
                    'id' => $id,
                ],
            );
        }
        $this->connection->executeStatement('ALTER TABLE api_token DROP COLUMN scopes');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE api_token ADD scopes JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE api_token DROP COLUMN access_policy');
    }

    /**
     * @param list<mixed> $scopes
     *
     * @return array{categories: array<string, string>, items: array<string, array<string, string>>}|null
     */
    private function scopesToPolicy(array $scopes): ?array
    {
        if ([] === $scopes || in_array('admin', $scopes, true)) {
            return null; // unrestricted
        }

        $categories = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            [$action, $resource] = array_pad(explode(':', $scope, 2), 2, '');
            if ('' === $resource) {
                continue;
            }
            $level = 'write' === $action ? 'edit' : 'view';
            // write wins over read for the same resource.
            if ('edit' === $level || !isset($categories[$resource])) {
                $categories[$resource] = $level;
            }
        }

        return ['categories' => $categories, 'items' => []];
    }
}
