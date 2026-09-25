<?php
/**
 * ITEM 0 OF SPRINT ACF-READ: THE THREE CLAIMS THE DESIGN RESTS ON, MEASURED AT RUNTIME.
 *
 * `analysis/71-scout-acf-6810-api.md` and `analysis/72-scout-acf-supported-api.md` are both
 * marked, in their own words, as reasoned from source with NOTHING confirmed at runtime -
 * both scouts were read-only and every route to executing PHP here boots WordPress and
 * writes. Three of their claims are load-bearing for everything this sprint builds, so they
 * are measured here before anything is built on them, and they are measured AGAIN on every
 * run of this group rather than once in a report:
 *
 *   A1  `acf_format_value_for_rest( $raw, $post_id, $field, 'standard' )` is callable from
 *       our own code and returns exactly what `acf_format_value()` returns, plus the
 *       reduction. If that is false, `get_field` is the only read available and the sprint's
 *       whole permission story goes with it (`analysis/72` §1).
 *   A2  the reduction FIRES for a Relationship whose target the acting user cannot read and
 *       does NOT fire when they can - i.e. it is a permission gate and not a blanket
 *       flattening (`analysis/71` §4, `analysis/72` §1b).
 *   A3  the value store's `"$post_id:$field_name:formatted"` key
 *       (`includes/acf-value-functions.php` 181-188) never serves a REDUCED value to an
 *       un-reduced caller, and never suppresses the reduction for a reduced one. This is the
 *       one `analysis/72` §1c flagged as a trap, and it is the one that would be INVISIBLE:
 *       a wrong answer here is a correct-looking value with the permission check silently
 *       skipped (`analysis/72` §1c).
 *
 * WHY THIS IS NOT IN THE SPRINT GATE GROUP. The gate group must contain only tests that pass
 * on a site with no ACF, because CI fails any gate group with even one skip and CI has no
 * ACF. This class cannot run without ACF and says so by skipping, so it carries `acf-data`
 * and no sprint group. Run it with `--group acf-data` against a site that has ACF.
 *
 * WHY IT SKIPS RATHER THAN FAILS WITHOUT ACF, WHEN FixtureIntegrationTestCase's OWN RULE IS
 * THAT A BROKEN HARNESS MUST BE RED. That rule is about a harness that cannot seed a site
 * that EXISTS. "This site has no ACF" is not a broken harness; it is the other half of the
 * product's own supported configuration - the half `BareCoreTest` and the sprint gate group
 * cover. A skip here is honest precisely because the absence is asserted elsewhere and not
 * merely unobserved.
 *
 * THE MEASUREMENT ITSELF IS `tests/Support/acf-rest-probe.php`, one PHP process on the site,
 * because the field group it measures is a LOCAL one that exists only for that process. See
 * that file for why. It prints JSON and judges nothing; every judgement below is PHPUnit's.
 *
 * @group acf-data
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class AcfRestValuePathTest extends FixtureIntegrationTestCase
{
    /** @var array<string, mixed>|null the probe's answer, read once for the class */
    private static ?array $probe = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
    }

    public static function tearDownAfterClass(): void
    {
        self::$probe = null;

        Fixtures::purge();

        parent::tearDownAfterClass();
    }

    /**
     * Run the probe once, or skip the whole class when the site has no ACF.
     *
     * @return array<string, mixed>
     */
    private function probe(): array
    {
        if (self::$probe !== null) {
            return self::$probe;
        }

        $path = WPMCP_PLUGIN_DIR . '/tests/Support/acf-rest-probe.php';

        self::assertFileExists($path, 'The ACF runtime probe is missing.');

        if (WpCli::usesWpEnv()) {
            self::markTestSkipped(
                'Item 0 is measured by `wp eval-file` against a HOST path, which a wp-env'
                . ' container cannot see. Run this group against a local site that has ACF.'
            );
        }

        // BEFORE THE CHILD IS STARTED, and this is not ceremony: runId() is what calls
        // putenv(WPMCP_TEST_RUN_ID), and proc_open hands the child the environment as it is at
        // that moment. Nothing else in this class builds an McpClient, so without this line the
        // probe names its posts `wpmcp-test-noRunId0-...` and purge() does not recognise them.
        Fixtures::runId();

        $raw    = WpCli::run(['eval-file', $path]);
        $answer = json_decode(trim($raw), true);

        self::assertIsArray($answer, "The ACF probe did not print JSON. It printed:\n" . $raw);

        if (($answer['skip'] ?? '') !== '') {
            self::markTestSkipped('Item 0 needs ACF on the site: ' . (string) $answer['skip']);
        }

        self::$probe = $answer;

        return $answer;
    }

    /**
     * A1. Our own call returns what `acf_format_value()` returns, for a caller who can read
     * every referenced object - so `'standard'` is ACF's ordinary formatting and nothing
     * else, and routing through the REST formatter costs us no fidelity.
     *
     * @group acf-data
     */
    public function testTheRestFormatterMatchesAcfFormatValueForACallerWhoCanReadEverything(): void
    {
        $probe = $this->probe();

        self::assertGreaterThan(
            0,
            (int) $probe['administrator'],
            'The site has no administrator, so the "can read everything" half of A1 and A2 was'
            . ' never exercised.'
        );

        foreach (['administrator.wpmcp_probe_hidden', 'administrator.wpmcp_probe_public'] as $read) {
            $row = $probe['reads'][$read];

            self::assertSame(
                $row['acf_format_value'],
                $row['for_rest'],
                "For {$read}, acf_format_value_for_rest(..., 'standard') did not return what"
                . ' acf_format_value() returns. analysis/72 §1b says the two are the same call'
                . ' in standard mode; if they are not, the sprint is built on a wrong reading.'
            );
            self::assertNotSame(
                $row['bare_ids'],
                $row['for_rest'],
                "For {$read}, the value came back as bare IDs for a caller who can read the"
                . ' target. That is the reduction firing when it must not.'
            );
        }

        // And it is a real expansion, not an empty value that would match anything.
        self::assertSame(
            ['post:' . (int) $probe['ids']['draft']],
            $probe['reads']['administrator.wpmcp_probe_hidden']['for_rest'],
            'The administrator read did not expand the Relationship to a post at all, so the'
            . ' comparison above compared two empty values.'
        );
    }

    /**
     * A2. The reduction is a PERMISSION gate: it fires on the draft target for a caller who
     * cannot read it, and does not fire on the published one for the same caller.
     *
     * BOTH HALVES IN ONE TEST, because either alone is passable by a broken implementation -
     * "always reduce" passes the first, "never reduce" passes the second.
     *
     * @group acf-data
     */
    public function testTheReductionFiresOnlyWhenTheCallerCannotReadTheTarget(): void
    {
        $probe  = $this->probe();
        $hidden = $probe['reads']['nobody.wpmcp_probe_hidden'];
        $public = $probe['reads']['nobody.wpmcp_probe_public'];

        self::assertFalse(
            (bool) $hidden['can_read_target'],
            'The fixture is wrong: a caller with no user could read the draft target, so'
            . ' nothing here tests the reduction.'
        );
        self::assertFalse((bool) $hidden['exposable'], 'ACF considers the draft target exposable to nobody.');
        self::assertTrue((bool) $public['exposable'], 'ACF considers a PUBLISHED target unexposable, which would reduce everything.');

        self::assertSame(
            $hidden['bare_ids'],
            $hidden['for_rest'],
            'A Relationship pointing at a draft was NOT reduced to bare IDs for a caller who'
            . ' cannot read it. That is the 6.8.10 reduction not reaching our call site, which'
            . ' is the whole reason this sprint reads through the REST formatter.'
        );
        self::assertNotSame(
            $public['bare_ids'],
            $public['for_rest'],
            'A Relationship pointing at a PUBLISHED post was reduced for a caller who can read'
            . ' it. The reduction is then a blanket flattening, not a permission gate.'
        );
        self::assertSame(
            ['post:' . (int) $probe['ids']['published']],
            $public['for_rest'],
            'The published target did not come back expanded.'
        );

        // AND THE ADMINISTRATOR SEES THE DRAFT EXPANDED, which is the "and does not fire when
        // they can" half stated about the SAME object the previous assertion reduced.
        self::assertTrue(
            (bool) $probe['reads']['administrator.wpmcp_probe_hidden']['exposable'],
            'An administrator cannot expand a draft, so the reduction is about the object and'
            . ' not about the caller.'
        );
    }

    /**
     * A3. The formatted-value cache cannot leak a reduction in either direction.
     *
     * THE FAILURE THIS FORBIDS IS SILENT. If the store held the reduced value, a later
     * `get_field()` in the same request would hand back bare IDs and look like an empty
     * relationship; if the store's hit suppressed the filter, our own read would hand back a
     * full expansion with the permission check skipped. Neither raises anything.
     *
     * @group acf-data
     */
    public function testTheFormattedValueCacheNeverCarriesTheReductionEitherWay(): void
    {
        $probe    = $this->probe();
        $cache    = $probe['cache'];
        $draftRef = ['post:' . (int) $probe['ids']['draft']];
        $bare     = [(int) $probe['ids']['draft']];

        self::assertSame($bare, $cache['a_our_call'], 'The reduced read did not reduce.');
        self::assertSame(
            $draftRef,
            $cache['a_store_after_our_call'],
            "The values store holds the REDUCED value under {$cache['key']}. Anything else in"
            . ' this request that reads the field would get bare IDs and no way to tell that'
            . ' from an empty relationship.'
        );
        self::assertSame(
            $draftRef,
            $cache['a_get_field_after'],
            'get_field() after our read returned the reduced value out of the cache.'
        );

        self::assertSame($draftRef, $cache['b_get_field_first'], 'get_field() did not expand on a cold cache.');
        self::assertSame($draftRef, $cache['b_store_after_get_field'], 'get_field() cached something other than its own answer.');
        self::assertSame(
            $bare,
            $cache['b_our_call_after'],
            'With the store already holding the un-reduced value, our read did NOT reduce. The'
            . ' cache hit would then skip the permission check for every field a request reads'
            . ' twice - the failure analysis/72 §1c flagged and could not run.'
        );
    }

    /**
     * WHY `'light'` IS NEVER PASSED, held as a fact about the site rather than a sentence in a
     * docblock: `acf_get_field_type()` returns null for a type nothing registered, and
     * `'light'` calls a method on that return value.
     *
     * @group acf-data
     */
    public function testAnUnregisteredFieldTypeHasNoTypeObject(): void
    {
        self::assertTrue(
            (bool) $this->probe()['unregistered_field_type_is_null'],
            'acf_get_field_type() answered something for a type nothing registered, so the'
            . " reason this sprint never passes 'light' needs re-measuring."
        );
    }

    /**
     * The probe left nothing behind. Asserted rather than trusted, because the fixture it
     * builds is three posts on a live site.
     *
     * @group acf-data
     */
    public function testTheProbeRemovedItsOwnFixture(): void
    {
        foreach ($this->probe()['cleaned'] as $what => $gone) {
            self::assertTrue((bool) $gone, "The probe left its {$what} post on the site.");
        }

        self::assertStringStartsWith(
            Fixtures::runPrefix(),
            (string) $this->probe()['prefix'],
            'The probe did not use this run\'s fixture prefix, so its debris would not be'
            . ' recognised as ours by purge() or by bin/debris-check.php.'
        );
    }
}
