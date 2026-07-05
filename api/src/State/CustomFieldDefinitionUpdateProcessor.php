<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CustomField\CustomFieldTypeRegistry;
use App\CustomField\CustomFieldValueConverter;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Handles `PATCH /custom_field_definitions/{id}`. When the edit changes a
 * field's value shape — its (kind, subtype), its single↔multi mode, a
 * money field's currency, or a select field's option set — every existing
 * {@see CustomFieldValue} for the definition is run through
 * {@see CustomFieldValueConverter} so data is kept whenever it can be
 * reshaped (e.g. Integer → Money scales to minor units).
 *
 * Values that can't be represented in the new type are deleted — but only
 * after the caller opts in. Without `?confirmValueConversion=1` the
 * request is rejected with 409 Conflict (and an `X-Conversion-Lost-Count`
 * header) so the PWA can show a "these N values will be deleted" dialog
 * before anything is destroyed. Nothing is persisted on the rejection
 * path, so a declined confirmation leaves the field untouched.
 *
 * @implements ProcessorInterface<CustomFieldDefinition, CustomFieldDefinition>
 */
final class CustomFieldDefinitionUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<CustomFieldDefinition, CustomFieldDefinition> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $em,
        private CustomFieldValueConverter $converter,
        private CustomFieldTypeRegistry $registry,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param CustomFieldDefinition $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomFieldDefinition
    {
        $previous = $context['previous_data'] ?? null;
        if ($previous instanceof CustomFieldDefinition && $this->shapeChanged($previous, $data)) {
            $this->convertValues($previous, $data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Whether the edit could invalidate existing values — i.e. the type
     * key changed, the single↔multi mode flipped, a money field's currency
     * moved, or a select field's option set changed. Pure config tweaks
     * that leave the value shape intact (bounds, labels, colors) skip the
     * conversion pass entirely.
     */
    private function shapeChanged(CustomFieldDefinition $from, CustomFieldDefinition $to): bool
    {
        if ($from->getTypeKey() !== $to->getTypeKey()) {
            return true;
        }
        if ($this->converter->isMulti($from) !== $this->converter->isMulti($to)) {
            return true;
        }
        if ('numeric' === $to->getKind() && 'money' === $to->getSubtype()) {
            $fromCurrency = $from->getConfig()['currency'] ?? null;
            $toCurrency = $to->getConfig()['currency'] ?? null;
            $fromCurrency = is_string($fromCurrency) ? strtoupper($fromCurrency) : null;
            $toCurrency = is_string($toCurrency) ? strtoupper($toCurrency) : null;

            return $fromCurrency !== $toCurrency;
        }
        if ('select' === $to->getKind()) {
            return ($from->getConfig()['options'] ?? null) !== ($to->getConfig()['options'] ?? null);
        }

        return false;
    }

    private function convertValues(CustomFieldDefinition $from, CustomFieldDefinition $to): void
    {
        /** @var list<CustomFieldValue> $values */
        $values = $this->em->getRepository(CustomFieldValue::class)->findBy(['definition' => $to]);

        $toDelete = [];
        foreach ($values as $cfv) {
            $current = $cfv->getValue();
            if (null === $current) {
                continue;
            }
            [$ok, $converted] = $this->converter->convert($current, $from, $to);
            if ($ok) {
                if ($converted !== $current) {
                    $cfv->setValue($converted);
                    // Set value_search here too so it lands in the same
                    // change-set as `value`. An in-place CFV update is the
                    // one path that fires the search subscriber's preUpdate
                    // (task edits delete + re-insert instead), and that
                    // path can't add `valueSearch` to the change-set on its
                    // own — pre-setting it keeps the boardion consistent.
                    $cfv->setValueSearch($this->searchText($to, $converted));
                }
            } else {
                $toDelete[] = $cfv;
            }
        }

        if ([] === $toDelete) {
            return;
        }

        if (!$this->confirmed()) {
            $count = count($toDelete);
            throw new ConflictHttpException(
                sprintf(
                    '%d task value%s cannot be converted to "%s" and will be deleted.',
                    $count,
                    1 === $count ? '' : 's',
                    $to->getName(),
                ),
                null,
                0,
                ['X-Conversion-Lost-Count' => (string) $count],
            );
        }

        foreach ($toDelete as $cfv) {
            $this->em->remove($cfv);
        }
    }

    /**
     * The FTS boardion for a converted value under the new type — mirrors
     * {@see \App\CustomField\EventSubscriber\CustomFieldValueSearchSubscriber}'s
     * empty-string-to-null normalization.
     */
    private function searchText(CustomFieldDefinition $definition, mixed $value): ?string
    {
        $key = $definition->getTypeKey();
        if (!$this->registry->has($key)) {
            return null;
        }
        $text = $this->registry->get($key)->searchText($value, $definition->getConfig());

        return null === $text || '' === trim($text) ? null : $text;
    }

    /**
     * The caller has acknowledged that unconvertible values will be
     * deleted (the PWA re-sends the PATCH with this flag after the
     * confirmation dialog).
     */
    private function confirmed(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return false;
        }
        $flag = $request->query->get('confirmValueConversion');

        return '1' === $flag || 'true' === $flag;
    }
}
