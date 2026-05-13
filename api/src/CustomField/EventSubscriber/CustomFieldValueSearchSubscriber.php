<?php

declare(strict_types=1);

namespace App\CustomField\EventSubscriber;

use App\CustomField\CustomFieldTypeRegistry;
use App\Entity\CustomFieldValue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps {@see CustomFieldValue::$valueSearch} aligned with the live
 * value by dispatching to the type strategy on every persist + update.
 *
 * Each strategy decides how its value renders to plain searchable text
 * (text strategies trim + join, money emits "<amount> <currency>",
 * references dereference and emit the target's display label), so
 * concentrating the projection here means the FTS index always
 * reflects the strategy's choice without duplicating the rules in SQL
 * or in entity lifecycle hooks.
 *
 * Registered with explicit `AsDoctrineListener` rather than a regular
 * event subscriber because Doctrine lifecycle events need an
 * `EntityManagerInterface`-aware listener and we want this to fire
 * inside the same UoW pass that flushed the CFV.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class CustomFieldValueSearchSubscriber
{
    public function __construct(private readonly CustomFieldTypeRegistry $registry)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CustomFieldValue) {
            return;
        }
        $this->refresh($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof CustomFieldValue) {
            return;
        }
        $previous = $entity->getValueSearch();
        $this->refresh($entity);
        // preUpdate fires inside the change-set computation, so a
        // late mutation needs an explicit recompute or Doctrine
        // ignores it on the UPDATE statement.
        if ($previous !== $entity->getValueSearch()) {
            $args->setNewValue('valueSearch', $entity->getValueSearch());
        }
    }

    private function refresh(CustomFieldValue $cfv): void
    {
        $definition = $cfv->getDefinition();
        if (null === $definition) {
            $cfv->setValueSearch(null);
            return;
        }

        $key = $definition->getTypeKey();
        if (!$this->registry->has($key)) {
            // Unknown type — leave value_search empty rather than
            // calling into a strategy that won't exist. The
            // definition itself will already have failed
            // ValidCustomFieldDefinition by the time we get here.
            $cfv->setValueSearch(null);
            return;
        }

        $strategy = $this->registry->get($key);
        $text = $strategy->searchText($cfv->getValue(), $definition->getConfig());
        $cfv->setValueSearch(null === $text || '' === trim($text) ? null : $text);
    }
}
