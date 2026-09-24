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

// A THIRD KIND, and it is the opposite shape to the other two: not something a run LEFT
// BEHIND but something a run TOOK AWAY. Five integration classes unschedule the plugin's
// hourly sweep for their duration, because a dead token row is exactly what the sweep
// deletes and those classes need one to survive (see Fixtures::suspendTokenSweep). Every
// one of them puts it back in destroy(). A run killed between the two - Ctrl-C, a cancelled
// gate, a crashed runner - leaves the site with no sweep at all, and the site then keeps
// dead token rows for ever with nothing saying why. This script is the project's answer to
// "did a killed run leave something behind", so it has to be able to answer this too
// (analysis/58 §6).
//
// It is NOT in the "clean" sentence's list, deliberately: that sentence enumerates things
// that should not be there, and a missing cron event is the other direction. It prints only
// when it is missing, and then it is debris and the exit code is 1.
$cron = wpmcp_debris_sweep_report();

[$code, $output] = Fixtures::debrisVerdict(
    Fixtures::foreignDebris() . $cron,
    $switches['notices'],
    $switches['debris']
);

echo $output;
exit($code);

/**
 * '' when the plugin's hourly sweep is scheduled, a debris report when it is not.
 *
 * Tolerant on purpose: this script already exits 2 when wp-cli cannot be reached, so by the
 * time it runs the site answers. A malformed answer is reported rather than guessed at,
 * because "I could not tell" and "it is scheduled" must not look the same here.
 */
function wpmcp_debris_sweep_report(): string
{
    $answer = trim(WpCli::tryEvaluate(
        'echo wp_next_scheduled("wpmcp_flush_expired") ? "SCHEDULED " . gmdate("Y-m-d H:i:s", wp_next_scheduled("wpmcp_flush_expired")) . " UTC" : "MISSING";'
    ));

    if (strpos($answer, 'SCHEDULED') === 0) {
        return '';
    }

    return "DEBRIS: the hourly token sweep (wpmcp_flush_expired) is NOT scheduled on this site.\n"
        . "  Five integration classes take it off the schedule for their duration and put it back\n"
        . "  in destroy(); a run killed in between leaves it off, and the site then keeps dead\n"
        . "  token rows for ever. wp-cli said: " . ($answer === '' ? '(no answer)' : $answer) . "\n"
        . "  Put it back by deactivating and reactivating the plugin, which is what schedules it,\n"
        . "  or: wp eval 'wp_schedule_event(time() + HOUR_IN_SECONDS, \"hourly\", \"wpmcp_flush_expired\");'\n";
}
