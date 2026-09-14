<?php
/**
 * Schema revision 4's sweep: the sibling backup files the old code tools left inside the
 * active theme are collected into the version table and removed from disk - and the ones
 * it will not take are left alone and named.
 *
 * DELETING THE CODE THAT WROTE THEM DOES NOTHING ABOUT THE ONES ALREADY THERE, and those
 * are the whole reason this sprint exists: each is a URL under the document root that
 * returns the complete source of a theme file to anybody who asks. A site that upgrades
 * is a site that has been running the old code, so the upgrade has to go and get them.
 *
 * THREE PLANTED SHAPES, because they fail differently. One backup whose ORIGINAL still
 * exists - the sweep must store the backup and remove it without touching the original,
 * which is the live file on somebody's site. One ORPHAN, whose original was deleted by
 * the old code-delete (which renamed rather than unlinked) - the sweep must store it
 * under the original's path and must NOT put the file back, because the operator deleted
 * it on purpose. And one whose original name is NOT a text extension this plugin writes -
 * the sweep must leave it exactly where it is and say so, because a file code-restore
 * could never hand back is a file the sweep should never have taken. That third case was
 * found by the sprint-8 review: a backup of a `README` with no extension at all was being
 * stored under `README` and then refused by code-restore's allow-list on the way out,
 * leaving the bytes reachable only through SQL.
 *
 * IT RUNS WITH CODE EDITING TURNED OFF, in-process, for the same reason: the switch
 * decides whether the tools are offered, and has nothing to do with whether an earlier
 * session already left files on disk. The operator who turned it off is exactly the one
 * who will never go looking.
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

final class StaleBackupSweepMigrationTest extends FixtureIntegrationTestCase
{
    /** The backup whose original is still there. */
    private static function pairedOriginal(): string { return Fixtures::name('swept') . '.css'; }
    private static function pairedBackup(): string { return Fixtures::siblingBackupName(self::pairedOriginal()); }

    /** The backup whose original the old code-delete renamed away. */
    private static function orphanOriginal(): string { return Fixtures::name('orphan') . '.css'; }
    private static function orphanBackup(): string { return Fixtures::siblingBackupName(self::orphanOriginal()); }

    /** The backup whose original is not a text extension this plugin ever writes. */
    private static function foreignOriginal(): string { return Fixtures::name('notmine') . '.xyz'; }
    private static function foreignBackup(): string { return Fixtures::siblingBackupName(self::foreignOriginal()); }

    /** A fourth, for the log-line test alone, so it cannot disturb the counts above. */
    private static function loggedOriginal(): string { return Fixtures::name('logged') . '.css'; }
    private static function loggedBackup(): string { return Fixtures::siblingBackupName(self::loggedOriginal()); }

    private const PAIRED_BACKUP_BODY  = "/* wpmcp-test paired backup */\r\n.a { color: red }\r\n";
    private const PAIRED_LIVE_BODY    = "/* wpmcp-test paired live */\n.a { color: blue }\n";
    private const ORPHAN_BACKUP_BODY  = "\xEF\xBB\xBF/* wpmcp-test orphan caf\xC3\xA9 */\r\n";
    private const FOREIGN_BACKUP_BODY = "wpmcp-test not a stylesheet\n";
    private const LOGGED_BACKUP_BODY  = "/* wpmcp-test logged */\n";

    /** The mu-plugin that watches the sweep's event fire inside the upgrade request. */
    private const WITNESS = 'sweep-witness';

    private static function witnessTransient(): string { return Fixtures::name('sweep-witness'); }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(static function (): void {
            Fixtures::purge();
            MuPlugin::drop(self::WITNESS, self::witnessSource());
        }, self::destroy(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::WITNESS);
        WpCli::tryEvaluate(sprintf(
            'echo (int) delete_transient(%s);',
            "'" . addcslashes(self::witnessTransient(), "'\\") . "'"
        ));

        foreach ([
            self::pairedOriginal(), self::pairedBackup(),
            self::orphanOriginal(), self::orphanBackup(),
            self::foreignOriginal(), self::foreignBackup(),
            self::loggedOriginal(), self::loggedBackup(),
        ] as $file) {
            Fixtures::deleteThemeFile($file);
        }

        // Whatever the tests left, plus this run's version rows.
        Fixtures::purge();

        // The revision is restored unconditionally: a crash between setting it to 3 and
        // the request that upgrades it would otherwise leave the site re-running
        // wpmcp_install() on every request until somebody noticed.
        WpCli::tryEvaluate('echo (int) update_option("wpmcp_db_ver", (int) WPMCP_DB_VER);');
    }

    /**
     * Both collectable shapes land in the table under the ORIGINAL path, both leave the
     * disk, the live original is untouched, the orphan is not resurrected, the file it
     * will not take is left exactly where it is and named - and a second run adds
     * nothing.
     *
     * One test rather than seven, because they are assertions about one act: running the
     * sweep twice over one planted tree. Splitting them would mean planting and sweeping
     * seven times, and the second sweep can only be observed against the first.
     *
     * @group sprint-8
     */
    public function testTheSweepCollectsWhatItCanGiveBackAndLeavesTheRestNamed(): void
    {
        Fixtures::writeThemeFile(self::pairedOriginal(), self::PAIRED_LIVE_BODY);
        Fixtures::writeThemeFile(self::pairedBackup(), self::PAIRED_BACKUP_BODY);
        Fixtures::writeThemeFile(self::orphanBackup(), self::ORPHAN_BACKUP_BODY);
        Fixtures::writeThemeFile(self::foreignBackup(), self::FOREIGN_BACKUP_BODY);

        self::assertSame(
            [],
            self::versions(self::pairedOriginal()),
            'The path already had versions before the sweep ran.'
        );

        $first = self::runSweep();

        self::assertSame(
            'OFF',
            $first['code_enabled'],
            'The fixture did not manage to turn code editing off, so this run says'
            . ' nothing about whether the sweep depends on the switch.'
        );

        self::assertGreaterThanOrEqual(3, (int) $first['found']);
        self::assertGreaterThanOrEqual(2, (int) $first['moved']);
        self::assertGreaterThanOrEqual(1, (int) $first['skipped_extension']);

        // The two it took are gone from the disk - read as a LISTING, because "no file
        // ending in the old suffix is left" is a claim about the directory.
        $left = Fixtures::ourSiblingBackupsInTheTheme();

        self::assertSame(
            [self::foreignBackup()],
            $left,
            'The sweep should have taken exactly the two it can give back and left the'
            . ' third where it is. Left behind: ' . implode(', ', $left)
        );

        // AND IT IS STILL READABLE, not merely still listed: a sweep that truncated or
        // emptied a file it declined to take would pass the listing assertion above.
        self::assertSame(
            self::FOREIGN_BACKUP_BODY,
            Fixtures::readThemeFile(self::foreignBackup()),
            'The sweep changed a file it refused to collect.'
        );

        self::assertSame(
            [],
            self::versions(self::foreignOriginal()),
            'The sweep stored a version of a file it says it skipped.'
        );

        // NAMED, not just counted. This is the only notice anybody gets that a file was
        // or was not taken, and a bare number does not let an operator check whether one
        // of them was theirs.
        self::assertStringContainsString(
            self::foreignBackup(),
            $first['skipped_paths'],
            'The skipped file is not named in the report: ' . $first['skipped_paths']
        );
        self::assertStringContainsString(
            self::pairedOriginal(),
            $first['moved_paths'],
            'A collected file is not named in the report: ' . $first['moved_paths']
        );

        // The live original is untouched. It is the file that is actually serving the
        // site, and a sweep that rewrote or removed it would be a catastrophe on a real
        // theme.
        self::assertSame(
            self::PAIRED_LIVE_BODY,
            Fixtures::readThemeFile(self::pairedOriginal()),
            'The sweep changed the live file next to the backup it collected.'
        );

        // And the orphan is NOT put back: the old code-delete renamed it away on purpose.
        self::assertFalse(
            Fixtures::themeFileExists(self::orphanOriginal()),
            'The sweep recreated a file whose deletion was deliberate.'
        );

        foreach ([
            [self::pairedOriginal(), self::PAIRED_BACKUP_BODY],
            [self::orphanOriginal(), self::ORPHAN_BACKUP_BODY],
        ] as [$path, $body]) {
            $rows = self::versions($path);

            self::assertCount(1, $rows, "The sweep stored no version for {$path}.");
            self::assertSame('sweep', $rows[0]['reason']);
            self::assertSame(
                '0',
                $rows[0]['saved_by'],
                'A swept file is attributed to a user. Nobody did it; the upgrade did.'
            );
            self::assertSame('NULL', $rows[0]['token_id']);
            self::assertSame(
                bin2hex($body),
                self::contentHex((int) $rows[0]['id']),
                "The stored bytes for {$path} are not the bytes that were in the backup."
            );
        }

        // IDEMPOTENT. wpmcp_install() calls every migration on every schema bump for the
        // rest of the plugin's life, so a sweep that inserted a second copy each time
        // would fill the table with duplicates of files that no longer exist. The one it
        // declined is still on disk, so it is still FOUND - and still not taken.
        $second = self::runSweep();

        self::assertSame('0', $second['moved'], 'The second sweep collected something.');
        self::assertGreaterThanOrEqual(1, (int) $second['found']);

        foreach ([self::pairedOriginal(), self::orphanOriginal()] as $path) {
            self::assertCount(
                1,
                self::versions($path),
                "A second sweep added another version for {$path}."
            );
        }
    }

    /**
     * The report reaches THE DEFAULT LISTENER on the path an upgrade actually takes.
     *
     * THIS IS THE ONE THE REVIEW MEASURED AND FOUND EMPTY. `wpmcp_maybe_upgrade` and
     * `wpmcp_attach_default_auth_log` are both `plugins_loaded` callbacks, both were
     * priority 10, and this file registers the upgrade first - so the sweep fired its
     * event before `wpmcp_log_auth_event` was on the hook, and the only account of what
     * it took went nowhere. A single front-end request that performed the upgrade added
     * ZERO lines to the log; a 401 in the very next request added one.
     *
     * WHAT THIS ASSERTS AND WHY IT IS NOT "THE EVENT FIRED". The first version of this
     * test used the ordinary recorder mu-plugin, which attaches on `muplugins_loaded` -
     * earlier than either priority - so it saw the event whatever the ordering was, and
     * it passed against the broken code. A test that cannot fail is worse than none. The
     * witness below therefore records `has_action('wpmcp_auth_event',
     * 'wpmcp_log_auth_event')` AT THE MOMENT THE SWEEP'S EVENT FIRES, which is precisely
     * the question: was anything listening? It runs at priority 1 so it observes the hook
     * before the default listener would have run.
     *
     * It has to be a real HTTP request. `wp eval` loads the plugin and fires
     * `plugins_loaded` in its own process, so any `wp` call between setting the revision
     * and the request performs the upgrade itself and the request finds nothing to do -
     * the review lost an afternoon to exactly that.
     *
     * @group sprint-8
     */
    public function testTheReportFiresWhereTheDefaultListenerCanHearItOnAnOrdinaryRequest(): void
    {
        Fixtures::writeThemeFile(self::loggedBackup(), self::LOGGED_BACKUP_BODY);

        WpCli::evaluate(sprintf(
            'echo (int) delete_transient(%s);',
            "'" . addcslashes(self::witnessTransient(), "'\\") . "'"
        ));

        // Set the revision back, and then touch the site with NOTHING but the HTTP
        // request under test. A `wp` call here would perform the upgrade in its own
        // process - wp-cli loads the plugin and fires plugins_loaded - and the request
        // would find nothing to do. The review lost an afternoon to exactly that.
        self::assertSame(
            '1',
            WpCli::evaluate('echo (int) update_option("wpmcp_db_ver", 3);'),
            'Could not move the recorded schema revision back to 3.'
        );

        $response = $this->client()->get('/');

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The front page did not answer 200, so the upgrade may not have run at all.'
        );

        $witness = self::witness();

        self::assertNotSame(
            [],
            $witness,
            'The sweep fired no auth event at all during the upgrade request.'
        );

        self::assertSame(
            1,
            $witness['listener'],
            'The sweep fired its report while wpmcp_log_auth_event was NOT yet attached to'
            . ' wpmcp_auth_event, so the one account of what left the theme directory went'
            . ' nowhere. Both are plugins_loaded callbacks; the upgrade has to run after'
            . ' the listener is on the hook.'
        );

        self::assertGreaterThanOrEqual(1, (int) $witness['moved']);
        self::assertStringContainsString(
            self::loggedOriginal(),
            (string) $witness['moved_paths'],
            'The event fired but does not name what it took.'
        );

        // And the upgrade really did happen in that request.
        self::assertSame(
            WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            WpCli::evaluate('echo (int) get_option("wpmcp_db_ver", 0);'),
            'The schema revision was not restored by the request, so the upgrade path'
            . ' this test claims to exercise did not run.'
        );
        self::assertFalse(Fixtures::themeFileExists(self::loggedBackup()));
    }

    /**
     * What the witness mu-plugin recorded, or [] if the event never fired.
     *
     * @return array<string, mixed>
     */
    private static function witness(): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$w = get_transient(%s); echo is_array($w) ? wp_json_encode($w) : "";',
            "'" . addcslashes(self::witnessTransient(), "'\\") . "'"
        ));

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A mu-plugin that answers one question: at the instant the sweep fires its report,
     * is the plugin's own log listener attached?
     *
     * Gated on this run's request header exactly like TestRecorder, because a mu-plugin
     * is loaded by every request the site serves - including a concurrent runner's and
     * the operator's own browsing.
     */
    private static function witnessSource(): string
    {
        $run       = Fixtures::runId();
        $transient = self::witnessTransient();
        $header    = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));

        return <<<PHP
/**
 * wp-mcp sprint-8 sweep witness for run {$run}. Dropped and removed by
 * tests/integration/StaleBackupSweepMigrationTest.php. If you are reading this on a live
 * site, the run that wrote it crashed; deleting the file is safe.
 */
add_action('wpmcp_auth_event', static function (\$type, \$context) {
    if (\$type !== 'stale_backup_sweep') {
        return;
    }

    if (!isset(\$_SERVER['{$header}']) || \$_SERVER['{$header}'] !== '{$run}') {
        return;
    }

    set_transient('{$transient}', array(
        // has_action() returns the PRIORITY or false. The whole question is whether the
        // plugin's own listener is on the hook by the time this event fires.
        'listener'    => has_action('wpmcp_auth_event', 'wpmcp_log_auth_event') === false ? 0 : 1,
        'moved'       => isset(\$context['moved']) ? (int) \$context['moved'] : -1,
        'moved_paths' => wp_json_encode(isset(\$context['moved_paths']) ? \$context['moved_paths'] : array()),
    ), 600);
}, 1, 2);
PHP;
    }

    /**
     * Run the migration with code editing filtered off for this process only.
     *
     * IN-PROCESS AND NOT THROUGH A mu-plugin, deliberately: a mu-plugin that forces the
     * switch off is loaded by every request to the site, including a concurrent runner's
     * code-tool tests, which need it on.
     *
     * @return array<string, string>
     */
    private static function runSweep(): array
    {
        $raw = WpCli::evaluate(
            'add_filter("pre_option_wpmcp_code_enabled", static function () { return 0; }, 99);'
            . ' echo wpmcp_code_enabled() ? "ON" : "OFF", "\t";'
            . ' $t = wpmcp_migrate_sweep_stale_backups();'
            . ' echo (int) $t["found"], "\t", (int) $t["moved"], "\t",'
            . ' (int) $t["skipped_extension"], "\t", (int) $t["skipped_unreadable"], "\t",'
            . ' (int) $t["skipped_too_big"], "\t", (int) $t["skipped_undeletable"], "\t",'
            . ' implode("|", $t["moved_paths"]), "\t", implode("|", $t["skipped_paths"]);'
        );

        $parts = explode("\t", trim($raw, "\r\n"));

        self::assertGreaterThanOrEqual(7, count($parts), "The sweep did not report a tally: {$raw}");

        return [
            'code_enabled'        => $parts[0],
            'found'               => $parts[1],
            'moved'               => $parts[2],
            'skipped_extension'   => $parts[3],
            'skipped_unreadable'  => $parts[4],
            'skipped_too_big'     => $parts[5],
            'skipped_undeletable' => $parts[6],
            'moved_paths'         => $parts[7] ?? '',
            'skipped_paths'       => $parts[8] ?? '',
        ];
    }

    /** @return list<array<string, string>> */
    private static function versions(string $path): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$rows = wpmcp_file_versions_for(%s, 100);'
            . ' foreach ($rows as $r) {'
            . '  echo (int) $r->id, "\t", $r->reason, "\t", (int) $r->saved_by, "\t",'
            . '   $r->token_id === null ? "NULL" : (int) $r->token_id, "\n";'
            . ' }',
            "'" . addcslashes($path, "'\\") . "'"
        ));

        $rows = [];

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", trim($line, "\r\n"));

            if (count($parts) !== 4) {
                continue;
            }

            $rows[] = [
                'id'       => $parts[0],
                'reason'   => $parts[1],
                'saved_by' => $parts[2],
                'token_id' => $parts[3],
            ];
        }

        return $rows;
    }

    private static function contentHex(int $id): string
    {
        return WpCli::evaluate(sprintf(
            '$r = wpmcp_file_version_get(%d); echo $r ? bin2hex($r->content) : "";',
            $id
        ));
    }
}
