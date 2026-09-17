<?php
/**
 * wp-cli against the site the integration tier is pointed at.
 *
 * Fixtures are seeded and torn down through the real CLI, not through SQL, so a user
 * gets its real roles and a post its real meta - the capability checks under test read
 * exactly what a human-created fixture would produce.
 *
 * TWO BACKENDS, because the tier runs in two places.
 *
 *   local   bin/local-env.sh defines `wp` as a shell FUNCTION (Local's own wp.bat is
 *           broken by the space in "Program Files (x86)"), and proc_open cannot reach
 *           a shell function. It does export the three parts the function is made of
 *           - PHP, WPCLI, WPMCP_SITE_PATH - so the invocation is reassembled here.
 *           Git Bash hands the native php.exe those already converted to Windows
 *           paths, so nothing needs translating.
 *   wp-env  In CI, WordPress lives inside a Docker container. A host-side
 *           wp-cli.phar --path= cannot see its filesystem at all, so calls go through
 *           `npx @wordpress/env run cli wp ...`. Selected by WPMCP_WP_ENV=1.
 *
 * Without the second backend the whole sprint-1 group SKIPPED in CI, and a skip is
 * green - the gate was only ever observable on one workstation. That is why
 * unavailableReason() now refuses to be a skip once WPMCP_TEST_URL is set: a site with
 * no way to seed it is a broken harness, and a broken harness must be red.
 *
 * proc_open is given an ARRAY, not a string: the arguments then never pass through a
 * shell, which is what lets `wp eval` carry PHP source containing quotes. wp-env's own
 * `run` re-quotes for the container, so eval snippets stay single-line and
 * single-quoted (see Fixtures::phpString).
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class WpCli
{
    /** Silences Local's PHP build, which warns about an imagick DLL it does not ship. */
    private const QUIET = ['-d', 'display_startup_errors=0', '-d', 'error_reporting=0'];

    /**
     * The wp-env service to run against. `cli` is the development instance, which is
     * the one .wp-env.json maps the plugin into and the one WPMCP_TEST_URL points at
     * in CI (port 8888). `tests-cli` is the separate 8889 instance.
     */
    private const WP_ENV_SERVICE = 'cli';

    /** True when calls should be routed through `npx @wordpress/env run cli wp`. */
    public static function usesWpEnv(): bool
    {
        return self::env('WPMCP_WP_ENV') === '1';
    }

    /**
     * Why wp-cli cannot be used, or '' when it can.
     *
     * Only ever a SKIP reason. FixtureIntegrationTestCase turns it into a failure
     * whenever WPMCP_TEST_URL is set, because then the site exists and being unable to
     * seed it is a bug in the harness, not an absent environment.
     */
    public static function unavailableReason(): string
    {
        if (self::usesWpEnv()) {
            return self::commandExists('npx')
                ? ''
                : 'wp-cli is not reachable: WPMCP_WP_ENV=1 but `npx` is not on PATH,'
                    . ' so `npx @wordpress/env run cli wp` cannot be started.';
        }

        foreach (['PHP', 'WPCLI', 'WPMCP_SITE_PATH'] as $name) {
            if (self::env($name) === '') {
                return "wp-cli is not reachable: \${$name} is not set. Fixtures need it;"
                    . ' run `source bin/local-env.sh` first, or set WPMCP_WP_ENV=1 to go'
                    . ' through @wordpress/env.';
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

        return self::clean($out);
    }

    /**
     * Run wp-cli and swallow a failure. For teardown, where "the user is already
     * gone" is a success condition and one failed step must not abandon the rest.
     */
    public static function tryRun(array $args): string
    {
        [, $out] = self::attempt($args);

        return self::clean($out);
    }

    /**
     * `wp eval <php>`. Returns whatever the snippet echoed.
     *
     * $asUser runs the snippet as that WordPress user. wp-cli has NO current user by
     * default - get_current_user_id() is 0 and current_user_can() is false for
     * everything - so anything that checks a capability has to say who it is acting
     * as. Minting is the case that matters: wpmcp_mint() requires edit_user over the
     * target, which nobody satisfies.
     */
    public static function evaluate(string $php, int $asUser = 0): string
    {
        $args = ['eval', $php];

        if ($asUser > 0) { $args[] = '--user=' . $asUser; }

        return self::run($args);
    }

    /**
     * `wp eval <php>` with the exit code, stdout and stderr, never throwing.
     *
     * For a script whose REFUSAL is the thing under test (sprint 14b, bin/dev-tokens.php):
     * run() would turn the non-zero exit into an exception and fold both streams into
     * its message, and the test needs to assert on each of the three separately.
     *
     * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
     */
    public static function evaluateWithStatus(string $php): array
    {
        [$code, $out, $err] = self::attempt(['eval', $php]);

        return [$code, self::clean($out), trim($err)];
    }

    /** `wp eval <php>`, failure swallowed. */
    public static function tryEvaluate(string $php): string
    {
        return self::tryRun(['eval', $php]);
    }

    /** @return array{0:int,1:string,2:string} [exit code, stdout, stderr] */
    private static function attempt(array $args): array
    {
        $command = self::usesWpEnv()
            ? array_merge(
                ['npx', '--yes', '@wordpress/env', 'run', self::WP_ENV_SERVICE, 'wp'],
                $args
            )
            : array_merge(
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

    /**
     * wp-env's `run` prints its own banner and a trailing blank line around the
     * container's output, so `--porcelain` is no longer the only thing on stdout.
     * Keep the last non-empty line that is not one of those markers.
     */
    private static function clean(string $out): string
    {
        $out = trim($out);

        if (!self::usesWpEnv() || $out === '') {
            return $out;
        }

        $lines = [];

        foreach (explode("\n", $out) as $line) {
            $line = trim($line, "\r\n ");

            // `> wp ...` is wp-env echoing the command; "✔ Ran `wp ...`" is its
            // completion banner. Neither is output from WordPress.
            if ($line === '' || str_starts_with($line, '> ') || str_contains($line, 'Ran `wp')) {
                continue;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** Is $name runnable? Used only to tell a missing toolchain from a broken call. */
    private static function commandExists(string $name): bool
    {
        $probe = stripos(PHP_OS_FAMILY, 'Windows') === 0
            ? ['where', $name]
            : ['sh', '-c', 'command -v ' . escapeshellarg($name)];

        $process = proc_open(
            $probe,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
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
