<?php
/** Isolated Order 003 raw-row seam, approved by orchestrator mailbox answer 12. */
declare(strict_types=1);

namespace WpMcp\Tests\Support {
    final class SiteConfigOptionRows
    {
        public string $options = 'wp_options';
        public string $last_error = '';
        public array $queries = [];
        public ?int $failRead = null;

        public function prepare(string $query, mixed ...$values): string
        {
            return (new FakeWpdb())->prepare($query, ...$values);
        }

        public function get_row(string $query): ?object
        {
            $this->queries[] = $query;
            $this->last_error = '';
            // A single supported prepared SELECT, not a general SQL interpreter. A wrong
            // table, column or predicate fails loudly rather than returning a convenient row.
            if (!preg_match("/^SELECT option_value FROM wp_options WHERE option_name = '([A-Za-z0-9_-]+)' LIMIT 1$/D", $query, $match)) {
                throw new \InvalidArgumentException('query ' . $query
                    . ' is unsupported; required: exact options-row SELECT by option_name, e.g. blogname.');
            }
            if ($this->failRead === count($this->queries)) {
                $this->last_error = 'fixture database read failure';
                return null;
            }
            $options = $GLOBALS['wpmcp_test_wp']['options'];
            if (!array_key_exists($match[1], $options)) { return null; }
            $value = $options[$match[1]];
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('stored fixture value ' . var_export($value, true)
                    . ' is unsupported; required: string or integer, e.g. blog_public with 0.');
            }
            // Database scalar option_value columns return strings, even after integer writes.
            return (object) ['option_value' => (string) $value];
        }
    }
}

namespace {
    if (!function_exists('maybe_unserialize')) {
        function maybe_unserialize($value)
        {
            // These fixtures contain only plain scalar rows. Refuse serialized-looking
            // inputs and nonstrings so this seam cannot silently grow into an unserializer.
            if (!is_string($value) || preg_match('/^(?:[abisdOSCErR]:|N;)/', $value)) {
                throw new \InvalidArgumentException('maybe_unserialize fixture value ' . var_export($value, true)
                    . ' is unsupported; required: a plain scalar-row string, e.g. "subscriber" or "0".');
            }
            return $value;
        }
    }
}
