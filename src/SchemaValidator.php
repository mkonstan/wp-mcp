<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * The always-on input validator: a hand-written JSON Schema 2020-12 SUBSET, sized to
 * the dialect the tool catalog actually speaks and not one keyword wider.
 *
 * HAND-WRITTEN RATHER THAN VENDORED, decided in the build plan's log (2026-09-12):
 * `opis/json-schema` would have to be namespace-prefixed to survive inside WordPress,
 * where another plugin may already have loaded a different version of it, and this
 * plugin ships with no vendor directory at all.
 *
 * THE DIALECT IS A CLOSED LIST, and it was read off the 20 built-in schemas rather than
 * copied from the specification. What they use, in full:
 *
 *   type          object, string, integer, boolean   (and nothing else, today)
 *   properties
 *   required
 *
 * SUPPORTED is wider than USED, by exactly the keywords a tool author here would reach
 * for next - `enum`, `minimum`/`maximum`, `minLength`/`maxLength`, `items`, the
 * remaining `type` names - so that adding one to a schema enforces something instead of
 * being silently ignored. Everything else is absent on purpose: no `$ref`, no `oneOf`,
 * `anyOf`, `allOf`, `not`, no `format`, no `patternProperties`, no `const`. An
 * unsupported keyword in a schema is NOT enforced, which is why
 * tests/unit/ToolContractTest.php asserts that every built-in schema stays inside
 * KEYWORDS - the validator's silence about a keyword it does not know is a hole, and
 * that test is what keeps the hole out of this plugin's own tools.
 *
 * `additionalProperties: false` IS THE DEFAULT for a tool's top-level schema - see
 * validateArguments() - which is Max's explicit-scope rule applied to the one input
 * dimension that had no allow-list yet: argument keys. An argument nobody declared is
 * refused rather than ignored, so a caller that misspells `limit` finds out instead of
 * silently getting the default.
 *
 * INTEGER MEANS INTEGER. `"20"` is a string and is refused; nothing here coerces. The
 * tools themselves cast - `(int) $a['limit']` - and a cast turns every wrong type into
 * a plausible-looking value: `(int) "twenty"` is 0, `(int) "5 posts"` is 5. Refusing at
 * the boundary is the only place that distinction still exists.
 *
 * NOTHING FROM THE CALLER'S VALUES REACHES THE MESSAGE, only the TYPE of what was sent
 * and the KEY it was sent under, truncated. Keys are attacker-controlled (they are
 * arbitrary JSON object members), so the same truncation the protocol-version gate
 * applies to its echoed header applies here, and the failure list itself is capped.
 */

declare(strict_types=1);

namespace WpMcp;

final class SchemaValidator
{
    /**
     * Every keyword this validator understands. The dialect, as a list, so a test can
     * assert the built-in schemas stay inside it.
     */
    public const KEYWORDS = array(
        'type',
        'properties',
        'required',
        'enum',
        'minimum',
        'maximum',
        'minLength',
        'maxLength',
        'items',
        'additionalProperties',
        // Annotation-only, never validated against: they describe, they do not constrain.
        'description',
        'title',
        'default',
    );

    /** Every `type` name this validator understands. */
    public const TYPES = array('object', 'array', 'string', 'integer', 'number', 'boolean', 'null');

    /**
     * At most this many failures are reported. A body may legally carry thousands of
     * unknown keys, and each one would otherwise become a line of an error message
     * echoed back to whoever sent it.
     */
    public const MAX_FAILURES = 20;

    /** Longest key fragment echoed inside a JSON pointer. */
    private const MAX_KEY = 64;

    /**
     * The arguments of one `tools/call`, against that tool's `inputSchema`.
     *
     * THE ONE THING THIS ADDS over validate(): `additionalProperties: false` at the TOP
     * LEVEL when the schema does not say otherwise. Only the top level, and that is
     * deliberate - `create-post`'s `terms` is declared `{"type":"object"}` with no
     * `properties` at all, because its members are taxonomy names nobody can enumerate
     * in advance. Defaulting the whole tree closed would refuse every taxonomy name
     * there. A schema that wants a closed nested object says so.
     *
     * @param mixed $arguments the decoded `params.arguments`
     * @param mixed $schema    the tool's inputSchema
     * @return list<string> '<JSON pointer>: <what is wrong>', empty when valid
     */
    public static function validateArguments($arguments, $schema): array
    {
        if (is_array($schema) && !array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        return self::validate($arguments, $schema);
    }

    /**
     * $value against $schema. The public entry point; capped.
     *
     * @return list<string>
     */
    public static function validate($value, $schema, string $pointer = ''): array
    {
        $failures = self::check($value, $schema, $pointer);

        if (count($failures) <= self::MAX_FAILURES) {
            return $failures;
        }

        $kept      = array_slice($failures, 0, self::MAX_FAILURES);
        $remaining = count($failures) - self::MAX_FAILURES;
        $kept[]    = '(and ' . $remaining . ' more)';

        return $kept;
    }

    /**
     * The recursive body. Uncapped; call validate().
     *
     * A TYPE FAILURE STOPS THE DESCENT at that node: once the value is known not to be
     * the right kind of thing, every constraint below it would report the same fact
     * again in another five lines, and "/terms: expected object, got string" is the one
     * sentence the caller has to act on.
     *
     * @return list<string>
     */
    private static function check($value, $schema, string $pointer): array
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return array();
        }

        if (isset($map['type']) && is_string($map['type']) && !self::matches($value, $map['type'])) {
            return array(self::failure(
                $pointer,
                'expected ' . $map['type'] . ', got ' . self::typeName($value)
            ));
        }

        $failures = array();

        if (isset($map['enum']) && is_array($map['enum']) && !in_array($value, $map['enum'], true)) {
            $failures[] = self::failure(
                $pointer,
                'not one of the permitted values: ' . self::asList($map['enum'])
            );
        }

        if (is_int($value) || is_float($value)) {
            if (isset($map['minimum']) && (is_int($map['minimum']) || is_float($map['minimum']))
                && $value < $map['minimum']) {
                $failures[] = self::failure($pointer, 'must be >= ' . $map['minimum']);
            }
            if (isset($map['maximum']) && (is_int($map['maximum']) || is_float($map['maximum']))
                && $value > $map['maximum']) {
                $failures[] = self::failure($pointer, 'must be <= ' . $map['maximum']);
            }
        }

        if (is_string($value)) {
            $length = self::length($value);

            if (isset($map['minLength']) && is_int($map['minLength']) && $length < $map['minLength']) {
                $failures[] = self::failure(
                    $pointer,
                    'must be at least ' . $map['minLength'] . ' characters long'
                );
            }
            if (isset($map['maxLength']) && is_int($map['maxLength']) && $length > $map['maxLength']) {
                $failures[] = self::failure(
                    $pointer,
                    'must be at most ' . $map['maxLength'] . ' characters long'
                );
            }
        }

        if (isset($map['items']) && is_array($value) && self::matches($value, 'array')) {
            foreach ($value as $index => $element) {
                $failures = array_merge(
                    $failures,
                    self::check($element, $map['items'], $pointer . '/' . (int) $index)
                );
            }
        }

        if (self::matches($value, 'object') && (is_array($value) || is_object($value))) {
            $failures = array_merge($failures, self::checkObject((array) $value, $map, $pointer));
        }

        return $failures;
    }

    /**
     * `required`, `properties` and `additionalProperties` of one object node.
     *
     * THE ORDER IS THE ORDER THE FAILURES ARE REPORTED IN, and it is fixed rather than
     * incidental: missing required members first (the caller cannot proceed without
     * them), then the declared members it did send, in SCHEMA order, then the members
     * nobody declared. Two identical calls produce byte-identical messages, which is
     * what lets a test assert on one.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $map
     * @return list<string>
     */
    private static function checkObject(array $object, array $map, string $pointer): array
    {
        $failures = array();

        if (isset($map['required']) && is_array($map['required'])) {
            foreach ($map['required'] as $key) {
                if (is_string($key) && !array_key_exists($key, $object)) {
                    $failures[] = self::failure(
                        $pointer . '/' . self::escape($key),
                        'required property is missing'
                    );
                }
            }
        }

        $properties = isset($map['properties']) ? self::asMap($map['properties']) : null;

        if ($properties !== null) {
            foreach ($properties as $name => $sub) {
                $name = (string) $name;

                if (array_key_exists($name, $object)) {
                    $failures = array_merge($failures, self::check(
                        $object[$name],
                        $sub,
                        $pointer . '/' . self::escape($name)
                    ));
                }
            }
        }

        if (!array_key_exists('additionalProperties', $map)) {
            return $failures;
        }

        $extra = array_diff_key($object, (array) $properties);

        if ($map['additionalProperties'] === false) {
            foreach (array_keys($extra) as $name) {
                $failures[] = self::failure(
                    $pointer . '/' . self::escape((string) $name),
                    'unknown property - this tool declares no such argument'
                );
            }

            return $failures;
        }

        if (self::asMap($map['additionalProperties']) !== null) {
            foreach ($extra as $name => $sub) {
                $failures = array_merge($failures, self::check(
                    $sub,
                    $map['additionalProperties'],
                    $pointer . '/' . self::escape((string) $name)
                ));
            }
        }

        return $failures;
    }

    /**
     * Does $value satisfy the JSON Schema type $type?
     *
     * THE EMPTY ARRAY IS BOTH, and it has to be: `json_decode('{}', true)` and
     * `json_decode('[]', true)` are the same PHP value, so a validator that picked one
     * would refuse half of the legal inputs. The same ambiguity the serialization guard
     * exists to undo on the way out (see wpmcp_objectify_schema()), seen from the
     * inbound side.
     *
     * A TYPE NAME THIS DIALECT DOES NOT KNOW PASSES. It cannot be checked, so refusing
     * on it would refuse a legal input over a schema this validator does not understand
     * - a decision for the author of the schema, not for the caller. Built-in schemas
     * are held to TYPES by tests/unit/ToolContractTest.php instead.
     */
    private static function matches($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                // is_int, and nothing else. 20.0 is a number, "20" is a string.
                return is_int($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            case 'object':
                return is_object($value)
                    || (is_array($value) && ($value === array() || !array_is_list($value)));
            case 'array':
                return is_array($value) && ($value === array() || array_is_list($value));
            default:
                return true;
        }
    }

    /** What the caller actually sent, named in JSON's vocabulary. */
    private static function typeName($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return array_is_list($value) && $value !== array() ? 'array' : 'object';
        }

        return 'object';
    }

    /**
     * A schema node as a key => value map, or null when it is not one.
     *
     * OBJECTS ARE ACCEPTED as well as arrays, because `new stdClass()` is how an empty
     * `properties` was written before the serialization guard existed - site-info did
     * exactly that, and a tool added through the `wpmcp_tools` filter still may.
     *
     * @return array<string, mixed>|null
     */
    private static function asMap($schema): ?array
    {
        if (is_object($schema)) {
            return (array) $schema;
        }

        return is_array($schema) ? $schema : null;
    }

    /** '<pointer>: <what is wrong>'. The root pointer is rendered, not left blank. */
    private static function failure(string $pointer, string $problem): string
    {
        return ($pointer === '' ? '(root)' : $pointer) . ': ' . $problem;
    }

    /**
     * One JSON pointer segment: RFC 6901 escaping, then truncated.
     *
     * The key came from the caller, so its length is the caller's choice and it ends up
     * in a message sent back - the same shape of problem as the echoed
     * MCP-Protocol-Version header, and the same answer.
     *
     * TRUNCATED ON A CHARACTER BOUNDARY, NOT A BYTE ONE. A byte `substr` at 64 splits a
     * three-byte character whose first byte lands on 64, and the half-character left
     * behind is invalid UTF-8: `json_encode` refuses the whole document on it
     * (JSON_ERROR_UTF8), and wp_json_encode's `_wp_json_sanity_check` instead strips the
     * bad bytes - so the failure message comes back mangled rather than truncated, or
     * not at all. Found by review 2026-09-12; a 2-byte character happens to cut cleanly
     * at 64 and a 3-byte one does not, which is exactly the kind of difference a
     * byte-length cap cannot see. mb_strcut cuts to a byte budget WITHOUT splitting a
     * character, which is the operation wanted here - mb_substr would cap characters and
     * let a 64-character key of 4-byte emoji through at 256 bytes.
     */
    private static function escape(string $key): string
    {
        if (strlen($key) > self::MAX_KEY) {
            $key = (function_exists('mb_strcut')
                ? (string) mb_strcut($key, 0, self::MAX_KEY, 'UTF-8')
                : substr($key, 0, self::MAX_KEY)) . '...';
        }

        return str_replace(array('~', '/'), array('~0', '~1'), $key);
    }

    /** The permitted values of an enum, for the message. The SCHEMA's own values. */
    private static function asList(array $values): string
    {
        $rendered = array();

        foreach ($values as $value) {
            $rendered[] = is_string($value) ? $value : json_encode($value);
        }

        return implode(', ', $rendered);
    }

    /**
     * Characters, not bytes - JSON Schema counts code points.
     *
     * mbstring is a PHP extension and this plugin assumes a bare site, so its absence
     * degrades to a byte count rather than to a fatal. No built-in schema uses a length
     * keyword today, so nothing currently depends on the difference.
     */
    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
