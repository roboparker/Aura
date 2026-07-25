<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Board;
use App\Repository\GlobalCustomFieldDefinitionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * While a board has Timeline (#timeline) enabled, the canonical global "Start
 * date" field stays in its field set — enabling attaches it, and any request
 * that leaves the feature on while dropping the field just re-adds it. The
 * field's presence is *derived* from the toggle: turning Timeline off is the
 * only way to remove it (the fields manager greys the toggle to say so).
 *
 * This runs after validation (it's the write processor), which is why the
 * invariant lives here and not in a validator — the field is legitimately
 * absent at validation time right after the client flips the toggle on, and
 * this makes it present before the write. We deliberately do NOT auto-detach on
 * disable: any start values tasks already hold stay intact, and the field can
 * be removed manually once the feature is off.
 *
 * @implements ProcessorInterface<Board, Board>
 */
final class BoardTimelineProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Board, Board> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private GlobalCustomFieldDefinitionRepository $globalFields,
    ) {
    }

    /**
     * @param Board $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Board
    {
        if ($data->isTimelineEnabled()) {
            $field = $this->globalFields->findTimelineStartField();
            // Null only on an install whose migrations haven't seeded the field;
            // the validator then reports the missing invariant rather than us
            // silently enabling a timeline with no start field.
            if (null !== $field && !$data->getGlobalCustomFieldDefinitions()->contains($field)) {
                $data->addGlobalCustomFieldDefinition($field);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
