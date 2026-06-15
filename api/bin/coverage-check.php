#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Minimal PHP coverage-floor gate (no Composer dependency).
 *
 * Parses a Clover XML report and fails (exit 1) when line coverage falls
 * below a floor. Wired into CI after PHPUnit runs with `--coverage-clover`.
 * The floor is intentionally a *minimum* — a regression guard, not a target.
 *
 * Usage:
 *   php bin/coverage-check.php <clover.xml> [minPercent]
 *
 * `minPercent` defaults to the COVERAGE_MIN env var, then to 70.
 *
 * "Line coverage" here is Clover's `coveredstatements / statements`, which
 * matches the "Lines" figure PHPUnit's own `--coverage-text` summary prints.
 */

$cloverPath = $argv[1] ?? '';
if ($cloverPath === '' || !is_file($cloverPath)) {
    fwrite(STDERR, "coverage-check: clover report not found at '{$cloverPath}'\n");
    fwrite(STDERR, "usage: php bin/coverage-check.php <clover.xml> [minPercent]\n");
    exit(2);
}

$minRaw = $argv[2] ?? getenv('COVERAGE_MIN');
$min = is_numeric($minRaw) ? (float) $minRaw : 70.0;

$xml = @simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "coverage-check: could not parse clover XML at '{$cloverPath}'\n");
    exit(2);
}

// Project-level summary metrics (one node: /coverage/project/metrics).
$metrics = $xml->xpath('/coverage/project/metrics');
if ($metrics === false || $metrics === null || count($metrics) === 0) {
    fwrite(STDERR, "coverage-check: no <project><metrics> node in clover report\n");
    exit(2);
}

$summary = $metrics[0];
$statements = (int) ($summary['statements'] ?? 0);
$covered = (int) ($summary['coveredstatements'] ?? 0);

if ($statements === 0) {
    fwrite(STDERR, "coverage-check: zero statements reported — refusing to pass a hollow run\n");
    exit(2);
}

$percent = $covered / $statements * 100;
$rounded = round($percent, 2);

printf("Line coverage: %.2f%% (%d/%d statements) — floor %.2f%%\n", $rounded, $covered, $statements, $min);

if ($percent + 1e-9 < $min) {
    fwrite(STDERR, sprintf("coverage-check: FAIL — %.2f%% is below the %.2f%% floor\n", $rounded, $min));
    exit(1);
}

echo "coverage-check: OK\n";
exit(0);
