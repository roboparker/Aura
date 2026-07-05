<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\CustomField\CustomFieldKind;
use App\CustomField\Type\AbstractTypeStrategy;
use App\CustomField\Type\MultiValueHelper;
use App\CustomField\Type\TypeViolation;
use App\Entity\CustomFieldDefinitionInterface;
use App\Entity\Space;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared validation + normalization for reference kinds. Value shape
 * is an IRI string (`/users/<uuid>`, `/tasks/<uuid>`, …); each subtype
 * pins the target entity class via {@see targetClass()} and the IRI
 * prefix via {@see iriPrefix()}.
 *
 * Scope rule (#227-locked): every reference type scopes to the field's
 * space. `reference.user` resolves through `space.getEffectiveUsers()`;
 * the entity-backed subtypes (`task`, `page`, `discussion`) inner-join
 * the target's `space` column. Cross-space references are rejected
 * during validation — a 404 from the API would be more familiar to
 * clients than a 422, but the validation layer is what catches the
 * other shape errors and emitting a single consistent 422 keeps the
 * client error-handling code path narrow.
 *
 * Config: `{multi}`. No per-field scope knob — the field's space is
 * the scope.
 *
 * Footer: `count` only — sum/avg/min/max aren't meaningful on opaque
 * IDs.
 */
abstract class AbstractReferenceStrategy extends AbstractTypeStrategy
{
    use MultiValueHelper;

    public function __construct(protected readonly EntityManagerInterface $em)
    {
    }

    public function kind(): string
    {
        return CustomFieldKind::REFERENCE->value;
    }

    public function supportsMulti(): bool
    {
        return true;
    }

    /**
     * Fully-qualified target entity class. Used for repository lookup
     * + scope checks.
     *
     * @return class-string
     */
    abstract protected function targetClass(): string;

    /**
     * Leading URL segment for the IRI form — `/users/`, `/tasks/`, etc.
     */
    abstract protected function iriPrefix(): string;

    /**
     * Plain-text display label for a resolved target. Drives the FTS
     * search-text projection — emitting "Alice Example" rather than a
     * UUID is the whole point of the strategy-owned projection.
     */
    abstract protected function displayLabel(object $target): string;

    /**
     * Whether the resolved target is reachable from the field's space.
     * `reference.user` overrides to walk the space's effective-users
     * set (direct + via-group); the entity-backed subtypes check
     * `target->getSpace() === $field->getSpace()`.
     */
    abstract protected function isInScope(object $target, Space $space): bool;

    public function validateValue(mixed $value, array $config, CustomFieldDefinitionInterface $definition): array
    {
        $multi = $this->isMulti($config);
        $shape = $this->ensureMultiShape($value, $multi);
        if (null !== $shape) {
            return [$shape];
        }

        $space = $definition->getSpace();
        $violations = [];
        foreach ($this->walkValues($value, $multi) as [$path, $element]) {
            foreach ($this->validateScalar($element, $space) as $v) {
                $violations[] = new TypeViolation($path . $v->path, $v->message, $v->parameters);
            }
        }
        return $violations;
    }

    public function searchText(mixed $value, array $config): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($this->isMulti($config) && is_array($value)) {
            $parts = [];
            foreach ($value as $element) {
                $label = $this->labelFor($element);
                if (null !== $label) {
                    $parts[] = $label;
                }
            }
            return [] === $parts ? null : implode(' ', $parts);
        }
        return $this->labelFor($value);
    }

    /**
     * @return list<TypeViolation>
     */
    protected function validateScalar(mixed $value, ?Space $space): array
    {
        if (!is_string($value)) {
            return [new TypeViolation('', 'Expected a reference IRI string.')];
        }
        $uuid = $this->extractUuid($value);
        if (null === $uuid) {
            return [new TypeViolation('', sprintf('Expected an IRI of the form %s{uuid}.', $this->iriPrefix()))];
        }

        $target = $this->em->getRepository($this->targetClass())->find($uuid);
        if (null === $target) {
            return [new TypeViolation('', 'Referenced item does not exist.')];
        }

        if (null !== $space && !$this->isInScope($target, $space)) {
            // Hide cross-space items behind the same "doesn't exist"
            // message — leaking "exists but in another space" through
            // validation messages would hand a probe attacker a free
            // existence oracle.
            return [new TypeViolation('', 'Referenced item does not exist.')];
        }

        return [];
    }

    protected function labelFor(mixed $iri): ?string
    {
        if (!is_string($iri)) {
            return null;
        }
        $uuid = $this->extractUuid($iri);
        if (null === $uuid) {
            return null;
        }
        $target = $this->em->getRepository($this->targetClass())->find($uuid);
        return null === $target ? null : trim($this->displayLabel($target));
    }

    private function extractUuid(string $iri): ?Uuid
    {
        $prefix = $this->iriPrefix();
        if (!str_starts_with($iri, $prefix)) {
            return null;
        }
        $candidate = substr($iri, strlen($prefix));
        if (!Uuid::isValid($candidate)) {
            return null;
        }
        return Uuid::fromString($candidate);
    }
}
