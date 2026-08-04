<?php

declare(strict_types=1);

namespace App\Command;

use App\Billing\PlanMatrixExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print the plan matrix as the TypeScript module the public pricing page
 * imports. See {@see PlanMatrixExporter} for why the page needs a generated
 * copy rather than reading the entitlement API.
 *
 *   bin/console app:plans:export-matrix > pwa/lib/planEntitlements.generated.ts
 *
 * In practice you want the wrapper, which knows the destination path:
 *
 *   scripts/gen-plan-matrix.sh
 *
 * Writes to stdout deliberately. The api container only bind-mounts ./api, so
 * it cannot write into pwa/ — the redirect has to happen on the host.
 */
#[AsCommand(
    name: 'app:plans:export-matrix',
    description: 'Print the plan entitlement matrix as a TypeScript module for the PWA.',
)]
final class ExportPlanMatrixCommand extends Command
{
    public function __construct(private readonly PlanMatrixExporter $exporter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // write(), not writeln(): export() already ends with a newline, and
        // SymfonyStyle would decorate output that has to stay byte-exact.
        $output->write($this->exporter->export());

        return Command::SUCCESS;
    }
}
