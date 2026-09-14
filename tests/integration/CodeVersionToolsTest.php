<?php
/**
 * code-write and code-delete version a file into the database instead of leaving a copy
 * of it in the document root.
 *
 * THE DEFECT THIS CLOSES. Both tools used to put the previous contents in a SIBLING FILE
 * inside the ACTIVE THEME - the same name with a backup extension appended - and the
 * parse-error revert copied it back. That file is under the document root with an
 * extension nothing executes and nothing blocks, so its URL returns the complete source
 * of a theme file to anyone who asks. It was also a one-generation backup: the second
 * write overwrote the only copy there was.
 *
 * EVERY ASSERTION ABOUT THE ABSENCE OF ONE IS A DIRECTORY LISTING, never an is_file() on
 * the single name this test expects - see Fixtures::ourSiblingBackupsInTheTheme(), which
 * is the one place in the suite that spells the old extension, and which filters the
 * listing to THIS RUN's prefix because the stress site's active theme is a real client's.
 *
 * @group sprint-8
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class CodeVersionToolsTest extends FixtureIntegrationTestCase
{
    private const CODE_SWITCH = 'code-on-versions';

    private static function adminLabel(): string { return Fixtures::name('codever-admin'); }
    private static function adminLogin(): string { return Fixtures::name('codeveradmin'); }

    /** Distinct fixture files, so the tests do not depend on each other's order. */
    private static function overwriteTarget(): string { return Fixtures::name('overwrite') . '.css'; }
    private static function freshTarget(): string { return Fixtures::name('fresh') . '.css'; }
    private static function deleteTarget(): string { return Fixtures::name('doomed') . '.css'; }
    private static function parseTarget(): string { return Fixtures::name('parse') . '.php'; }
    private static function rotateTarget(): string { return Fixtures::name('rotate') . '.css'; }

    private static int $adminId = 0;
    private static int $tokenId = 0;
    private static string $adminToken = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        // An administrator: edit_themes is a capability of that role alone, and
        // wpmcp_code_forbidden() checks it before any code tool runs.
        self::$adminId = Fixtures::createUser(self::adminLogin(), 'administrator');

        MuPlugin::drop(self::CODE_SWITCH, self::codeToolsSource());

        self::$adminToken = Fixtures::mintToken('admin', self::adminLabel(), self::$adminId);
        self::$tokenId    = Fixtures::tokenIdLabelled(self::adminLabel());
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::CODE_SWITCH);

        foreach ([
            self::overwriteTarget(),
            self::freshTarget(),
            self::deleteTarget(),
            self::parseTarget(),
            self::rotateTarget(),
        ] as $file) {
            Fixtures::deleteThemeFile($file);
        }

        Fixtures::deleteTokensLabelled(self::adminLabel());
        Fixtures::deleteUser(self::$adminId);

        // Takes back this run's theme files and version rows, whatever they are named.
        Fixtures::purge();
    }

    /**
     * A write over an existing file stores the bytes that were there, once, attributed
     * to the user and the token that did it - and leaves nothing on disk beside it.
     *
     * @group sprint-8
     */
    public function testWritingOverAnExistingFileStoresThePriorBytesAndNoFileBesideIt(): void
    {
        $prior = "/* wpmcp-test prior */\r\n.a { color: red }\r\n";
        $next  = "/* wpmcp-test next */\n.a { color: blue }\n";

        Fixtures::writeThemeFile(self::overwriteTarget(), $prior);

        self::assertSame(
            [],
            self::versions(self::overwriteTarget()),
            'The path already had versions before this test wrote anything.'
        );

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::overwriteTarget(),
            'content' => $next,
        ]);

        self::assertFalse($result->isError, 'code-write failed: ' . $result->text);

        $rows = self::versions(self::overwriteTarget());

        self::assertCount(1, $rows, 'One write should store exactly one version.');
        self::assertSame('write', $rows[0]['reason']);
        self::assertSame((string) self::$adminId, $rows[0]['saved_by']);
        self::assertSame(
            (string) self::$tokenId,
            $rows[0]['token_id'],
            'The version is not attributed to the token that caused it.'
        );

        self::assertSame(
            bin2hex($prior),
            self::contentHex((int) $rows[0]['id']),
            'The stored version is not the bytes that were on disk before the write.'
        );

        self::assertSame($next, Fixtures::readThemeFile(self::overwriteTarget()));

        self::assertSame(
            [],
            Fixtures::ourSiblingBackupsInTheTheme(),
            'code-write left a backup file inside the theme, which the web server serves.'
        );
    }

    /**
     * A file that did not exist has no prior bytes, so there is nothing to store. A row
     * here would be a row full of nothing, and code-history would offer an undo that
     * restores an empty file.
     *
     * @group sprint-8
     */
    public function testWritingANewFileStoresNothing(): void
    {
        self::assertFalse(
            Fixtures::themeFileExists(self::freshTarget()),
            'The fixture path already exists, so this test cannot say anything about a'
            . ' new file.'
        );

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::freshTarget(),
            'content' => "/* wpmcp-test fresh */\n",
        ]);

        self::assertFalse($result->isError, 'code-write failed: ' . $result->text);
        self::assertTrue($result->data()['created'], 'The file was not reported as created.');

        self::assertSame(
            [],
            self::versions(self::freshTarget()),
            'A file that had no previous contents got a version row anyway.'
        );
    }

    /**
     * code-delete stores the file and then actually unlinks it. It used to RENAME it to
     * the sibling backup, which is why "the file is gone" and "nothing is left beside it"
     * are two assertions and not one.
     *
     * @group sprint-8
     */
    public function testDeletingAFileStoresItAndRemovesItWithNothingLeftBeside(): void
    {
        $content = "/* wpmcp-test doomed */\r\n.b { color: green }\r\n";

        Fixtures::writeThemeFile(self::deleteTarget(), $content);

        $result = $this->mcp(self::$adminToken)->callTool('code-delete', [
            'path' => self::deleteTarget(),
        ]);

        self::assertFalse($result->isError, 'code-delete failed: ' . $result->text);

        $data = $result->data();

        self::assertTrue($data['deleted']);
        self::assertArrayNotHasKey(
            'backup',
            $data,
            'code-delete still reports a backup file. There is no file to report.'
        );

        self::assertFalse(
            Fixtures::themeFileExists(self::deleteTarget()),
            'The file is still on disk after code-delete.'
        );

        self::assertSame(
            [],
            Fixtures::ourSiblingBackupsInTheTheme(),
            'code-delete left a backup file inside the theme, which the web server serves.'
        );

        $rows = self::versions(self::deleteTarget());

        self::assertCount(1, $rows);
        self::assertSame('delete', $rows[0]['reason']);
        self::assertSame(bin2hex($content), self::contentHex((int) $rows[0]['id']));
    }

    /**
     * The auto-revert puts back the exact bytes, from the version it just took, with no
     * file on disk involved at any point.
     *
     * The prior content carries a BOM, CRLF and a multibyte character on purpose: the old
     * revert was a file copy, which preserves all three for free, and a revert written
     * through the database only preserves them if the store is byte-exact.
     *
     * @group sprint-8
     */
    public function testAParseErrorRevertsToTheExactPriorBytesWithNoFileBesideIt(): void
    {
        $prior = "\xEF\xBB\xBF<?php\r\n// caf\xC3\xA9 wpmcp-test\r\n\$x = 1;\r\n";

        Fixtures::writeThemeFile(self::parseTarget(), $prior);

        $result = $this->mcp(self::$adminToken)->callTool('code-write', [
            'path'    => self::parseTarget(),
            'content' => "<?php\nthis is not php(((\n",
        ]);

        self::assertFalse($result->isError, 'code-write failed outright: ' . $result->text);

        $data = $result->data();

        self::assertTrue($data['reverted'], 'A syntax error was not reverted.');
        self::assertStringContainsString('parse error', $data['error']);

        self::assertSame(
            bin2hex($prior),
            bin2hex(Fixtures::readThemeFile(self::parseTarget())),
            'The revert did not put back the exact bytes.'
        );

        self::assertSame(
            [],
            Fixtures::ourSiblingBackupsInTheTheme(),
            'The revert went through a backup file inside the theme.'
        );

        $rows = self::versions(self::parseTarget());

        self::assertCount(
            1,
            $rows,
            'The version was taken before the write, so a reverted write still leaves'
            . ' exactly one row - the file as it was.'
        );
        self::assertSame(bin2hex($prior), self::contentHex((int) $rows[0]['id']));
    }

    /**
     * Retention: the table cannot grow without bound, and it is the OLDEST that goes.
     *
     * Twenty-one writes over a file that already exists means twenty-one prior states
     * stored. The first of them - the content the fixture planted - is the one that must
     * be gone, and the other twenty must all still be there.
     *
     * @group sprint-8
     */
    public function testTwentyOneWritesLeaveTwentyVersionsWithTheOldestGone(): void
    {
        $body = static fn (int $n): string => "/* wpmcp-test rotate {$n} */\n";

        Fixtures::writeThemeFile(self::rotateTarget(), $body(0));

        $mcp = $this->mcp(self::$adminToken);

        for ($n = 1; $n <= 21; $n++) {
            $result = $mcp->callTool('code-write', [
                'path'    => self::rotateTarget(),
                'content' => $body($n),
            ]);

            self::assertFalse($result->isError, "code-write {$n} failed: " . $result->text);
        }

        $rows = self::versions(self::rotateTarget());

        self::assertCount(
            20,
            $rows,
            'Twenty-one writes left ' . count($rows) . ' versions. The cap is 20 per path.'
        );

        // Newest first: row 0 holds the state before the LAST write, which is body(20).
        self::assertSame(hash('sha256', $body(20)), $rows[0]['sha256']);
        self::assertSame(hash('sha256', $body(1)), $rows[19]['sha256']);

        self::assertNotContains(
            hash('sha256', $body(0)),
            array_column($rows, 'sha256'),
            'The oldest version survived the cap, so something other than the oldest was'
            . ' deleted.'
        );
    }

    /**
     * Version rows for one path, newest first, without their content.
     *
     * @return list<array<string, string>>
     */
    private static function versions(string $path): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$rows = wpmcp_file_versions_for(%s, 100);'
            . ' foreach ($rows as $r) {'
            . '  echo (int) $r->id, "\t", (int) $r->size, "\t", $r->sha256, "\t",'
            . '   $r->reason, "\t", (int) $r->saved_by, "\t",'
            . '   $r->token_id === null ? "NULL" : (int) $r->token_id, "\t", $r->saved_at, "\n";'
            . ' }',
            "'" . addcslashes($path, "'\\") . "'"
        ));

        $rows = [];

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", trim($line, "\r\n"));

            if (count($parts) !== 7) {
                continue;
            }

            $rows[] = [
                'id'       => $parts[0],
                'size'     => $parts[1],
                'sha256'   => $parts[2],
                'reason'   => $parts[3],
                'saved_by' => $parts[4],
                'token_id' => $parts[5],
                'saved_at' => $parts[6],
            ];
        }

        return $rows;
    }

    /** One version's content as hex, so bytes never travel through stdout as bytes. */
    private static function contentHex(int $id): string
    {
        return WpCli::evaluate(sprintf(
            '$r = wpmcp_file_version_get(%d); echo $r ? bin2hex($r->content) : "";',
            $id
        ));
    }

    /** See WriteToolCapabilityTest: a filter, not the option, so two runners cannot race. */
    private static function codeToolsSource(): string
    {
        return "add_filter('pre_option_wpmcp_code_enabled', static function () { return 1; });\n";
    }
}
