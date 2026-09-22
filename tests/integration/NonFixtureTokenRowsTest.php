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
 *   Fixtures::revokeTokenIds()           DevTokensScriptTest's teardown, by id only
 *                                        (round 2: it used to sweep by the fixed dev
 *                                        label and an id range, which would have taken
 *                                        a token an operator minted or labelled while
 *                                        the suite ran)
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

    /**
     * THE LABEL `bin/dev-tokens.php` writes, on a row this class did not mint through the
     * script - the operator's own dev token, as it looks the moment somebody runs
     * `dev-tokens.sh label`. Nothing in a run may delete it.
     *
     * IT CARRIES THIS RUN'S PREFIX AFTER THAT LABEL (round 3, review R2-1). It still does
     * not START with the prefix, so `purge()`'s anchored DELETE leaves it and the digest
     * snapshot counts it as a non-fixture row - which is the whole point of the decoy -
     * but `leftoverTokenLabels()` finds it, so a run killed while it exists leaves a row
     * the debris check names instead of one nobody can see.
     */
    private static function operatorLabel(): string { return 'claude-code dev (local) ' . Fixtures::name('g6-operator-row'); }
    private static function scratchLabel(): string { return Fixtures::name('g6-scratch'); }
    private static function decoyLabel(): string { return 'dev decoy ' . Fixtures::name('g6-operator'); }

    private static int $sentinelId = 0;
    private static int $operatorId = 0;

    /**
     * An operator's own label that merely CONTAINS the prefix, with no run id after it -
     * the one shape the debris listing is meant to ignore, and therefore the one shape a
     * crashed run could leave unseen. It lives for the length of ONE test, inside a
     * try/finally that deletes it by id, rather than for the whole class.
     */
    private static function lookalikeLabel(): string { return 'ops notes ' . Fixtures::PREFIX . 'keepme'; }
    private static function lookalikeSeedLabel(): string { return 'lookalike seed ' . Fixtures::name('g6-lookalike'); }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        // THE HOURLY SWEEP IS HELD OFF for this class, because it makes a token DEAD and
        // then needs the row to still be there. `wpmcp_flush_expired_cb()` deletes exactly
        // the rows `wpmcp_token_state()` calls dead, so the two sets are the same set and no
        // fixture shape avoids the race - see Fixtures::suspendTokenSweep(), and run
        // 35669745657, where this race cost a three-hour run. destroy() puts it back.
        Fixtures::suspendTokenSweep();

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

        // The decoy: minted with a prefixed label so a crash leaves it findable, then
        // relabelled by id to the operator's own label. Deleted by id in teardown.
        Fixtures::mintToken('read', self::decoyLabel(), 1);

        self::$operatorId = (int) WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . wpmcp_table() . " WHERE label = %%s", %s));',
            self::literal(self::decoyLabel())
        ));

        if (self::$operatorId === 0) {
            throw new \RuntimeException('The G6 operator-token decoy was not minted.');
        }

        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->update(wpmcp_table(), array("label" => %s), array("id" => %d));',
            self::literal(self::operatorLabel()),
            self::$operatorId
        ));

        
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        if (self::$operatorId > 0) {
            Fixtures::revokeTokenIds([self::$operatorId]);
            self::$operatorId = 0;
        }

        if (self::$sentinelId > 0) {
            WpCli::tryEvaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->delete(wpmcp_table(), array("id" => %d, "label" => %s));',
                self::$sentinelId,
                self::literal(self::sentinelLabel())
            ));
            self::$sentinelId = 0;
        }

        Fixtures::deleteTokensLabelled(self::scratchLabel());
        Fixtures::deleteTokensLabelled(self::scratchLabel() . '-dev');
        Fixtures::deleteTokensLabelled(self::decoyLabel());
        Fixtures::deleteTokensLabelled(self::lookalikeSeedLabel());

        // Put the hourly sweep back; the plugin only ever schedules it on activation.
        Fixtures::resumeTokenSweep();

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

        // DevTokensScriptTest's teardown, replayed with the ids IT would have: the ones
        // its script printed. The operator's own row carries the same fixed label and is
        // not among them, so it must survive.
        Fixtures::mintToken('read', self::scratchLabel() . '-dev', 1);
        $mintedByAScript = Fixtures::tokenIdLabelled(self::scratchLabel() . '-dev');
        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->update(wpmcp_table(), array("label" => %s), array("id" => %d));',
            self::literal(self::operatorLabel()),
            $mintedByAScript
        ));
        Fixtures::revokeTokenIds([$mintedByAScript]);

        self::assertSame(
            0,
            (int) WpCli::evaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . wpmcp_table() . " WHERE id = %%d", %d));',
                $mintedByAScript
            )),
            'The dev-token teardown did not revoke the row it was given.'
        );

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

        // And an operator's own label that merely contains the prefix is NOT debris
        // (round 2, review S6): nothing follows the prefix that this harness would write,
        // so nobody should go hunting for the run that left it. Built and removed inside
        // this test, because it is by definition the one row a killed run would hide.
        Fixtures::mintToken('read', self::lookalikeSeedLabel(), 1);
        $lookalikeId = Fixtures::tokenIdLabelled(self::lookalikeSeedLabel());

        try {
            WpCli::evaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->update(wpmcp_table(), array("label" => %s), array("id" => %d));',
                self::literal(self::lookalikeLabel()),
                $lookalikeId
            ));

            self::assertArrayNotHasKey(
                $lookalikeId,
                Fixtures::leftoverTokenLabels(),
                'A label that merely contains the fixture prefix is reported as foreign debris.'
            );

            // A bare `wpmcp-test-` label, the pre-run-id shape, IS still reported (R2-5).
            WpCli::evaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->update(wpmcp_table(), array("label" => %s), array("id" => %d));',
                self::literal(Fixtures::PREFIX . 'legacy-name'),
                $lookalikeId
            ));

            self::assertArrayHasKey(
                $lookalikeId,
                Fixtures::leftoverTokenLabels(),
                'A legacy wpmcp-test- label is no longer reported as debris.'
            );
        } finally {
            Fixtures::revokeTokenIds([$lookalikeId]);
        }
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
