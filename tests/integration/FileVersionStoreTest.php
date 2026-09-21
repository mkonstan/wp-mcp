<?php
/**
 * Schema revision 4: the file-version store exists, and it gives back the bytes it was
 * given.
 *
 * WHY THE STORE IS TESTED BELOW THE TOOLS. What the code tools put in here is a theme
 * file - arbitrary bytes on disk, in whatever encoding the theme's author used, with
 * whatever line endings their editor wrote. A store that is byte-exact for the ASCII a
 * test happens to send and lossy for a latin1 comment or a BOM is a store that silently
 * corrupts the one copy of a file somebody is about to need back, and the tool-level
 * tests cannot see it: they send what a caller sends, which is always valid UTF-8
 * because it arrived as JSON.
 *
 * The failure this rules out is specific and was the reason for the shape of the INSERT.
 * $wpdb escapes a string value into a SQL literal which MySQL then reads under the
 * connection's utf8mb4 charset; bytes that are not valid UTF-8 have no meaning there.
 * wpmcp_file_version_save() writes through UNHEX() instead, and this test is what says
 * so.
 *
 * Integration tier without HTTP, like the migration tests: "a row came back out of MySQL
 * identical to what went in" is not a question the unit tier can answer.
 *
 * @group sprint-8
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class FileVersionStoreTest extends FixtureIntegrationTestCase
{
    /**
     * Every column the code and the tools read by name.
     *
     * `theme` IS THE ONE THAT MATTERS MOST HERE. It arrived in a later commit than the
     * table, and for one commit it arrived INSIDE the revision that had already been
     * stamped - so a site carrying that stamp kept a table without the column, every
     * INSERT failed, and all three code writers failed closed while reporting only
     * "could not store a version". This list is what makes that a red test rather than a
     * support thread. See the revision comment in wp-mcp.php.
     */
    private const COLUMNS = [
        'id', 'theme', 'path', 'content', 'size', 'sha256',
        'reason', 'saved_by', 'token_id', 'saved_at',
    ];

    private static function path(): string
    {
        return Fixtures::name('store-target') . '.php';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
    }

    public static function tearDownAfterClass(): void
    {
        Fixtures::purge();

        parent::tearDownAfterClass();
    }

    /**
     * The table is there, past the revision that creates it, with every column the rest
     * of this sprint selects by name.
     *
     * AT LEAST 4, NOT EXACTLY 4, for the reason the sprint-7 migration test learned: the
     * claim is that the table shipped WITH a bump, which stays true at 5 and at 9.
     * Pinning the number makes an unrelated later sprint red. What is NOT relaxed is the
     * column list: a revision may move, a column the code selects by name may not go
     * missing.
     *
     * @group sprint-8
     */
    public function testTheSiteIsPastRevisionFourWithTheVersionsTableAndEveryColumn(): void
    {
        self::assertGreaterThanOrEqual(
            4,
            (int) WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            'WPMCP_DB_VER is below 4, so the versions table ships without a schema bump'
            . ' and wpmcp_maybe_upgrade() will never create it on an existing site.'
        );

        self::assertSame(
            WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            WpCli::evaluate('echo (int) get_option("wpmcp_db_ver", 0);'),
            'The recorded schema revision does not match the code\'s, so wpmcp_install()'
            . ' refused to stamp - which it does when the versions table is missing.'
        );

        self::assertSame(
            '1',
            WpCli::evaluate('echo (int) wpmcp_versions_table_exists();'),
            'The file-version table does not exist on the site under test. Every'
            . ' code-write and code-delete fails closed without it.'
        );

        $columns = explode("\n", trim(WpCli::evaluate(
            'global $wpdb;'
            . ' foreach ((array) $wpdb->get_col("SHOW COLUMNS FROM " . wpmcp_versions_table()) as $c) {'
            . '  echo $c, "\n";'
            . ' }'
        )));

        foreach (self::COLUMNS as $column) {
            self::assertContains(
                $column,
                $columns,
                "The versions table has no `{$column}` column; the store selects it by"
                . ' name. Got: ' . implode(', ', $columns)
            );
        }
    }

    /**
     * Bytes in, the same bytes out - including the ones that are not valid UTF-8.
     *
     * Each member of the payload is a way a real theme file breaks a store written the
     * obvious way: a UTF-8 BOM, CRLF line endings, a multibyte character, a NUL, a lone
     * 0x80 and a lone 0xFF (which no valid UTF-8 sequence contains), and a `%` and a
     * backslash, which are the two characters $wpdb->prepare() itself treats specially.
     *
     * @group sprint-8
     */
    public function testTheStoreGivesBackExactlyTheBytesItWasGiven(): void
    {
        $content = "\xEF\xBB\xBF<?php\r\n// \xC3\xA9\xE2\x82\xAC \x80\xFF 100%% \\ \x00 done\r\n";

        $id = self::save(self::path(), $content, 'write');

        self::assertGreaterThan(0, $id, 'The store did not return a row id.');

        self::assertSame(
            bin2hex($content),
            self::contentHexOf($id),
            'The stored bytes are not the bytes that were given to the store. A theme'
            . ' file that is not valid UTF-8 is corrupted on the way into the table, so'
            . ' the version kept for an undo is not the file that was there.'
        );
    }

    /**
     * size, sha256 and reason describe the content rather than being taken on trust from
     * the caller - and saved_by 0 with token_id NULL is the shape the upgrade sweep
     * writes, which is what tells a swept file from one a person changed.
     *
     * @group sprint-8
     */
    public function testARowDescribesItsOwnContent(): void
    {
        $content = str_repeat("a\r\n", 40);

        $id = self::save(self::path(), $content, 'sweep', 0, null);

        $row = self::rowOf($id);

        self::assertSame((string) strlen($content), $row['size']);
        self::assertSame(hash('sha256', $content), $row['sha256']);
        self::assertSame('sweep', $row['reason']);
        self::assertSame('0', $row['saved_by']);
        self::assertSame(
            'NULL',
            $row['token_id'],
            'token_id came back as a number for a save with no session. 0 is a real'
            . ' token id, so "no token" has to be NULL or the two are the same row.'
        );
    }

    /**
     * An unknown id is null rather than a fatal or a row of empty strings. code-restore
     * turns this into its "no such version" tool error.
     *
     * @group sprint-8
     */
    public function testAnUnknownIdIsNull(): void
    {
        self::assertSame(
            'NULL',
            WpCli::evaluate(
                '$r = wpmcp_file_version_get(2147483600); echo $r === null ? "NULL" : "ROW";'
            )
        );
    }

    /** @return int the new row id */
    private static function save(
        string $path,
        string $content,
        string $reason,
        ?int $userId = null,
        ?int $tokenId = null
    ): int {
        $args = sprintf(
            '%s, base64_decode(%s), %s, %s, %s',
            self::phpString($path),
            self::phpString(base64_encode($content)),
            self::phpString($reason),
            $userId === null ? 'null' : (string) $userId,
            $tokenId === null ? 'null' : (string) $tokenId
        );

        return (int) WpCli::evaluate(
            '$id = wpmcp_file_version_save(' . $args . ');'
            . ' echo $id === false ? "0" : (int) $id;'
        );
    }

    /** The row's content as hex, so the comparison never goes through stdout as bytes. */
    private static function contentHexOf(int $id): string
    {
        return WpCli::evaluate(sprintf(
            '$r = wpmcp_file_version_get(%d); echo $r ? bin2hex($r->content) : "";',
            $id
        ));
    }

    /** @return array<string, string> */
    private static function rowOf(int $id): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$r = wpmcp_file_version_get(%d);'
            . ' if (!$r) { echo "MISSING"; return; }'
            . ' echo (int) $r->size, "\t", $r->sha256, "\t", $r->reason, "\t",'
            . ' (int) $r->saved_by, "\t", $r->token_id === null ? "NULL" : (int) $r->token_id;',
            $id
        ));

        $parts = explode("\t", trim($raw));

        self::assertCount(5, $parts, "Could not read back version row {$id}: {$raw}");

        return [
            'size'     => $parts[0],
            'sha256'   => $parts[1],
            'reason'   => $parts[2],
            'saved_by' => $parts[3],
            'token_id' => $parts[4],
        ];
    }

    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
