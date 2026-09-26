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
 *      AN EMPTY `$param` IS NOT ENOUGH ON ITS OWN, which is what review 85 B2 measured over
 *      HTTPS: core interpolates the caller's PROPERTY NAME independently of `$param` when it
 *      recurses into an object branch of a combinator (rest-api.php:2467, relayed by :1909), and
 *      a 400-byte key carrying markup came back whole. So `relay()` is the other half of this
 *      addition and it covers every path rather than the one that was found: the combinator
 *      groups answer with a fixed sentence of ours, and every other relayed message is collapsed
 *      to ONE LINE - a newline would forge a failure line, since wpmcp_dispatch() joins the list
 *      with one - and capped at MAX_MESSAGE bytes.
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
 *   4. THIRD-PARTY TOOLS - AN UNENFORCEABLE KEYWORD IS STRIPPED, NOT REFUSED, AND SAID OUT LOUD.
 *      A tool added through the `wpmcp_tools` filter or a module is not in the catalog, so
 *      ToolContractTest never sees it and its author could declare a constraint nothing applies.
 *      `enforceable()` below removes it from what `wpmcp_tools()` publishes, and a
 *      `registry_strip` event names the tool and the keyword. ROUND 1 REFUSED THE WHOLE TOOL AND
 *      THAT WAS A MISFILED LEDGER ROW (review 85 S4): WordPress already decided how to treat a
 *      keyword it cannot validate, and it decided to strip - `rest_get_endpoint_args_for_schema()`
 *      (rest-api.php:3395-3426) at our floor, and WP 7.1's `wp_prepare_json_schema_for_client()`
 *      for the very context `tools/list` is. A decision is inherited; inheriting core's SILENCE
 *      is not part of it, which is what the event is for. Three things are removed: a keyword
 *      outside dialect(), a type-specific keyword whose node declares a `type` it does not apply
 *      to or no usable `type` at all, and an exclusive bound flag without its inclusive partner.
 *      Stripping changes no verdict - every keyword it removes is one core was ignoring anyway.
 *
 * NO KEYWORD IS PUBLISHED THAT DOES NOTHING, and that sentence is the sprint's actual invariant.
 * Round 1 stated it and did not hold it: `exclusiveMinimum` alone sat in dialect(), passed
 * registration, and core ignored it - a third-party tool RAN with `-5` against
 * `{"type":"integer","exclusiveMinimum":0}` over HTTPS (review 85 B1). Membership of DELEGATED says
 * the keyword is HANDED OVER, not that core applies it in the arrangement the schema wrote it in;
 * APPLIES_TO and EXCLUSIVE_NEEDS are what close that gap, and enforceable() is where they are
 * applied. `tools/list` publishes only constraints that are applied, and
 * tests/unit/ToolContractTest.php holds the catalog to the same rule by calling the same method.
 *
 * WHERE CORE'S COERCION STILL SURVIVES is inside `anyOf`/`oneOf`: core validates each branch
 * itself (rest-api.php:1993-2087), so a branch declaring `{"type":"integer"}` accepts `"20"` where
 * a top-level `"type":"integer"` would not. Handling the combinators here instead would be
 * re-implementing them, which is the overbuild this sprint exists to undo; no built-in schema uses
 * either (ToolContractTest), and a third-party tool that does now gets branch validation where it
 * previously got none. SchemaValidatorTest holds the limitation so it cannot drift unnoticed.
 *
 * THE OTHER FACE OF THAT COERCION WAS A FALSE REFUSAL, AND IT IS FIXED. `oneOf: [integer, boolean]`
 * refused the integer `1`, because `rest_is_boolean(1)` is true and core therefore counted two
 * matching branches (review 85 S2). A caller who sent a legal value being told it "matches more
 * than one of the expected formats" is worse than a missing constraint - there is nothing they can
 * do about it. So `oneOf` is asked of core as `anyOf`: a value valid under ANY branch is accepted.
 * See askCore().
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
     * AND GROUPING IS NOT WHAT MAKES A LONE `exclusiveMinimum` HARMLESS - a schema that declares
     * the flag WITHOUT its bound has nothing to group it with, and round 1 published exactly that
     * and enforced nothing (review 85 B1). The group is how the pair reaches core; enforceable()
     * is what refuses to publish the flag on its own. Both are needed and neither substitutes.
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
     * Longest RELAYED message, in bytes. Ours are fixed sentences; core's are not.
     *
     * The combinator groups answer with a fixed sentence of ours (see relay(), and addition 3 in the
     * file docblock for what they used to leak). This cap is what makes the promise true of EVERY
     * path, including the next core message that interpolates something we did not predict.
     */
    private const MAX_MESSAGE = 200;

    /**
     * Which declared `type` each DELEGATED type-specific keyword applies to, in CORE.
     *
     * THIS IS THE TABLE THAT MAKES "PERMITTED" MEAN "ENFORCED" (review 85 B1). Core dispatches on
     * `type` and then reads only that type's keywords (rest-api.php:2276-2299), so `format` beside
     * `type: integer` is read by nothing, and neither is `minItems` beside `type: string`. The
     * keyword is in the dialect, `tools/list` publishes it, and no value is ever measured against
     * it - the silent decoration this whole sprint exists to remove, one level out.
     *
     * A MISMATCH IS STRIPPED; A MISSING `type` IS NOT, and the difference is not laziness. With no
     * declared type askCore() sends the VALUE'S own type, so `{"minLength": 3}` is enforced for a
     * string and skipped for an integer - which is what JSON Schema says should happen, since
     * `minLength` only ever constrains strings. Core's type-gated dispatch and the specification's
     * type-gated semantics coincide there, so nothing is lost and there is nothing to strip.
     * NEEDS_TYPE below is the one place that coincidence breaks.
     *
     * ONLY DELEGATED KEYWORDS ARE IN HERE. `properties`, `required`, `additionalProperties`,
     * `patternProperties` and `items` are in OURS: checkObject() and check() apply them from the
     * SHAPE OF THE VALUE and never look at the declared type, so they are enforced with or without
     * one. Putting them in this table - which an earlier draft of this round did - would have
     * stripped `properties` off a third-party schema that declares no `type`, and
     * validateArguments() would then have refused every argument the tool has as undeclared. Caught
     * by reading, before it ran: no built-in omits `type: object`, so the catalog would not have
     * shown it.
     *
     * @var array<string, list<string>>
     */
    private const APPLIES_TO = array(
        'format'           => array('string'),
        'pattern'          => array('string'),
        'minLength'        => array('string'),
        'maxLength'        => array('string'),
        'minimum'          => array('number', 'integer'),
        'maximum'          => array('number', 'integer'),
        'exclusiveMinimum' => array('number', 'integer'),
        'exclusiveMaximum' => array('number', 'integer'),
        'multipleOf'       => array('number', 'integer'),
        'minItems'         => array('array'),
        'maxItems'         => array('array'),
        'uniqueItems'      => array('array'),
        'minProperties'    => array('object'),
        'maxProperties'    => array('object'),
    );

    /**
     * Keywords that must have a DECLARED `type` beside them, because the value's own type cannot be
     * trusted to supply it.
     *
     * THE EMPTY ARRAY IS THE WHOLE REASON, and review 85 S5 measured it. `json_decode('{}', true)`
     * and `json_decode('[]', true)` are the same PHP value, so typeName() has to pick one and picks
     * `object` - see matches(). On a node with no declared type askCore() therefore sends `object`
     * for `[]`, core's OBJECT validator runs, and `{"minItems": 1}` accepts the empty list: the one
     * value `minItems: 1` exists to refuse. Every other type group is safe without a declaration -
     * typeName() is exact for a string, a number, a boolean and null, and for `object` the ambiguity
     * points the generous way (`minProperties` on `[]` counts 0 and refuses correctly).
     *
     * So the array keywords need `type: array` written down, and a schema that leaves it out has
     * them stripped rather than published-and-skipped.
     *
     * @var list<string>
     */
    private const NEEDS_TYPE = array('minItems', 'maxItems', 'uniqueItems');

    /**
     * The inclusive bound each exclusive flag is USELESS WITHOUT.
     *
     * Core is draft-04 here: `exclusiveMinimum` is a BOOLEAN that modifies `minimum`, and every
     * bound branch is gated on `isset($args['minimum'])` / `isset($args['maximum'])`
     * (rest-api.php:2614, 2632, 2650). So the flag alone constrains nothing, and the JSON Schema
     * 2020-12 NUMERIC form - which is the dialect MCP's `inputSchema` is specified in - is worse
     * than nothing: `exclusiveMinimum: 0` reaches `! empty( 0 )`, which is false, so core reads it
     * as "inclusive" and accepts 0. Both forms are stripped rather than published; see
     * enforceable(). Translating the numeric form into core's pair was the alternative and was
     * rejected: `{"minimum": 1, "exclusiveMinimum": 5}` is two independent bounds in 2020-12 and
     * core cannot express both in one call, so translating means this class deciding what the pair
     * MEANS - a second dialect implementation, which is what this sprint deleted.
     *
     * @var array<string, string>
     */
    private const EXCLUSIVE_NEEDS = array(
        'exclusiveMinimum' => 'minimum',
        'exclusiveMaximum' => 'maximum',
    );

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
     * $schema with every keyword this server cannot enforce AS WRITTEN removed, and the list of
     * what was removed.
     *
     * WHY STRIP AND NOT REFUSE is item 4 of this file's docblock: WordPress decided how to treat a
     * keyword it cannot validate, twice, and decided to strip. Round 1 refused the whole tool, which
     * was a divergence with no argument behind it (review 85 S4). What strips fixes is the actual
     * defect - this server PUBLISHED a constraint and applied nothing - and it costs a third party
     * nothing but the keyword that was already doing nothing. `wpmcp_tools()` fires a
     * `registry_strip` event naming what went, because inheriting core's behaviour is not the same
     * as inheriting core's silence.
     *
     * THREE REASONS A KEYWORD IS REMOVED:
     *   1. it is not in dialect() at all - `$schema`, `$ref`, `allOf`, `not`, `const`, a typo;
     *   2. it is type-specific and the node declares a `type` it does not apply to, so core's type
     *      dispatch never reaches it - see APPLIES_TO - or it is one of the ARRAY keywords on a node
     *      with no declared `type` at all, where the empty array's ambiguity makes the value's own
     *      type the wrong answer - see NEEDS_TYPE;
     *   3. it is an exclusive bound flag without its inclusive partner, or written in 2020-12's
     *      numeric form that core misreads - see EXCLUSIVE_NEEDS.
     *
     * THE STRIPPED SCHEMA IS STILL VALID AGAINST THE SAME VALUES. Nothing removed here was being
     * applied, so no call that used to pass now fails and none that used to fail now passes. What
     * changes is only what this server CLAIMS, which was the defect.
     *
     * NO DEPTH CAP, for the same reason check() has none: a schema is the SITE'S code, not the
     * caller's input, so a hostile depth is a hostile plugin and this walk is not what would stop
     * it. The walk covers exactly the places a sub-schema can sit, and it is the ONLY such walk in
     * the plugin - tests/unit/ToolContractTest.php holds the catalog with this method rather than a
     * second walker of its own, because two walks agree by luck (review 85 S3).
     *
     * @param mixed  $schema
     * @param string $pointer where this node sits, for the report
     * @return array{0: mixed, 1: list<string>} [the enforceable schema, '<pointer>/<keyword>' each]
     */
    public static function enforceable($schema, string $pointer = ''): array
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return array($schema, array());
        }

        $dialect = self::dialect();
        $type    = isset($map['type']) && is_string($map['type']) && in_array($map['type'], self::TYPES, true)
            ? $map['type']
            : null;
        $removed = array();

        foreach (array_keys($map) as $key) {
            $keyword = (string) $key;

            if (!in_array($keyword, $dialect, true)) {
                $removed[] = $keyword;
                continue;
            }
            if (isset(self::APPLIES_TO[$keyword]) && $type !== null
                && !in_array($type, self::APPLIES_TO[$keyword], true)) {
                $removed[] = $keyword;
                continue;
            }
            if ($type === null && in_array($keyword, self::NEEDS_TYPE, true)) {
                $removed[] = $keyword;
                continue;
            }
            if (isset(self::EXCLUSIVE_NEEDS[$keyword])
                && (!is_bool($map[$keyword]) || !isset($map[self::EXCLUSIVE_NEEDS[$keyword]]))) {
                $removed[] = $keyword;
            }
        }

        $report = array();

        foreach ($removed as $keyword) {
            unset($map[$keyword]);
            $report[] = $pointer . '/' . $keyword;
        }

        // THE DESCENT, over every place a sub-schema can sit. A `false` or a null is not a map and
        // asMap() stops there, which is how `additionalProperties: false` survives untouched.
        foreach (array('properties', 'patternProperties') as $holder) {
            foreach ((array) self::asMap($map[$holder] ?? null) as $name => $sub) {
                [$map[$holder][$name], $found] = self::enforceable(
                    $sub,
                    $pointer . '/' . $holder . '/' . $name
                );
                $report = array_merge($report, $found);
            }
        }

        foreach (array('anyOf', 'oneOf') as $holder) {
            foreach (is_array($map[$holder] ?? null) ? array_keys($map[$holder]) : array() as $index) {
                [$map[$holder][$index], $found] = self::enforceable(
                    $map[$holder][$index],
                    $pointer . '/' . $holder . '/' . $index
                );
                $report = array_merge($report, $found);
            }
        }

        foreach (array('items', 'additionalProperties') as $holder) {
            if (isset($map[$holder])) {
                [$map[$holder], $found] = self::enforceable($map[$holder], $pointer . '/' . $holder);
                $report = array_merge($report, $found);
            }
        }

        return array($map, $report);
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
     * duplicate the prefix failure() already writes. It is NOT sufficient on its own: core
     * interpolates the caller's property name independently of `$param`, which is what relay()
     * exists for.
     *
     * `oneOf` IS ASKED AS `anyOf`, AND THAT IS A DELIBERATE DIVERGENCE (review 85 S2). Core decides
     * "exactly one branch matches" using its own coercive per-branch type checks, so
     * `oneOf: [integer, boolean]` REFUSES the integer `1` - `rest_is_boolean(1)` is true
     * (rest-api.php:1556-1577), two branches match, and a caller who sent a perfectly legal value
     * is told it "matches more than one of the expected formats". A false refusal is worse than a
     * missing constraint: the caller did nothing wrong and has no way to comply. Under a coercive
     * matcher the exactly-one COUNT is a property of core's coercions rather than of the value, so
     * it is not a constraint worth enforcing; branch membership is. So a value valid under any
     * branch is accepted, `oneOf` constrains what `anyOf` constrains, and the docblock, the
     * CHANGELOG and a test per multi-branch combination say so.
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

            if ($group === array('oneOf')) {
                $present = array('anyOf' => $present['oneOf']);
            }

            $verdict = rest_validate_value_from_schema($value, array('type' => $type) + $present, '');

            if (is_wp_error($verdict)) {
                $failures[] = self::failure($pointer, self::relay($verdict, $group));
            }
        }

        return $failures;
    }

    /**
     * Core's verdict as one line of ours: bounded, single-line, and carrying no caller bytes.
     *
     * THE COMBINATOR GROUPS ANSWER WITH A FIXED SENTENCE - addition 3 in this file's docblock has the
     * measurement and the core line numbers. Discarding core's text is the only fix that keeps the
     * promise exactly rather than approximately, and it costs little: core's inner pointers use its
     * own `param[key]` notation and stop at the first branch failure, so they were never a pointer a
     * caller could act on.
     *
     * AND EVERY OTHER MESSAGE IS COLLAPSED AND CAPPED, which is two separate guarantees:
     *
     *   ONE LINE, because wpmcp_dispatch() joins the failure list with a newline. A newline inside a
     *   message would FORGE a failure line - a caller could make the refusal appear to say
     *   `/id: required property is missing` - so every run of whitespace becomes one space. That is
     *   structural, not cosmetic, and tests/unit/SchemaValidatorTest.php asserts it.
     *
     *   BOUNDED, so no message can carry an unbounded number of bytes back however core builds it.
     *   Cut with mb_strcut for escape()'s reason: a byte cut can split a character and leave invalid
     *   UTF-8, which json_encode refuses the whole document on.
     *
     * MARKUP IS NOT ESCAPED, deliberately. After the two rules above the only bytes a relayed
     * message can carry are the SCHEMA AUTHOR'S own - a `pattern`, an `enum` value, a bound - and a
     * pattern legitimately contains `<`, `>` and `&`. Entity-encoding them would corrupt the one
     * sentence the message exists to say, in order to guard against a client that renders an MCP
     * tool error as HTML. What this class keeps out is the CALLER'S bytes, and they are out.
     *
     * @param \WP_Error    $verdict
     * @param list<string> $group the delegated group that produced it
     */
    private static function relay($verdict, array $group): string
    {
        if ($group === array('anyOf') || $group === array('oneOf')) {
            return 'does not match any of the shapes this argument permits';
        }

        $message = (string) $verdict->get_error_message();
        // NO /u MODIFIER, ON PURPOSE. preg_replace with /u returns NULL on a subject that is not
        // valid UTF-8, and `(string) null` is '' - so a message carrying a bad byte would VANISH
        // instead of being collapsed, which is the exact failure sprint CORE-FIX found in
        // json_encode(). `\s` without /u is ASCII, and the bytes that can forge a failure line are
        // ASCII: line feed, carriage return and tab. The `?? $message` is a second belt
        // on the same buckle.
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (strlen($message) > self::MAX_MESSAGE) {
            $message = (function_exists('mb_strcut')
                ? (string) mb_strcut($message, 0, self::MAX_MESSAGE, 'UTF-8')
                : substr($message, 0, self::MAX_MESSAGE)) . '...';
        }

        return $message;
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
