<?php
/**
 * Every call to `wp_opcache_invalidate()` in this plugin passes `$force = true`.
 *
 * WHY THIS IS A SOURCE TEST AND NOT A BEHAVIOURAL ONE. Sprint 14e's review tried to break
 * the witness test in `tests/integration/OpcacheInvalidationTest.php` and found exactly one
 * mutation it cannot see: dropping the second argument. With
 * `opcache.validate_timestamps=1` - measured on both Local sites, and what the `wordpress`
 * image CI runs on is EXPECTED to have, never measured, because nothing has been pushed -
 * `opcache_invalidate($path, false)` returns TRUE whether or not it actually marked the
 * entry, and it only marks it when the file's mtime has moved. So the recorded boolean, the
 * md5 read from disk, the count and the ordering are all unchanged by the drop, and the
 * integration test stays green.
 *
 * AND THE CASE IT BREAKS IS THE ONE THE WHOLE SPRINT IS ABOUT. `$force` exists for a write
 * whose mtime does not look newer than what the cache holds: a second write inside the same
 * second on a filesystem with one-second mtime granularity, a clock that went backwards, a
 * restore that puts back bytes older than the compile. Without it, those writes are on disk
 * and not in the cache, which is the defect with a narrower trigger. Core passes `true` at
 * BOTH of its own call sites (`wp-admin/includes/file.php:525`, `:638`), and so must we.
 *
 * NO HOST CAN BE ASKED THIS QUESTION - an invalidation that did nothing and one that worked
 * are indistinguishable from outside the engine - so the claim is asserted where it lives: in
 * the source. `tests/unit/SurfaceSweepTest.php` holds prose to account the same way and for
 * the same reason.
 *
 * READ WITH `token_get_all()`, NOT A GREP. A grep for `wp_opcache_invalidate(` matches the
 * docblocks that name the function and the `function_exists('wp_opcache_invalidate')` guards,
 * and would have to exempt them by hand. The tokenizer sees only real calls - in BOTH
 * spellings PHP has for one, which is a correction rather than a design note: see
 * namesTheFunction().
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OpcacheForceArgumentTest extends TestCase
{
    private const FUNCTION = 'wp_opcache_invalidate';

    /**
     * The plugin's own PHP. `tests/` is excluded because a fixture may legitimately name
     * the function without calling it, and `vendor/` is not ours.
     *
     * @return list<string>
     */
    private static function sources(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];

        foreach (['*.php', 'src/*.php', 'src/*/*.php', 'bin/*.php'] as $pattern) {
            foreach ((array) glob($root . '/' . $pattern) as $path) {
                if (is_file($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    /**
     * Every call site passes a literal `true` as the second argument.
     *
     * @group sprint-14d
     */
    public function testEveryOpcacheInvalidateCallForces(): void
    {
        $calls = [];

        foreach (self::sources() as $path) {
            foreach (self::callsIn($path) as $call) {
                $calls[] = $call;
            }
        }

        // A test that found nothing would pass. The plugin has the helper's call and
        // uninstall.php's, and a third would have to be deliberate.
        self::assertGreaterThanOrEqual(
            2,
            count($calls),
            'No calls to ' . self::FUNCTION . '() were found at all, so this test proved'
            . ' nothing. If the helper was renamed or removed, this test has to change with it.'
        );

        foreach ($calls as $call) {
            self::assertSame(
                'true',
                $call['force'],
                self::FUNCTION . '() is called at ' . $call['file'] . ':' . $call['line']
                . ' with $force = ' . var_export($call['force'], true) . '. It must be the'
                . ' literal true, as core passes at wp-admin/includes/file.php:525 and :638:'
                . ' without it, a write whose mtime does not look newer than the compiled'
                . ' entry is not invalidated, and no test on any host can see that.'
            );
        }
    }

    /**
     * Every real call to the function in one file: its line, and the source of its second
     * argument, or `'(none)'` when it was called with one.
     *
     * @return list<array{file: string, line: int, force: string}>
     */
    private static function callsIn(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $found  = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !self::namesTheFunction($token)) {
                continue;
            }

            // `$obj->wp_opcache_invalidate(...)` or `Foo::wp_opcache_invalidate(...)` would
            // be a different function; neither exists here, and skipping them keeps that true.
            $before = self::previousMeaningful($tokens, $i);
            if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            $open = self::nextMeaningfulIndex($tokens, $i);
            if ($open === null || $tokens[$open] !== '(') {
                continue; // a docblock or a string naming the function, not a call
            }

            $found[] = [
                'file'  => basename($path),
                'line'  => (int) $token[2],
                'force' => self::secondArgument($tokens, $open),
            ];
        }

        return $found;
    }

    /**
     * Does this token name the function - under either of the two spellings PHP tokenises
     * differently?
     *
     * `wp_opcache_invalidate(...)` is one `T_STRING`. `\wp_opcache_invalidate(...)` - the
     * same call, fully qualified, and the spelling an IDE offers inside a namespaced file -
     * is ONE `T_NAME_FULLY_QUALIFIED` token including the leading backslash (PHP 8.0+), not
     * a `\` followed by a `T_STRING`. Filtering on `T_STRING` alone therefore made a third
     * call site written that way invisible to this test, so it could have shipped without
     * `$force` and left this green. Found by the sprint-14e round-2 review with a tokenizer
     * probe, and re-proved here by adding such a call and watching this test go red.
     *
     * A string callable - `call_user_func('wp_opcache_invalidate', $p)` - is still invisible
     * and is left so deliberately: it is not a spelling anybody reaches for, and matching
     * string literals would drag in every docblock and `function_exists()` guard that the
     * tokenizer walk exists to skip.
     *
     * @param array{0: int, 1: string, 2: int} $token
     */
    private static function namesTheFunction(array $token): bool
    {
        if ($token[0] === T_STRING) {
            return $token[1] === self::FUNCTION;
        }

        if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
            return $token[1] === '\\' . self::FUNCTION;
        }

        return false;
    }

    /**
     * The source text of the second argument of the call whose `(` is at $open, trimmed,
     * or `'(none)'`.
     */
    private static function secondArgument(array $tokens, int $open): string
    {
        $depth = 0;
        $arg   = 1;
        $text  = '';
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $raw   = is_array($token) ? $token[1] : $token;

            if ($raw === '(' || $raw === '[') { $depth++; if ($depth === 1) { continue; } }
            elseif ($raw === ')' || $raw === ']') { $depth--; if ($depth === 0) { break; } }
            elseif ($raw === ',' && $depth === 1) { $arg++; continue; }

            if ($arg === 2 && (!is_array($token) || $token[0] !== T_COMMENT) && $token[0] !== T_DOC_COMMENT) {
                $text .= $raw;
            }
        }

        $text = trim($text);

        return $text === '' ? '(none)' : $text;
    }

    /** @return array|string|null */
    private static function previousMeaningful(array $tokens, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    private static function nextMeaningfulIndex(array $tokens, int $i): ?int
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }
}
