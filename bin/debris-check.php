<?php
/**
 * Is anything the integration suite creates still on the site under test?
 *
 *   source bin/local-env.sh && php bin/debris-check.php
 *
 * Run it AFTER a suite run, and after a crashed one. Exit code 0 means the site is
 * clean; 1 means something prefixed `wpmcp-test-` is still there, and the listing says
 * what, of which kind, and which run id it carries. A NOTICE line - SQL reads switched on
 * by an operator - is printed above the verdict and does not change it.
 *
 * THIS PROCESS CREATES NOTHING, so it deliberately does not inherit a run id: it
 * invents its own, which makes every fixture on the site "foreign" and therefore
 * reported rather than deleted. That is the right default for a check run by hand -
 * another suite may be live, and deleting its users mid-test is exactly the damage the
 * per-run prefix exists to prevent. Remove what it names yourself, or let the next run
 * of that same run id clean up after itself.
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "debris-check: vendor/autoload.php is missing. Run composer install.\n");
    exit(2);
}

require $autoload;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\WpCli;

// A run id of this process's own, never an inherited one - see the docblock.
putenv(Fixtures::RUN_ID_ENV . '=');
unset($_ENV[Fixtures::RUN_ID_ENV], $_SERVER[Fixtures::RUN_ID_ENV]);

$reason = WpCli::unavailableReason();

if ($reason !== '') {
    fwrite(STDERR, "debris-check: {$reason}\n");
    exit(2);
}

// TWO KINDS, and the second one has no name to match on: a shared OPTION, so "did a run
// leave it on" cannot be answered by the run prefix. The post-meta allow-list's KEYS are
// prefixed, so a leftover key is debris. The sql-select switch is not: no test writes it,
// so when it is on an operator put it there, and it prints as a NOTICE that leaves the
// verdict clean (sprint 14b). See Fixtures::switchState().
$switches = Fixtures::switchState();

[$code, $output] = Fixtures::debrisVerdict(
    Fixtures::foreignDebris(),
    $switches['notices'],
    $switches['debris']
);

echo $output;
exit($code);
