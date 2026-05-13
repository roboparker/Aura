<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Loggable\Entity\MappedSuperclass\AbstractLogEntry;
use Gedmo\Loggable\Entity\Repository\LogEntryRepository;

/**
 * Activity log row backing the audit trail for Loggable entities (Task,
 * Project, …). Thin subclass of Gedmo's `AbstractLogEntry` so the entry
 * lives in `App\Entity` alongside our own model — which means it's
 * picked up by the `App` Doctrine mapping without extra configuration,
 * and we don't need to map Gedmo's vendor namespace separately. The
 * legacy `array` column type on `data` is aliased to JSON in
 * `config/packages/doctrine.yaml` (DBAL 4 dropped the original).
 *
 * @extends AbstractLogEntry<object>
 */
#[ORM\Entity(repositoryClass: LogEntryRepository::class)]
#[ORM\Table(name: 'ext_log_entries')]
#[ORM\Index(name: 'log_class_lookup_idx', columns: ['object_class'])]
#[ORM\Index(name: 'log_date_lookup_idx', columns: ['logged_at'])]
#[ORM\Index(name: 'log_user_lookup_idx', columns: ['username'])]
#[ORM\Index(name: 'log_version_lookup_idx', columns: ['object_id', 'object_class', 'version'])]
class ActivityLog extends AbstractLogEntry
{
}
