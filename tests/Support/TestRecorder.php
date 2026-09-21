<?php
/**
 * A mu-plugin that writes down what happened INSIDE the request the test just made.
 *
 * Sprint 2's observable claims are hook firings: `wpmcp_auth_event` fired once, with
 * that reason and without token material; `preprocess_comment` ran, so Akismet and the
 * notification mails are back in the path; the tool registry refused an entry the
 * `wpmcp_tools` filter added. All of that happens in the server's process, and the
 * test is a black-box HTTP client - so the observation has to be left behind on the
 * site and fetched afterwards. A transient is the cheapest durable place WordPress
 * already has, and `wp eval` reads it back.
 *
 * ONLY OUR OWN REQUESTS ARE RECORDED. A mu-plugin is loaded by every request the site
 * serves, including a concurrent runner's and the operator's own browsing, so the
 * recorder gates on this run's id: over HTTP the X-Wpmcp-Test-Run header the suite's
 * Guzzle client always sends, and under wp-cli the inherited WPMCP_TEST_RUN_ID.
 * Without that gate "fired exactly once" quietly becomes "fired once per live runner".
 *
 * The recorder is a FILTER on preprocess_comment and returns its input untouched, so
 * observing the comment pipeline does not alter it.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class TestRecorder
{
    /** mu-plugin slug; the file is wpmcp-test-<run id>-recorder.php. */
    public const SLUG = 'recorder';

    /** Prefix every auth event is recorded under, so it cannot collide with a filter name. */
    public const AUTH = 'auth:';

    public static function install(): void
    {
        MuPlugin::drop(self::SLUG, self::source());
    }

    public static function uninstall(): void
    {
        self::reset();
        MuPlugin::remove(self::SLUG);
    }

    /** Forget everything recorded so far. Call it immediately before the act under test. */
    public static function reset(): void
    {
        WpCli::tryEvaluate(sprintf(
            'echo (int) delete_transient(%s);',
            self::phpString(self::transient())
        ));
    }

    /**
     * Everything recorded since the last reset(), in order.
     *
     * @return list<array{event: string, detail: array<string, mixed>}>
     */
    public static function events(): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$log = get_transient(%s); echo wp_json_encode(is_array($log) ? $log : array());',
            self::phpString(self::transient())
        ));

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** How many times $event was recorded. The "exactly once" assertions read this. */
    public static function countOf(string $event): int
    {
        return count(self::detailsOf($event));
    }

    /**
     * The context array of every recording of $event.
     *
     * @return list<array<string, mixed>>
     */
    public static function detailsOf(string $event): array
    {
        $found = [];

        foreach (self::events() as $entry) {
            if (($entry['event'] ?? '') === $event) {
                $found[] = is_array($entry['detail'] ?? null) ? $entry['detail'] : [];
            }
        }

        return $found;
    }

    /** The transient the mu-plugin writes and this class reads. */
    public static function transient(): string
    {
        return Fixtures::name('events');
    }

    /** The mu-plugin body, with this run's id baked in. */
    private static function source(): string
    {
        $run       = Fixtures::runId();
        $auth      = self::AUTH;
        $transient = self::transient();
        $header    = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));

        return <<<PHP
/**
 * wp-mcp integration-suite recorder for run {$run}. Dropped and removed by
 * WpMcp\Tests\Support\TestRecorder. If you are reading this on a live site, the
 * run that wrote it crashed; deleting the file is safe.
 */

\$wpmcp_test_run       = '{$run}';
\$wpmcp_test_transient = '{$transient}';

\$wpmcp_test_is_ours = static function () use (\$wpmcp_test_run) {
    if (PHP_SAPI === 'cli') {
        return trim((string) getenv('WPMCP_TEST_RUN_ID')) === \$wpmcp_test_run;
    }

    return isset(\$_SERVER['{$header}'])
        && \$_SERVER['{$header}'] === \$wpmcp_test_run;
};

/*
 * CAPPED, both ways. This is a read-append-write on every event, so an unbounded log is
 * O(n^2) in bytes within the TTL, and the events it records carry attacker-shaped values
 * (an Origin header, a tool name). 200 entries is far more than any single test makes -
 * the assertions count to 1 - so a run that hits the cap has a runaway, and keeping the
 * NEWEST 200 is what leaves that visible.
 */
\$wpmcp_test_record = static function (\$event, \$detail) use (\$wpmcp_test_is_ours, \$wpmcp_test_transient) {
    if (!\$wpmcp_test_is_ours()) {
        return;
    }

    \$log = get_transient(\$wpmcp_test_transient);

    if (!is_array(\$log)) {
        \$log = array();
    }

    \$trim = static function (\$value) use (&\$trim) {
        if (is_array(\$value)) {
            return array_map(\$trim, \$value);
        }

        return is_string(\$value) && strlen(\$value) > 512
            ? substr(\$value, 0, 512) . '...'
            : \$value;
    };

    \$log[] = array('event' => (string) \$event, 'detail' => \$trim((array) \$detail));

    if (count(\$log) > 200) {
        \$log = array_slice(\$log, -200);
    }

    set_transient(\$wpmcp_test_transient, \$log, 600);
};

add_action('wpmcp_auth_event', static function (\$type, \$context) use (\$wpmcp_test_record) {
    \$wpmcp_test_record('{$auth}' . \$type, \$context);
}, 10, 2);

add_filter('preprocess_comment', static function (\$commentdata) use (\$wpmcp_test_record) {
    \$wpmcp_test_record('preprocess_comment', array(
        'comment_content' => isset(\$commentdata['comment_content']) ? \$commentdata['comment_content'] : '',
        'comment_post_ID' => isset(\$commentdata['comment_post_ID']) ? (int) \$commentdata['comment_post_ID'] : 0,
    ));

    return \$commentdata;
}, 1);

add_action('comment_post', static function (\$comment_id, \$approved) use (\$wpmcp_test_record) {
    \$wpmcp_test_record('comment_post', array('id' => (int) \$comment_id, 'approved' => \$approved));
}, 10, 2);

/*
 * THE OPCODE-CACHE WITNESS (sprint 14e). wpmcp_opcache_invalidate() fires this action
 * immediately after calling wp_opcache_invalidate(), so a recording is proof the call was
 * made - and the FILE IS READ FROM DISK HERE, which is what makes the recording proof of
 * ORDER as well: an invalidation that ran before its write would record the old bytes, and
 * one that ran before its rollback would record the rolled-back-from bytes. Without the
 * disk read this would only say "our own helper was reached".
 */
add_action('wpmcp_compiled_file_changed', static function (\$abs, \$invalidated) use (\$wpmcp_test_record) {
    \$abs = (string) \$abs;

    // unlink() clears the entry for its own path, but the write branch stat()ed the file
    // before touching it; clear it so `exists` is this moment's answer, not an older one.
    clearstatcache(true, \$abs);

    \$exists = is_file(\$abs);

    \$wpmcp_test_record('wpmcp_compiled_file_changed', array(
        'path'        => \$abs,
        'invalidated' => \$invalidated ? 1 : 0,
        'exists'      => \$exists ? 1 : 0,
        'md5'         => \$exists ? (string) md5_file(\$abs) : '',
        // wp-admin/includes/file.php is NOT loaded on a REST request, so this is the half
        // of the fix that a code-reading review cannot confirm.
        'core_loaded' => function_exists('wp_opcache_invalidate') ? 1 : 0,
        // What core's own guard will answer on this host, measured in the request that
        // made the call rather than assumed from a php.ini somebody read.
        'opcache_on'  => (function_exists('opcache_invalidate') && ini_get('opcache.enable')) ? 1 : 0,
        'restrict'    => (string) ini_get('opcache.restrict_api'),
    ));
}, 10, 2);
PHP;
    }

    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
