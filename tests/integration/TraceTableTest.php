<?php
/**
 * The trace log is a TABLE, and the promise it exists for still holds (1.1.2).
 *
 * WHAT CHANGED AND WHY. The private log was `wp-content/wpmcp/trace-<32 hex>.log` behind an
 * `.htaccess`, which is an APACHE file: nginx has no per-directory config and never reads it.
 * MEASURED on the development host - `GET /wp-content/wpmcp/trace.log` returned 200 with 14 KB
 * of absolute paths, the OS username, the plugin inventory, tool names, user ids and every
 * stack frame, to anybody, with no token. Randomising the name bought secrecy and nothing
 * more. A table cannot be served over HTTP at all, so the class of failure is gone rather than
 * mitigated - and with it the guard files, the daily self-check, both admin notices, the size
 * cap and the rewrite-inside-every-write that held it. See analysis/53 D25.
 *
 * THE ONE PROMISE THIS CLASS IS ABOUT. The boundary hands a caller `Internal error (trace
 * 1a2b3c4d)` and tells it to quote that id. Every assertion below is a way of asking whether
 * the id still resolves for the person supporting the site:
 *
 *   over the wire      a REAL failure over HTTP gets an id, and the detail is findable by it.
 *   on the screen      the operator pastes that id into Settings > WP MCP and sees the entry.
 *   and only them      a user without `manage_options` is refused the screen outright.
 *   for a bounded time the sweep takes what is past retention and leaves what is not.
 *   and the file goes  an existing site's log, directory and options are deleted on upgrade.
 *
 * `sql-select` REFUSING THE TABLE IS THE SIXTH, and it lives in
 * tests/integration/SqlSelectTest.php beside the other two table denials, because that is the
 * class that owns the tool and already proves a refusal reaches the database nowhere.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\TraceLog;
use WpMcp\Tests\Support\WpCli;

final class TraceTableTest extends FixtureIntegrationTestCase
{
    /** The mu-plugin that registers the throwing tool. */
    private const THROWING = 'trace-table-thrower';

    /** Where the symlinked wp-content/wpmcp points, relative to wp-content. */
    private const SYMLINK_TARGET = 'wpmcp-test-trace-symlink-target';

    /** The message the TypeError carries. It must reach the table and never the wire. */
    private const THROWN_MESSAGE = 'wpmcp trace table probe: this message must not reach a client';

    private static function label(): string { return Fixtures::name('tracetable'); }
    private static function login(): string { return Fixtures::name('tracetable-author'); }
    private static function toolName(): string { return Fixtures::name('tool-trace-table'); }

    private static int $userId = 0;
    private static string $token = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        MuPlugin::drop(self::THROWING, self::throwingToolSource());

        self::$userId = Fixtures::createUser(self::login(), 'author');
        self::$token  = Fixtures::mintToken('read', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::THROWING);
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());

        // Every planted trace row, and 1.1.1's log file if the upgrade test was killed
        // between planting it and the request that removes it - which would otherwise leave
        // a readable file full of stack traces under the document root.
        TraceLog::forgetPlanted();
        TraceLog::forgetLegacyFile();
        TraceLog::forgetSymlinkedDir(self::SYMLINK_TARGET);
        TraceLog::forgetFileLeft();

        // Unconditionally, for the reason StaleBackupSweepMigrationTest gives: a crash between
        // moving the revision back and the request that upgrades it would leave the site
        // running wpmcp_install() on every request until somebody noticed.
        WpCli::tryEvaluate('echo (int) update_option("wpmcp_db_ver", (int) WPMCP_DB_VER);');

        Fixtures::purge();
    }

    /**
     * The control, and it is two claims: the table is there, and the throwing tool is
     * registered. Without either, every assertion below passes vacuously.
     *
     * @group sprint-14d
     */
    public function testTheTableExistsAndTheThrowingToolIsRegistered(): void
    {
        self::assertTrue(
            TraceLog::tableExists(),
            'The traces table does not exist on this site, so the boundary has nowhere to'
            . ' write and every trace is going to error_log() through the fallback. Revision 7'
            . ' creates it; if this is red the upgrade did not run or its CREATE TABLE failed.'
        );

        $body = (string) $this->mcp(self::$token)->post('tools/list')->getBody();

        self::assertStringContainsString(
            self::toolName(),
            $body,
            'The throwing fixture tool is not in tools/list, so the wpmcp_tools filter never'
            . ' ran and nothing below is testing the boundary.'
        );
    }

    /**
     * A trace id issued by a REAL failure over HTTP is findable afterwards.
     *
     * THE WHOLE SPRINT REDUCES TO THIS TEST. The wire carries eight hex digits and nothing
     * else; the row has to carry the rest. It is asserted through the plugin's own
     * wpmcp_trace_find() - the function the operator's screen uses - rather than through a
     * query of the test's own, so a lookup that broke would be red here and not merely on
     * the screen.
     *
     * @group sprint-14d
     */
    public function testATraceIdIssuedByARealFailureOverHttpIsFindableAfterwards(): array
    {
        $before = TraceLog::count();

        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::toolName(),
            'arguments' => ['wpmcp_test_key' => 'must-not-be-in-the-stack'],
        ]);

        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32603, $body['error']['code'] ?? null, $raw);

        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $traceId, $raw);

        self::assertGreaterThan(
            $before,
            TraceLog::count(),
            'The failure wrote no row at all. The site held ' . $before . ' traces before it.'
        );

        $entry = TraceLog::entry($traceId);

        self::assertNotSame(
            '',
            $entry,
            'No row carries trace=' . $traceId . '. The client was handed an id that leads'
            . ' nowhere - which is worse than the 500 the boundary replaced, because nobody'
            . ' learns anything.'
        );

        self::assertStringContainsString('class=TypeError', $entry, $entry);
        self::assertStringContainsString('message=' . self::THROWN_MESSAGE, $entry, $entry);
        self::assertStringContainsString('method=tools/call', $entry, $entry);
        self::assertStringContainsString('tool=' . self::toolName(), $entry, $entry);
        self::assertStringContainsString('user=' . self::$userId, $entry, $entry);

        // file:line, matched up to `.php:<line>` rather than as a run of non-whitespace,
        // because the path can contain spaces ("C:\Users\x\Local Sites\...").
        self::assertMatchesRegularExpression(
            '/ at=.+\.php:\d+$/m',
            $entry,
            'The row has no file:line, so an operator holding the id still cannot find the'
            . ' failure. Entry: ' . $entry
        );

        // The stack, indented under the header line - the same shape the file held, which is
        // what makes an entry readable and a `grep trace=<id>` still work.
        self::assertMatchesRegularExpression(
            '/\n {4}#0 /',
            $entry,
            'The row has no stack. Entry: ' . $entry
        );

        // The argument's KEY is in the stack and its VALUE is not - the 1.1.1 rule, which a
        // change of storage must not quietly undo.
        self::assertStringContainsString('wpmcp_test_key', $entry, $entry);
        self::assertStringNotContainsString('must-not-be-in-the-stack', $entry, $entry);

        // And the credential is nowhere in the table, which is now searched on the SITE
        // rather than shipped here - see TraceLog::contains().
        self::assertFalse(
            TraceLog::contains(self::$token),
            'The raw token is in the traces table. A token is identified by its row id.'
        );

        return ['trace_id' => $traceId];
    }

    /**
     * The operator pastes that id into the settings screen and sees that one entry.
     *
     * WITHOUT THIS THE SPRINT MAKES DIAGNOSIS HARDER for exactly the person the id is for.
     * While the traces were a file the operator opened the file; a table has no such door, so
     * the one that replaces it has to exist and has to be proved.
     *
     * RENDERED THROUGH wp-cli AND NOT OVER HTTP, which is the limitation
     * AdminTokenTableTest already states: this harness is a Guzzle client with no WordPress
     * session, so it cannot log in to wp-admin. What it does exercise is the real function,
     * the real nonce check and the real capability gate, against the real row.
     *
     * @depends testATraceIdIssuedByARealFailureOverHttpIsFindableAfterwards
     *
     * @group sprint-14d
     */
    public function testTheOperatorScreenResolvesTheIdTheCallerWasGiven(array $wire): void
    {
        [$code, $html] = self::renderLookup($wire['trace_id'], 1);

        self::assertSame(0, $code, 'The settings screen did not render for user 1: ' . $html);

        self::assertStringContainsString(
            'trace=' . $wire['trace_id'],
            $html,
            'The settings screen was given the id the caller was handed and did not print the'
            . ' entry. That id is the only thing the boundary discloses, so a screen that'
            . ' cannot resolve it leaves the operator with nothing.'
        );
        self::assertStringContainsString(
            'class=TypeError',
            $html,
            'The entry is on the screen but without the class of the failure: ' . $html
        );
        self::assertStringContainsString(
            htmlspecialchars(self::THROWN_MESSAGE, ENT_QUOTES),
            $html,
            'The throwable\'s message is not on the screen, escaped. It is the sentence that'
            . ' says what broke.'
        );

        // A LOOKUP AND NOT A BROWSER. An id that does not exist prints the "no trace" notice
        // rather than a listing of everything - which is what a table UI would have done, and
        // is the thing this screen deliberately is not.
        [, $empty] = self::renderLookup('00000000', 1);

        self::assertStringContainsString(
            'No trace with that id',
            $empty,
            'An id with no row behind it did not produce the empty answer: ' . $empty
        );
        self::assertStringNotContainsString(
            'class=TypeError',
            $empty,
            'An id with no row behind it printed somebody else\'s trace, so the screen is'
            . ' listing rather than looking up.'
        );
    }

    /**
     * A user without `manage_options` is refused the screen, and sees none of the entry.
     *
     * THE CAPABILITY IS THE WHOLE OF THE ACCESS CONTROL ON THIS DETAIL. What the screen prints
     * is the class, the message, the absolute file:line, the WP_Error data and the stack -
     * exactly what the boundary withholds from a caller. An Author who could read it would be
     * reading past the boundary through wp-admin instead of through the API.
     *
     * @depends testATraceIdIssuedByARealFailureOverHttpIsFindableAfterwards
     *
     * @group sprint-14d
     */
    public function testTheScreenRefusesAUserWithoutManageOptions(array $wire): void
    {
        self::assertFalse(
            Fixtures::userCan(self::$userId, 'manage_options'),
            'The fixture user holds manage_options, so this test would prove nothing. It is'
            . ' created as an author precisely because an author does not.'
        );

        [$code, $html, $stderr] = self::renderLookup($wire['trace_id'], self::$userId);

        self::assertNotSame(
            0,
            $code,
            'wpmcp_render_admin() returned normally for a user without manage_options, so the'
            . ' wp_die() at the top of it did not fire. Output: ' . $html
        );

        foreach (['trace=' . $wire['trace_id'], self::THROWN_MESSAGE, 'class=TypeError'] as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $html . "\n" . $stderr,
                'A user without manage_options was shown "' . $secret . '". The screen prints'
                . ' the detail the error boundary exists to keep from a caller.'
            );
        }
    }

    /**
     * The sweep removes what is past retention and keeps what is not.
     *
     * ON THE HOOK THAT ALREADY EXISTS, `wpmcp_flush_expired`, hourly, beside the dead token
     * rows. The file era had no clean-up hook at all - MEASURED, 1.7 MB over 763 entries on a
     * development site in eleven days, never rotated or aged out, and on a customer host
     * nothing ever came along to clean it up. Retention is DAYS now because a trace rides in
     * every database backup, export and staging clone, which is the one thing a file did not
     * do.
     *
     * THE ROW PAST RETENTION IS PLANTED ONE DAY BEYOND THE CAP, not an arbitrary year ago, so
     * the test reads the site's own answer to "how many days" rather than asserting a number
     * that a filter could legitimately move.
     *
     * @group sprint-14d
     */
    public function testTheSweepTakesWhatIsPastRetentionAndKeepsWhatIsNot(): void
    {
        [$days, $rows] = TraceLog::retention();

        self::assertGreaterThanOrEqual(1, $days, 'The site reports a retention of ' . $days . ' days.');
        self::assertGreaterThanOrEqual(100, $rows, 'The site reports a row cap of ' . $rows . '.');

        $old   = 'aaaaaa01';
        $fresh = 'aaaaaa02';

        self::assertSame('1', TraceLog::plant($old, $days + 1), 'Could not plant the old trace.');
        self::assertSame('1', TraceLog::plant($fresh, 0), 'Could not plant the fresh trace.');

        self::assertNotSame('', TraceLog::entry($old), 'The planted old trace is not there.');
        self::assertNotSame('', TraceLog::entry($fresh), 'The planted fresh trace is not there.');

        // FIRED THROUGH THE HOOK, NOT BY CALLING THE SWEEP, and that is the difference between
        // proving the policy and proving it HAPPENS. `wpmcp_flush_expired` is what WP-Cron runs
        // hourly; a sweep function nothing is wired to is a sweep no site ever performs.
        self::assertSame(
            '1',
            WpCli::evaluate('echo (int) (bool) has_action("wpmcp_flush_expired", "wpmcp_flush_expired_cb");'),
            'wpmcp_flush_expired_cb is not on the hook, so neither the token flush nor the trace'
            . ' sweep ever runs.'
        );
        self::assertSame(
            '1',
            WpCli::evaluate('echo (int) (bool) wp_next_scheduled("wpmcp_flush_expired");'),
            'The hourly event is not scheduled on this site, so nothing fires the sweep. A run'
            . ' killed mid-class can leave it off - see bin/debris-check.php.'
        );

        WpCli::evaluate('do_action("wpmcp_flush_expired"); echo 1;');

        self::assertSame(
            '',
            TraceLog::entry($old),
            'A trace ' . ($days + 1) . ' days old survived the hourly sweep, so retention is not'
            . ' enforced and stack traces accumulate in every backup of this database for ever -'
            . ' which is what the file did, and the cost a table was chosen against.'
        );

        self::assertNotSame(
            '',
            TraceLog::entry($fresh),
            'The sweep took a trace inside its retention window. That is an id a caller may be'
            . ' holding right now - the cut takes the OLDEST, exactly as the file cap did, and'
            . ' for the same reason.'
        );
    }

    /**
     * The upgrade DELETES an existing site's log file, its directory, its guard files, its
     * three options and its transient.
     *
     * DELETING RATHER THAN MIGRATING, AND MAX APPROVED EXACTLY THAT. The file is the exposure:
     * on nginx it is served to anybody who knows the URL. A table that is private while the
     * public copy is still sitting in `wp-content` is a cosmetic fix. The old ENTRIES are not
     * carried into the table either - they would ride in every backup from then on, which is a
     * cost this release chose to bear for new entries only, and CHANGELOG.md tells an operator
     * with a live support case to take a copy first.
     *
     * IT HAS TO BE A REAL HTTP REQUEST, and there must be no `wp` call between moving the
     * revision back and making it: `wp eval` loads the plugin and fires `plugins_loaded` in its
     * own process, so any wp-cli call in between performs the upgrade itself and the request
     * under test finds nothing to do. The sprint-8 review lost an afternoon to exactly that.
     *
     * @group sprint-14d
     */
    public function testTheUpgradeDeletesTheLogFileItsDirectoryAndItsOptions(): void
    {
        $name = TraceLog::plantLegacyFile();

        self::assertMatchesRegularExpression(
            '/^trace-[0-9a-f]{32}\.log$/',
            $name,
            'The fixture did not plant a 1.1.1-shaped log file, so this test says nothing.'
        );

        $planted = TraceLog::legacyState();

        self::assertTrue($planted['directory'], 'The planted directory is not there.');
        self::assertTrue($planted['logs'], 'The planted log file is not there.');
        self::assertTrue($planted['index'], 'The planted index.php is not there.');
        self::assertTrue($planted['htaccess'], 'The planted .htaccess is not there.');
        self::assertTrue($planted['name_option'], 'The planted name option is not there.');

        // The last wp-cli call before the request. Nothing may touch the site after it.
        //
        // THE READ-BACK IS THE ASSERTION, NOT update_option()'s RETURN. It answers false when
        // the value is UNCHANGED, which is exactly the case on a site already at revision 6 -
        // and that read "could not move the revision" while the revision was already where it
        // needed to be. The red run of this test hit it.
        self::assertSame(
            '6',
            WpCli::evaluate('update_option("wpmcp_db_ver", 6); echo (int) get_option("wpmcp_db_ver", 0);'),
            'Could not move the recorded schema revision back to 6.'
        );

        $response = $this->client()->get('/');

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The front page did not answer 200, so the upgrade may not have run at all.'
        );

        self::assertSame(
            WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            WpCli::evaluate('echo (int) get_option("wpmcp_db_ver", 0);'),
            'The schema revision was not stamped by that request, so the upgrade path this'
            . ' test claims to exercise did not run.'
        );

        $after = TraceLog::legacyState();

        self::assertFalse(
            $after['logs'],
            'The trace log file survived the upgrade. It is served over HTTP on nginx - MEASURED'
            . ' - so leaving it makes the move to a table cosmetic.'
        );
        self::assertFalse($after['legacy_log'], 'The legacy trace.log survived the upgrade.');
        self::assertFalse($after['index'], 'The index.php guard file survived the upgrade.');
        self::assertFalse($after['htaccess'], 'The .htaccess survived the upgrade.');
        self::assertFalse(
            $after['directory'],
            'wp-content/wpmcp/ survived the upgrade. An empty directory is the URL the log used'
            . ' to live at, and nothing writes into it any more.'
        );
        self::assertFalse($after['name_option'], 'wpmcp_trace_log_name survived the upgrade.');
        self::assertFalse($after['readable_option'], 'wpmcp_trace_log_readable survived the upgrade.');
        self::assertFalse($after['unwritable_option'], 'wpmcp_trace_log_unwritable survived the upgrade.');
        self::assertFalse($after['transient'], 'wpmcp_trace_checked survived the upgrade.');

        // AND THE OLD ENTRIES ARE NOT IN THE TABLE. Importing them was the obvious thing to do
        // and is deliberately not done - see this test's docblock.
        self::assertFalse(
            TraceLog::contains('wpmcp-test planted by the upgrade test'),
            'The upgrade migrated the old file\'s entries into the table. They were left out on'
            . ' purpose: every one of them would then ride in every database backup.'
        );
    }

    /**
     * Two rows under one id are BOTH shown, and the screen says so.
     *
     * `trace_id` is a KEY and not a UNIQUE KEY on purpose - a duplicate must not make the INSERT
     * fail and lose the entry whose id has just gone out on the wire - so two rows can carry one
     * id. It is about one per two million traces, which at the measured rate is decades; the cost
     * of NOT saying so is that an operator reads the newest match as "the" trace and diagnoses the
     * wrong failure, and that cost does not scale with the odds.
     *
     * @group sprint-14d
     */
    public function testAnIdSharedByTwoTracesIsLabelledRatherThanSilentlyDisambiguated(): void
    {
        $shared = 'aaaaaa03';

        self::assertSame('1', TraceLog::plantDuplicate($shared), 'Could not plant the first row.');
        self::assertSame('1', TraceLog::plantDuplicate($shared), 'Could not plant the second row.');

        [$code, $html] = self::renderLookup($shared, 1);

        self::assertSame(0, $code, 'The settings screen did not render: ' . $html);
        self::assertStringContainsString(
            '2 traces share this id',
            $html,
            'Two rows carry that id and the screen showed them unlabelled, so an operator reads'
            . ' the newest as the one they were told about. HTML: ' . $html
        );
        self::assertSame(
            2,
            substr_count($html, 'trace=' . $shared),
            'The screen did not render BOTH rows, so a collision hides the one being asked about.'
        );
    }

    /**
     * A SYMLINKED wp-content/wpmcp is reported and NOT acted on.
     *
     * THE SHAPE WHERE ACTING IS WORSE THAN NOT ACTING. `glob()` and `unlink()` follow a link, so
     * the migration would delete files somewhere it has never written - a volume mount, a shared
     * directory, somebody's backup target - and `rmdir()` would take the LINK while leaving every
     * exposed byte where it is. The same rule the code tools' jail applies to a caller's path.
     *
     * AND IT MUST BE REPORTED, not merely skipped: a link is a host where the log is still
     * readable, so the operator has to be told, through the same option and notice a failed
     * unlink uses.
     *
     * WINDOWS MAY REFUSE TO CREATE THE LINK - `symlink()` needs SeCreateSymbolicLinkPrivilege or
     * Developer Mode - and that is STATED rather than skipped, the same way ErrorBoundaryTest
     * handles `0600` on a filesystem that cannot express it. Where the link can be made, which is
     * every Linux host and CI, the assertions have teeth.
     *
     * @group sprint-14d
     */
    public function testASymlinkedTraceDirectoryIsReportedAndNotFollowed(): void
    {
        TraceLog::forgetFileLeft();

        if (!TraceLog::plantSymlinkedDir(self::SYMLINK_TARGET)) {
            // NOT A SKIP, for the reason a test that can skip must never sit in a gate group: a
            // skip is green and the fact vanishes. This host cannot create the link, so the
            // weaker check runs instead and SAYS it is weaker - the guard is asserted in the
            // shipped source, and CI, which is Linux, runs the real one.
            $source = RepoFile::read('wp-mcp.php');

            self::assertFalse(
                TraceLog::dirIsSymlink(),
                'symlink() reported failure and the path IS a link, so the fixture is confused.'
            );
            self::assertStringContainsString(
                'is_link($dir)',
                $source,
                'This host refuses symlink() - on Windows that needs'
                . ' SeCreateSymbolicLinkPrivilege or Developer Mode - so the behaviour could not'
                . ' be exercised, and the migration does not even GUARD on is_link($dir). That'
                . ' guard is the only thing between the upgrade and unlinking files somewhere it'
                . ' has never written.'
            );
            self::assertStringContainsString(
                'is_link($file)',
                $source,
                'A linked FILE inside a real directory is the same decision one level down, and'
                . ' the migration does not guard on it.'
            );

            return;
        }

        self::assertTrue(TraceLog::dirIsSymlink(), 'The fixture did not leave a symlink behind.');

        $tally = WpCli::evaluate(
            '$t = wpmcp_migrate_remove_trace_file();'
            . ' echo implode("|", $t["failed"]), "\t", implode("|", $t["removed"]);'
        );

        self::assertStringContainsString(
            'symlink',
            $tally,
            'The migration did not report the symlink, so an operator is never told why the log is'
            . ' still there. Tally: ' . $tally
        );
        self::assertTrue(
            TraceLog::dirIsSymlink(),
            'The migration REMOVED the symlink. That takes the link and leaves every exposed byte'
            . ' where it is, while telling the operator the log was removed.'
        );
        self::assertStringContainsString(
            'symlink',
            TraceLog::fileLeft(),
            'The symlink is not recorded in the option the admin notice reads, so the report went'
            . ' to the PHP error log and nowhere an operator looks.'
        );
    }

    /**
     * wpmcp_render_admin() with a trace-lookup POST already built, as one user.
     *
     * The nonce is created as that same user, because check_admin_referer() verifies it against
     * the session it was minted in - so this exercises the real nonce check rather than
     * stepping around it. wp-admin/includes/template.php is required for the same reason
     * AdminTokenTableTest requires it: `submit_button()` lives there and a CLI bootstrap does
     * not load it, which is exactly what wp-admin itself does before rendering a settings page.
     *
     * @return array{0:int,1:string,2:string} [exit code, HTML, stderr]
     */
    private static function renderLookup(string $traceId, int $asUser): array
    {
        [$code, $out, $err] = WpCli::evaluateWithStatus(
            'require_once ABSPATH . "wp-admin/includes/template.php";'
            . ' $_POST = array("wpmcp_action" => "trace_lookup",'
            . ' "trace_id" => "' . $traceId . '",'
            . ' "_wpnonce" => wp_create_nonce("wpmcp_trace_lookup"));'
            . ' $_REQUEST = $_POST;'
            . ' ob_start(); wpmcp_render_admin(); echo base64_encode(ob_get_clean());',
            $asUser
        );

        $html = trim($out) === '' ? '' : (string) base64_decode(trim($out), true);

        // A wp_die() prints its own page before wp-cli exits, so the stdout of a refused render
        // is not base64 at all. Report whatever came back rather than an empty string, so a
        // failure names what the screen actually did.
        if ($html === '' && trim($out) !== '') { $html = $out; }

        return [$code, $html, $err];
    }

    /**
     * A tool whose `run` throws a TypeError, registered through the public `wpmcp_tools`
     * filter - the same door a third-party plugin uses.
     *
     * IT IS HANDED AN ARGUMENT WITH A KEY AND A VALUE, because the stack has to carry the key
     * and not the value (1.1.1), and a change of storage is exactly the moment that could be
     * lost without anybody noticing.
     */
    private static function throwingToolSource(): string
    {
        $name    = self::toolName();
        $message = self::THROWN_MESSAGE;

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$tools['{$name}'] = array(
        'write'       => false,
        'annotations' => array(
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ),
        'description' => 'wp-mcp test fixture: throws a TypeError, for the trace table.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array('wpmcp_test_key' => array('type' => 'string')),
        ),
        'run'         => static function (\$args) {
            throw new TypeError('{$message}');
        },
    );

    return \$tools;
});
PHP;
    }
}
