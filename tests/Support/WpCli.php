<?php
/**
 * wp-cli against the site the integration tier is pointed at.
 *
 * Fixtures are seeded and torn down through the real CLI, not through SQL, so a user
 * gets its real roles and a post its real meta - the capability checks under test read
 * exactly what a human-created fixture would produce.
 *
 * WHY THE COMMAND IS REBUILT HERE. bin/local-env.sh defines `wp` as a *shell function*
 * (Local's own wp.bat is broken by the space in "Program Files (x86)"), and a shell
 * function cannot be reached from proc_open. It does however export the three parts
 * the function is made of - PHP, WPCLI, WPMCP_SITE_PATH - so this class reassembles
 * the same invocation. Git Bash hands the native php.exe those variables already
 * converted to Windows paths, so nothing has to be translated here.
 *
 * proc_open is given an ARRAY, not a string: the arguments then never pass through a
 * shell, which is what lets `wp eval` carry PHP source containing quotes.
 *
 * The three variables come from the environment, so an un-sourced shell (or CI, where
 * wp-cli lives elsewhere) reports "not available" and the fixture-bearing tests skip
 * with a message that names what is missing, instead of failing as though the code
 * were broken.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class WpCli
{
    /** Silences Local's PHP build, which warns about an imagick DLL it does not ship. */
    private const QUIET = ['-d', 'display_startup_errors=0', '-d', 'error_reporting=0'];

    /** Why wp-cli cannot be used, or '' when it can. */
    public static function unavailableReason(): string
    {
        foreach (['PHP', 'WPCLI', 'WPMCP_SITE_PATH'] as $name) {
            if (self::env($name) === '') {
                return "wp-cli is not reachable: \${$name} is not set. Fixtures need it;"
                    . ' run `source bin/local-env.sh` first.';
            }
        }

        if (!is_file(self::env('WPCLI'))) {
            return 'wp-cli is not reachable: $WPCLI points at ' . self::env('WPCLI')
                . ', which does not exist.';
        }

        return '';
    }

    /**
     * Run wp-cli and return trimmed stdout.
     *
     * @param list<string> $args e.g. ['post', 'create', '--porcelain']
     * @throws RuntimeException on a non-zero exit
     */
    public static function run(array $args): string
    {
        [$code, $out, $err] = self::attempt($args);

        if ($code !== 0) {
            throw new RuntimeException(
                'wp ' . implode(' ', $args) . " exited {$code}.\n"
                . trim($out . "\n" . $err)
            );
        }

        return trim($out);
    }

    /**
     * Run wp-cli and swallow a failure. For teardown, where "the user is already
     * gone" is a success condition and one failed step must not abandon the rest.
     */
    public static function tryRun(array $args): string
    {
        [, $out] = self::attempt($args);

        return trim($out);
    }

    /** `wp eval <php>`. Returns whatever the snippet echoed. */
    public static function evaluate(string $php): string
    {
        return self::run(['eval', $php]);
    }

    /** `wp eval <php>`, failure swallowed. */
    public static function tryEvaluate(string $php): string
    {
        return self::tryRun(['eval', $php]);
    }

    /** @return array{0:int,1:string,2:string} [exit code, stdout, stderr] */
    private static function attempt(array $args): array
    {
        $command = array_merge(
            [self::env('PHP')],
            self::QUIET,
            [self::env('WPCLI'), '--path=' . self::env('WPMCP_SITE_PATH')],
            $args
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start wp-cli: ' . implode(' ', $args));
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** getenv() and $_ENV disagree depending on how PHPUnit was launched; check both. */
    private static function env(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }

        return trim((string) $value);
    }
}
