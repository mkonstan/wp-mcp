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
