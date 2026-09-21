<?php
/**
 * Every path that changes a file PHP compiles tells the opcode cache about it - after the
 * write, again after a rollback, never for a file PHP does not compile, and for a DELETE
 * while the path can still be resolved.
 *
 * THE DEFECT THIS CLOSES. code-write, code-restore and code-delete changed a `.php` file
 * and said nothing to the opcode cache. The condition is measurable rather than rhetorical:
 * `opcache.validate_timestamps=0`, where PHP never stats a file it has already compiled, or
 * a raised `opcache.revalidate_freq`, where it stats it no more often than that. So the tool
 * answered `bytes: 4096` while the site went on executing the previous `functions.php` -
 * until the pool was restarted, or for up to `revalidate_freq` seconds. A success that is not
 * true is worse than a failure. Core's own theme editor has always made the call, twice:
 * after the write (`wp-admin/includes/file.php:525`) and again after its rollback (`:638`).
 *
 * ONE MUTATION THIS TEST CANNOT SEE, and it is asserted elsewhere rather than left implicit:
 * dropping `$force = true`. With `opcache.validate_timestamps=1` - measured on both Local
 * sites, and expected but NOT measured on CI, which nothing has been pushed to -
 * `opcache_invalidate($p, false)` returns true whether or not it
 * marked anything, so the count, the md5, the ordering and the boolean are all unchanged by
 * the drop. No host can be asked that question, so it is asserted against the SOURCE in
 * `tests/unit/OpcacheForceArgumentTest.php`, which reads every real call in the plugin with
 * `token_get_all()` and requires a literal `true`.
 *
 * WHY THIS IS NOT A CODE REVIEW. The claim "the call is made" cannot be read off the
 * source with any confidence, because the function lives in `wp-admin/includes/file.php`,
 * which a REST request does not load: the line can be present and be a fatal. And it
 * cannot be observed from outside either - `opcache_get_status()` is about the cache, not
 * about who invalidated what, and on a host with no opcode cache there is no cache to
 * inspect at all. So `wpmcp_opcache_invalidate()` fires one action, immediately after the
 * core call, and the suite's recorder listens for it (see TestRecorder::source()).
 *
 * WHAT STOPS THAT BEING VACUOUS. Three things, and the count alone is none of them.
 *
 *  1. THE RECORDER READS THE FILE FROM DISK at the moment the action fires, and every
 *     assertion below is about those bytes. An invalidation moved above its write would
 *     record the OLD md5; one moved above its rollback would record the rolled-back-from
 *     md5; the delete's, moved below its unlink, would record no file at all. The order
 *     core is careful about is therefore measured, not read - and one of these assertions
 *     failing is what decided where the delete's call goes.
 *  2. THE RECORDER RECORDS WHETHER `wp_opcache_invalidate` WAS A DEFINED FUNCTION at that
 *     moment. That is the half of the fix a reader cannot check, and it is asserted true
 *     on a REST request, where WordPress does not load the file it lives in.
 *  3. A `.css` WRITE MUST RECORD NOTHING. The witness is not fired by every write, so
 *     "exactly one recording" in the tests above it means something. Without this test a
 *     helper called unconditionally would pass all the others.
 *
 * And `invalidated` - what core answered - is cross-checked against core's own
 * precondition (`opcache_invalidate` defined and `opcache.enable` on), read in the same
 * request. That never skips: it asserts false where there is no opcode cache and true
 * where there is, which is the point of the whole fix - nothing here behaves differently
 * because a host has no opcode cache.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class OpcacheInvalidationTest extends FixtureIntegrationTestCase
{
    /** The action wpmcp_opcache_invalidate() fires, as the recorder files it. */
    private const EVENT = 'wpmcp_compiled_file_changed';

    private const CODE_SWITCH = 'code-on-opcache';

    /** Valid PHP, and inert: a theme-root file with no Template Name header is never loaded. */
    private const GOOD_PHP = "<?php\n// wpmcp-test opcache fixture - safe to delete.\nreturn 1;\n";
    private const NEXT_PHP = "<?php\n// wpmcp-test opcache fixture, second write.\nreturn 2;\n";
    private const BAD_PHP  = "<?php\n// wpmcp-test opcache fixture, unparseable.\nfunction {\n";

    private static function adminLabel(): string { return Fixtures::name('opcache-admin'); }
    private static function adminLogin(): string { return Fixtures::name('opcacheadmin'); }

    /** One fixture file per act, so no test depends on another's order. */
    private static function writeTarget(): string { return Fixtures::name('opc-write') . '.php'; }
    private static function revertTarget(): string { return Fixtures::name('opc-revert') . '.php'; }
    private static function newRevertTarget(): string { return Fixtures::name('opc-newrevert') . '.php'; }
    private static function deleteTarget(): string { return Fixtures::name('opc-delete') . '.php'; }
    private static function restoreTarget(): string { return Fixtures::name('opc-restore') . '.php'; }
    private static function cssTarget(): string { return Fixtures::name('opc-plain') . '.css'; }

    private static int $adminId = 0;
    private static string $adminToken = '';

    /** realpath(get_stylesheet_directory()), slashes normalised. Read once. */
    private static string $themeRoot = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$adminId = Fixtures::createUser(self::adminLogin(), 'administrator');

        MuPlugin::drop(self::CODE_SWITCH, self::codeToolsSource());
        TestRecorder::install();

        self::$adminToken = Fixtures::mintToken('admin', self::adminLabel(), self::$adminId);

        self::$themeRoot = self::normalise(
            WpCli::evaluate('echo (string) realpath(get_stylesheet_directory());')
        );

        if (self::$themeRoot === '') {
            throw new \RuntimeException('Could not resolve the active theme directory.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        TestRecorder::uninstall();
        MuPlugin::remove(self::CODE_SWITCH);

        foreach ([
            self::writeTarget(),
            self::revertTarget(),
            self::newRevertTarget(),
            self::deleteTarget(),
            self::restoreTarget(),
            self::cssTarget(),
        ] as $file) {
            Fixtures::deleteThemeFile($file);
        }

        Fixtures::deleteTokensLabelled(self::adminLabel());
        Fixtures::deleteUser(self::$adminId);

        Fixtures::purge();
    }

    /**
     * A write over an existing PHP file invalidates exactly once, with the NEW bytes
     * already on disk.
     *
     * The md5 is the assertion that matters: it is read inside the request, at the moment
     * the call is made, so it fails if the invalidation is moved above the write.
     *
     * @group sprint-14d
     */
    public function testAPhpWriteInvalidatesOnceAfterTheBytesAreOnDisk(): void
    {
        Fixtures::writeThemeFile(self::writeTarget(), self::GOOD_PHP);
        TestRecorder::reset();

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::writeTarget(),
            'content' => self::NEXT_PHP,
        ]);

        self::assertFalse($result->isError, 'code-write failed: ' . $result->text);

        $calls = self::callsFor(self::writeTarget());

        self::assertCount(
            1,
            $calls,
            'A PHP write must invalidate the opcode cache exactly once. Recorded: '
            . self::describe($calls)
        );

        self::assertSame(1, $calls[0]['exists'], 'The file was not on disk when the cache was told.');
        self::assertSame(
            md5(self::NEXT_PHP),
            $calls[0]['md5'],
            'The cache was told before the new bytes reached the disk, which invalidates nothing.'
        );

        self::assertCoreCallWasReal($calls[0]);
    }

    /**
     * A write whose PHP does not parse invalidates TWICE: once after the bytes land, and
     * again after the revert - and the second one sees the PRIOR bytes back on disk.
     *
     * This is core's own pair (`file.php:525` and `:638`). Without the second call a
     * rejected write leaves the rejected bytes compiled, which is the defect running
     * backwards: the tool reports `reverted: true` and the site runs what was reverted.
     *
     * @group sprint-14d
     */
    public function testARevertedWriteInvalidatesAgainWithThePriorBytesBack(): void
    {
        Fixtures::writeThemeFile(self::revertTarget(), self::GOOD_PHP);
        TestRecorder::reset();

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::revertTarget(),
            'content' => self::BAD_PHP,
        ]);

        self::assertFalse($result->isError, 'code-write failed: ' . $result->text);
        self::assertTrue(
            (bool) ($result->data()['reverted'] ?? false),
            'The unparseable write was not reverted, so this test proves nothing: ' . $result->text
        );

        $calls = self::callsFor(self::revertTarget());

        self::assertCount(
            2,
            $calls,
            'A reverted write must invalidate after the write AND after the rollback. Recorded: '
            . self::describe($calls)
        );

        self::assertSame(
            md5(self::BAD_PHP),
            $calls[0]['md5'],
            'The first call did not see the written bytes, so it did not follow the write.'
        );
        self::assertSame(
            md5(self::GOOD_PHP),
            $calls[1]['md5'],
            'The second call did not see the restored bytes, so it did not follow the rollback.'
        );

        self::assertSame(
            self::GOOD_PHP,
            Fixtures::readThemeFile(self::revertTarget()),
            'The revert itself did not put the prior bytes back.'
        );

        self::assertCoreCallWasReal($calls[0]);
        self::assertCoreCallWasReal($calls[1]);
    }

    /**
     * A NEW file whose PHP does not parse is unlinked by the revert, and the second
     * invalidation sees no file at all - the state a stale cache entry would contradict
     * most loudly.
     *
     * @group sprint-14d
     */
    public function testARevertedNewPhpFileInvalidatesWithNoFileLeft(): void
    {
        Fixtures::deleteThemeFile(self::newRevertTarget());
        TestRecorder::reset();

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::newRevertTarget(),
            'content' => self::BAD_PHP,
        ]);

        self::assertFalse($result->isError, 'code-write failed: ' . $result->text);

        $calls = self::callsFor(self::newRevertTarget());

        self::assertCount(2, $calls, 'Recorded: ' . self::describe($calls));

        self::assertSame(1, $calls[0]['exists'], 'The created file was not on disk when the cache was told.');
        self::assertSame(md5(self::BAD_PHP), $calls[0]['md5']);

        self::assertSame(
            0,
            $calls[1]['exists'],
            'The second call ran before the revert unlinked the file.'
        );

        self::assertFalse(
            Fixtures::themeFileExists(self::newRevertTarget()),
            'The reverted new file is still on disk.'
        );

        self::assertCoreCallWasReal($calls[1]);
    }

    /**
     * A delete invalidates once, WHILE THE PATH STILL RESOLVES - the one place this plugin
     * does not invalidate after the change. Core's editor has no delete to mirror, and
     * `opcache_invalidate()` resolves the path on the filesystem before it looks in the
     * cache, so "after the unlink" would leave the deleted file's compiled entry exactly
     * where it was. That ordering was decided by this assertion FAILING: it was written the
     * other way round first, and both sites answered `invalidated: false` on a host whose
     * opcode cache is on. A deleted PHP file that something still includes is the case a
     * stale entry hides longest - it goes on executing with no file on disk to explain it.
     *
     * @group sprint-14d
     */
    public function testAPhpDeleteInvalidatesWhileThePathStillResolves(): void
    {
        Fixtures::writeThemeFile(self::deleteTarget(), self::GOOD_PHP);
        TestRecorder::reset();

        $result = $this->mcp(self::$adminToken)->callTool('code-delete', [
            'path' => self::deleteTarget(),
        ]);

        self::assertFalse($result->isError, 'code-delete failed: ' . $result->text);

        $calls = self::callsFor(self::deleteTarget());

        self::assertCount(1, $calls, 'Recorded: ' . self::describe($calls));

        self::assertSame(
            1,
            $calls[0]['exists'],
            'The cache was told after the unlink, when the path no longer resolves and'
            . ' there is nothing left for it to drop.'
        );
        self::assertSame(md5(self::GOOD_PHP), $calls[0]['md5']);

        // The pair that keeps the assertion above honest: the file was there when the
        // cache was told, and gone when the tool answered.
        self::assertFalse(
            Fixtures::themeFileExists(self::deleteTarget()),
            'code-delete did not delete the file, so this test measured nothing.'
        );

        self::assertCoreCallWasReal($calls[0]);
    }

    /**
     * code-restore is a write, and invalidates like one - with the RESTORED bytes on disk.
     *
     * @group sprint-14d
     */
    public function testAPhpRestoreInvalidatesWithTheRestoredBytesOnDisk(): void
    {
        // The version to restore is made the ordinary way: a write stores what was there.
        Fixtures::writeThemeFile(self::restoreTarget(), self::GOOD_PHP);

        $overwrite = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::restoreTarget(),
            'content' => self::NEXT_PHP,
        ]);

        self::assertFalse($overwrite->isError, 'code-write failed: ' . $overwrite->text);

        $versionId = (int) ($overwrite->data()['version_id'] ?? 0);

        self::assertGreaterThan(0, $versionId, 'The write stored no version to restore.');

        TestRecorder::reset();

        $result = $this->mcp(self::$adminToken)->callTool('code-restore', [
            'version_id' => $versionId,
        ]);

        self::assertFalse($result->isError, 'code-restore failed: ' . $result->text);

        $calls = self::callsFor(self::restoreTarget());

        self::assertCount(1, $calls, 'Recorded: ' . self::describe($calls));

        self::assertSame(1, $calls[0]['exists']);
        self::assertSame(
            md5(self::GOOD_PHP),
            $calls[0]['md5'],
            'The cache was told before the restored bytes reached the disk.'
        );

        self::assertCoreCallWasReal($calls[0]);
    }

    /**
     * A `.css` write records NOTHING - and this is what stops every count above from
     * passing vacuously.
     *
     * A helper called unconditionally, or an action fired before core's own extension
     * test, satisfies all five tests above and fails this one. PHP compiles nothing in
     * `wpmcp_code_allowed_ext()` but `php`, and core's function refuses any other
     * extension outright (`file.php:2762-2765`), so an invalidation here would be a call
     * that can only ever answer false.
     *
     * @group sprint-14d
     */
    public function testANonPhpWriteInvalidatesNothing(): void
    {
        Fixtures::writeThemeFile(self::cssTarget(), ".a { color: red }\n");
        TestRecorder::reset();

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::cssTarget(),
            'content' => ".a { color: blue }\n",
        ]);

        self::assertFalse($result->isError, 'code-write failed: ' . $result->text);

        self::assertSame(
            [],
            self::callsFor(self::cssTarget()),
            'A file PHP does not compile must not be handed to the opcode cache.'
        );

        // And the write itself happened, so the empty list above is about the cache and
        // not about a refused call.
        self::assertSame(".a { color: blue }\n", Fixtures::readThemeFile(self::cssTarget()));
    }

    /**
     * The two facts about the call itself that no amount of source reading establishes.
     *
     * @param array<string, mixed> $call
     */
    private static function assertCoreCallWasReal(array $call): void
    {
        self::assertSame(
            1,
            $call['core_loaded'],
            'wp_opcache_invalidate() was not a defined function when the plugin called it.'
            . ' It lives in wp-admin/includes/file.php, which a REST request does not load,'
            . ' so without the require this line is a fatal rather than a fix.'
        );

        // CORE'S OWN PRECONDITION, read in the request that made the call, plus the one
        // PHP adds underneath it: the answer is true exactly when this host has an opcode
        // cache AND the path resolved at that moment. Both halves were measured rather
        // than assumed - the second by this assertion failing, on both sites, on the
        // delete and on the revert that unlinks a file it had just created.
        //
        // This is what makes "false where there is no opcode cache" a measurement instead
        // of a hope, and it skips on neither kind of host: on a host without one it
        // asserts false everywhere, which is the case the whole fix has to be safe in.
        $expected = ($call['opcache_on'] === 1 && $call['exists'] === 1) ? 1 : 0;

        self::assertSame(
            $expected,
            $call['invalidated'],
            'wp_opcache_invalidate() did not answer what this opcode cache state and the'
            . ' file\'s presence say it should: opcache_on=' . $call['opcache_on']
            . ', exists=' . $call['exists']
            . ', opcache.restrict_api=' . var_export($call['restrict'], true) . '.'
        );
    }

    /**
     * Every recorded invalidation whose path is this theme-relative file, in order.
     *
     * MATCHED ON THE WHOLE PATH, not the basename: the recorded value is the absolute path
     * the jail resolved, and asserting it starts at the theme root is part of the claim.
     * Slashes are normalised because realpath() on Windows answers with backslashes while
     * the jail appends the relative part with forward ones.
     *
     * @return list<array<string, mixed>>
     */
    private static function callsFor(string $relative): array
    {
        $want  = self::$themeRoot . '/' . $relative;
        $found = [];

        foreach (TestRecorder::detailsOf(self::EVENT) as $detail) {
            if (self::normalise((string) ($detail['path'] ?? '')) !== $want) {
                continue;
            }

            $found[] = [
                'path'        => (string) $detail['path'],
                'invalidated' => (int) ($detail['invalidated'] ?? -1),
                'exists'      => (int) ($detail['exists'] ?? -1),
                'md5'         => (string) ($detail['md5'] ?? ''),
                'core_loaded' => (int) ($detail['core_loaded'] ?? -1),
                'opcache_on'  => (int) ($detail['opcache_on'] ?? -1),
                'restrict'    => (string) ($detail['restrict'] ?? ''),
            ];
        }

        return $found;
    }

    /** @param list<array<string, mixed>> $calls */
    private static function describe(array $calls): string
    {
        return $calls === [] ? '(nothing)' : (string) json_encode($calls);
    }

    private static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    /** See WriteToolCapabilityTest: a filter, not the option, so two runners cannot race. */
    private static function codeToolsSource(): string
    {
        return "add_filter('pre_option_wpmcp_code_enabled', static function () { return 1; });\n";
    }
}
