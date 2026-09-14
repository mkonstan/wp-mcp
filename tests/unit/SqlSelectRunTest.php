<?php
/**
 * sql-select's preamble, its refusals and its cell encoder, with a recorded connection.
 *
 * WHAT THIS TIER CAN SEE THAT THE OTHER CANNOT: the ORDER of the statements sent to the
 * connection. Over HTTP, sql-select is a black box that either answers or does not; the
 * argument that it is safe is an argument about a sequence - read the session variables,
 * set the caps, `START TRANSACTION READ ONLY`, the wrapped statement, `ROLLBACK`, put the
 * session variables back - and a sequence is only assertable from inside. FakeWpdb records
 * every query in order and this file reads that list.
 *
 * THE ROLLBACK IS THE ONE THAT MATTERS. $wpdb is reused for the rest of the request, so a
 * connection left inside a READ ONLY transaction fails every write WordPress makes after
 * the tool returns - 1792, in the middle of somebody else's code. The integration tier
 * proves it does not happen on a real request; this proves the `finally` is what makes
 * that true, by making the driver throw and looking for the ROLLBACK anyway.
 *
 * NO DATABASE AND NO WORDPRESS. The rows come from the fake, so nothing here asserts what
 * MySQL does with a derived table - that is the integration tier's job and it is measured
 * against a real server.
 *
 * @group sprint-9
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class SqlSelectRunTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        $this->wpdb = WordPressRuntime::install();

        WordPressRuntime::logInAs(11, 'wpmcp-unit-admin');
        WordPressRuntime::allowCap('manage_options');

        // The two session variables the tool reads before it changes them. Real values
        // from jaygroup, so the restore has something recognisable to put back.
        $this->wpdb->vars = [
            'SELECT @@SESSION.MAX_EXECUTION_TIME' => '0',
            'SELECT @@SESSION.optimizer_switch'   => 'index_merge=on,derived_merge=on,hash_join=on',
        ];
    }

    /**
     * The whole sequence, in order: read, set, begin, run, roll back, put back.
     *
     * @group sprint-9
     */
    public function testTheStatementIsWrappedRunInAReadOnlyTransactionAndRolledBack(): void
    {
        $this->wpdb->results     = [['1']];
        $this->wpdb->columnNames = ['one'];

        \wpmcp_sql_select_run('SELECT 1 AS one');

        $queries = $this->wpdb->queries;

        self::assertSame('SELECT @@SESSION.MAX_EXECUTION_TIME', $queries[0]);
        self::assertSame('SELECT @@SESSION.optimizer_switch', $queries[1]);
        self::assertSame('SET SESSION MAX_EXECUTION_TIME = 5000', $queries[2]);
        self::assertSame("SET SESSION optimizer_switch = 'derived_merge=off'", $queries[3]);
        self::assertSame('START TRANSACTION READ ONLY', $queries[4]);
        self::assertSame(
            'SELECT * FROM (SELECT 1 AS one) AS wpmcp_q LIMIT 201',
            $queries[5],
            'The statement must be wrapped as a derived table with one more row than the'
            . ' cap. The wrapper is what makes a non-SELECT a server syntax error, and the'
            . ' 201st row is what sets `truncated`.'
        );
        self::assertSame('ROLLBACK', $queries[6]);
        self::assertSame('SET SESSION MAX_EXECUTION_TIME = 0', $queries[7]);
        self::assertSame(
            "SET SESSION optimizer_switch = 'index_merge=on,derived_merge=on,hash_join=on'",
            $queries[8],
            'The prior optimizer_switch must go back verbatim. $wpdb is the connection the'
            . ' rest of the request uses.'
        );
        self::assertCount(9, $queries, 'Something else was sent: ' . implode(' | ', $queries));
    }

    /**
     * The session variables are restored after a THROW as well, in the same `finally`.
     *
     * Same reasoning as the ROLLBACK below: the connection is handed back to WordPress
     * either way, so "we put it back unless something went wrong" is the case that matters.
     *
     * @group sprint-9
     */
    public function testTheSessionVariablesAreRestoredOnTheThrowPathToo(): void
    {
        $this->wpdb->throwOnGetResults = new RuntimeException('the driver blew up');

        try {
            \wpmcp_sql_select_run('SELECT 1');
        } catch (\Throwable $ignored) {
            // See testAThrowingDriverStillLeavesTheConnectionRolledBack.
        }

        self::assertContains(
            'SET SESSION MAX_EXECUTION_TIME = 0',
            $this->wpdb->queries,
            'The 5-second statement cap was left on the connection after a throw, so every'
            . ' later SELECT in the request runs under it.'
        );
        self::assertContains(
            "SET SESSION optimizer_switch = 'index_merge=on,derived_merge=on,hash_join=on'",
            $this->wpdb->queries,
            'derived_merge was left off after a throw, so every later query in the request'
            . ' is planned differently.'
        );
    }

    /**
     * A prior value that could not be read is not restored, and nothing is invented.
     *
     * A null is what an unexpected flavour or a proxy gives back, and `SET SESSION x = `
     * with nothing after it is a syntax error sent on every call. The restore is
     * best-effort by design and this is the "effort was not possible" half.
     *
     * @group sprint-9
     */
    public function testAnUnreadablePriorValueIsNotRestored(): void
    {
        $this->wpdb->vars = [];

        \wpmcp_sql_select_run('SELECT 1');

        foreach ($this->wpdb->queries as $query) {
            self::assertStringNotContainsString(
                'SET SESSION MAX_EXECUTION_TIME = 0',
                (string) $query
            );
            self::assertDoesNotMatchRegularExpression(
                '/SET SESSION \S+ =\s*$/',
                (string) $query,
                'A restore was attempted with no value: ' . $query
            );
        }

        self::assertSame('ROLLBACK', end($this->wpdb->queries));
    }

    /**
     * A MariaDB connection gets MariaDB's spelling of the timeout.
     *
     * MEASURED, AND IT IS WHY THIS BRANCH EXISTS: `SET SESSION max_statement_time = 5` is
     * 1193 "Unknown system variable" on MySQL 8.4, and MySQL's MAX_EXECUTION_TIME does not
     * exist on MariaDB. Neither server knows the other's name for it, so a single spelling
     * would leave one of the two flavours with no statement timeout at all.
     *
     * @group sprint-9
     */
    public function testMariadbGetsItsOwnSpellingOfTheStatementTimeout(): void
    {
        self::assertSame(
            'SET SESSION MAX_EXECUTION_TIME = 5000',
            \wpmcp_sql_timeout_statement('8.4.0'),
            'MySQL counts milliseconds and calls it MAX_EXECUTION_TIME.'
        );
        self::assertSame(
            'SET SESSION max_statement_time = 5',
            \wpmcp_sql_timeout_statement('5.5.5-10.6.12-MariaDB-1:10.6.12+maria~ubu2004'),
            'MariaDB counts SECONDS and calls it max_statement_time. MySQL answers 1193'
            . ' "Unknown system variable" to this spelling (measured on 8.4.0), and'
            . ' MariaDB answers the same to the other one - so one spelling for both'
            . ' leaves one flavour with no statement timeout, silently.'
        );

        // The name is matched case-insensitively and anywhere in the string, because the
        // version prefix mysqli reports varies by build.
        self::assertSame(
            \wpmcp_sql_timeout_statement('10.11.6-mariadb-log'),
            \wpmcp_sql_timeout_statement('5.5.5-10.6.12-MariaDB'),
            'The flavour test is case-sensitive or anchored, so some MariaDB builds take'
            . ' the MySQL branch.'
        );

        // An unreachable server description falls back to the MySQL spelling. It is the
        // wider install base, and a failed SET costs nothing but a cleared last_error.
        self::assertSame(
            'SET SESSION MAX_EXECUTION_TIME = 5000',
            \wpmcp_sql_timeout_statement('')
        );

        // The variable NAME follows the same branch, because the restore has to read and
        // write the one the cap was set on. Two spellings that disagreed would restore a
        // variable nobody touched and leave the cap in place for the rest of the request.
        self::assertSame('MAX_EXECUTION_TIME', \wpmcp_sql_timeout_variable('8.4.0'));
        self::assertSame('max_statement_time', \wpmcp_sql_timeout_variable('10.11.6-MariaDB'));
        self::assertStringContainsString(
            \wpmcp_sql_timeout_variable('10.11.6-MariaDB'),
            \wpmcp_sql_timeout_statement('10.11.6-MariaDB')
        );
    }

    /**
     * A driver that THROWS still leaves the connection rolled back.
     *
     * The call itself may or may not come back with a WP_Error here: reporting the failure
     * goes through trace.php, which wants a WordPress content directory this tier does not
     * have. Either outcome is fine and neither is what is being asserted - the ROLLBACK is.
     *
     * @group sprint-9
     */
    public function testAThrowingDriverStillLeavesTheConnectionRolledBack(): void
    {
        $this->wpdb->throwOnGetResults = new RuntimeException('the driver blew up');

        try {
            \wpmcp_sql_select_run('SELECT 1');
        } catch (\Throwable $ignored) {
            // See the docblock.
        }

        $queries = $this->wpdb->queries;

        self::assertContains(
            'ROLLBACK',
            $queries,
            'The driver threw and the transaction was never rolled back. $wpdb is reused'
            . ' for the rest of the request, so every write after this one would fail with'
            . ' 1792. That is what the `finally` is for. Sent: ' . implode(' | ', $queries)
        );

        // AND IT COMES FIRST IN THE `finally`. The session restores that follow it are
        // ordinary statements; issuing one while still inside the transaction would make
        // the clean-up depend on the thing it is cleaning up after.
        self::assertLessThan(
            array_search('SET SESSION MAX_EXECUTION_TIME = 0', $queries, true),
            array_search('ROLLBACK', $queries, true),
            'The session restore was sent before the ROLLBACK.'
        );
    }

    /**
     * A failed `SET` in the preamble is not reported as the caller's error.
     *
     * An exotic server that does not know one of the session variables leaves its
     * complaint in last_error, and the code reads last_error after the statement to decide
     * whether the statement failed. Without the clear, every call on such a server would
     * come back as a failure with a trace id, for a statement that ran perfectly.
     *
     * @group sprint-9
     */
    public function testAComplaintLeftBehindByThePreambleIsNotTheCallersError(): void
    {
        $this->wpdb->last_error  = "Unknown system variable 'max_statement_time'";
        $this->wpdb->results     = [['ok']];
        $this->wpdb->columnNames = ['a'];

        $result = \wpmcp_sql_select_run('SELECT 1');

        self::assertIsArray(
            $result,
            'A complaint from the preamble was reported as the statement failing.'
        );
        self::assertSame(1, $result['row_count']);
    }

    /**
     * Neither of the plugin's own tables may be named, anywhere, in any case.
     *
     * @group sprint-9
     */
    public function testThePluginsOwnTablesAreRefusedWhereverTheyAreNamed(): void
    {
        $cases = [
            'a FROM clause'      => 'SELECT * FROM wp_wpmcp_tokens',
            'the bare name'      => 'SELECT * FROM wpmcp_file_versions',
            'upper case'         => 'SELECT * FROM WP_WPMCP_TOKENS',
            'a trailing comment' => 'SELECT 1 -- wpmcp_tokens',
            'a block comment'    => 'SELECT /* wpmcp_file_versions */ 1',
            'a string literal'   => "SELECT 'wpmcp_tokens' AS label",
        ];

        foreach ($cases as $what => $statement) {
            $result = \wpmcp_sql_select_run($statement);

            self::assertInstanceOf(
                \WP_Error::class,
                $result,
                "{$what} was not refused: {$statement}"
            );
            self::assertSame('wpmcp_sql_denied', $result->get_error_code(), $what);
            self::assertSame(
                [],
                $this->wpdb->queries,
                "{$what} reached the connection. The refusal has to happen BEFORE the"
                . ' statement is sent, or the rule is decoration.'
            );
        }
    }

    /**
     * `LOAD_FILE()` is refused by name, before the connection is touched.
     *
     * IT PASSES BOTH WALLS. It is a valid query expression, so the derived-table wrapper
     * takes it, and it is a read, so `START TRANSACTION READ ONLY` takes it too - measured
     * through the exact wrapper on MySQL 8.4.0, errno 0 from both. What decides whether
     * bytes come back is then `secure_file_priv` and the database user's `FILE` privilege,
     * neither of which this plugin owns. On a host where those permit it,
     * `SELECT LOAD_FILE('.../wp-config.php')` is the database password and every salt, out
     * of a tool whose documentation promised the blast radius was the database.
     *
     * `INTO OUTFILE` and `INTO DUMPFILE`, the write side of the same privilege, are already
     * 1064 inside the wrapper. This is the read side.
     *
     * @group sprint-9
     */
    public function testLoadFileIsRefusedByNameBeforeAnythingRuns(): void
    {
        $cases = [
            'plain'            => "SELECT LOAD_FILE('/etc/passwd') AS x",
            'lower case'       => "SELECT load_file('/etc/passwd') AS x",
            'mixed case'       => "SELECT LoAd_FiLe('/etc/passwd') AS x",
            'inside a comment' => 'SELECT 1 -- load_file',
            'as a column name' => 'SELECT payload_load_file FROM wp_options',
        ];

        foreach ($cases as $what => $statement) {
            $result = \wpmcp_sql_select_run($statement);

            self::assertInstanceOf(\WP_Error::class, $result, "{$what} was not refused.");
            self::assertSame('wpmcp_sql_denied', $result->get_error_code(), $what);
            self::assertStringContainsString(
                'read the server filesystem',
                $result->get_error_message(),
                "{$what} was refused for the wrong reason: " . $result->get_error_message()
            );
            self::assertSame(
                [],
                $this->wpdb->queries,
                "{$what} reached the connection."
            );
        }
    }

    /**
     * An empty statement is a validation refusal, with nothing sent and nothing logged.
     *
     * @group sprint-9
     */
    public function testAnEmptyStatementIsRefusedWithoutTouchingTheConnection(): void
    {
        foreach (['', '   ', " ;\n", ';'] as $empty) {
            $result = \wpmcp_sql_select_run($empty);

            self::assertInstanceOf(\WP_Error::class, $result, var_export($empty, true));
            self::assertSame('wpmcp_sql_empty', $result->get_error_code());
        }

        self::assertSame([], $this->wpdb->queries);
    }

    /**
     * ONE trailing semicolon comes off, and nothing else is touched.
     *
     * A HUMAN TYPES `SELECT 1;` AND NOTHING MORE IS ASSUMED. The tool does not strip
     * comments, does not normalise whitespace inside the statement and does not rewrite
     * anything: every one of those would be this file deciding what the SQL means, which
     * is the job the design refuses to take.
     *
     * @group sprint-9
     */
    public function testExactlyOneTrailingSemicolonIsStripped(): void
    {
        $this->wpdb->results     = [];
        $this->wpdb->columnNames = [];

        \wpmcp_sql_select_run("  SELECT 1 /* ; keep me */ ;  \n ");

        self::assertSame(
            'SELECT * FROM (SELECT 1 /* ; keep me */) AS wpmcp_q LIMIT 201',
            $this->wpdb->queries[5],
            'The trailing semicolon and the surrounding whitespace come off; a semicolon'
            . ' inside the statement is the statement\'s business.'
        );
    }

    /**
     * A caller whose user does not hold manage_options is refused before anything runs.
     *
     * ADMIN SCOPE IS NOT AN ADMINISTRATOR - wpmcp_mint() takes an owner. This is the same
     * gate the code tools grew in sprint 8, for the same reason.
     *
     * @group sprint-9
     */
    public function testAUserWithoutManageOptionsIsRefused(): void
    {
        WordPressRuntime::install();
        WordPressRuntime::logInAs(12, 'wpmcp-unit-editor');

        $result = \wpmcp_sql_select_run('SELECT 1');

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wpmcp_forbidden', $result->get_error_code());
    }

    /* ------------------------------------------------------------------
     * the cell encoder
     * ---------------------------------------------------------------- */

    /**
     * NULL, plain text, and bytes that are not UTF-8.
     *
     * @group sprint-9
     */
    public function testTheCellEncoderKeepsNullAndHexesWhatIsNotText(): void
    {
        self::assertNull(\wpmcp_sql_cell(null));
        self::assertSame('', \wpmcp_sql_cell(''));
        self::assertSame('plain', \wpmcp_sql_cell('plain'));
        self::assertSame('héllo', \wpmcp_sql_cell('héllo'), 'Valid UTF-8 must pass through.');
        self::assertSame('0x61806263', \wpmcp_sql_cell("a\x80bc"));
        self::assertSame('0xFF', \wpmcp_sql_cell("\xFF"));
    }

    /**
     * The 8 KiB cut lands on a character boundary, not in the middle of one.
     *
     * A BYTE BUDGET CUT WITH A BYTE FUNCTION LANDS MID-CHARACTER, and the result would be
     * exactly the invalid UTF-8 the hex branch exists to keep away from
     * wp_json_encode() - which does not fail on it, it silently rewrites the broken byte
     * as a question mark. The three-byte ellipsis is added after the repair, so an
     * oversized cell is 8192 bytes of valid text plus the marker.
     *
     * @group sprint-9
     */
    public function testAnOversizedCellIsCutOnACharacterBoundary(): void
    {
        // 4095 three-byte characters is 12285 bytes; a byte cut at 8192 lands two bytes
        // into the 2731st.
        $long = str_repeat("\u{4E16}", 4095);
        $cut  = \wpmcp_sql_cell($long);

        self::assertStringEndsWith("\u{2026}", $cut);

        $body = substr($cut, 0, -3);

        self::assertSame(
            1,
            preg_match('//u', $body),
            'The cut left a partial UTF-8 sequence at the end of the cell.'
        );
        self::assertSame(8190, strlen($body), 'The cut should take whole characters only.');

        // AND A VALUE AT THE CAP IS NOT TOUCHED. An off-by-one here would mark an
        // untruncated cell as cut.
        $exact = str_repeat('x', WPMCP_SQL_CELL_CAP);

        self::assertSame($exact, \wpmcp_sql_cell($exact));
        self::assertStringEndsWith("\u{2026}", \wpmcp_sql_cell($exact . 'x'));
    }

    /* ------------------------------------------------------------------
     * the caps, shaped from recorded rows
     * ---------------------------------------------------------------- */

    /**
     * 201 rows in, 200 out, `truncated_by: rows`.
     *
     * @group sprint-9
     */
    public function testTheRowCapCutsAtTwoHundredAndSaysWhy(): void
    {
        $this->wpdb->columnNames = ['i'];
        $this->wpdb->results     = array_map(
            static fn (int $i): array => [(string) $i],
            range(1, 201)
        );

        $result = \wpmcp_sql_select_run('SELECT 1');

        self::assertSame(200, $result['row_count']);
        self::assertTrue($result['truncated']);
        self::assertSame('rows', $result['truncated_by']);
        self::assertSame(['200'], $result['rows'][199]);
    }

    /**
     * Exactly 200 rows is NOT truncated, and neither is a result that fills the byte
     * budget with nothing left over.
     *
     * THE FALSE POSITIVE IS THE BUG HERE. `truncated: true` tells an agent there is more
     * to fetch; saying it when the last row was the last row sends it looking for a page
     * that does not exist.
     *
     * @group sprint-9
     */
    public function testAResultThatFitsExactlyIsNotCalledTruncated(): void
    {
        $this->wpdb->columnNames = ['i'];
        $this->wpdb->results     = array_map(
            static fn (int $i): array => [(string) $i],
            range(1, 200)
        );

        $result = \wpmcp_sql_select_run('SELECT 1');

        self::assertSame(200, $result['row_count']);
        self::assertFalse($result['truncated']);
        self::assertArrayNotHasKey(
            'truncated_by',
            $result,
            'truncated_by is present on a result that was not truncated.'
        );

        // Two rows of 200 KB: the first takes the total past the 256 KiB budget, and the
        // second is the last one there is - so nothing was dropped and nothing is claimed.
        $this->wpdb->results = [
            [str_repeat('a', 200000)],
            [str_repeat('b', 200000)],
        ];

        $result = \wpmcp_sql_select_run('SELECT 1');

        self::assertSame(2, $result['row_count']);
        self::assertFalse(
            $result['truncated'],
            'A result whose last row tipped the byte budget was reported as having more'
            . ' behind it.'
        );
    }

    /**
     * Rows that are too big stop on the byte budget, flagged `bytes`.
     *
     * @group sprint-9
     */
    public function testTheByteCapStopsAppendingAndSaysWhy(): void
    {
        $this->wpdb->columnNames = ['pad'];
        $this->wpdb->results     = array_fill(0, 100, [str_repeat('a', 8000)]);

        $result = \wpmcp_sql_select_run('SELECT 1');

        self::assertTrue($result['truncated']);
        self::assertSame('bytes', $result['truncated_by']);
        self::assertGreaterThan(0, $result['row_count']);
        self::assertLessThan(
            100,
            $result['row_count'],
            '800 KB of rows came back whole; the 256 KiB budget did nothing.'
        );
    }

    /**
     * The event carries who, how much, and the first 200 characters of the statement.
     *
     * @group sprint-9
     */
    public function testTheEventIsFiredWithABoundedStatement(): void
    {
        $this->wpdb->columnNames = ['a'];
        $this->wpdb->results     = [['1']];

        \wpmcp_sql_select_run('SELECT ' . str_repeat('1', 400) . ' AS a');

        $fired = WordPressRuntime::firedActions('wpmcp_auth_event');
        $mine  = array_values(array_filter(
            $fired,
            static fn (array $call): bool => ($call[0] ?? '') === 'sql_select'
        ));

        self::assertCount(1, $mine, 'sql-select did not fire exactly one sql_select event.');

        $context = $mine[0][1];

        self::assertSame(1, $context['row_count']);
        self::assertFalse($context['truncated']);
        self::assertSame(
            200,
            mb_strlen($context['sql']),
            'The logged statement is not capped at 200 characters, so one query can carry'
            . ' a database row into the auth log.'
        );
    }
}
