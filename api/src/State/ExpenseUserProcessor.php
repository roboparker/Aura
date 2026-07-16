<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Expense;
use App\Security\AuthenticatedUserResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stamps the current user as the expense's spender on create (server-side,
 * never from the payload — same trust model as TimeEntryUserProcessor) and
 * freezes billed expenses: once an expense backs an invoice line, editing it
 * would desync the financial document from the cost behind it.
 *
 * @implements ProcessorInterface<Expense, Expense>
 */
final class ExpenseUserProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Expense, Expense> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
    ) {
    }

    /**
     * @param Expense $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Expense
    {
        $user = $this->auth->requireUser('track expenses');

        $isCreate = null === $data->getUser();
        if (!$isCreate && null !== $data->getBilledAt()) {
            throw new UnprocessableEntityHttpException(
                'This expense is on an invoice and can no longer be edited.',
            );
        }
        if ($isCreate) {
            $data->setUser($user);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
