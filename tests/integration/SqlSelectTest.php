<?php
/**
 * sql-select: the tool whose safety is the database's opinion, not this plugin's.
 *
 * THE CLAIM UNDER TEST IS NARROW AND UNUSUAL. Every other write gate in this plugin is a
 * PHP decision - a capability, a scope, a jailed path - and a test of one is a test of
 * code in this repository. sql-select's gate is MySQL: the statement is wrapped as
 * `SELECT * FROM (<sql>) AS wpmcp_q LIMIT 201` and run inside `START TRANSACTION READ
 * ONLY`, and the claim is that between the two of them there is no statement a caller can
 * send that changes a row. So the tests that matter here send the four shapes a reviewer
 * would reach for - a locking read, an INTO OUTFILE, a stacked statement, a bare UPDATE -
 * and assert both halves: the call fails, AND the row it aimed at is still what it was.
 *
 * THE SWITCH IS ARMED PER REQUEST, not per class, and that is a decision about this
 * SITE rather than about the test. A mu-plugin answering `pre_option_wpmcp_sql_enabled`
 * with 1 for as long as the class runs would put every table the WordPress database user
 * can read - `wp_users` and its password hashes included - in front of every admin-scope
 * token on the site, on a stress site that belongs to a real client. The fixture here
 * answers 1 only for a request carrying this run's id AND the arming header, so the
 * switch is on for exactly the calls that ask for it and the "switch off" half of the
 * gate can be tested by the same class simply not sending it.
 *
 * NOTHING WRITES THE OPTION. The stored value is read before the class runs and asserted
 * unchanged after it, because an option is a shared value with no room for a run prefix:
 * a suite that left this one on would have changed the site permanently and silently.
 *
 * @group sprint-9
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\TraceLog;
use WpMcp\Tests\Support\WpCli;

final class SqlSelectTest extends FixtureIntegrationTestCase
{
    /** The mu-plugin slug: the per-request switch and the transaction probe. */
    private const SWITCH = 'sql-on';

    /** Sent to turn the switch on for one request. See the class docblock. */
    private const ARM_HEADER = 'X-Wpmcp-Test-Sql';

    /** Sent to make the probe in the fixture try a write at the end of the request. */
    private const PROBE_HEADER = 'X-Wpmcp-Test-Txprobe';

    /** The event the probe fires with whatever the database said about its write. */
    private const PROBE_EVENT = TestRecorder::AUTH . 'tx_probe';

    private static function adminLabel(): string { return Fixtures::name('sql-admin'); }
    private static function readLabel(): string { return Fixtures::name('sql-read'); }
    private static function editorLabel(): string { return Fixtures::name('sql-editor'); }

    private static function adminLogin(): string { return Fixtures::name('sqladmin'); }
    private static function editorLogin(): string { return Fixtures::name('sqleditor'); }

    /** Title prefix of the three dated posts the ORDER BY test reads. */
    private static function orderedTitle(): string { return Fixtures::name('ordered'); }

    private static int $adminId  = 0;
    private static int $editorId = 0;
    private static string $adminToken  = '';
    private static string $readToken   = '';
    private static string $editorToken = '';

    /** IDs of the three dated posts, oldest first. */
    private static array $orderedIds = [];

    /** The stored option before this class touched anything. Asserted unchanged after. */
    private static string $optionBefore = '';

    /** `on` or `off`: whether THIS site's optimizer_switch has derived_merge on. */
    private static string $mergeDefault = 'on';

    /** What a session variable this server does not have reads back as. */
    private const ABSENT = '(absent)';

    /** The statement-timeout variable THIS server's flavour uses, per the plugin. */
    private static string $timeoutVariable = '';

    /** Its value before anything in this class ran, or ABSENT. */
    private static string $timeoutBefore = '';

    /** What the server calls itself, for the failure message when the name is wrong. */
    private static string $serverInfo = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$optionBefore = self::storedSwitch();

        // WHAT THIS SITE'S CONNECTION STARTS A REQUEST WITH, so the restore assertions
        // compare against the operator's own values rather than against a constant.
        //
        // The timeout variable's NAME comes from the plugin, because the two flavours
        // spell it differently and a test that hard-coded MySQL's spelling would read
        // null on MariaDB - and `assertNotSame('5000', null)` passes for a connection
        // that was never restored at all. Asking wpmcp_sql_timeout_variable() means the
        // probe reads the variable the tool actually sets, on whichever server this is.
        self::$serverInfo = trim(WpCli::evaluate('echo wpmcp_sql_server_info();'));
        self::$timeoutVariable = trim(WpCli::evaluate(
            'echo wpmcp_sql_timeout_variable(wpmcp_sql_server_info());'
        ));
        self::$timeoutBefore = trim(WpCli::evaluate(
            'global $wpdb; $s = $wpdb->suppress_errors(true);'
            . ' $v = $wpdb->get_var("SELECT @@SESSION." . wpmcp_sql_timeout_variable(wpmcp_sql_server_info()));'
            . ' $wpdb->suppress_errors($s);'
            . " echo \$v === null ? '" . self::ABSENT . "' : (string) \$v;"
        ));

        self::$mergeDefault = str_contains(
            WpCli::evaluate('global $wpdb; echo (string) $wpdb->get_var("SELECT @@SESSION.optimizer_switch");'),
            'derived_merge=off'
        ) ? 'off' : 'on';

        self::$adminId = Fixtures::createUser(self::adminLogin(), 'administrator');
        // An Editor holds edit_posts and not manage_options, so an admin-SCOPE token
        // bound to one is the shape that proves scope is not the only gate.
        self::$editorId = Fixtures::createUser(self::editorLogin(), 'editor');

        TestRecorder::install();
        MuPlugin::drop(self::SWITCH, self::switchSource());

        self::$adminToken  = Fixtures::mintToken('admin', self::adminLabel(), self::$adminId);
        self::$readToken   = Fixtures::mintToken('read', self::readLabel(), self::$adminId);
        self::$editorToken = Fixtures::mintToken('admin', self::editorLabel(), self::$editorId);

        // Three posts with distinct dates, for the ORDER BY assertion. Created oldest
        // first and then back-dated, so the date order is not the ID order - an ORDER BY
        // that was silently dropped would fall back to the ID order and look right.
        self::$orderedIds = [
            Fixtures::createPost(self::orderedTitle() . '-b', 'publish', self::$adminId, 'wpmcp-test-sql'),
            Fixtures::createPost(self::orderedTitle() . '-c', 'publish', self::$adminId, 'wpmcp-test-sql'),
            Fixtures::createPost(self::orderedTitle() . '-a', 'publish', self::$adminId, 'wpmcp-test-sql'),
        ];

        // -a oldest, -b middle, -c newest. The ID order is b, c, a.
        $dates = [
            self::$orderedIds[2] => '2001-01-01 00:00:00',
            self::$orderedIds[0] => '2002-01-01 00:00:00',
            self::$orderedIds[1] => '2003-01-01 00:00:00',
        ];

        foreach ($dates as $id => $date) {
            WpCli::run(['post', 'update', (string) $id, '--post_date=' . $date, '--post_date_gmt=' . $date]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        TestRecorder::uninstall();
        MuPlugin::remove(self::SWITCH);

        Fixtures::deleteTokensLabelled(self::adminLabel());
        Fixtures::deleteTokensLabelled(self::readLabel());
        Fixtures::deleteTokensLabelled(self::editorLabel());

        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * (c) the switch
     * ---------------------------------------------------------------- */

    /**
     * With the switch off the tool is not in tools/list, and calling it is refused with
     * the SAME code and the SAME sentence as calling a name nobody ever registered.
     *
     * NO ORACLE. "This tool exists but the operator has it switched off" is a fact about
     * somebody's configuration, and a caller that may not use the tool has no business
     * learning it. The assertion is therefore not "it was refused" but "the refusal is
     * byte-for-byte the one a nonexistent name gets, with only the name differing".
     *
     * @group sprint-9
     */
    public function testWithTheSwitchOffTheToolDoesNotExist(): void
    {
        self::assertNotContains(
            'sql-select',
            $this->listedTools(self::$adminToken, false),
            'sql-select is advertised to an admin token while the switch is off. A tool'
            . ' that cannot run must not be listed.'
        );

        $absent  = Fixtures::name('no-such-tool');
        $refusal = $this->rawCall(self::$adminToken, 'sql-select', ['sql' => 'SELECT 1'], false);
        $control = $this->rawCall(self::$adminToken, $absent, ['sql' => 'SELECT 1'], false);

        self::assertSame(
            ['code' => -32602, 'message' => 'Unknown tool: sql-select'],
            $refusal,
            'Calling sql-select with the switch off must be refused exactly the way a tool'
            . ' that does not exist is refused. Anything else tells the caller the tool is'
            . ' there.'
        );
        self::assertSame(
            ['code' => -32602, 'message' => 'Unknown tool: ' . $absent],
            $control,
            'The control refusal changed shape, so the assertion above is comparing'
            . ' against nothing.'
        );
    }

    /**
     * With the switch on, the tool is listed for an admin token and it runs.
     *
     * @group sprint-9
     */
    public function testWithTheSwitchOnTheToolIsListedAndRuns(): void
    {
        self::assertContains(
            'sql-select',
            $this->listedTools(self::$adminToken, true),
            'sql-select is missing from tools/list with the switch on.'
        );

        $result = $this->sql('SELECT 1 AS one');

        self::assertFalse($result->isError, $result->text);
        self::assertSame(
            ['columns' => ['one'], 'rows' => [['1']], 'row_count' => 1, 'truncated' => false],
            $result->data()
        );
    }

    /* ------------------------------------------------------------------
     * (b) the scope and capability gates
     * ---------------------------------------------------------------- */

    /**
     * A read-scope token is refused, with the sentence every other admin tool gives, and
     * never sees the tool in its listing.
     *
     * @group sprint-9
     */
    public function testAReadScopeTokenIsRefused(): void
    {
        self::assertNotContains(
            'sql-select',
            $this->listedTools(self::$readToken, true),
            'sql-select appeared in a READ token\'s tools/list. The `write` flag is what'
            . ' filters that listing, and it is what gates the call.'
        );

        $result = $this->sql('SELECT 1', self::$readToken);

        self::assertTrue($result->isError, 'A read-scope token ran sql-select.');
        self::assertSame('This tool requires an admin-scope token.', $result->text);
    }

    /**
     * An admin-SCOPE token whose USER is an Editor is refused too.
     *
     * SCOPE IS NOT AN ADMINISTRATOR. wpmcp_mint() takes an owner, so an administrator can
     * mint an admin-scope token that runs as an Editor - which is exactly the hole sprint
     * 8 found in front of the code tools, where scope plus the option were the whole gate.
     * The refusal is the plugin's ordinary capability sentence, not the scope one, because
     * the scope gate passed.
     *
     * @group sprint-9
     */
    public function testAnAdminScopeTokenBoundToAnEditorIsRefused(): void
    {
        $result = $this->sql('SELECT 1', self::$editorToken);

        self::assertTrue(
            $result->isError,
            'An admin-scope token running as an Editor read the database. Admin scope is a'
            . ' property of the token; manage_options is a property of the user.'
        );
        self::assertStringContainsString('not allowed to read this site\'s database', $result->text);
    }

    /* ------------------------------------------------------------------
     * (a) the four writes disguised as reads
     * ---------------------------------------------------------------- */

    /**
     * A locking read, an INTO OUTFILE, a stacked statement and a bare UPDATE are all
     * refused BY THE SERVER, come back as tool errors carrying a trace id and an error
     * number, and leave the private log holding the server's own sentence.
     *
     * THE NUMBERS ARE ASSERTED, not just the failure. 1064 is "this is not a query
     * expression" - the wrapper's answer - and 1792 is "cannot execute statement in a READ
     * ONLY transaction" - the transaction's answer. `SELECT ... FOR UPDATE` produces 1792
     * and NOT 1064, which is the measurement this whole design rests on: MySQL 8.4 parses
     * a locking read inside a derived table perfectly happily, so the wrapper does not
     * stop it and the second wall is not decoration.
     *
     * @group sprint-9
     */
    public function testTheServerRefusesEveryWriteDisguisedAsASelect(): void
    {
        $target = self::$orderedIds[0];
        $before = TraceLog::contents();

        $cases = [
            'a locking read'      => ["SELECT ID FROM \$posts WHERE ID = {$target} FOR UPDATE", 1792],
            'an INTO OUTFILE'     => ["SELECT ID FROM \$posts WHERE ID = {$target} INTO OUTFILE '/tmp/" . Fixtures::name('outfile') . ".txt'", 1064],
            'a stacked statement' => ['SELECT 1; DROP TABLE $posts', 1064],
            'a bare UPDATE'       => ["UPDATE \$posts SET post_title = 'wpmcp-test-sql-owned' WHERE ID = {$target}", 1064],
        ];

        foreach ($cases as $what => [$template, $expected]) {
            $result = $this->sql(str_replace('$posts', $this->postsTable(), $template));

            self::assertTrue(
                $result->isError,
                "{$what} was NOT refused. It returned: " . $result->text
            );
            self::assertStringContainsString(
                'Database error number ' . $expected . '.',
                $result->text,
                "{$what} should have been refused with error {$expected}. Got: " . $result->text
            );

            $traceId = $this->traceIdIn($result->text, $what);
            $entry   = TraceLog::entry($traceId);

            self::assertStringContainsString(
                'tool=sql-select',
                $entry,
                "The trace log has no sql-select entry for {$what} (trace {$traceId})."
            );
            self::assertStringContainsString(
                'class=WP_Error:wpmcp_sql_server',
                $entry,
                "The log entry for {$what} is not the one this tool writes: {$entry}"
            );
            self::assertMatchesRegularExpression(
                '/message=\S/',
                $entry,
                "The log entry for {$what} carries no server message, which is the whole"
                . " reason it exists: {$entry}"
            );

            // AND NONE OF IT CROSSES. The wire carries a number and a trace id; the
            // server's sentence quotes the statement back, names columns, and on a 1064
            // includes the table the caller aimed at.
            foreach (['syntax', 'READ ONLY', 'MySQL server version', $this->postsTable()] as $leak) {
                self::assertStringNotContainsString(
                    $leak,
                    $result->text,
                    "The wire text for {$what} carries '{$leak}'. Only the error NUMBER and"
                    . ' the trace id may cross: ' . $result->text
                );
            }
        }

        self::assertNotSame(
            $before,
            TraceLog::contents(),
            'Four refused statements wrote nothing to the private log, so the trace ids'
            . ' the client was given point at nothing.'
        );

        // AND THE ROW IS STILL THERE. A refusal that had already written would be a
        // refusal about the response, not about the database.
        $title = trim(WpCli::evaluate(sprintf('echo get_post(%d)->post_title;', $target)));

        self::assertSame(
            self::orderedTitle() . '-b',
            $title,
            'The UPDATE disguised as a SELECT changed the row after all.'
        );
    }

    /* ------------------------------------------------------------------
     * (e) the plugin's own tables
     * ---------------------------------------------------------------- */

    /**
     * A statement naming either of the plugin's own tables is refused WITHOUT RUNNING -
     * no event, no trace, no trace id - and the refusal is a tool error, not a 401.
     *
     * AND THE MENTION NEED NOT BE A TABLE REFERENCE. The second case names the versions
     * table inside a trailing comment, where it could not possibly be read. That is
     * over-refusal and it is the documented, deliberate direction: the alternative is a
     * comment-and-string stripper that has to be exactly as correct as MySQL's lexer.
     *
     * @group sprint-9
     */
    public function testTheStatementCannotEvenMentionThePluginsOwnTables(): void
    {
        $tokens   = $this->tokensTable();
        $versions = $this->versionsTable();

        $cases = [
            'the token table'                => "SELECT * FROM {$tokens}",
            'the versions table in a comment' => "SELECT 1 AS n -- {$versions}",
            'the token table in a string'    => "SELECT '{$tokens}' AS label",
        ];

        foreach ($cases as $what => $statement) {
            TestRecorder::reset();
            $before = TraceLog::contents();

            $result = $this->sql($statement);

            self::assertTrue($result->isError, "{$what} was not refused: " . $result->text);
            self::assertStringContainsString(
                "The plugin's own tables cannot be read",
                $result->text,
                "{$what} was refused for the wrong reason: " . $result->text
            );
            self::assertStringNotContainsString(
                'Trace id:',
                $result->text,
                "{$what} produced a trace id. A rule this server applies on purpose is not"
                . ' a failure and has nothing to put in a log of failures.'
            );
            self::assertSame(
                0,
                TestRecorder::countOf(TestRecorder::AUTH . 'sql_select'),
                "{$what} fired a sql_select event, so the statement reached the database."
            );
            self::assertSame(
                $before,
                TraceLog::contents(),
                "{$what} wrote to the private log, so something went to the server."
            );
        }
    }

    /**
     * `LOAD_FILE()` is refused by name, without running - the one file-reading function
     * that gets through both walls.
     *
     * FOUND BY REVIEW, AND IT IS THE ONE HOLE THE TWO WALLS DO NOT CLOSE. `LOAD_FILE` is a
     * valid query expression, so the wrapper takes it, and it is a read, so the READ ONLY
     * transaction takes it too - the reviewer probed errno 0 from both, through this tool's
     * exact wrapper, on this site. What stops the bytes coming back is `secure_file_priv`
     * and the database user's `FILE` privilege, which belong to the operator and not to
     * this plugin: on jaygroup `secure_file_priv` is NULL so the read is disabled, and the
     * database user is `root` WITH `FILE`. On a host configured the other way,
     * `SELECT LOAD_FILE('.../wp-config.php')` is the database password and every salt.
     *
     * SO THE TEST CANNOT BE "the read failed" - on this box it would pass against code
     * that had no rule at all, which is exactly how the hole survived the first round. It
     * is "the statement never reached the database": a validation-style refusal, no
     * `sql_select` event, nothing in the trace log.
     *
     * @group sprint-9
     */
    public function testLoadFileIsRefusedWithoutReachingTheDatabase(): void
    {
        $cases = [
            'upper case'       => "SELECT LOAD_FILE('/etc/hostname') AS f",
            'lower case'       => "SELECT load_file('/etc/hostname') AS f",
            'inside a comment' => 'SELECT 1 AS n /* load_file */',
        ];

        foreach ($cases as $what => $statement) {
            TestRecorder::reset();
            $before = TraceLog::contents();

            $result = $this->sql($statement);

            self::assertTrue($result->isError, "{$what} was not refused: " . $result->text);
            self::assertStringContainsString(
                'read the server filesystem',
                $result->text,
                "{$what} was refused for the wrong reason: " . $result->text
            );
            self::assertStringNotContainsString(
                'Trace id:',
                $result->text,
                "{$what} produced a trace id. A rule this server applies on purpose is not"
                . ' a failure.'
            );
            self::assertSame(
                0,
                TestRecorder::countOf(TestRecorder::AUTH . 'sql_select'),
                "{$what} fired a sql_select event, so the statement reached the database."
            );
            self::assertSame(
                $before,
                TraceLog::contents(),
                "{$what} wrote to the private log, so something went to the server."
            );
        }
    }

    /* ------------------------------------------------------------------
     * (d) the caps
     * ---------------------------------------------------------------- */

    /**
     * Over 200 rows comes back as exactly 200, flagged `truncated_by: rows`.
     *
     * THE ROWS ARE GENERATED, not seeded. A recursive CTE counts to 500 inside the
     * wrapper, so the assertion is about the cap and not about how many posts happen to
     * be on somebody's site, and the test creates nothing it then has to take away.
     *
     * @group sprint-9
     */
    public function testAQueryOverTheRowCapReturnsExactlyTwoHundred(): void
    {
        $data = $this->sql(self::countTo(500) . ' SELECT i FROM n')->data();

        self::assertSame(200, $data['row_count']);
        self::assertCount(200, $data['rows']);
        self::assertTrue($data['truncated']);
        self::assertSame('rows', $data['truncated_by']);
        self::assertSame([['1'], ['2']], array_slice($data['rows'], 0, 2));
        self::assertSame(['200'], $data['rows'][199], 'The 200 rows are not the FIRST 200.');
    }

    /**
     * A wide result stops on the byte budget instead, flagged `truncated_by: bytes`, well
     * short of 200 rows.
     *
     * @group sprint-9
     */
    public function testAWideQueryTripsTheByteCapInstead(): void
    {
        $data = $this->sql(self::countTo(200) . " SELECT i, REPEAT('x', 9000) AS pad FROM n")->data();

        self::assertTrue($data['truncated']);
        self::assertSame('bytes', $data['truncated_by']);
        self::assertLessThan(
            200,
            $data['row_count'],
            'A result of 200 rows of 8 KB each is 1.6 MB. The byte cap did not fire.'
        );
        self::assertGreaterThan(0, $data['row_count'], 'The byte cap returned nothing at all.');
    }

    /**
     * A cell over 8 KiB is cut and marked, so one longtext column cannot become the
     * whole response.
     *
     * @group sprint-9
     */
    public function testAnOversizedCellIsCutAndMarked(): void
    {
        $data = $this->sql("SELECT REPEAT('x', 9000) AS big")->data();
        $cell = $data['rows'][0][0];

        self::assertStringEndsWith("\u{2026}", $cell, 'An oversized cell was not marked as cut.');
        self::assertSame(
            8192,
            strlen(substr($cell, 0, -3)),
            'The cut is a byte budget of 8192 plus the three bytes of the ellipsis. Got '
            . strlen($cell) . ' bytes.'
        );
    }

    /* ------------------------------------------------------------------
     * (f) what must still work
     * ---------------------------------------------------------------- */

    /**
     * An ORDER BY inside the statement survives the wrapper.
     *
     * THE DATE ORDER IS NOT THE ID ORDER, deliberately - see build(). A wrapper that
     * dropped the inner ORDER BY would return the rows in whatever order the server found
     * them, which for a small table is the ID order, and a test seeded in date order would
     * have passed anyway.
     *
     * @group sprint-9
     */
    public function testAnOrderByInsideTheStatementIsHonoured(): void
    {
        $prefix = self::orderedTitle();
        $data   = $this->sql(
            // post_type, because back-dating through wp-cli leaves a REVISION carrying the
            // same title, and three of those made the first version of this test read six
            // rows in the right order and still call it wrong.
            "SELECT post_title FROM {$this->postsTable()} WHERE post_title LIKE '{$prefix}-%'"
            . " AND post_type = 'post' ORDER BY post_date DESC"
        )->data();

        self::assertSame(
            [["{$prefix}-c"], ["{$prefix}-b"], ["{$prefix}-a"]],
            $data['rows'],
            'The inner ORDER BY did not survive the derived-table wrapper.'
        );
    }

    /**
     * A CTE, a trailing semicolon, and an empty statement.
     *
     * @group sprint-9
     */
    public function testACteATrailingSemicolonAndAnEmptyStatement(): void
    {
        $cte = $this->sql('WITH c AS (SELECT 7 AS n) SELECT * FROM c');

        self::assertFalse($cte->isError, 'A CTE was refused: ' . $cte->text);
        self::assertSame([['7']], $cte->data()['rows']);

        $semicolon = $this->sql('  SELECT 8 AS n ;   ');

        self::assertFalse($semicolon->isError, 'A trailing semicolon was refused: ' . $semicolon->text);
        self::assertSame([['8']], $semicolon->data()['rows']);

        $empty = $this->sql('    ');

        self::assertTrue($empty->isError, 'An empty statement was accepted.');
        self::assertSame('Error: The sql argument is empty.', $empty->text);
        self::assertStringNotContainsString(
            'Trace id:',
            $empty->text,
            'An empty argument is a validation refusal, not a failure with a trace.'
        );
    }

    /* ------------------------------------------------------------------
     * (h) NULL and bytes
     * ---------------------------------------------------------------- */

    /**
     * A NULL cell is JSON null; a cell that is not valid UTF-8 is hex, not a question mark.
     *
     * THE QUESTION MARK IS THE BUG BEING RULED OUT. wp_json_encode() does not fail on
     * invalid UTF-8 and does not null it either - WordPress's own sanity pass rewrites the
     * offending byte as `?`, so a caller reading a blob would be handed something that
     * looks like text and is not the data.
     *
     * @group sprint-9
     */
    public function testNullStaysNullAndBytesBecomeHex(): void
    {
        $data = $this->sql("SELECT NULL AS n, UNHEX('61806263') AS b, 'plain' AS p")->data();

        self::assertNull($data['rows'][0][0], 'A NULL cell did not come back as JSON null.');
        self::assertSame(
            '0x61806263',
            $data['rows'][0][1],
            'A non-UTF-8 cell was not hex-encoded. WordPress would have rewritten the bad'
            . ' byte as "?" and said nothing.'
        );
        self::assertSame('plain', $data['rows'][0][2], 'A plain ASCII cell was mangled.');
        self::assertSame(['n', 'b', 'p'], $data['columns']);
    }

    /* ------------------------------------------------------------------
     * (g) the connection is handed back clean
     * ---------------------------------------------------------------- */

    /**
     * After a FAILED statement, the same request can still write.
     *
     * THE PROBE RUNS INSIDE THE REQUEST THE TOOL RAN IN, which is the only place the
     * question exists: a second HTTP request gets a second connection and would answer
     * yes no matter what this code did. The fixture hooks `shutdown` and sends a real
     * write statement - a DELETE matching nothing, so it is a write that changes nothing -
     * and reports whatever the database said. Inside a transaction that was never rolled
     * back that is 1792; with the ROLLBACK in its `finally` it is silence.
     *
     * @group sprint-9
     */
    public function testAFailedStatementDoesNotLeaveTheConnectionInATransaction(): void
    {
        TestRecorder::reset();

        $failed = $this->sql(
            "SELECT ID FROM {$this->postsTable()} LIMIT 1 FOR UPDATE",
            self::$adminToken,
            true
        );

        self::assertTrue($failed->isError, 'The statement this test needs to FAIL succeeded.');

        $probes = TestRecorder::detailsOf(self::PROBE_EVENT);

        self::assertCount(
            1,
            $probes,
            'The end-of-request write probe did not run, so this test proved nothing.'
        );
        self::assertSame(
            '',
            (string) ($probes[0]['error'] ?? 'the probe reported no error field at all'),
            'A write later in the SAME request was refused, so the connection was still'
            . ' inside the READ ONLY transaction. 1792 is what that looks like.'
        );

        $this->assertSessionHandedBackClean($probes[0], 'a failed statement');

        // And the ordinary path: a write tool in a LATER request works too.
        $post = $this->mcp(self::$adminToken)->callTool('create-post', [
            'title'  => Fixtures::name('after-a-failed-select'),
            'status' => 'draft',
        ]);

        self::assertFalse($post->isError, 'create-post after a failed sql-select: ' . $post->text);
    }

    /**
     * And after a SUCCESSFUL statement, for the same reason - the `finally` runs on both
     * paths and only one of them is the interesting one to get wrong.
     *
     * @group sprint-9
     */
    public function testASuccessfulStatementDoesNotLeaveTheConnectionInATransaction(): void
    {
        TestRecorder::reset();

        $ok = $this->sql('SELECT 1 AS n', self::$adminToken, true);

        self::assertFalse($ok->isError, $ok->text);

        $probes = TestRecorder::detailsOf(self::PROBE_EVENT);

        self::assertCount(1, $probes, 'The end-of-request write probe did not run.');
        self::assertSame('', (string) ($probes[0]['error'] ?? 'no error field'));

        $this->assertSessionHandedBackClean($probes[0], 'a successful statement');
    }

    /* ------------------------------------------------------------------
     * the log line
     * ---------------------------------------------------------------- */

    /**
     * A successful call fires ONE sql_select event carrying who ran it, how much came
     * back, and the first 200 characters of the statement - and not a character more.
     *
     * @group sprint-9
     */
    public function testTheAuthEventSaysWhoRanWhatAndIsBoundedAtTwoHundredCharacters(): void
    {
        $padding   = str_repeat('a', 400);
        $statement = "SELECT 1 AS n /* {$padding} */";

        TestRecorder::reset();

        $result = $this->sql($statement);

        self::assertFalse($result->isError, $result->text);

        $events = TestRecorder::detailsOf(TestRecorder::AUTH . 'sql_select');

        self::assertCount(1, $events, 'A successful sql-select did not fire exactly one event.');

        $event = $events[0];

        self::assertSame(self::$adminId, (int) $event['user_id']);
        self::assertGreaterThan(0, (int) $event['token_id'], 'The event names no token.');
        self::assertSame(1, (int) $event['row_count']);
        self::assertFalse((bool) $event['truncated']);
        self::assertIsInt($event['elapsed_ms']);
        self::assertSame(
            200,
            mb_strlen((string) $event['sql']),
            'The logged statement is ' . mb_strlen((string) $event['sql']) . ' characters.'
            . ' It is capped at 200 so one query cannot carry a database row into a log'
            . ' that is not the trace log.'
        );
        self::assertStringStartsWith('SELECT 1 AS n', (string) $event['sql']);
    }

    /**
     * The option this class arms with a filter was not written by anything it did.
     *
     * An option is a shared value with no room for a run prefix, and this one exposes
     * every readable table to every admin-scope token on the site. It is DECLARED LAST,
     * which is the order PHPUnit runs methods in: the value is read again from the
     * database and compared with what was there before any test ran.
     *
     * @group sprint-9
     */
    public function testZTheStoredSwitchIsExactlyWhatItWas(): void
    {
        self::assertSame(
            self::$optionBefore,
            self::storedSwitch(),
            'The stored wpmcp_sql_enabled option changed while this class ran. No test'
            . ' here writes it - they filter it per request - so something turned it on'
            . ' for the whole site.'
        );
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /**
     * The two session variables sql-select changes are back as this site had them.
     *
     * READ IN THE SAME REQUEST, on the same connection, after the tool returned - see the
     * class docblock for why a second request would answer this for free. Left as the tool
     * sets them, every later WordPress SELECT in the request runs under a 5-second server
     * cap and every later plan is built with derived_merge off: this tool silently changing
     * how unrelated queries behave.
     *
     * The merge default is read from the site rather than assumed, because `derived_merge`
     * is an operator-settable flag and a test that hard-coded `on` would be asserting
     * something about somebody's my.cnf.
     */
    private function assertSessionHandedBackClean(array $probe, string $after): void
    {
        $variable = self::$timeoutVariable;

        // The probe read the variable the PLUGIN sets, not one this test chose. If those
        // two ever disagree, every assertion below is about the wrong variable.
        self::assertSame(
            $variable,
            (string) ($probe['timeout_var'] ?? 'the probe reported no variable name'),
            'The probe and the plugin disagree about which session variable carries the'
            . ' statement timeout on this server.'
        );

        // AND THE VARIABLE HAS TO EXIST HERE. If it does not, sql-select's statement
        // timeout is silently doing nothing on this flavour - and the equality below
        // would be comparing one absence with another and passing. This is the assertion
        // that fires on a MariaDB if wpmcp_sql_timeout_variable() names the wrong thing,
        // which is the first place anyone on this project will meet a MariaDB.
        self::assertNotSame(
            self::ABSENT,
            self::$timeoutBefore,
            "This server has no @@SESSION.{$variable}, so the statement timeout"
            . ' sql-select sets does nothing here. Server: ' . self::$serverInfo
        );

        self::assertSame(
            self::$timeoutBefore,
            (string) ($probe['timeout'] ?? 'the probe reported no timeout at all'),
            "@@SESSION.{$variable} did not go back to this site's own value ("
            . self::$timeoutBefore . ") after {$after} returned."
        );
        self::assertSame(
            self::$mergeDefault,
            (string) ($probe['merge'] ?? 'the probe reported no merge field at all'),
            "optimizer_switch did not go back to this site's own value after {$after}"
            . ' returned.'
        );
    }

    /** The option as the database holds it, with no filter in the way. */
    private static function storedSwitch(): string
    {
        return trim(WpCli::evaluate(
            'global $wpdb; $v = $wpdb->get_var($wpdb->prepare('
            . '"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",'
            . ' "wpmcp_sql_enabled"));'
            . ' echo $v === null ? "(absent)" : $v;'
        ));
    }

    /** A recursive CTE that counts to $n, as the head of a statement. */
    private static function countTo(int $n): string
    {
        return "WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < {$n})";
    }

    private function postsTable(): string { return $this->tableName('posts'); }
    private function tokensTable(): string { return $this->tableName('wpmcp_tokens'); }
    private function versionsTable(): string { return $this->tableName('wpmcp_file_versions'); }

    /** The site's own prefix in front of a table name, read from the site. */
    private function tableName(string $bare): string
    {
        static $prefix = null;

        if ($prefix === null) {
            $prefix = trim(WpCli::evaluate('global $wpdb; echo $wpdb->prefix;'));
        }

        return $prefix . $bare;
    }

    /** One sql-select call with the switch armed for that request. */
    private function sql(string $statement, string $token = '', bool $probe = false): ToolResult
    {
        $headers = [self::ARM_HEADER => 'on'];

        if ($probe) { $headers[self::PROBE_HEADER] = 'on'; }

        return $this->mcp($token !== '' ? $token : self::$adminToken)
            ->callTool('sql-select', ['sql' => $statement], $headers);
    }

    /** Every tool name in tools/list, with the switch armed or not. */
    private function listedTools(string $token, bool $armed): array
    {
        $response = $this->mcp($token)->post(
            'tools/list',
            [],
            $armed ? [self::ARM_HEADER => 'on'] : []
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body['result']['tools'] ?? null, 'tools/list returned no tools.');

        return array_column($body['result']['tools'], 'name');
    }

    /**
     * A tools/call that is expected to be refused at the JSON-RPC level, as
     * ['code' => ..., 'message' => ...].
     */
    private function rawCall(string $token, string $name, array $arguments, bool $armed): array
    {
        $response = $this->mcp($token)->post(
            'tools/call',
            ['name' => $name, 'arguments' => $arguments],
            $armed ? [self::ARM_HEADER => 'on'] : []
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray(
            $body['error'] ?? null,
            "tools/call {$name} was not refused at all: " . (string) $response->getBody()
        );

        return ['code' => $body['error']['code'], 'message' => $body['error']['message']];
    }

    /** The trace id out of a failure sentence. */
    private function traceIdIn(string $text, string $what): string
    {
        self::assertSame(
            1,
            preg_match('/Trace id: ([0-9a-f]{8})\./', $text, $match),
            "{$what} came back without a trace id: {$text}"
        );

        return $match[1];
    }

    /**
     * The fixture: the per-request switch, and the end-of-request write probe.
     *
     * THE SWITCH ANSWERS 1 ONLY FOR THIS RUN'S ARMED REQUESTS. `false` from a
     * `pre_option_` filter means "no short-circuit", so every other request on the site -
     * including this run's own unarmed ones - reads the stored option and sees it off.
     *
     * THE PROBE IS A WRITE THAT CHANGES NOTHING: a DELETE whose WHERE matches no row.
     * MySQL refuses it with 1792 inside a READ ONLY transaction whether or not it would
     * have matched anything (measured), so it answers "is this connection still inside a
     * transaction" without being able to damage the site it asks on.
     */
    private static function switchSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $arm    = 'HTTP_' . strtoupper(str_replace('-', '_', self::ARM_HEADER));
        $probe  = 'HTTP_' . strtoupper(str_replace('-', '_', self::PROBE_HEADER));
        $never  = Fixtures::name('option-that-never-exists');
        $absent = self::ABSENT;

        return <<<PHP
/**
 * wp-mcp sprint-9 sql-select fixture for run {$run}. Dropped and removed by
 * tests/integration/SqlSelectTest.php. EVERY EFFECT IS GATED ON THIS RUN'S REQUEST
 * HEADERS, so it changes nothing for anybody else. If you are reading this on a live
 * site, the run that wrote it crashed; deleting the file is safe.
 */
\$wpmcp_test_sql_armed = static function () {
    return isset(\$_SERVER['{$header}'])
        && \$_SERVER['{$header}'] === '{$run}'
        && isset(\$_SERVER['{$arm}'])
        && \$_SERVER['{$arm}'] === 'on';
};

add_filter('pre_option_wpmcp_sql_enabled', static function (\$pre) use (\$wpmcp_test_sql_armed) {
    return \$wpmcp_test_sql_armed() ? 1 : \$pre;
});

add_action('shutdown', static function () use (\$wpmcp_test_sql_armed) {
    if (!\$wpmcp_test_sql_armed()
        || !isset(\$_SERVER['{$probe}'])
        || \$_SERVER['{$probe}'] !== 'on'
        || !function_exists('wpmcp_auth_event')) {
        return;
    }

    global \$wpdb;

    \$suppressed = \$wpdb->suppress_errors(true);
    \$wpdb->last_error = '';
    \$wpdb->query(\$wpdb->prepare(
        "DELETE FROM {\$wpdb->options} WHERE option_name = %s",
        '{$never}'
    ));
    \$error = (string) \$wpdb->last_error;

    // The session variables sql-select changed, read back on the connection it handed
    // over. Anything but the values this site started the request with means the tool
    // put a 5-second cap and an altered plan on everybody else's queries.
    //
    // THE VARIABLE NAME COMES FROM THE PLUGIN. MySQL and MariaDB spell it differently,
    // and reading MySQL's name on a MariaDB gives null - which an assertion written as
    // \"not 5000\" would accept from a connection nobody restored.
    \$variable = function_exists('wpmcp_sql_timeout_variable')
        && function_exists('wpmcp_sql_server_info')
        ? wpmcp_sql_timeout_variable(wpmcp_sql_server_info())
        : 'MAX_EXECUTION_TIME';

    \$timeout = \$wpdb->get_var('SELECT @@SESSION.' . \$variable);
    \$switch  = \$wpdb->get_var('SELECT @@SESSION.optimizer_switch');
    \$wpdb->suppress_errors(\$suppressed);

    wpmcp_auth_event('tx_probe', array(
        'error'       => \$error,
        'timeout_var' => (string) \$variable,
        'timeout'     => \$timeout === null ? '{$absent}' : (string) \$timeout,
        'merge'       => strpos((string) \$switch, 'derived_merge=off') === false ? 'on' : 'off',
    ));
}, 1);
PHP;
    }
}
