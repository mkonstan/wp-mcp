<?php
/**
 * Is anything the integration suite creates still on the site under test?
 *
 *   source bin/local-env.sh && php bin/debris-check.php
 *
 * Run it AFTER a suite run, and after a crashed one. Exit code 0 means the site is
 * clean; 1 means something prefixed `wpmcp-test-` is still there, and the listing says
 * what, of which kind, and which run id it carries.
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

// TWO KINDS, and the second one has no name to match on: an opt-in switch is a shared
// value, so "did a run leave it on" cannot be answered by the run prefix. See
// Fixtures::switchesLeftOn().
$report   = Fixtures::foreignDebris();
$switches = Fixtures::switchesLeftOn();

if ($report === '' && $switches === '') {
    echo "debris-check: clean - no wpmcp-test-* users, posts, terms, tokens, mu-plugins,"
        . " transients, theme files or file-version rows, and no opt-in switch left on.\n";
    exit(0);
}

echo $report;

if ($report !== '' && $switches !== '') { echo "\n"; }

echo $switches;
exit(1);
