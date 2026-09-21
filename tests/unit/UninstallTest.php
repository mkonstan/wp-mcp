<?php
/**
 * uninstall.php names every option the plugin writes.
 *
 * WHY THIS IS THE TEST AND NOT A SMARTER ONE. uninstall.php cannot ask the plugin what it
 * stored: WordPress includes that file in a request where wp-mcp.php has not run, so
 * WPMCP_TRACE_NAME_OPTION and friends do not exist and every name has to be a literal.
 * That duplication is the bug waiting to happen. Somebody adds `update_option(
 * 'wpmcp_something', ... )` in 2027, ships it, and a row sits in wp_options on every site
 * that ever installed the plugin, forever, because nothing connected the two files.
 *
 * So this test reads the plugin's SOURCE, finds every option and transient key it touches,
 * and fails if uninstall.php does not mention one. It is deliberately a source scan rather
 * than a runtime check: the failure mode is an option written in a code path no test
 * exercises, which a runtime check would miss by construction.
 *
 * Keys are collected two ways, because the plugin writes them both ways: as a literal in
 * the call, and through a constant defined for the purpose (WPMCP_DB_VER_OPTION,
 * WPMCP_TRACE_NAME_OPTION). A constant is resolved by finding its define().
 *
 * @group sprint-6
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    /** Functions whose FIRST argument is an option or transient key. */
    private const KEYED_CALLS = [
        'get_option',
        'add_option',
        'update_option',
        'delete_option',
        'get_transient',
        'set_transient',
        'delete_transient',
        'get_site_option',
        'update_site_option',
        'delete_site_option',
    ];

    /**
     * Every `wpmcp_` option and transient key in the plugin is named in uninstall.php.
     *
     * @group sprint-6
     */
    public function testUninstallNamesEveryOptionThePluginWrites(): void
    {
        $keys      = self::optionKeysInPluginSource();
        $uninstall = self::read('uninstall.php');

        self::assertNotEmpty(
            $keys,
            'The scan found no option keys at all, which means it is broken rather than'
            . ' that the plugin stores nothing. Check KEYED_CALLS against the source.'
        );

        foreach ($keys as $key => $where) {
            self::assertStringContainsString(
                "'" . $key . "'",
                $uninstall,
                "The plugin writes the option or transient '{$key}' ({$where}) and"
                . ' uninstall.php does not name it, so deleting the plugin would leave it'
                . ' behind on every site. Add it to $wpmcp_options or $wpmcp_transients.'
            );
        }
    }

    /**
     * The other four things uninstall has to do, asserted on the source for the same
     * reason: there is no way to run this file in a unit test without a WordPress that is
     * being uninstalled.
     *
     * @group sprint-6
     */
    public function testUninstallIsGuardedAndRemovesTheRest(): void
    {
        $uninstall = self::read('uninstall.php');

        self::assertStringContainsString(
            "if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }",
            $uninstall,
            'Without the WP_UNINSTALL_PLUGIN guard this file is a URL that drops a table.'
        );

        self::assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS/',
            $uninstall,
            'The token table is not dropped.'
        );

        self::assertStringContainsString(
            "wp_clear_scheduled_hook('wpmcp_flush_expired')",
            $uninstall,
            'The hourly flush stays scheduled after the plugin is gone, and WordPress'
            . ' keeps trying to fire a hook nothing listens to.'
        );

        self::assertStringContainsString(
            "WP_CONTENT_DIR . '/wpmcp'",
            $uninstall,
            'The trace log directory is not removed. It holds stack traces.'
        );

        // The table name has to be spelled out here, because the constant does not exist
        // in an uninstall request. If this assertion fails because the name moved, the
        // plugin and uninstall.php have drifted.
        self::assertStringContainsString(
            "'wpmcp_tokens'",
            $uninstall,
            "uninstall.php does not name the token table. WPMCP_TABLE is 'wpmcp_tokens'"
            . ' and is not defined in an uninstall request.'
        );
    }

    /**
     * Every table the plugin CREATEs is named in uninstall.php, read out of the plugin's
     * own CREATE TABLE statements rather than listed here.
     *
     * The test above names one table as a literal, which is the assertion that caught
     * nothing when a second table arrived: a list written by hand does not grow when the
     * schema does. This one asks the schema. wpmcp_install() gained
     * `wpmcp_file_versions` in revision 4, and that table holds up to half a megabyte of
     * theme source per row - the largest thing this plugin ever writes, and the worst
     * thing to leave on somebody's site after they delete it.
     *
     * @group sprint-8
     */
    public function testUninstallDropsEveryTableThePluginCreates(): void
    {
        $tables = self::tableNamesInPluginSource();

        // COMMENTS STRIPPED, and that is the point of reading it this way: the comment
        // above the drop names the new table, so a `str_contains` over the raw file goes
        // green on prose while the table survives the uninstall. Only code counts.
        $uninstall = self::codeOnly('uninstall.php');

        self::assertContains(
            'wpmcp_tokens',
            $tables,
            'The scan found no CREATE TABLE for the token table, so it is reading the'
            . ' source wrongly and would not notice a new table either.'
        );

        self::assertStringContainsString(
            'DROP TABLE IF EXISTS',
            $uninstall,
            'uninstall.php drops no table at all.'
        );

        foreach ($tables as $bare) {
            self::assertStringContainsString(
                "'" . $bare . "'",
                $uninstall,
                "wpmcp_install() creates the table '{$bare}' and no CODE in uninstall.php"
                . ' names it, so deleting the plugin would leave it in the database.'
            );
        }
    }

    /** A plugin file's source with every comment and docblock removed. */
    private static function codeOnly(string $relative): string
    {
        $code = '';

        foreach (token_get_all(self::read($relative)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * The bare (unprefixed) name of every table wpmcp_install() creates, taken from the
     * `$wpdb->prefix . CONSTANT` expressions its CREATE TABLE statements are built from.
     *
     * @return list<string>
     */
    private static function tableNamesInPluginSource(): array
    {
        $source = self::read('wp-mcp.php');
        $names  = [];

        // define('WPMCP_TABLE', 'wpmcp_tokens') and friends.
        preg_match_all(
            "/define\(\s*'(WPMCP_[A-Z0-9_]*TABLE)'\s*,\s*'([a-z0-9_]+)'/",
            $source,
            $constants,
            PREG_SET_ORDER
        );

        $byName = [];

        foreach ($constants as $constant) {
            $byName[$constant[1]] = $constant[2];
        }

        // "CREATE TABLE $table (" is built from $wpdb->prefix . CONSTANT a line or two
        // earlier, so the CONSTANTS that appear in a prefix expression are the tables.
        preg_match_all(
            '/\$wpdb->prefix\s*\.\s*(WPMCP_[A-Z0-9_]*TABLE)/',
            $source,
            $used,
            PREG_SET_ORDER
        );

        foreach ($used as $use) {
            if (isset($byName[$use[1]]) && !in_array($byName[$use[1]], $names, true)) {
                $names[] = $byName[$use[1]];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Every `wpmcp_*` option or transient key the plugin source touches, key => where.
     *
     * @return array<string, string>
     */
    private static function optionKeysInPluginSource(): array
    {
        $constants = [];
        $sources   = [];

        foreach (self::pluginFiles() as $relative) {
            $sources[$relative] = self::read($relative);

            // define('NAME', 'wpmcp_whatever') - the constants that exist to hold a key.
            preg_match_all(
                "/define\(\s*'([A-Z0-9_]+)'\s*,\s*'(wpmcp_[a-z0-9_]+)'/",
                $sources[$relative],
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $constants[$match[1]] = $match[2];
            }
        }

        $found = [];

        foreach ($sources as $relative => $source) {
            $tokens = array_values(array_filter(
                token_get_all($source),
                static fn($token) => !(is_array($token) && in_array(
                    $token[0],
                    [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                ))
            ));

            $count = count($tokens);

            for ($i = 0; $i < $count - 2; $i++) {
                $token = $tokens[$i];

                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                if (!in_array($token[1], self::KEYED_CALLS, true)) {
                    continue;
                }
                if (($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }

                $argument = $tokens[$i + 2];

                if (!is_array($argument)) {
                    continue;
                }

                $key = null;

                if ($argument[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $key = trim($argument[1], "'\"");
                } elseif ($argument[0] === T_STRING && isset($constants[$argument[1]])) {
                    $key = $constants[$argument[1]];
                }

                if ($key === null || strpos($key, 'wpmcp_') !== 0) {
                    continue;
                }

                $found[$key] = $relative . ':' . $token[2];
            }
        }

        // Any constant named for a key counts even if the scan above never saw it used,
        // because defining one is already the decision to store something under it.
        foreach ($constants as $name => $key) {
            if (!isset($found[$key])) {
                $found[$key] = 'define(' . $name . ')';
            }
        }

        ksort($found);

        return $found;
    }

    /** @return list<string> */
    private static function pluginFiles(): array
    {
        $files = ['wp-mcp.php', 'endpoint.php', 'tools.php', 'admin.php', 'trace.php'];

        foreach (glob(WPMCP_PLUGIN_DIR . '/src/*.php') ?: [] as $class) {
            $files[] = 'src/' . basename($class);
        }

        return $files;
    }

    private static function read(string $relative): string
    {
        $path = WPMCP_PLUGIN_DIR . '/' . $relative;

        self::assertFileExists($path);

        $source = file_get_contents($path);

        self::assertIsString($source, "Could not read {$path}");

        return $source;
    }
}
