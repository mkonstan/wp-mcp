<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * The always-on input validator, and IT IS NOT A JSON SCHEMA IMPLEMENTATION. Core's
 * `rest_validate_value_from_schema()` (wp-includes/rest-api.php:2221) does the keyword work.
 * This class walks the structure, holds the three things core cannot be asked for, and hands
 * every remaining keyword to core one group at a time.
 *
 * WHAT THIS REPLACED (sprint VALIDATOR, D33(1)). The file was a hand-written SUBSET enforcing
 * ten constraining keywords. `rest_get_allowed_schema_keywords()` (rest-api.php:2169) lists
 * twenty-five, so core validated THIRTEEN this file silently ignored - `format pattern
 * patternProperties minProperties maxProperties exclusiveMinimum exclusiveMaximum multipleOf
 * minItems maxItems uniqueItems anyOf oneOf`. A keyword the validator did not know was not an
 * error and not a log line: the argument reached the tool body unchecked.
 *
 * THE THREE ADDITIONS, and none is available by filtering - core's validator body contains no
 * `apply_filters` at all (verified 2026-09-26, rest-api.php:2221-2352). Each is a GAP rather
 * than an inherited decision (D32): core decided these for a browser form posting a query
 * string, and this server is JSON from a tool client, so the decision does not arrive here.
 *
 *   1. STRICT TYPES. `"20"` is a string and is REFUSED. `rest_is_integer("20")` is true,
 *      `rest_is_array("a,b")` splits on commas, `rest_is_object("")` is true - deliberately,
 *      because REST arguments arrive from query strings where every value is a string. Our
 *      callers send JSON, where the type is already expressed, and the tools cast
 *      (`(int) $a['limit']`), so a coerced wrong type becomes a plausible-looking value:
 *      `(int) "twenty"` is 0, `(int) "5 posts"` is 5. `matches()` therefore runs BEFORE any
 *      delegation and a type failure returns without calling core at all - which is asserted,
 *      because "core was not consulted" is the only observable form of this addition.
 *   2. ALL FAILURES AT ONCE, each at its own JSON Pointer. Core returns the FIRST `WP_Error`
 *      and stops; a client that fixes one argument per round trip is a worse tool. So this
 *      class owns the RECURSION - `properties`, `items`, `patternProperties`,
 *      `additionalProperties` - and asks core once per keyword GROUP per node.
 *   3. KEY TRUNCATION, and nothing from the caller's VALUES in the message. Argument keys are
 *      attacker-controlled (arbitrary JSON object members) and end up in a string sent back,
 *      so `escape()` applies RFC 6901 escaping and a byte-budget cut on a character boundary,
 *      and the list itself is capped at MAX_FAILURES. It is also why core is called with an
 *      EMPTY `$param`: core interpolates it into every message, so sending the pointer would
 *      put an untruncated unescaped key in the reply and duplicate failure()'s own prefix.
 *
 * `additionalProperties: false` IS THE DEFAULT for a tool's top-level schema - see
 * validateArguments(). CORE'S DECISION, NOT OURS, and this file used to claim it: core ships
 * `rest_default_additional_properties_to_false()` (rest-api.php:3192) and applies it to every
 * registered route's args. What is ours is only the SHAPE of the refusal - one line per unknown
 * key at that key's pointer, rather than core's first-one-and-stop.
 *
 * THE FOUR THINGS THAT MADE THE SWAP DELICATE, resolved rather than left implicit:
 *
 *   1. WP VERSION VARIANCE - RESOLVED BY THE FLOOR, WHICH IS ALREADY HIGH ENOUGH. The thirteen
 *      arrived in core over three releases and the LATEST of them is 5.6.0 (rest-api.php:
 *      2199-2215). The declared floor is WordPress 6.9 - `Requires at least` in wp-mcp.php,
 *      enforced on activation by core's own validate_plugin_requirements(), held in four
 *      places by tests/unit/FloorConsistencyTest.php and executed by ci.yml's floor leg. So all
 *      thirteen are enforced at the floor, there is no variance to feature-detect, and a future
 *      floor DROP has to come past this paragraph. SchemaValidatorTest asserts the floor is 5.6 or newer.
 *   2. ERROR CODES ARE WIRE-VISIBLE - RESOLVED BY DROPPING THEM, NOT MAPPING THEM. Core answers
 *      `rest_invalid_param`, `rest_too_short`, `rest_not_in_enum` and a dozen more; this
 *      plugin's contract is that every `WP_Error` it emits carries the `wpmcp_` prefix. Nothing
 *      here emits a `WP_Error`: this class returns STRINGS and `wpmcp_dispatch()` turns a
 *      non-empty list into an MCP tool error (`isError: true`, the lines as text), which has no
 *      code field at all. Core's codes are discarded here and cannot reach a client; only its
 *      MESSAGE text is relayed, behind our pointer. What a client observes that it did not
 *      before is the WORDING of five messages once written here (`enum`, `minimum`, `maximum`,
 *      `minLength`, `maxLength`): core's are longer, localized and pluralized by `_n()`.
 *      SchemaValidatorTest asserts no `rest_` code appears in any failure line.
 *   3. `required` IS OURS AND IS NOT IN CORE'S LIST. Core handles it inside
 *      `rest_validate_object_value_from_schema()`, outside the allowed-keywords list, its
 *      message names the OBJECT rather than the missing member, and it returns on the first one.
 *      So dialect() is core's twenty-five PLUS `required`, and `required` is in OURS.
 *   4. THIRD-PARTY TOOLS - REGISTRATION REFUSES AN UNKNOWN KEYWORD. A tool added through the
 *      `wpmcp_tools` filter or a module is not in the catalog, so ToolContractTest never sees
 *      it and its author could declare a keyword nothing enforces. unknownKeyword() below is
 *      what `wpmcp_registry_reject_reason()` asks; the entry is dropped with reason
 *      `schema_keyword_unknown` and a `registry_reject` event naming the tool. Same rule as
 *      `write` and `annotations`: absence of enforcement is not a declaration of safety. The
 *      cost is that `$schema`, `$ref`, `allOf`, `not` and `const` now refuse the tool instead
 *      of being ignored, and the fix is to delete the keyword - it never did anything.
 *
 * THE ONE PLACE CORE'S COERCION SURVIVES is inside `anyOf`/`oneOf`: core validates each branch
 * itself (rest-api.php:1993-2087), so a branch declaring `{"type":"integer"}` accepts `"20"`
 * where a top-level `"type":"integer"` would not. Handling the combinators here instead would be
 * re-implementing them, which is the overbuild this sprint exists to undo; no built-in schema
 * uses either (ToolContractTest), and a third-party tool that does now gets branch validation
 * where it previously got none. SchemaValidatorTest holds the limitation so it cannot drift unnoticed.
 */

declare(strict_types=1);

namespace WpMcp;

final class SchemaValidator
{
    /**
     * The keywords THIS class enforces, because delegating each one would lose something
     * named in the docblock above: `type` its strictness, `required` its pointer, and the four
     * structural ones the recursion that gives every failure a pointer of its own.
     *
     * `patternProperties` is here because the ITERATION is ours; the pattern MATCHING is core's
     * `rest_find_matching_pattern_property_schema()`, which is the whole keyword minus the loop.
     */
    public const OURS = array('type', 'required', 'properties', 'additionalProperties', 'items', 'patternProperties');

    /**
     * Handed to `rest_validate_value_from_schema()`, ONE GROUP PER CALL.
     *
     * WHY GROUPS AND NOT ONE CALL PER NODE: core returns the first failure and stops, so a node
     * violating both `pattern` and `maxLength` would report one of them. One call per group
     * gives one possible failure per group, which is addition 2 applied within a node.
     *
     * WHY GROUPS AND NOT ONE CALL PER KEYWORD: core's four number bounds are INTERLOCKED -
     * `exclusiveMinimum` is only read when `minimum` is also set (rest-api.php:2606-2710), so a
     * call carrying `exclusiveMinimum` alone enforces nothing at all. They travel together or
     * they do not travel. The pairs that cannot both fail on one value - too short and too long,
     * too few and too many - are grouped to save a call; `uniqueItems` is NOT grouped with the
     * item counts, because a list really can be both too long and full of duplicates.
     *
     * @var list<list<string>>
     */
    public const DELEGATED = array(
        array('enum'),
        array('format'),
        array('pattern'),
        array('minLength', 'maxLength'),
        array('minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum'),
        array('multipleOf'),
        array('minItems', 'maxItems'),
        array('uniqueItems'),
        array('minProperties', 'maxProperties'),
        array('anyOf'),
        array('oneOf'),
    );

    /** They describe, they do not constrain. Nothing validates against these, here or in core. */
    public const ANNOTATIONS = array('description', 'title', 'default');

    /**
     * Every `type` name this validator understands - and it is core's own `$allowed_types`
     * (rest-api.php:2243), in core's order, because a name outside that set makes core call
     * `_doing_it_wrong()` and the delegated type must never be one.
     */
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
     * Every keyword a schema in this plugin may use: core's twenty-five, plus `required`.
     *
     * DERIVED, NOT LISTED, AND THAT IS THE POINT. This used to be a hand-written list beside the
     * implementation, so a keyword could sit in it while nothing checked it - and to
     * tests/unit/ToolContractTest.php, which reads this, an unenforced keyword in this set reads
     * as "enforced, so the constraint is real". Composing it from the three sets above makes that
     * hole impossible to write rather than something a test has to catch: a keyword is here
     * exactly when it is ours, delegated, or declared annotation-only.
     *
     * A METHOD RATHER THAN A CONST because DELEGATED is a list of lists and flattening it is not
     * a constant expression. `SchemaValidator::KEYWORDS` is gone; callers ask this.
     *
     * @return list<string>
     */
    public static function dialect(): array
    {
        return array_merge(
            self::OURS,
            array_merge(...self::DELEGATED),
            self::ANNOTATIONS
        );
    }

    /**
     * The arguments of one `tools/call`, against that tool's `inputSchema`.
     *
     * THE ONE THING THIS ADDS over validate(): `additionalProperties: false` at the TOP
     * LEVEL when the schema does not say otherwise - core's own default for a registered
     * route, applied here because a tool is not a route. Only the top level, and that is
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
     * The first keyword anywhere in $schema that dialect() does not contain, or null.
     *
     * WHAT THIS IS FOR: `wpmcp_registry_reject_reason()` asks it about every tool a filter or a
     * module registers, and drops the entry when the answer is not null. A built-in is held to
     * the same set by tests/unit/ToolContractTest.php, which is a test rather than a runtime
     * check because a built-in that fails it must not ship at all.
     *
     * NO DEPTH CAP, for the same reason check() has none: a schema is the SITE'S code, not the
     * caller's input, so a hostile depth is a hostile plugin and this walk is not what would
     * stop it. The walk covers exactly the places a sub-schema can sit.
     *
     * @param mixed $schema
     */
    public static function unknownKeyword($schema): ?string
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return null;
        }

        $dialect = self::dialect();

        foreach (array_keys($map) as $keyword) {
            if (!in_array((string) $keyword, $dialect, true)) {
                return (string) $keyword;
            }
        }

        // Every place a sub-schema can sit: the values of a map of them, the members of a list
        // of them, or one on its own. A null or a `false` is not a map and stops the descent.
        $nested = array_merge(
            array_values((array) self::asMap($map['properties'] ?? null)),
            array_values((array) self::asMap($map['patternProperties'] ?? null)),
            is_array($map['anyOf'] ?? null) ? $map['anyOf'] : array(),
            is_array($map['oneOf'] ?? null) ? $map['oneOf'] : array(),
            array($map['items'] ?? null, $map['additionalProperties'] ?? null)
        );

        foreach ($nested as $sub) {
            $found = self::unknownKeyword($sub);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The recursive body. Uncapped; call validate().
     *
     * THE ORDER OF THE THREE STEPS IS THE CONTRACT. Our strict type check first, so core never
     * sees a value of the wrong kind and its coercions can never accept one. Then the delegated
     * keywords of THIS node. Then the members, because a failure inside a member needs the
     * member's pointer and core's recursion would report core's `param[key]` notation and stop
     * at the first one.
     *
     * A TYPE FAILURE STOPS THE DESCENT at that node: once the value is known not to be
     * the right kind of thing, every constraint below it would report the same fact
     * again in another five lines, and "/terms: expected object, got string" is the one
     * sentence the caller has to act on.
     *
     * $declared IS NULL IN TWO CASES AND BOTH FALL THROUGH TO THE VALUE'S OWN TYPE. A schema
     * with no `type` at all is one this dialect cannot constrain by kind. A schema whose `type`
     * is an ARRAY of names is core's multiple-types feature (`@since` 5.3), which
     * `rest_handle_multi_type_schema()` resolves by asking which type the value is CLOSEST to -
     * a coercion, and therefore the one core keyword this file will not delegate. Neither is
     * enforced, exactly as neither was before this sprint, and tests/unit/ToolContractTest.php
     * is what keeps both out of this plugin's own schemas.
     *
     * @return list<string>
     */
    private static function check($value, $schema, string $pointer): array
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return array();
        }

        $declared = isset($map['type']) && is_string($map['type']) && in_array($map['type'], self::TYPES, true)
            ? $map['type']
            : null;

        if ($declared !== null && !self::matches($value, $declared)) {
            return array(self::failure(
                $pointer,
                'expected ' . $declared . ', got ' . self::typeName($value)
            ));
        }

        $failures = self::askCore($value, $map, $declared ?? self::typeName($value), $pointer);

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
     * Every delegated keyword group present at this node, asked of core one call each.
     *
     * $type IS ALWAYS SET AND IS ALWAYS ONE OF TYPES, which is not a nicety: core reads
     * `$args['type']` unconditionally three lines after warning about its absence
     * (rest-api.php:2245-2251), so a schema without one produces an "Undefined array key"
     * warning AND a `_doing_it_wrong()` notice - and this suite fails on either. When the node
     * declares no usable type the value's OWN type is sent, which makes core's type check a
     * tautology and leaves the group's keyword as the only thing being asked.
     *
     * THE `$param` IS EMPTY ON PURPOSE - see addition 3 in the file docblock. Core interpolates
     * it into every message, and the only name we have for this node is a pointer built from
     * caller-supplied keys. Sending it would put an untruncated, unescaped key in the reply and
     * duplicate the prefix failure() already writes.
     *
     * @param array<string, mixed> $map
     * @return list<string>
     */
    private static function askCore($value, array $map, string $type, string $pointer): array
    {
        $failures = array();

        foreach (self::DELEGATED as $group) {
            $present = array_intersect_key($map, array_flip($group));

            if ($present === array()) {
                continue;
            }

            $verdict = rest_validate_value_from_schema($value, array('type' => $type) + $present, '');

            if (is_wp_error($verdict)) {
                $failures[] = self::failure($pointer, trim((string) $verdict->get_error_message()));
            }
        }

        return $failures;
    }

    /**
     * `required`, `properties`, `patternProperties` and `additionalProperties` of one object node.
     *
     * THE ORDER IS THE ORDER THE FAILURES ARE REPORTED IN, and it is fixed rather than
     * incidental: missing required members first (the caller cannot proceed without
     * them), then the declared members it did send, in SCHEMA order, then the members
     * nobody declared, in the order they arrived. Two identical calls produce byte-identical
     * messages, which is what lets a test assert on one.
     *
     * A MEMBER THAT MATCHES A `patternProperties` PATTERN IS NOT AN ADDITIONAL PROPERTY, and
     * that precedence is core's (rest-api.php:2454-2463): declared property, then pattern, then
     * additional. Getting it wrong would refuse every pattern-matched key on a closed object.
     * The matching itself is `rest_find_matching_pattern_property_schema()`; what is ours is
     * only walking the members so each failure carries that member's pointer.
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

        $extra     = array_diff_key($object, (array) $properties);
        $patterns  = isset($map['patternProperties']);
        $declared  = array_key_exists('additionalProperties', $map);
        $subSchema = $declared ? self::asMap($map['additionalProperties']) : null;

        foreach ($extra as $name => $member) {
            $name    = (string) $name;
            $pattern = $patterns ? rest_find_matching_pattern_property_schema($name, $map) : null;

            if ($pattern !== null) {
                $failures = array_merge($failures, self::check(
                    $member,
                    $pattern,
                    $pointer . '/' . self::escape($name)
                ));
                continue;
            }

            if ($declared && $map['additionalProperties'] === false) {
                $failures[] = self::failure(
                    $pointer . '/' . self::escape($name),
                    'unknown property - this tool declares no such argument'
                );
                continue;
            }

            if ($subSchema !== null) {
                $failures = array_merge($failures, self::check(
                    $member,
                    $map['additionalProperties'],
                    $pointer . '/' . self::escape($name)
                ));
            }
        }

        return $failures;
    }

    /**
     * Does $value satisfy the JSON Schema type $type? STRICTLY - this is addition 1.
     *
     * THE EMPTY ARRAY IS BOTH, and it has to be: `json_decode('{}', true)` and
     * `json_decode('[]', true)` are the same PHP value, so a validator that picked one
     * would refuse half of the legal inputs. The same ambiguity the serialization guard
     * exists to undo on the way out (see wpmcp_objectify_schema()), seen from the
     * inbound side.
     *
     * EVERY BRANCH IS A REFUSAL CORE WOULD NOT MAKE. `rest_is_integer("20")` is true,
     * `rest_is_array("a,b")` splits the string on commas, `rest_is_object("")` is true. Those
     * are right for a query string and wrong for a JSON body, and this function is the whole
     * difference. It has no default-true case for an unknown name because declaredType()
     * already refused one.
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
}
