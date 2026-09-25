<?php
/**
 * The slice of $wpdb the plugin's token model touches, with the writes recorded
 * instead of executed.
 *
 * Only what is actually called is implemented. A method the plugin starts using and
 * this class does not have raises an Error naming it, which is the point: a silent
 * no-op $wpdb would let a broken query pass a unit test.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class FakeWpdb
{
    /** Matches a default single-site install; wpmcp_table() concatenates it. */
    public string $prefix = 'wp_';

    /** Set by insert(), read by wpmcp_mint() for its return value. */
    public int $insert_id = 0;

    /** Every insert() call, in order: ['table' => ..., 'data' => ..., 'format' => ...]. */
    public array $inserts = [];

    /** Every query() call, in order. */
    public array $queries = [];

    /** What the next insert() should return. false makes wpmcp_mint() report failure. */
    public bool $insertSucceeds = true;

    /**
     * What get_row() answers with, or null for "no such row".
     *
     * ONE ROW, NOT A TABLE, and deliberately. The unit tier calls wpmcp_validate() to
     * ask what it does with a row once it has one - is this token expired, is its user
     * gone, does the caller's address matter. Which row a SHA-256 lookup returns is a
     * question about SQL, and SQL is the integration tier's business; a fake that
     * re-implemented the lookup would be asserting its own behaviour.
     */
    public ?object $row = null;

    /** Every update() call, in order: ['table', 'data', 'where', ...]. */
    public array $updates = [];

    /** Every delete() call, in order: ['table' => ..., 'where' => ...]. */
    public array $deletes = [];

    /* --------------------------------------------------------------------
     * Sprint 9: the slice sql-select touches.
     *
     * It is a READ path, so none of this records a write - what it records is the ORDER
     * of the session statements, which is the whole of sql-select's safety argument:
     * caps, then START TRANSACTION READ ONLY, then the wrapped statement, then ROLLBACK,
     * and ROLLBACK last WHATEVER HAPPENED. `query()` already keeps them in $queries.
     * ------------------------------------------------------------------ */

    /** What get_results() answers with: a list of positional rows. */
    public array $results = [];

    /** Column names get_col_info('name') answers with. */
    public array $columnNames = [];

    /** Set by get_results() from $errorOnGetResults; read by the code under test. */
    public string $last_error = '';

    /** The message get_results() should leave in last_error, or '' for success. */
    public string $errorOnGetResults = '';

    /** A throwable get_results() should throw instead of answering. */
    public ?\Throwable $throwOnGetResults = null;

    /** What db_server_info() reports. Put 'MariaDB' in it to take the other branch. */
    public string $serverInfo = '8.4.0';

    /** Current suppress_errors state; the setter returns the PREVIOUS one, as wpdb does. */
    public bool $suppressErrors = false;

    public function get_results($query, $output = null)
    {
        $this->queries[] = $query;

        if ($this->throwOnGetResults !== null) {
            throw $this->throwOnGetResults;
        }

        $this->last_error = $this->errorOnGetResults;

        return $this->errorOnGetResults === '' ? $this->results : null;
    }

    public function get_col_info($info = 'name', $col_offset = -1)
    {
        return $this->columnNames;
    }

    /**
     * What get_var() answers, by query string, with a fallback for anything unlisted.
     *
     * sql-select reads two session variables before it changes them, so the restore in its
     * `finally` has something to put back. A fake that answered null for both would make
     * every restore assertion pass by never running.
     *
     * @var array<string, string|null>
     */
    public array $vars = [];

    /** What get_var() answers for a query not in $vars. */
    public ?string $defaultVar = null;

    public function get_var($query = null, $x = 0, $y = 0)
    {
        $this->queries[] = $query;

        return $this->vars[$query] ?? $this->defaultVar;
    }

    /**
     * Columns get_col() answers with, by SUBSTRING of the query, first match wins.
     *
     * A SUBSTRING AND NOT THE WHOLE QUERY, unlike $vars, because the only caller is
     * `SHOW COLUMNS FROM <table> LIKE '<column>'` and what a test wants to say is "this column
     * is there and that one is not" - the table name and the prefix are noise it would otherwise
     * have to reproduce exactly. Anything unmatched answers $defaultCol, which is how the
     * revision-6 test says "every required column is present, the two new ones are not".
     *
     * @var array<string, list<string>>
     */
    public array $cols = [];

    /** What get_col() answers for a query no key of $cols appears in. */
    public array $defaultCol = [];

    public function get_col($query = null, $x = 0)
    {
        $this->queries[] = $query;

        foreach ($this->cols as $needle => $answer) {
            if (str_contains((string) $query, $needle)) {
                return $answer;
            }
        }

        return $this->defaultCol;
    }

    public function suppress_errors($suppress = true)
    {
        $previous             = $this->suppressErrors;
        $this->suppressErrors = (bool) $suppress;

        return $previous;
    }

    public function db_server_info()
    {
        return $this->serverInfo;
    }

    public function insert($table, $data, $format = null)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data, 'format' => $format];

        if (!$this->insertSucceeds) {
            return false;
        }

        $this->insert_id = count($this->inserts);

        return 1;
    }

    /**
     * What query() answers, in order; the LAST entry repeats once the list runs out.
     *
     * ADDED FOR THE TRACE SWEEP'S BATCHING (sprint TRACE-TABLE round 2), which is the first
     * caller in this plugin that reads query()'s return value and decides whether to run it
     * again. A fake that always answered 0 could not express "this DELETE removed a full batch,
     * so there is more to do" - and the loop's two interesting cases are exactly that and its
     * round cap. The last-entry-repeats rule is what lets a test say "always a full batch"
     * without writing twenty entries.
     *
     * @var list<int|false>
     */
    public array $queryReturns = [];

    public function query($sql)
    {
        $this->queries[] = $sql;

        if ($this->queryReturns === []) {
            return 0;
        }

        return count($this->queryReturns) === 1
            ? $this->queryReturns[0]
            : array_shift($this->queryReturns);
    }

    /**
     * Substitute %s / %d / %f the way $wpdb->prepare does, well enough for a recorded
     * query string to be readable in a failure message. Nothing asserts on the SQL.
     */
    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $replacement = is_int($arg) || is_float($arg) ? (string) $arg : "'" . $arg . "'";
            $query = preg_replace('/%[sdf]/', $replacement, (string) $query, 1);
        }

        return $query;
    }

    public function get_row($query)
    {
        $this->queries[] = $query;

        return $this->row;
    }

    public function update($table, $data, $where, $format = null, $whereFormat = null)
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];

        return 1;
    }

    public function delete($table, $where, $format = null)
    {
        $this->deletes[] = ['table' => $table, 'where' => $where];

        return 1;
    }

    /** The data array of the most recent insert(), or null if there was none. */
    public function lastInsertData(): ?array
    {
        $last = end($this->inserts);

        return $last === false ? null : $last['data'];
    }

    public function get_charset_collate(): string
    {
        return '';
    }
}
