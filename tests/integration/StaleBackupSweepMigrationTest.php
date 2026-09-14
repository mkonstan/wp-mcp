<?php
/**
 * Schema revision 4's sweep: the sibling backup files the old code tools left inside the
 * active theme are collected into the version table and removed from disk.
 *
 * DELETING THE CODE THAT WROTE THEM DOES NOTHING ABOUT THE ONES ALREADY THERE, and those
 * are the whole reason this sprint exists: each is a URL under the document root that
 * returns the complete source of a theme file to anybody who asks. A site that upgrades
 * is a site that has been running the old code, so the upgrade has to go and get them.
 *
 * TWO PLANTED SHAPES, because they fail differently. One backup whose ORIGINAL still
 * exists - the sweep must store the backup and remove it without touching the original,
 * which is the live file on somebody's site. One ORPHAN, whose original was deleted by
 * the old code-delete (which renamed rather than unlinked) - the sweep must store it
 * under the original's path and must NOT put the file back, because the operator deleted
 * it on purpose.
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
use WpMcp\Tests\Support\WpCli;

final class StaleBackupSweepMigrationTest extends FixtureIntegrationTestCase
{
    /** The backup whose original is still there. */
    private static function pairedOriginal(): string { return Fixtures::name('swept') . '.css'; }
    private static function pairedBackup(): string { return self::pairedOriginal() . '.bak'; }

    /** The backup whose original the old code-delete renamed away. */
    private static function orphanOriginal(): string { return Fixtures::name('orphan') . '.css'; }
    private static function orphanBackup(): string { return self::orphanOriginal() . '.bak'; }

    private const PAIRED_BACKUP_BODY  = "/* wpmcp-test paired backup */\r\n.a { color: red }\r\n";
    private const PAIRED_LIVE_BODY    = "/* wpmcp-test paired live */\n.a { color: blue }\n";
    private const ORPHAN_BACKUP_BODY  = "\xEF\xBB\xBF/* wpmcp-test orphan caf\xC3\xA9 */\r\n";

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([
            self::pairedOriginal(),
            self::pairedBackup(),
            self::orphanOriginal(),
            self::orphanBackup(),
        ] as $file) {
            Fixtures::deleteThemeFile($file);
        }

        Fixtures::purge();

        parent::tearDownAfterClass();
    }

    /**
     * Both planted files land in the table under the ORIGINAL path, both leave the disk,
     * the live original is untouched, the orphan is not resurrected - and a second run
     * adds nothing.
     *
     * One test rather than six, because the six assertions are about one act: running the
     * sweep twice. Splitting them would mean planting and sweeping six times, and the
     * second sweep can only be observed against the first.
     *
     * @group sprint-8
     */
    public function testTheSweepCollectsBothShapesAndIsIdempotent(): void
    {
        Fixtures::writeThemeFile(self::pairedOriginal(), self::PAIRED_LIVE_BODY);
        Fixtures::writeThemeFile(self::pairedBackup(), self::PAIRED_BACKUP_BODY);
        Fixtures::writeThemeFile(self::orphanBackup(), self::ORPHAN_BACKUP_BODY);

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

        self::assertGreaterThanOrEqual(2, (int) $first['found']);
        self::assertGreaterThanOrEqual(2, (int) $first['removed']);

        // Gone from the disk - read as a LISTING, because "no file ending in the old
        // suffix is left" is a claim about the directory.
        self::assertSame(
            [],
            self::ourBackupFilesInTheTheme(),
            'The sweep left a backup file inside the theme.'
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
        // would fill the table with duplicates of files that no longer exist.
        $second = self::runSweep();

        self::assertSame('0', $second['found'], 'The second sweep found something to do.');

        foreach ([self::pairedOriginal(), self::orphanOriginal()] as $path) {
            self::assertCount(
                1,
                self::versions($path),
                "A second sweep added another version for {$path}."
            );
        }
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
            . ' echo (int) $t["found"], "\t", (int) $t["stored"], "\t",'
            . ' (int) $t["removed"], "\t", (int) $t["skipped"];'
        );

        $parts = explode("\t", trim($raw));

        self::assertCount(5, $parts, "The sweep did not report a tally: {$raw}");

        return [
            'code_enabled' => $parts[0],
            'found'        => $parts[1],
            'stored'       => $parts[2],
            'removed'      => $parts[3],
            'skipped'      => $parts[4],
        ];
    }

    /** @return list<string> */
    private static function ourBackupFilesInTheTheme(): array
    {
        return array_values(array_filter(
            Fixtures::themeDirListing(),
            static fn (string $name): bool =>
                str_starts_with($name, Fixtures::runPrefix())
                && str_ends_with(strtolower($name), '.bak')
        ));
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
