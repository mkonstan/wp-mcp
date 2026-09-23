<?php
/**
 * code-history and code-restore: the undo the version table exists for.
 *
 * The store is only worth having if there is a way back out of it, and "a way back out"
 * has two halves that fail independently - a listing that says what is in there, and a
 * write that puts one of them back byte for byte. A restore that is off by a byte is
 * worse than no restore at all: it looks like it worked.
 *
 * SO THE ROUND TRIP IS TESTED ON CONTENT THAT BREAKS A CARELESS PATH. A UTF-8 BOM
 * (dropped by anything that "trims" a file), CRLF line endings (rewritten by anything
 * that normalises them), and a multibyte character (mangled by anything that goes near
 * the file with a single-byte string function or hands the bytes to MySQL as text).
 *
 * THE TWO GATES ARE TESTED TOO, because these are the newest tools on the largest write
 * surface the plugin has. A read-scope token must not reach them, and an admin-SCOPE
 * token whose USER is not allowed to edit theme files must not either - the same pair
 * WriteToolCapabilityTest holds the other code tools to.
 *
 * @group sprint-8
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;
use WpMcp\Tests\Support\ToolResult;

final class CodeHistoryRestoreTest extends FixtureIntegrationTestCase
{
    private const CODE_SWITCH = 'code-on-history';

    /** Defines DISALLOW_FILE_EDIT, for THIS run's requests only. See noEditSource(). */
    private const NO_EDIT = 'no-file-edit';

    private static function adminLabel(): string { return Fixtures::name('codehist-admin'); }
    private static function readLabel(): string { return Fixtures::name('codehist-read'); }
    private static function editorLabel(): string { return Fixtures::name('codehist-editor'); }

    private static function adminLogin(): string { return Fixtures::name('codehistadmin'); }
    private static function editorLogin(): string { return Fixtures::name('codehisteditor'); }

    private static function historyTarget(): string { return Fixtures::name('history') . '.css'; }
    private static function restoreTarget(): string { return Fixtures::name('restore') . '.css'; }
    private static function deletedTarget(): string { return Fixtures::name('undelete') . '.css'; }
    private static function untouchedTarget(): string { return Fixtures::name('untouched') . '.css'; }
    private static function guardedTarget(): string { return Fixtures::name('guarded') . '.css'; }

    /** Written through two spellings; one history is the claim. */
    private static function spellingTarget(): string { return Fixtures::name('spelling') . '.css'; }

    /** A row planted under a theme that is not the active one. */
    private static function otherThemeTarget(): string { return Fixtures::name('othertheme') . '.css'; }
    private static function otherThemeSlug(): string { return Fixtures::name('a-theme-that-is-not-active'); }

    /**
     * A directory of OUR OWN added to the denylist for this run, and a file in it.
     *
     * The shipped denylist names `inc/`, and jaygroup's active theme really has one - a
     * real client's `inc/ajax.php` and friends. This suite does not read, list or write
     * anything that was already in a theme, so the denylist gets a prefixed entry and the
     * listing is done over a prefixed directory this run created.
     */
    private static function deniedDir(): string { return Fixtures::name('denied-dir'); }
    private static function deniedFile(): string { return self::deniedDir() . '/' . Fixtures::name('inner') . '.php'; }

    /** A version id no row can have, for the "unknown id" case. */
    private const UNKNOWN_VERSION_ID = 2147483600;

    private static int $adminId  = 0;
    private static int $editorId = 0;
    private static string $adminToken  = '';
    private static string $readToken   = '';
    private static string $editorToken = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$adminId  = Fixtures::createUser(self::adminLogin(), 'administrator');
        // An Editor holds edit_posts and nothing like edit_themes - the capability
        // wpmcp_code_forbidden() checks - so an admin-SCOPE token bound to one is the
        // shape that proves scope is not the only gate.
        self::$editorId = Fixtures::createUser(self::editorLogin(), 'editor');

        MuPlugin::drop(self::CODE_SWITCH, self::codeToolsSource());

        self::$adminToken  = Fixtures::mintToken('admin', self::adminLabel(), self::$adminId);
        self::$readToken   = Fixtures::mintToken('read', self::readLabel(), self::$adminId);
        self::$editorToken = Fixtures::mintToken('admin', self::editorLabel(), self::$editorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::CODE_SWITCH);
        MuPlugin::remove(self::NO_EDIT);

        foreach ([
            self::historyTarget(),
            self::restoreTarget(),
            self::deletedTarget(),
            self::untouchedTarget(),
            self::guardedTarget(),
            self::spellingTarget(),
            self::otherThemeTarget(),
            self::deniedFile(),
        ] as $file) {
            Fixtures::deleteThemeFile($file);
        }

        // After its contents: rmdir only takes an empty directory.
        Fixtures::deleteThemeDir(self::deniedDir());

        Fixtures::deleteTokensLabelled(self::adminLabel());
        Fixtures::deleteTokensLabelled(self::readLabel());
        Fixtures::deleteTokensLabelled(self::editorLabel());
        Fixtures::deleteUser(self::$adminId);
        Fixtures::deleteUser(self::$editorId);

        Fixtures::purge();
    }

    /**
     * The listing: newest first, with the six fields, and `saved_by` is a LOGIN.
     *
     * Not an email, and not a bare id. An email address is personal data that no other
     * tool on this surface returns, and a bare id is unreadable to the one audience this
     * listing has - a person deciding which version to put back.
     *
     * @group sprint-8
     */
    public function testHistoryListsVersionsNewestFirstWithReadableFields(): void
    {
        Fixtures::writeThemeFile(self::historyTarget(), "/* wpmcp-test v1 */\n");

        $mcp = $this->mcp(self::$adminToken);

        foreach (['v2', 'v3'] as $body) {
            $written = $mcp->callTool('code-write', [
                'path'    => self::historyTarget(),
                'content' => "/* wpmcp-test {$body} */\n",
            ]);
            self::assertFalse($written->isError, 'code-write failed: ' . $written->text);
        }

        $deleted = $mcp->callTool('code-delete', ['path' => self::historyTarget()]);
        self::assertFalse($deleted->isError, 'code-delete failed: ' . $deleted->text);

        $result = $mcp->callTool('code-history', ['path' => self::historyTarget()]);

        self::assertFalse($result->isError, 'code-history failed: ' . $result->text);

        $versions = $result->data()['versions'];

        self::assertCount(3, $versions, 'Two writes and a delete should leave three versions.');

        // NEWEST FIRST, asserted on the CONTENT of the versions and not on the ids: ids
        // ascend because the table is an auto-increment, so "the ids descend" would pass
        // for a listing that had them in any order the database happened to return.
        self::assertSame(['delete', 'write', 'write'], array_column($versions, 'reason'));
        self::assertSame(
            [
                hash('sha256', "/* wpmcp-test v3 */\n"),
                hash('sha256', "/* wpmcp-test v2 */\n"),
                hash('sha256', "/* wpmcp-test v1 */\n"),
            ],
            array_column($versions, 'sha256'),
            'The versions are not newest first.'
        );

        foreach ($versions as $version) {
            self::assertArrayHasKey('id', $version);
            self::assertArrayHasKey('saved_at', $version);
            self::assertSame(20, $version['size']);
            self::assertSame(
                self::adminLogin(),
                $version['saved_by'],
                'saved_by is not the login of the user who caused the version.'
            );
            self::assertStringNotContainsString(
                '@',
                (string) $version['saved_by'],
                'saved_by looks like an email address. No tool on this surface returns one.'
            );
        }
    }

    /**
     * A path nobody has ever changed has no versions, and that is an answer rather than
     * a failure. An error here would teach an agent that asking is dangerous.
     *
     * @group sprint-8
     */
    public function testHistoryForAPathWithNoVersionsIsAnEmptyListAndNotAnError(): void
    {
        Fixtures::writeThemeFile(self::untouchedTarget(), "/* wpmcp-test untouched */\n");

        $result = $this->mcp(self::$adminToken)->callTool('code-history', [
            'path' => self::untouchedTarget(),
        ]);

        self::assertFalse($result->isError, 'code-history errored on a path with no history: ' . $result->text);
        self::assertSame([], $result->data()['versions']);
    }

    /**
     * The round trip: the file comes back byte for byte, and the state it replaced is
     * stored first under reason `restore` so the restore itself can be undone.
     *
     * @group sprint-8
     */
    public function testRestorePutsBackTheExactBytesAndVersionsWhatItReplaced(): void
    {
        $original = "\xEF\xBB\xBF/* wpmcp-test caf\xC3\xA9 */\r\n.a { color: red }\r\n";
        $replaced = "/* wpmcp-test replaced */\n";

        Fixtures::writeThemeFile(self::restoreTarget(), $original);

        $mcp = $this->mcp(self::$adminToken);

        $written = $mcp->callTool('code-write', [
            'path'    => self::restoreTarget(),
            'content' => $replaced,
        ]);
        self::assertFalse($written->isError, 'code-write failed: ' . $written->text);

        $versionId = (int) $written->data()['version_id'];
        self::assertGreaterThan(0, $versionId, 'code-write reported no version id to restore.');

        $result = $mcp->callTool('code-restore', ['version_id' => $versionId]);

        self::assertFalse($result->isError, 'code-restore failed: ' . $result->text);

        $data = $result->data();

        self::assertSame(self::restoreTarget(), $data['path']);
        self::assertSame(strlen($original), $data['bytes']);
        self::assertSame(hash('sha256', $original), $data['sha256']);
        self::assertTrue(
            $data['matched'],
            'code-restore wrote bytes whose hash is not the stored version\'s.'
        );

        self::assertSame(
            bin2hex($original),
            bin2hex(Fixtures::readThemeFile(self::restoreTarget())),
            'The restored file is not byte-for-byte what was stored. A BOM, CRLF or a'
            . ' multibyte character did not survive.'
        );

        // The state the restore overwrote is itself now a version, with its own reason.
        $history = $mcp->callTool('code-history', ['path' => self::restoreTarget()]);
        $versions = $history->data()['versions'];

        self::assertSame(
            'restore',
            $versions[0]['reason'],
            'The newest version is not the one the restore took of what it replaced.'
        );
        self::assertSame(
            hash('sha256', $replaced),
            $versions[0]['sha256'],
            'The restore stored something other than the bytes it was about to overwrite.'
        );
    }

    /**
     * A deleted file comes back. This is the case the sibling-backup shape could not do
     * at all once a second write had rotated over it.
     *
     * @group sprint-8
     */
    public function testRestoreBringsBackADeletedFile(): void
    {
        $content = "/* wpmcp-test undelete */\r\n";

        Fixtures::writeThemeFile(self::deletedTarget(), $content);

        $mcp = $this->mcp(self::$adminToken);

        $deleted = $mcp->callTool('code-delete', ['path' => self::deletedTarget()]);
        self::assertFalse($deleted->isError, 'code-delete failed: ' . $deleted->text);
        self::assertFalse(Fixtures::themeFileExists(self::deletedTarget()));

        $restored = $mcp->callTool('code-restore', [
            'version_id' => (int) $deleted->data()['version_id'],
        ]);

        self::assertFalse($restored->isError, 'code-restore failed: ' . $restored->text);
        self::assertTrue($restored->data()['created'], 'The file was not reported as created.');
        self::assertSame(
            bin2hex($content),
            bin2hex(Fixtures::readThemeFile(self::deletedTarget())),
            'The file did not come back as it was.'
        );
    }

    /**
     * An id that names no row is a tool error the agent can act on, and NOTHING on disk
     * moves. A crash here would be a 500 with a trace id for a caller's typo.
     *
     * @group sprint-8
     */
    public function testRestoreOfAnUnknownIdIsAToolErrorAndChangesNothing(): void
    {
        $content = "/* wpmcp-test guarded */\n";

        Fixtures::writeThemeFile(self::guardedTarget(), $content);

        $before = Fixtures::themeDirListing();

        $result = $this->mcp(self::$adminToken)->callTool('code-restore', [
            'version_id' => self::UNKNOWN_VERSION_ID,
        ]);

        self::assertTrue($result->isError, 'code-restore accepted an id that names no row.');
        self::assertStringNotContainsString(
            'Internal error',
            $result->text,
            'An unknown version id reached the catch-all as an unexpected failure. It is'
            . ' a caller mistake and has to come back as a sentence they can act on.'
        );

        self::assertSame(
            bin2hex($content),
            bin2hex(Fixtures::readThemeFile(self::guardedTarget())),
            'A failed restore changed a file.'
        );
        self::assertSame(
            $before,
            Fixtures::themeDirListing(),
            'A failed restore added or removed something in the theme directory.'
        );
    }

    /**
     * A read-scope token cannot reach either tool. They sit behind the admin gate with
     * every other code tool - the theme is source code, not content.
     *
     * @group sprint-8
     */
    public function testAReadScopeTokenCannotReachHistoryOrRestore(): void
    {
        $mcp = $this->mcp(self::$readToken);

        foreach ([
            ['code-history', ['path' => self::untouchedTarget()]],
            ['code-restore', ['version_id' => self::UNKNOWN_VERSION_ID]],
        ] as [$tool, $arguments]) {
            $result = $mcp->callTool($tool, $arguments);

            self::assertTrue($result->isError, "{$tool} answered a read-scope token.");
            self::assertStringContainsString(
                'admin-scope token',
                $result->text,
                "{$tool} refused a read token for some other reason, so this test would"
                . ' pass with the scope gate removed. Message: ' . $result->text
            );
        }

        // And it is not simply absent for everyone: the admin token sees it listed.
        self::assertStringContainsString(
            'code-restore',
            (string) $this->mcp(self::$adminToken)->post('tools/list')->getBody(),
            'code-restore is not in the catalog at all, so the refusal above proves'
            . ' nothing.'
        );
    }

    /**
     * An admin-SCOPE token whose USER cannot edit theme files is refused by the
     * capability check, not by the scope gate. Scope and identity are two gates.
     *
     * @group sprint-8
     */
    public function testAnAdminScopeTokenWithoutEditThemesCannotReachHistoryOrRestore(): void
    {
        $mcp = $this->mcp(self::$editorToken);

        foreach ([
            ['code-history', ['path' => self::untouchedTarget()]],
            ['code-restore', ['version_id' => self::UNKNOWN_VERSION_ID]],
        ] as [$tool, $arguments]) {
            $result = $mcp->callTool($tool, $arguments);

            self::assertTrue($result->isError, "{$tool} answered a token bound to an Editor.");
            self::assertStringContainsString(
                'is not allowed to',
                $result->text,
                "{$tool} failed, but not because of a capability check - so this test"
                . ' would pass with the check removed. Message: ' . $result->text
            );
        }
    }


    /**
     * ONE FILE, ONE HISTORY, however the caller spelled it - end to end, over HTTP.
     *
     * `tests/unit/CodePathCanonicalTest.php` holds the rule at the jail. This holds the
     * consequence the rule exists for: `rel` is the version table's key and the group the
     * twenty-version cap counts within, so before it was canonicalised `./x.css` and
     * `x.css` were two histories of one file with two caps, and `code-history x.css`
     * after a `code-write ./x.css` came back empty.
     *
     * @group sprint-8
     */
    public function testTwoSpellingsOfOnePathShareOneHistory(): void
    {
        Fixtures::writeThemeFile(self::spellingTarget(), "/* wpmcp-test v0 */\n");

        $mcp = $this->mcp(self::$adminToken);

        foreach ([
            ['./' . self::spellingTarget(), 'v1'],
            [self::spellingTarget(), 'v2'],
            ['.\\' . self::spellingTarget(), 'v3'],
        ] as [$spelling, $body]) {
            $written = $mcp->callTool('code-write', [
                'path'    => $spelling,
                'content' => "/* wpmcp-test {$body} */\n",
            ]);

            self::assertFalse($written->isError, "code-write {$spelling} failed: " . $written->text);
            self::assertSame(
                self::spellingTarget(),
                $written->data()['path'],
                "code-write reported the path as the caller spelled it ('{$spelling}')."
                . ' That string is the version table key, so every spelling would get a'
                . ' history of its own.'
            );
        }

        // THREE VERSIONS IN ONE HISTORY, and the same three whichever spelling asks.
        foreach ([self::spellingTarget(), './' . self::spellingTarget()] as $spelling) {
            $history = $mcp->callTool('code-history', ['path' => $spelling]);

            self::assertFalse($history->isError, 'code-history failed: ' . $history->text);

            $versions = $history->data()['versions'];

            self::assertCount(
                3,
                $versions,
                "code-history '{$spelling}' returned " . count($versions) . ' versions,'
                . ' not the three writes that happened to that one file.'
            );
            self::assertSame(
                [
                    hash('sha256', "/* wpmcp-test v2 */\n"),
                    hash('sha256', "/* wpmcp-test v1 */\n"),
                    hash('sha256', "/* wpmcp-test v0 */\n"),
                ],
                array_column($versions, 'sha256'),
                "code-history '{$spelling}' does not return this file's three states."
            );
        }
    }

    /**
     * A version taken from another theme is neither listed nor restorable.
     *
     * WHY IT MATTERS. The jail is "the active theme", so `style.css` names a different
     * file once the theme changes. Without the theme on the row, `code-history style.css`
     * listed the old theme's versions as though they were this theme's, and code-restore
     * would write the old theme's bytes into the new theme's file under the same name.
     *
     * THE ROW IS PLANTED, NOT PRODUCED BY SWITCHING THE THEME. Switching the active theme
     * on the site under test is not something this suite may do - on the stress site that
     * is a real client's live theme. A `wp eval` that filters `stylesheet` for the length
     * of one save writes exactly the row a theme switch would have left behind, and the
     * filter dies with the process.
     *
     * @group sprint-8
     */
    public function testAVersionFromAnotherThemeIsNeitherListedNorRestorable(): void
    {
        Fixtures::writeThemeFile(self::otherThemeTarget(), "/* wpmcp-test live */\n");

        $foreignId = (int) WpCli::evaluate(sprintf(
            'add_filter("stylesheet", static function () { return %s; }, 99);'
            . ' $id = wpmcp_file_version_save(%s, "/* wpmcp-test from another theme */", "write", 0, null);'
            . ' echo $id === false ? "0" : (int) $id;',
            self::phpString(self::otherThemeSlug()),
            self::phpString(self::otherThemeTarget())
        ));

        self::assertGreaterThan(0, $foreignId, 'Could not plant a foreign-theme version row.');

        $mcp = $this->mcp(self::$adminToken);

        // NOT LISTED. The file exists and the path is right; only the theme differs.
        $history = $mcp->callTool('code-history', ['path' => self::otherThemeTarget()]);

        self::assertFalse($history->isError, 'code-history failed: ' . $history->text);
        self::assertSame(
            [],
            $history->data()['versions'],
            'code-history listed a version belonging to a theme that is not active. An'
            . ' agent reading it would believe those are this theme\'s bytes.'
        );

        // NOT RESTORABLE, and refused for the RIGHT reason - the row plainly exists, so
        // "no such version" would be a lie and "denied" would send the operator to the
        // denylist.
        $restored = $mcp->callTool('code-restore', ['version_id' => $foreignId]);

        self::assertTrue($restored->isError, 'code-restore wrote another theme\'s bytes.');
        self::assertStringContainsString(
            self::otherThemeSlug(),
            $restored->text,
            'code-restore refused, but without naming the theme the version came from,'
            . ' so this test would pass on any refusal at all. Message: ' . $restored->text
        );

        self::assertSame(
            "/* wpmcp-test live */\n",
            Fixtures::readThemeFile(self::otherThemeTarget()),
            'The refused restore changed the file anyway.'
        );
    }

    /**
     * code-list's `blocked` flag is computed from the canonical path, so it agrees with
     * the gate it describes however the caller spelled the directory.
     *
     * It used to build each entry's relative path from the caller's own `path` argument,
     * so listing `./<dir>` reported `blocked: false` for every file in a denylisted
     * directory - files `code-read` then refused. `blocked` is the only thing an agent
     * has to go on BEFORE it tries, so a false label sends it to spend a call finding
     * out. Cosmetic in consequence, the same defect in kind as the one that let `./` past
     * the denylist entirely.
     *
     * @group sprint-8
     */
    public function testCodeListReportsBlockedForADeniedDirectoryHoweverItIsSpelled(): void
    {
        Fixtures::makeThemeDir(self::deniedDir());
        Fixtures::writeThemeFile(self::deniedFile(), "<?php\n// wpmcp-test\n");

        $mcp  = $this->mcp(self::$adminToken);
        $name = basename(self::deniedFile());

        foreach ([self::deniedDir(), './' . self::deniedDir(), self::deniedDir() . '/'] as $spelling) {
            $result = $mcp->callTool('code-list', ['path' => $spelling]);

            self::assertFalse($result->isError, "code-list '{$spelling}' failed: " . $result->text);

            $data = $result->data();

            self::assertSame(
                self::deniedDir(),
                $data['path'],
                "code-list '{$spelling}' echoed the caller's spelling back rather than the"
                . ' canonical path.'
            );

            $blocked = [];

            foreach ($data['entries'] as $entry) {
                $blocked[$entry['name']] = $entry['blocked'];
            }

            self::assertArrayHasKey($name, $blocked, "for '{$spelling}': " . $result->text);
            self::assertTrue(
                $blocked[$name],
                "code-list '{$spelling}' reports a file in a denylisted directory as not"
                . ' blocked. code-read refuses it, so the flag is a lie.'
            );
        }

        // The control, and it is what makes the three above mean anything: the denylist
        // really is refusing the file, whichever way it is asked for.
        foreach ([self::deniedFile(), './' . self::deniedFile()] as $spelling) {
            $read = $mcp->callTool('code-read', ['path' => $spelling]);

            self::assertTrue($read->isError, "code-read '{$spelling}' was allowed.");
            self::assertStringContainsString('denylist', $read->text, "for '{$spelling}'");
        }
    }

    /**
     * A tool that can never run is not listed.
     *
     * MEASURED ON A REAL PUBLIC SITE, seosemia.net, with the code switch on and
     * DISALLOW_FILE_EDIT true in wp-config: `tools/list` advertised all six code tools and
     * `code-list` then answered "Theme file editing is disabled on this site
     * (DISALLOW_FILE_EDIT)." The registry asked only wpmcp_code_enabled(); the constants
     * were checked inside each run closure and nowhere else.
     *
     * Why it matters more than it looks. An agent reads a listing as a statement of what
     * it may do and plans on it, so it spends one call per tool discovering otherwise -
     * and the operator who set DISALLOW_FILE_EDIT on purpose has been told by their own
     * server that theme editing is on offer.
     *
     * THE CONSTANT IS DEFINED FOR THIS RUN'S REQUESTS ONLY. A mu-plugin is loaded by every
     * request the site serves, and defining DISALLOW_FILE_EDIT unconditionally would turn
     * off the theme editor for the site while this class runs - on the stress site, a real
     * client's. The body checks this run's request header first, exactly as the sweep
     * witness does, so only calls from this suite ever see it.
     *
     * @group sprint-8
     */
    public function testTheCodeToolsAreNotListedWhenTheSiteForbidsFileEditing(): void
    {
        $mcp = $this->mcp(self::$adminToken);

        // The control FIRST, and it is what makes the rest mean anything: with the switch
        // on and no constant, the tools are there.
        //
        // AND IT SAYS WHY WHEN IT FAILS (round 3). This premise went red in a full-suite run and
        // its message could only say "not listed", which is four different faults wearing one
        // sentence: the switch off, DISALLOW_FILE_MODS, DISALLOW_FILE_EDIT, or a
        // `file_mod_allowed` filter answering false - the last of which became a way to fail in
        // 1.1.1, when the registry started asking wp_is_file_mod_allowed(). A premise that cannot
        // name its own cause costs a round of bisection.
        self::assertNotSame(
            [],
            self::codeToolsIn($mcp),
            'The code tools are not listed even with the switch on, so the assertion below'
            . ' would pass for the wrong reason. The site says: ' . self::codeGateState()
        );

        MuPlugin::drop(self::NO_EDIT, self::noEditSource());

        try {
            $listed = self::codeToolsIn($mcp);

            self::assertSame(
                [],
                $listed,
                'DISALLOW_FILE_EDIT is set and the code tools are still advertised: '
                . implode(', ', $listed) . '. Every one of them refuses when called, so'
                . ' the listing is telling an agent it may do something it may not.'
            );

            // And the switch itself is still on - otherwise this test would pass on a
            // site where the code tools were simply disabled.
            self::assertStringContainsString(
                'list-posts',
                (string) $mcp->post('tools/list')->getBody(),
                'tools/list came back without the ordinary tools either, so something'
                . ' other than the constant emptied it.'
            );
        } finally {
            MuPlugin::remove(self::NO_EDIT);
        }

        // Removed again, and the tools come back: the mu-plugin is what did it.
        self::assertNotSame([], self::codeToolsIn($mcp));
    }

    /**
     * The names of the code tools in this token's tools/list.
     *
     * @return list<string>
     */
    /**
     * Every input to the code-tool listing gate, read from the site, as one line.
     *
     * The four ways the listing can be empty are indistinguishable from the tools/list answer
     * alone, and three of them are site state rather than anything this class did. Read at the
     * moment of the failure and printed in its message.
     */
    private static function codeGateState(): string
    {
        return trim(WpCli::evaluate(
            'printf("code_enabled=%s DISALLOW_FILE_MODS=%s DISALLOW_FILE_EDIT=%s'
            . ' wp_is_file_mod_allowed=%s forbid=%s",'
            . ' var_export((bool) get_option("wpmcp_code_enabled"), true),'
            . ' defined("DISALLOW_FILE_MODS") ? var_export(DISALLOW_FILE_MODS, true) : "undefined",'
            . ' defined("DISALLOW_FILE_EDIT") ? var_export(DISALLOW_FILE_EDIT, true) : "undefined",'
            . ' var_export(wp_is_file_mod_allowed("capability_edit_themes"), true),'
            . ' is_wp_error($e = wpmcp_code_constants_forbid()) ? $e->get_error_message() : "none");'
        ));
    }

    private static function codeToolsIn(\WpMcp\Tests\Support\McpClient $mcp): array
    {
        $body = json_decode((string) $mcp->post('tools/list')->getBody(), true);
        $tools = $body['result']['tools'] ?? [];

        return array_values(array_filter(
            array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $tools),
            static fn (string $name): bool => str_starts_with($name, 'code-')
        ));
    }

    /**
     * A mu-plugin that defines DISALLOW_FILE_EDIT for this run's requests and nobody
     * else's. mu-plugins load after wp-config, and every check in the plugin is a
     * `defined()` at call time, so this is indistinguishable from the wp-config line.
     */
    private static function noEditSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));

        return <<<PHP
/**
 * wp-mcp sprint-8 DISALLOW_FILE_EDIT fixture for run {$run}. Dropped and removed by
 * tests/integration/CodeHistoryRestoreTest.php. GATED ON THIS RUN'S REQUEST HEADER, so it
 * changes nothing for anybody else. If you are reading this on a live site, the run that
 * wrote it crashed; deleting the file is safe.
 */
if (isset(\$_SERVER['{$header}'])
    && \$_SERVER['{$header}'] === '{$run}'
    && !defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}
PHP;
    }

    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /**
     * Turns the code tools on AND adds this run's own directory to the denylist.
     *
     * FILTERS, NOT OPTIONS, for the reason WriteToolCapabilityTest gives: two runners on
     * one site cannot race over a value neither of them wrote. The shipped defaults are
     * repeated rather than appended to whatever is stored, so this class's denylist
     * assertions describe the denylist this class set up and not the operator's.
     */
    private static function codeToolsSource(): string
    {
        $denied = self::deniedDir();

        return "add_filter('pre_option_wpmcp_code_enabled', static function () { return 1; });\n"
            . "add_filter('pre_option_wpmcp_code_denylist', static function () {\n"
            . "    return array('functions.php', 'index.php', 'inc/', 'includes/', 'lib/', '{$denied}/');\n"
            . "});\n";
    }
}
