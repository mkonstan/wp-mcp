<?php
/**
 * What a run killed in the middle of `bin/dev-tokens.php mint` leaves behind (sprint 14b
 * round 3, review R2-1).
 *
 * THE CREDENTIAL THIS IS ABOUT. `mint` mints a real token: admin scope, a 30-day window, a
 * 365-day lifetime, and - until this round - the shipped label `claude-code dev (local)`,
 * bound to user 1 whenever the server it is minting for matches no row. Its id lived in a
 * static array and its raw value in a file under the site's temp directory. Kill the PHPUnit
 * process between the script's print and the class's teardown - a Ctrl-C, a fatal, a mutation
 * run that takes the site down - and that token stayed live on the site: `purge()` matches
 * `wpmcp-test-<run>-%` and the label did not start with it, the debris check listed nothing,
 * and in the admin table it looked exactly like the developer's own dev token.
 *
 * WHAT CLOSES IT. `DEVTOKENS_LABEL`, a test seam read from the wp-cli process's environment
 * and never from a request: the suite mints rows whose label carries its run prefix, so they
 * are ordinary fixtures - purged by the ordinary purge, listed by the ordinary debris check.
 * The raw value still has to be written, because the script's job is to rewrite a client
 * config; it is written inside this run's own directory under the site's temp directory,
 * removed by teardown on every path that reaches it, and `Fixtures::leftoverTempDirs()` now
 * reports one that a killed run left, so the bytes are named rather than nameless.
 *
 * THIS TEST IS THE KILLED RUN. It mints through the real script, then does what a killed
 * process does - nothing - and asserts that the ordinary cleanup finds and removes both the
 * row and the file.
 *
 * ONE GAP STAYS, and it is named rather than papered over (round 4, review R3-3): the
 * BACKUP the script writes cannot be noted before it exists, because only the script knows
 * its timestamped name. A death between that write and the note leaves `<path>.backup-*`
 * unnamed. Its contents are the OLD bearer values, whose rows carry this run's prefix and
 * are revoked by the same purge, so those bytes are dead as soon as the run is cleaned up.
 *
 * @group sprint-14b
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class DevTokensKilledRunTest extends FixtureIntegrationTestCase
{
    private static function mintLabel(): string { return Fixtures::name('killed-mint'); }
    private static function dirName(): string { return Fixtures::name('killed'); }

    private static string $dir  = '';
    private static string $host = '';

    /** Ids minted here, so teardown can revoke them even when an assertion fails. */
    private static array $mintedIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$host = WpCli::evaluate('echo strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));');
        self::$dir  = WpCli::evaluate(sprintf(
            '$d = get_temp_dir() . %s; wp_mkdir_p($d); echo is_dir($d) ? $d : "NO-DIR";',
            self::literal(self::dirName())
        ));

        if (self::$host === '' || self::$dir === 'NO-DIR') {
            throw new \RuntimeException('Could not read the home host or make the fixture temp directory.');
        }

        Fixtures::noteTempPath(self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::revokeTokenIds(self::$mintedIds);
        self::$mintedIds = [];

        Fixtures::deleteTokensLabelled(self::mintLabel());
        Fixtures::purge();
    }

    /**
     * R2-1. The row a killed run leaves is an ordinary fixture: the debris listing names it,
     * and purge() removes it - as it removes the file holding its raw value.
     *
     * @group sprint-14b
     */
    public function testWhatAKilledMintLeavesIsFoundAndRemovedByTheOrdinaryCleanup(): void
    {
        $path = $this->writeConfig();

        [$code, $out, $err] = $this->mint($path);

        self::assertSame(0, $code, 'mint failed: ' . $err);
        self::assertSame(1, preg_match('/minted row (\d+)/', $out, $m), 'mint minted nothing.');

        $id = (int) $m[1];
        self::$mintedIds[] = $id;

        // The backup mint wrote holds the OLD bearer value, so it is remembered too.
        if (preg_match('/^Backup of the previous file: (.+)$/m', $out, $b)) {
            Fixtures::noteTempPath(trim($b[1]));
        }

        // The shape the review found: admin scope, 30-day window, bound to user 1 because
        // the server it minted for matched no row. Only the LABEL is different now.
        $row = $this->rowOf($id);

        self::assertSame('admin', $row['scope']);
        self::assertSame(30 * 86400, (int) $row['window_secs']);
        self::assertSame(1, (int) $row['user_id']);
        self::assertSame(self::mintLabel(), $row['label'], 'DEVTOKENS_LABEL did not reach the minted row.');

        // 1. The debris listing names it, with THIS run's id, so another run would report
        //    it as foreign debris and a human would know which run to blame.
        $listed = Fixtures::leftoverTokenLabels();

        self::assertArrayHasKey($id, $listed, 'A killed run\'s minted token is invisible to the debris check.');
        self::assertSame(Fixtures::runId(), Fixtures::runIdIn($listed[$id]));
        self::assertArrayHasKey($id, Fixtures::ours($listed));

        // 2. The file holding its RAW value is named too. The site's temp directory cannot
        //    be enumerated on this host (scandir false, glob empty - measured), so the path
        //    is remembered in a fixture transient, which the debris check lists like any
        //    other fixture leftover and whose value names the file to delete.
        $noted = Fixtures::leftoverTempPaths();

        self::assertArrayHasKey(Fixtures::name('tempfile'), $noted, 'Nothing names the file holding the raw token.');
        self::assertContains($path, $noted[Fixtures::name('tempfile')], 'The config file this run wrote is not named.');
        self::assertStringContainsString(self::dirName(), $path);
        self::assertArrayHasKey(Fixtures::name('tempfile'), Fixtures::leftoverTransients());

        // 3. And the ordinary purge - the one every class runs before and after itself -
        //    takes both. This is the line that would have failed before this round.
        Fixtures::purge();

        self::assertSame(0, $this->rowCount($id), 'purge() left a live admin token behind.');

        $left = $this->survivingPaths();

        self::assertSame(
            [],
            $left,
            'purge() left a remembered path behind: ' . implode(', ', $left)
                . '. On Windows a delete is not final while a handle is open, so the retry'
                . ' in forgetTempPath() should have taken it; a path that outlives that is'
                . ' a leak, not a race.'
        );
        // A digest, never the bytes: this file holds bearer values, and a failing
        // assertSame would print them into the run log.
        self::assertTrue($this->readSiteFile($path) === '', 'The file holding the raw token survived purge().');

        self::$mintedIds = [];
    }

    /**
     * R3-1. The marker outlives a delete that FAILED, and goes when the delete worked.
     *
     * THE CASE IT EXISTS FOR. A cleanup that could not remove a file used to delete the
     * only record of that file anyway, so nothing would ever name it again - and on this
     * host nothing can find it by looking, because the temp directory refuses enumeration.
     *
     * The undeletable path here is a DIRECTORY with a file in it that the marker does not
     * name: `@rmdir` fails, exactly as it would for a file another process holds open, and
     * `file_exists()` still answers true.
     *
     * @group sprint-14b
     */
    public function testTheMarkerSurvivesADeleteThatFailedAndGoesWhenItWorked(): void
    {
        $blocked = rtrim(self::$dir, '/\\') . '/' . Fixtures::name('blocked-dir');
        $hidden  = $blocked . '/not-named.json';

        Fixtures::noteTempPath($blocked);

        WpCli::evaluate(sprintf(
            '$d = %s; wp_mkdir_p($d); file_put_contents($d . "/not-named.json", "x");'
            . ' echo is_file($d . "/not-named.json") ? "made" : "NO";',
            self::literal($blocked)
        ));

        $marker = Fixtures::name('tempfile');

        // The marker never expires (round 4, review R3-2): a day-long expiry would have
        // made exactly the file nobody cleaned up nameless again, quietly. WordPress writes
        // no `_transient_timeout_` row for an expiry of 0.
        self::assertSame(
            '0',
            WpCli::evaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('
                . '"SELECT COUNT(*) FROM $wpdb->options WHERE option_name = %%s", "_transient_timeout_" . %s));',
                self::literal($marker)
            )),
            "The marker expires, so a killed run\'s file is nameless a day later."
        );

        // A path noted but never written is not an error: that is what lets the note come
        // BEFORE the write (round 4, review R3-3).
        Fixtures::noteTempPath(rtrim(self::$dir, '/\\') . '/' . Fixtures::name('never-written') . '.json');

        // 1. The delete fails, and the record survives - rewritten to what is still there.
        Fixtures::purge();

        $kept = Fixtures::leftoverTempPaths();

        self::assertArrayHasKey($marker, $kept, 'A failed delete threw away the only record of the file.');
        self::assertContains($blocked, $kept[$marker], 'The surviving path is not the one that could not be deleted.');
        self::assertSame('yes', $this->existsOnSite($blocked), 'The fixture directory is gone, so nothing failed to delete.');

        // 2. Remove what was blocking it - the file the marker never knew about - and the
        //    next purge takes both the directory and the marker.
        WpCli::evaluate(sprintf('echo (int) @unlink(%s);', self::literal($hidden)));

        Fixtures::purge();

        $left = $this->survivingPaths();

        self::assertSame(
            [],
            $left,
            'The marker outlived the files it named, so every clean run would report debris.'
                . ' Still there: ' . implode(', ', $left)
        );
        self::assertSame('no', $this->existsOnSite($blocked), "The directory {$blocked} survived every retry.");
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * What this run's marker still names, after asking the cleanup to try again.
     *
     * THE RETRY IS THE POINT (round 5, review R4-1). A delete on Windows is not final while
     * any handle is open, and Defender or the indexer opening a file this suite has just
     * written keeps its directory entry alive for a moment - so `purge()` can leave a path
     * the next attempt removes. `forgetTempPath()` already tries three times 200 ms apart;
     * this asks for up to three more rounds of that, and then hands back whatever is left
     * so the assertion can NAME it. Nothing here weakens the claim: a survivor still fails.
     *
     * @return list<string>
     */
    private function survivingPaths(int $rounds = 3): array
    {
        $marker = Fixtures::name('tempfile');
        $left   = Fixtures::leftoverTempPaths()[$marker] ?? [];

        for ($i = 0; $i < $rounds && $left !== []; $i++) {
            $left = Fixtures::forgetTempPath($marker, $left);
        }

        return $left;
    }

    /** 'yes' or 'no': does this path exist on the site? */
    private function existsOnSite(string $path): string
    {
        return WpCli::evaluate(sprintf('echo file_exists(%s) ? "yes" : "no";', self::literal($path)));
    }


    /** One server on this host whose bearer value matches no row: the user-1 case. */
    private function writeConfig(): string
    {
        $json = json_encode([
            'mcpServers' => [
                'here-unknown' => [
                    'type'    => 'http',
                    'url'     => 'https://' . self::$host . '/wp-json/wpmcp/mcp',
                    'headers' => ['Authorization' => 'Bearer ' . bin2hex(random_bytes(32))],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        // Noted first, written second (round 4, review R3-3): see writeConfig() in
        // DevTokensScriptTest for why the order is the whole point.
        $path = rtrim(self::$dir, '/\\') . '/killed.mcp.json';

        Fixtures::noteTempPath($path);

        $written = WpCli::evaluate(sprintf(
            '$p = %s; wp_mkdir_p(dirname($p)); echo file_put_contents($p, base64_decode(%s)) ? $p : "NO-WRITE";',
            self::literal($path),
            self::literal(base64_encode($json))
        ));

        self::assertSame($path, $written, 'The fixture .mcp.json was not written where it was noted.');

        return $path;
    }

    /** @return array{0:int,1:string,2:string} */
    private function mint(string $path): array
    {
        return WpCli::evaluateWithStatus(sprintf(
            'putenv("DEVTOKENS_CMD=mint"); putenv("DEVTOKENS_MCP_JSON=" . %s); putenv("DEVTOKENS_LABEL=" . %s);'
            . ' require dirname((new ReflectionFunction("wpmcp_mint"))->getFileName()) . "/bin/dev-tokens.php";',
            self::literal($path),
            self::literal(self::mintLabel())
        ));
    }

    /** @return array<string, mixed> */
    private function rowOf(int $id): array
    {
        $json = WpCli::evaluate(sprintf(
            'global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT label, scope, window_secs, user_id FROM " . wpmcp_table() . " WHERE id = %%d", %d), ARRAY_A);'
            . ' echo wp_json_encode($r ?: array());',
            $id
        ));

        $row = json_decode($json, true);

        self::assertIsArray($row);
        self::assertNotSame([], $row, "Row {$id} is not in the table.");

        return $row;
    }

    private function rowCount(int $id): int
    {
        return (int) WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . wpmcp_table() . " WHERE id = %%d", %d));',
            $id
        ));
    }

    private function readSiteFile(string $path): string
    {
        return (string) base64_decode(WpCli::evaluate(sprintf(
            'echo base64_encode((string) @file_get_contents(%s));',
            self::literal($path)
        )), true);
    }

    private static function literal(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
