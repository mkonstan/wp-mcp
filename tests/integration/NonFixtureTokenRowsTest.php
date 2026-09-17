<?php
/**
 * The suite leaves every token row it did not create exactly as it was (sprint 14b, G6).
 *
 * WHY THIS MATTERS NOW. Both Local sites carry live tokens the developer's own Claude
 * uses - unprefixed, admin scope. A suite run that renewed, relabelled, shortened or
 * deleted one would break those MCP servers silently, the next time Claude starts.
 *
 * WHAT A RUN DOES TO THE TOKEN TABLE, swept from tests/ (every write that names
 * wpmcp_table() or calls a plugin function that writes it), and replayed here:
 *
 *   Fixtures::purge()                    DELETE ... WHERE label LIKE '<run prefix>%'
 *   mintToken / makeTokensDormant|Dead / deleteTokensLabelled   by exact label
 *   wpmcp_renew() / wpmcp_revoke()       by the id of a fixture row
 *   wpmcp_flush_expired_cb()             every DEAD row, site-wide (TokenLifecycleTest)
 *   wpmcp_install()                      dbDelta plus every migration (BoundIpDropMigrationTest)
 *   wpmcp_migrate_token_lifetimes()      WHERE window_secs = 0 (TokenLifetimeMigrationTest)
 *   wpmcp_migrate_token_user_ids()       WHERE user_id = 0 (TokenUserIdMigrationTest)
 *   wpmcp_migrate_drop_address_column()  a column, not a row (BoundIpDropMigrationTest)
 *
 * THE ONE EXCEPTION, BY DESIGN: a row that is already DEAD. The flush deletes it, and so
 * does the plugin's own hourly cron whether a suite runs or not. Dead rows are left out
 * of the comparison, and named here rather than hidden.
 *
 * A SENTINEL MAKES IT NON-VACUOUS. CI's wp-env has no developer token, so the class
 * mints one live row whose label CONTAINS the run prefix but does not START with it -
 * the shape of a non-fixture row as far as every prefix-anchored write above is
 * concerned - and deletes it by id afterwards. leftoverTokenLabels() matches the prefix
 * anywhere in a label, so a crashed run's sentinel is still reported as debris.
 *
 * NOTHING SECRET IS PRINTED. Rows are compared as digests; a failure names row ids.
 *
 * @group sprint-14b
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class NonFixtureTokenRowsTest extends FixtureIntegrationTestCase
{
    private static function sentinelLabel(): string { return 'dev sentinel ' . Fixtures::name('g6-sentinel'); }
    private static function scratchLabel(): string { return Fixtures::name('g6-scratch'); }

    private static int $sentinelId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        // Owned by user 1, not by a fixture user: the test runs purge(), which deletes
        // this run's users, and a sentinel must outlive everything the run does.
        Fixtures::mintToken('read', self::sentinelLabel(), 1);

        self::$sentinelId = (int) WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . wpmcp_table() . " WHERE label = %%s", %s));',
            self::literal(self::sentinelLabel())
        ));

        if (self::$sentinelId === 0) {
            throw new \RuntimeException('The G6 sentinel row was not minted.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        if (self::$sentinelId > 0) {
            WpCli::tryEvaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->delete(wpmcp_table(), array("id" => %d, "label" => %s));',
                self::$sentinelId,
                self::literal(self::sentinelLabel())
            ));
            self::$sentinelId = 0;
        }

        Fixtures::deleteTokensLabelled(self::scratchLabel());
        Fixtures::purge();
    }

    /**
     * G6. Every path by which a run writes the token table leaves each live non-prefixed
     * row with its count, label, active_until, expires_at, window, scope, owner and hash.
     *
     * @group sprint-14b
     */
    public function testEveryTokenWriteARunMakesLeavesNonPrefixedRowsExactlyAsTheyWere(): void
    {
        $before = self::snapshot();

        self::assertArrayHasKey(self::$sentinelId, $before, 'The sentinel is not in the snapshot, so this test compares nothing.');

        // The harness's own writes, as every fixture-bearing class makes them.
        Fixtures::purge();
        Fixtures::mintToken('admin', self::scratchLabel(), 1);
        $scratch = Fixtures::tokenIdLabelled(self::scratchLabel());
        Fixtures::makeTokensDormantLabelled(self::scratchLabel());
        WpCli::evaluate(sprintf('$r = wpmcp_renew(%d); echo is_wp_error($r) ? "E" : "ok";', $scratch), 1);
        Fixtures::countTokensLabelled(self::scratchLabel());
        Fixtures::makeTokensDeadLabelled(self::scratchLabel());
        Fixtures::deleteTokensLabelled(self::scratchLabel());
        Fixtures::mintToken('read', self::scratchLabel(), 1);
        WpCli::evaluate(sprintf('wpmcp_revoke(%d); echo "ok";', Fixtures::tokenIdLabelled(self::scratchLabel())));

        // The site-wide plugin functions the suite calls.
        WpCli::evaluate('wpmcp_flush_expired_cb(); echo "ok";');
        WpCli::evaluate('$r = wpmcp_migrate_token_lifetimes(); echo $r === false ? "FALSE" : "ok";');
        WpCli::evaluate('$r = wpmcp_migrate_token_user_ids(); echo $r === false ? "FALSE" : "ok";');
        WpCli::evaluate('$r = wpmcp_migrate_drop_address_column(); echo $r === false ? "FALSE" : "ok";');
        WpCli::evaluate('echo wpmcp_install() ? "1" : "0";');
        Fixtures::purge();

        $after = self::snapshot();

        $missing = array_keys(array_diff_key($before, $after));
        $changed = [];

        foreach ($before as $id => $digest) {
            if (isset($after[$id]) && $after[$id] !== $digest) {
                $changed[] = $id;
            }
        }

        self::assertSame([], $missing, 'Non-fixture token rows were deleted: ids ' . implode(', ', $missing));
        self::assertSame([], $changed, 'Non-fixture token rows were changed: ids ' . implode(', ', $changed));
        self::assertSame(count($before), count($after), 'The number of non-fixture token rows changed.');
    }

    /**
     * The debris check can find a sentinel a crashed run left: the prefix anywhere in a
     * label counts, not only at its start.
     *
     * @group sprint-14b
     */
    public function testTheDebrisListingFindsTheSentinel(): void
    {
        $found = Fixtures::leftoverTokenLabels();

        self::assertArrayHasKey(self::$sentinelId, $found, 'leftoverTokenLabels() cannot see a prefix inside a label.');
        self::assertSame(Fixtures::runId(), Fixtures::runIdIn($found[self::$sentinelId]));
        self::assertArrayHasKey(self::$sentinelId, Fixtures::ours($found));
    }

    /**
     * Live rows whose label does not start with the prefix: id => digest of every column
     * that matters to the client holding the token. Dead rows are excluded; see the class
     * docblock.
     *
     * @return array<int, string>
     */
    private static function snapshot(): array
    {
        $raw = WpCli::evaluate(sprintf(
            'global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SELECT id, label, scope, user_id, window_secs, active_until, expires_at, token_hash FROM " . wpmcp_table()'
            . ' . " WHERE label NOT LIKE %%s AND expires_at > UTC_TIMESTAMP()", %s), ARRAY_A);'
            . ' foreach ((array) $rows as $r) { echo (int) $r["id"], "\t", md5(implode("|", $r)), "\n"; }',
            self::literal(Fixtures::PREFIX . '%')
        ));

        $rows = [];

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", trim($line));

            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $rows[(int) $parts[0]] = $parts[1];
            }
        }

        return $rows;
    }

    private static function literal(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
