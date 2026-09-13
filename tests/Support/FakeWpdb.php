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

    public function insert($table, $data, $format = null)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data, 'format' => $format];

        if (!$this->insertSucceeds) {
            return false;
        }

        $this->insert_id = count($this->inserts);

        return 1;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;

        return 0;
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
