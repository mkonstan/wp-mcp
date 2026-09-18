<?php
/**
 * bin/dev-tokens.php against a real site and a COPY of a .mcp.json (sprint 14b, G4).
 *
 * WHAT IS UNDER TEST. The script refuses a site that is not local; it never prints a
 * token or a hash; it finds a server's row by the sha256 of its bearer value; it
 * considers only servers on this site's host; and `mint` rewrites only the matching
 * servers' Authorization values while keeping a byte-identical backup.
 *
 * THE .mcp.json IS A FIXTURE, written into the site's temp directory through `wp eval`
 * (in CI the site's filesystem is inside a container), and it carries fixture tokens
 * only. The project's real .mcp.json is never read here.
 *
 * THE SCRIPT IS REQUIRED FROM `wp eval`, not run with `wp eval-file`. It is the same
 * inclusion inside a loaded WordPress; `wp eval` is what WpCli can route through wp-env,
 * and it lets a test attach the narrowing filter first. The environment variables are
 * set with putenv() in that same process, because wp-env does not forward the host's.
 *
 * `label` AND `mint` WRITE ROWS WITHOUT THE TEST PREFIX - the label is fixed. Every such
 * row is tracked by id and deleted, or relabelled back, in a finally block, and again in
 * teardown.
 *
 * @group sprint-14b
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class DevTokensScriptTest extends FixtureIntegrationTestCase
{
    /** What the script writes when nothing overrides it - asserted, never written here. */
    private const SHIPPED_LABEL = 'claude-code dev (local)';

    /**
     * What this class makes the script write instead, through DEVTOKENS_LABEL.
     *
     * THE SEAM EXISTS FOR A CREDENTIAL (round 3, review R2-1). `mint` mints a real 30-day
     * admin token; with the shipped label, a run killed between the mint and teardown left
     * that token live, unprefixed, invisible to purge() and to the debris check, and
     * indistinguishable in the admin table from the developer's own dev token. A prefixed
     * label makes every row this class causes an ordinary fixture.
     */
    private static function devLabel(): string { return Fixtures::name('devtok-label'); }

    private static function login(): string { return Fixtures::name('devtok-owner'); }
    private static function activeLabel(): string { return Fixtures::name('devtok-active'); }
    private static function dormantLabel(): string { return Fixtures::name('devtok-dormant'); }
    private static function deadLabel(): string { return Fixtures::name('devtok-dead'); }
    private static function elsewhereLabel(): string { return Fixtures::name('devtok-elsewhere'); }

    private static int $userId = 0;

    /** @var array<string, string> server name => raw fixture token */
    private static array $tokens = [];

    /** @var array<string, int> server name => row id ('' for none) */
    private static array $ids = [];

    private static string $host = '';
    private static string $dir  = '';

    /**
     * Rows this class caused to exist without the test prefix - every id the script
     * printed as `minted row N`, captured the moment it printed it. Teardown deletes
     * EXACTLY these and nothing else.
     *
     * A sweep by label was the first shape and it was wrong (review round 1, S3): the
     * label is fixed, so `DELETE ... WHERE label = 'claude-code dev (local)' AND id >
     * <max at start>` would take a token an operator minted or labelled WHILE the suite
     * ran - and giving the two real dev tokens exactly that label is what the `label`
     * command exists for.
     */
    private static array $unprefixedIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
        self::$host   = WpCli::evaluate('echo strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));');
        self::$dir    = WpCli::evaluate(sprintf(
            '$d = get_temp_dir() . %s; wp_mkdir_p($d); echo is_dir($d) ? $d : "NO-DIR";',
            self::literal(Fixtures::runPrefix() . 'devtokens')
        ));

        if (self::$dir === 'NO-DIR' || self::$host === '') {
            throw new \RuntimeException('Could not prepare the dev-tokens fixture directory or read the home host.');
        }

        // The files written there hold raw fixture tokens, and the site's temp directory
        // cannot be listed on this host - so the path is remembered in a fixture transient
        // that the debris check reports and purge() clears (round 3, review R2-1).
        Fixtures::noteTempPath(self::$dir);

        foreach ([
            'here-active'    => self::activeLabel(),
            'here-dormant'   => self::dormantLabel(),
            'here-dead'      => self::deadLabel(),
            'elsewhere'      => self::elsewhereLabel(),
        ] as $server => $label) {
            self::$tokens[$server] = Fixtures::mintToken('admin', $label, self::$userId);
            self::$ids[$server]    = Fixtures::tokenIdLabelled($label);
        }

        Fixtures::makeTokensDormantLabelled(self::dormantLabel());
        Fixtures::makeTokensDeadLabelled(self::deadLabel());

        // A token no row carries, on this host.
        self::$tokens['here-unknown'] = bin2hex(random_bytes(32));
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::revokeTokenIds(self::$unprefixedIds);

        self::$unprefixedIds = [];

        Fixtures::deleteUser(self::$userId);

        foreach ([self::activeLabel(), self::dormantLabel(), self::deadLabel(), self::elsewhereLabel()] as $label) {
            Fixtures::deleteTokensLabelled($label);
        }

        if (self::$dir !== '' && self::$dir !== 'NO-DIR') {
            WpCli::tryEvaluate(sprintf(
                '$d = %s; foreach ((array) glob($d . "/*") as $f) { @unlink($f); } foreach ((array) glob($d . "/.*") as $f) { if (is_file($f)) { @unlink($f); } } @rmdir($d); echo "ok";',
                self::literal(self::$dir)
            ));
        }

        Fixtures::purge();
    }

    /**
     * G4. A site that is not treated as local is refused before the file is even read:
     * non-zero exit, a reason, and no server line.
     *
     * @group sprint-14b
     */
    public function testANonLocalSiteIsRefused(): void
    {
        $path = $this->writeConfig('refused');

        [$code, $out, $err] = $this->runScript('status', $path, 'add_filter("wpmcp_local_environment", "__return_false");');

        // Non-zero rather than exactly 1: the script exits 1, and wp-env's `run` is free to
        // map a container's failure onto its own code.
        self::assertNotSame(0, $code, 'dev-tokens ran on a site that is not local.');
        self::assertStringContainsString('refused', $err);
        self::assertStringContainsString('"local"', $err);
        self::assertStringNotContainsString('here-active', $out . $err, 'A refused run still reported a server.');
        $this->assertNoSecret($out . $err);

        // The same file on the local branch is not refused - the refusal above was the
        // environment, not the file.
        [$ok] = $this->runScript('status', $path, '');
        self::assertSame(0, $ok);
    }

    /**
     * G4. status matches rows by hash, reports each state, considers only this host, and
     * prints no token and no hash.
     *
     * @group sprint-14b
     */
    public function testStatusMatchesRowsByHashAndPrintsNoSecret(): void
    {
        [$code, $out, $err] = $this->runScript('status', $this->writeConfig('status'), '');

        $this->assertNoSecret($out . $err);
        self::assertSame(0, $code, 'status failed: ' . $err);

        $expect = [
            'here-active'  => 'active',
            'here-dormant' => 'dormant',
            'here-dead'    => 'dead',
        ];

        foreach ($expect as $server => $state) {
            $line = $this->lineFor($out, $server);

            self::assertStringStartsWith($server . ': ' . $state . ' - ', $line);
            self::assertStringContainsString('(row ' . self::$ids[$server] . ', admin scope, user ' . self::$userId . ',', $line);
        }

        self::assertMatchesRegularExpression('/here-active: active - (4[0-9]|5[0-9]|60) min left in the active window, (29|30) d left/', $out);
        self::assertStringContainsString('here-dormant: dormant - 0 min left', $out);
        self::assertStringContainsString('here-unknown: no token row on this site matches', $out);
        self::assertStringContainsString('here-noheader: no Authorization: Bearer header.', $out);
        self::assertStringNotContainsString('elsewhere', $out, 'A server on another host was considered.');
        self::assertStringNotContainsString('docs-server', $out);
        self::assertStringContainsString('5 server(s) in .mcp.json for this host', $out);

        // The seam is in force for this class, and the line says which label a write would
        // use - so an operator always reads the truth, and the test can tell the two apart.
        self::assertStringContainsString('label and mint would write "' . self::devLabel() . '"', $out);

        // Without the override the script writes its own label, and the line says so.
        [$plainCode, $plainOut] = $this->runShippedLabelStatus($this->writeConfig('shipped'));

        self::assertSame(0, $plainCode);
        self::assertStringContainsString(
            'label and mint would write "' . self::SHIPPED_LABEL . '"',
            $plainOut,
            'With DEVTOKENS_LABEL unset the script no longer writes its documented label.'
        );
    }

    /**
     * R2-4. The minutes on a status line come from the SAME reading as the state beside
     * them - the effective end, not the raw column.
     *
     * A row granted more than this site's ceiling is the only way the two can differ, and
     * the plugin cannot mint one here, so the fixture row is widened by hand: 60 days of
     * grant with 40 days left by its column is 10 days left by what this site honours.
     * With the raw column, the line would promise 57,600 minutes of a window that answers
     * for 14,400.
     *
     * @group sprint-14b
     */
    public function testTheMinutesLeftComeFromTheWindowThisSiteHonours(): void
    {
        $id = self::$ids['here-active'];

        try {
            WpCli::evaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->query($wpdb->prepare("UPDATE " . wpmcp_table()'
                . ' . " SET window_secs = %%d, active_until = %%s WHERE id = %%d", 60 * DAY_IN_SECONDS,'
                . ' gmdate("Y-m-d H:i:s", time() + 40 * DAY_IN_SECONDS), %d));',
                $id
            ));

            [$code, $out, $err] = $this->runScript('status', $this->writeConfig('honoured'), '');

            $this->assertNoSecret($out . $err);
            self::assertSame(0, $code, 'status failed: ' . $err);

            $line = $this->lineFor($out, 'here-active');

            self::assertSame(
                1,
                preg_match('/- (\d+) min left in the active window/', $line, $m),
                "The status line carries no minutes: {$line}"
            );

            // 10 days, not 40: 14400 minutes, within a minute of drift.
            self::assertEqualsWithDelta(10 * 24 * 60, (int) $m[1], 2, "Line: {$line}");
        } finally {
            WpCli::tryEvaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->query($wpdb->prepare("UPDATE " . wpmcp_table()'
                . ' . " SET window_secs = %%d, active_until = %%s WHERE id = %%d", 3600,'
                . ' gmdate("Y-m-d H:i:s", time() + 3600), %d));',
                $id
            ));
        }
    }

    /**
     * G4. label writes the fixed label on the matched rows only - never on the other
     * host's row, whose token is live in this very table.
     *
     * @group sprint-14b
     */
    public function testLabelSetsTheDevLabelOnMatchedRowsOnly(): void
    {
        $restore = [
            self::$ids['here-active']  => self::activeLabel(),
            self::$ids['here-dormant'] => self::dormantLabel(),
            self::$ids['here-dead']    => self::deadLabel(),
        ];

        self::$unprefixedIds = array_merge(self::$unprefixedIds, array_keys($restore));

        try {
            [$code, $out, $err] = $this->runScript('label', $this->writeConfig('label'), '');

            $this->assertNoSecret($out . $err);
            self::assertSame(0, $code, 'label failed: ' . $err);

            foreach ($restore as $id => $label) {
                self::assertSame(self::devLabel(), $this->labelOf($id), "Row {$id} was not labelled.");
            }

            self::assertSame(self::elsewhereLabel(), $this->labelOf(self::$ids['elsewhere']), 'The other host\'s row was labelled.');
        } finally {
            // The other host's row too: a broken host filter would have relabelled it.
            foreach ($restore + [self::$ids['elsewhere'] => self::elsewhereLabel()] as $id => $label) {
                WpCli::tryEvaluate(sprintf(
                    'global $wpdb; echo (int) $wpdb->update(wpmcp_table(), array("label" => %s), array("id" => %d));',
                    self::literal($label),
                    $id
                ));
            }

            self::$unprefixedIds = array_values(array_diff(self::$unprefixedIds, array_keys($restore)));
        }

        foreach ($restore as $id => $label) {
            self::assertSame($label, $this->labelOf($id), "Row {$id} did not get its fixture label back.");
        }
    }

    /**
     * G4. mint rewrites only the matching servers' bearer values, keeps a byte-identical
     * backup, binds each new token to the matched row's user (else user 1), and does not
     * revoke the old token.
     *
     * @group sprint-14b
     */
    public function testMintRewritesOnlyTheMatchingEntriesAndKeepsABackup(): void
    {
        $path     = $this->writeConfig('mint');
        $original = $this->readSiteFile($path);
        $newRows  = [];

        try {
            [$code, $out, $err] = $this->runScript('mint', $path, '');

            $after = $this->readSiteFile($path);
            $new   = $this->bearers($after);

            foreach (['here-active', 'here-dormant', 'here-dead', 'here-unknown'] as $server) {
                $row = $this->rowByToken($new[$server] ?? '');

                if ($row !== null) {
                    $newRows[$server]      = $row;
                    self::$unprefixedIds[] = (int) $row['id'];
                }
            }

            $this->assertNoSecret($out . $err, array_values($new));
            self::assertSame(0, $code, 'mint failed: ' . $err);

            self::assertStringContainsString('Restart Claude Code', $out);
            self::assertStringContainsString('NOT revoked', $out);

            // The backup is the file as it was, byte for byte.
            self::assertSame(1, preg_match('/^Backup of the previous file: (.+)$/m', $out, $m), 'mint named no backup.');
            self::assertTrue($original === $this->readSiteFile(trim($m[1])), 'The backup is not the original file, byte for byte.');

            // Only the four bearer values on this host changed, and nothing else did.
            $expected = $original;
            foreach (['here-active', 'here-dormant', 'here-dead', 'here-unknown'] as $server) {
                self::assertArrayHasKey($server, $newRows, "No new row carries the token now written for {$server}.");
                self::assertFalse(self::$tokens[$server] === ($new[$server] ?? ''), "The bearer value of {$server} was not replaced.");
                $expected = str_replace(self::$tokens[$server], $new[$server], $expected);
            }
            // A digest, not the strings: a diff of two .mcp.json files would print every
            // token in them.
            self::assertTrue(
                $expected === $after,
                'mint changed bytes other than the four bearer values (compared as md5:'
                . ' expected ' . md5($expected) . ', got ' . md5($after) . ').'
            );
            self::assertTrue(self::$tokens['elsewhere'] === ($new['elsewhere'] ?? ''), "The other host's bearer value was rewritten.");

            foreach ($newRows as $server => $row) {
                self::assertSame(self::devLabel(), $row['label']);
                self::assertSame('admin', $row['scope']);
                self::assertSame(30 * 86400, (int) $row['window_secs']);
                self::assertEqualsWithDelta((int) $row['now'] + 365 * 86400, (int) $row['expires_at'], 120);
                self::assertSame(
                    $server === 'here-unknown' ? 1 : self::$userId,
                    (int) $row['user_id'],
                    "The new token for {$server} is bound to the wrong user."
                );
            }

            foreach (['here-active', 'here-dormant', 'here-dead'] as $server) {
                self::assertNotNull($this->rowByToken(self::$tokens[$server]), "mint revoked the old token of {$server}.");
            }
        } finally {
            foreach ($newRows as $row) {
                WpCli::tryEvaluate(sprintf('wpmcp_revoke(%d); echo "ok";', (int) $row['id']));
            }

            self::$unprefixedIds = array_values(array_diff(self::$unprefixedIds, array_map(
                static fn (array $r) => (int) $r['id'],
                $newRows
            )));
        }
    }

    /* ------------------------------------------------------------------ helpers */

    /** Write a fixture .mcp.json into the site's temp directory; return its path there. */
    private function writeConfig(string $name): string
    {
        $url = 'https://' . self::$host . '/wp-json/wpmcp/mcp';

        $config = ['mcpServers' => [
            'docs-server'   => ['type' => 'http', 'url' => 'https://docs.example.invalid/mcp'],
            'here-active'   => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer ' . self::$tokens['here-active']]],
            'elsewhere'     => ['type' => 'http', 'url' => 'https://elsewhere.example.invalid/wp-json/wpmcp/mcp', 'headers' => ['Authorization' => 'Bearer ' . self::$tokens['elsewhere']]],
            'here-dormant'  => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer ' . self::$tokens['here-dormant']]],
            'here-dead'     => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer ' . self::$tokens['here-dead']]],
            'here-unknown'  => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer ' . self::$tokens['here-unknown']]],
            'here-noheader' => ['type' => 'http', 'url' => $url],
        ]];

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $path = WpCli::evaluate(sprintf(
            '$p = %s . "/" . %s . ".mcp.json"; echo file_put_contents($p, base64_decode(%s)) ? $p : "NO-WRITE";',
            self::literal(self::$dir),
            self::literal($name),
            self::literal(base64_encode($json))
        ));

        self::assertNotSame('NO-WRITE', $path);

        // Remembered, because the site's temp directory cannot be listed and this file
        // holds raw fixture tokens (round 3, review R2-1).
        Fixtures::noteTempPath($path);

        return $path;
    }

    /**
     * @return array{0:int,1:string,2:string}
     *
     * Every `minted row N` the script prints is recorded before the caller sees the
     * output, so a failure between here and the assertions still leaves teardown able to
     * revoke exactly the rows this class caused - and nothing else.
     */
    private function runScript(string $cmd, string $path, string $prefix): array
    {
        $result = WpCli::evaluateWithStatus(sprintf(
            '%s putenv("DEVTOKENS_CMD=" . %s); putenv("DEVTOKENS_MCP_JSON=" . %s); putenv("DEVTOKENS_LABEL=" . %s);'
            . ' require dirname((new ReflectionFunction("wpmcp_mint"))->getFileName()) . "/bin/dev-tokens.php";',
            $prefix,
            self::literal($cmd),
            self::literal($path),
            self::literal(self::devLabel())
        ));

        if (preg_match_all('/minted row (\d+)/', $result[1], $m)) {
            foreach ($m[1] as $id) {
                self::$unprefixedIds[] = (int) $id;
            }
        }

        if (preg_match('/^Backup of the previous file: (.+)$/m', $result[1], $b)) {
            Fixtures::noteTempPath(trim($b[1]));
        }

        return $result;
    }

    /**
     * One `status` run with DEVTOKENS_LABEL explicitly EMPTY, which is how the shipped
     * default is asserted without any row being written.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function runShippedLabelStatus(string $path): array
    {
        return WpCli::evaluateWithStatus(sprintf(
            'putenv("DEVTOKENS_CMD=status"); putenv("DEVTOKENS_MCP_JSON=" . %s); putenv("DEVTOKENS_LABEL=");'
            . ' require dirname((new ReflectionFunction("wpmcp_mint"))->getFileName()) . "/bin/dev-tokens.php";',
            self::literal($path)
        ));
    }

    private function readSiteFile(string $path): string
    {
        $b64 = WpCli::evaluate(sprintf('echo base64_encode((string) @file_get_contents(%s));', self::literal($path)));

        return (string) base64_decode($b64, true);
    }

    /** @return array<string, string> server name => bearer value */
    private function bearers(string $json): array
    {
        $found = [];

        foreach ((array) (json_decode($json, true)['mcpServers'] ?? []) as $name => $server) {
            $value = (string) ($server['headers']['Authorization'] ?? '');

            if (preg_match('/^Bearer (\S+)$/', $value, $m)) {
                $found[(string) $name] = $m[1];
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null the row carrying sha256($token), with the site's clock */
    private function rowByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $json = WpCli::evaluate(sprintf(
            'global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT id, label, scope, window_secs, expires_at, user_id FROM " . wpmcp_table() . " WHERE token_hash = %%s", %s), ARRAY_A);'
            . ' if (!$r) { echo "null"; return; } $r["expires_at"] = strtotime($r["expires_at"] . " UTC"); $r["now"] = time(); echo wp_json_encode($r);',
            self::literal(hash('sha256', $token))
        ));

        $row = json_decode($json, true);

        return is_array($row) ? $row : null;
    }

    private function labelOf(int $id): string
    {
        return WpCli::evaluate(sprintf(
            'global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare("SELECT label FROM " . wpmcp_table() . " WHERE id = %%d", %d));',
            $id
        ));
    }

    private function lineFor(string $out, string $server): string
    {
        foreach (explode("\n", $out) as $line) {
            if (str_starts_with(trim($line), $server . ':')) {
                return trim($line);
            }
        }

        self::fail("No output line for {$server}.");
    }

    /**
     * No fixture token, no hash of one, and no 64-hex string at all. assertFalse over
     * str_contains, so a failure never prints the output it is refusing.
     *
     * @param list<string> $extra more raw tokens that must not appear
     */
    private function assertNoSecret(string $output, array $extra = []): void
    {
        foreach (array_merge(array_values(self::$tokens), $extra) as $token) {
            self::assertFalse(str_contains($output, $token), 'dev-tokens printed a token.');
            self::assertFalse(str_contains($output, hash('sha256', $token)), 'dev-tokens printed a token hash.');
        }

        self::assertSame(0, preg_match('/[0-9a-f]{64}/i', $output), 'dev-tokens printed a 64-hex-digit value.');
    }

    private static function literal(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
