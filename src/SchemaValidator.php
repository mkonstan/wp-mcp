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
 *   4. THIRD-PARTY TOOLS. A tool added through the `wpmcp_tools` filter or a module is in no
 *      catalog, so tests/unit/ToolContractTest.php never sees it and its author can declare a
 *      constraint nothing applies. Two answers, and which applies turns on one question - would
 *      REMOVING the keyword change what core is asked?
 *        NO  -> it is left out of what `tools/list` publishes and a `registry_strip` event names it;
 *               the tool registers and runs. publishable() does this, for a keyword outside dialect()
 *               and for a `required` that is not an array. Leaving it out rather than refusing the
 *               tool is WordPress's own decision, taken twice - `rest_get_endpoint_args_for_schema()`
 *               (rest-api.php:3395-3426) at our floor and WP 7.1's
 *               `wp_prepare_json_schema_for_client()` (json-schema.php:90-220) for the very context
 *               `tools/list` is - so it is inherited.
 *        YES -> the entry does not register, reason `schema_constraint_unreadable`.
 *               unreadableConstraint() does this, for an exclusive bound flag core reads as something
 *               other than what it says and for `enum: []`. Removing those would LOOSEN validation.
 *
 * THAT LINE IS THE WHOLE OF ROUND 3, and it is drawn there because round 2 drew it elsewhere and made
 * validation WEAKER than round 1. Round 2 reduced the schema in `wpmcp_tools()`, whose output is also
 * what `wpmcp_dispatch()` validates against, and reduced it by tables encoding which `type` core reads
 * each keyword under. The tables were wrong toward PERMISSIVE within one round of being written, so
 * nine arrangements went from refused to running - `{"minItems": 2}` with `[1]` over HTTPS among them
 * (review 85 R2-B1). Two rules came out of it, both absolute here:
 *
 *   VALIDATION SEES THE SCHEMA AS WRITTEN, never a reduced copy. publishable() is called from the
 *   `tools/list` emitter and nowhere else.
 *   NOTHING IS REMOVED WHOSE REMOVAL COULD CHANGE WHAT CORE IS ASKED - no type-gated rule, no
 *   partner-gated rule, no guess about core's dispatch.
 *   tests/unit/SchemaValidatorTest::testPublicationNeverChangesWhatCoreIsAsked() holds the second
 *   directly, over the nine arrangements that broke.
 *
 * WHAT IS PUBLISHED AND VACUOUS, IN ONE SENTENCE, because the trade was accepted on condition it is
 * stated rather than implied: a keyword written beside a `type` it does not apply to - `format` or
 * `minLength` on an integer, `minItems` on a string, `minProperties` on an array - is published
 * unchanged and constrains nothing, and this file no longer guesses which of those core reads. That is
 * not the false claim round 1's B1 was: JSON Schema itself defines each keyword for one type and says
 * it has no effect on others, so a conforming client reading `{"type":"integer","format":"email"}`
 * already knows `format` does nothing there - and core publishes it the same way
 * (`rest_get_endpoint_args_for_schema()` copies it, rest-api.php:3423-3426). The tables that tried to
 * remove this class were wrong toward permissive twice; see item 4.
 *
 * WHERE CORE'S COERCION SURVIVES is inside `anyOf`: core validates each branch itself
 * (rest-api.php:1993-2010) with its own coercive type checks, so a branch declaring
 * `{"type":"integer"}` accepts `"20"` where a top-level `"type":"integer"` refuses it. Handling the
 * combinator here would be re-implementing it. No built-in uses `anyOf` (ToolContractTest), and a
 * third-party tool that does now gets branch validation where it previously got none.
 *
 * `oneOf` IS THE ONE KEYWORD OF CORE'S TWENTY-FIVE THIS SERVER DECLINES, so twelve of the thirteen
 * core validated and this file ignored are delivered and the thirteenth is a documented
 * non-delivery. Both ways of honouring it are wrong: core's exactly-one count runs over the coercive
 * branch matching above, so `[integer, boolean]` refuses the integer `1` - a false refusal the caller
 * cannot comply with - and enforcing `anyOf` while publishing `oneOf`, which round 2 shipped, tells a
 * client "exactly one" and delivers "at least one" (measured: `5` against
 * `[{integer,minimum:0},{integer,maximum:10}]`, accepted there, refused by core's own `oneOf`). So
 * nothing enforces it and nothing claims it. See DELEGATED.
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
        // AND NOT `oneOf`, WHICH THIS SERVER DECLINES - the one keyword of core's twenty-five left
        // deliberately outside the dialect, so nothing enforces it and publishable() leaves it out of
        // what is published. The file docblock has the two measurements that rule out both
        // alternatives; the short version is that core's exactly-one count runs over coercive branch
        // matching and refuses legal values, and enforcing `anyOf` while publishing `oneOf` is a false
        // claim. `anyOf` has no such count and is enforced.
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
     * Every keyword a schema in this plugin may use: core's twenty-five, minus `oneOf`, plus
     * `required`.
     *
     * DERIVED, NOT LISTED, AND THAT IS THE POINT. This used to be a hand-written list beside the
     * implementation, so a keyword could sit in it while nothing checked it - and to
     * tests/unit/ToolContractTest.php, which reads this, an unenforced keyword in this set reads as
     * "enforced, so the constraint is real". Composing it from the three sets above makes that hole
     * impossible to write rather than something a test has to catch: a keyword is here exactly when
     * it is ours, delegated, or declared annotation-only. `oneOf` is absent because it is in none of
     * the three - see DELEGATED for why it is declined rather than mis-enforced.
     *
     * A METHOD RATHER THAN A CONST because DELEGATED is a list of lists and flattening it is not a
     * constant expression. `SchemaValidator::KEYWORDS` is gone; callers ask this.
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
     * `required` must be an ARRAY - the only keyword whose own value decides whether any code in this
     * file can read it at all.
     *
     * WHERE ROUND 2'S TABLES WERE. `APPLIES_TO` and `NEEDS_TYPE` encoded which `type` core reads each
     * keyword under; both were wrong toward permissive within one round and both are deleted (item 4 of
     * the file docblock has the measurements). This is a different question - not "would core read this
     * for this type", which is core's business and moves with core, but "can the line in THIS file that
     * enforces it read the value", which is answerable by reading that line.
     *
     * checkObject() reads `required` through `isset(...) && is_array(...)`, so `required: true` does
     * nothing here whatever core would do - and that covers draft-03's per-property spelling, where the
     * boolean sits inside the property's own sub-schema. Core WOULD enforce that form
     * (rest-api.php:2432-2440) and this validator does not, because `required` is OURS and is never
     * handed over: published and unenforced, and therefore not published (review 85 R2-S2).
     *
     * `enum: []` is NOT here, deliberately - removing it would change what core is asked, so it refuses
     * instead. See unreadableConstraint().
     */
    private const REQUIRED_MUST_BE_ARRAY = 'required';

    /**
     * The inclusive bound each exclusive flag is read against, in CORE.
     *
     * Core is draft-04 here: the flag MODIFIES `minimum`/`maximum`, and every bound branch is gated on
     * `isset($args['minimum'])` / `isset($args['maximum'])` (rest-api.php:2614, 2632, 2650) - so with no
     * partner, nothing reads it. That is round 1's B1, where a probe tool RAN with `-5`.
     *
     * WITH A PARTNER PRESENT CORE DOES READ IT, WHATEVER ITS PHP TYPE, and round 2 got this wrong in the
     * permissive direction: the gate is `! empty( $args['exclusiveMinimum'] )` (:2615), so `5`, `1` and
     * even the string `"true"` are read as the draft-04 boolean and enforced as `> minimum`. Round 2
     * removed them and `{"type":"integer","minimum":0,"exclusiveMinimum":5}` with `0` went from REFUSED
     * to RUN. So a BOOLEAN flag beside its partner is core's own spelling and is left entirely alone;
     * every other form refuses registration, because removing it would change core's verdict and
     * publishing it would claim a bound core is not applying. See unreadableConstraint().
     *
     * @var array<string, string>
     */
    private const EXCLUSIVE_NEEDS = array(
        'exclusiveMinimum' => 'minimum',
        'exclusiveMaximum' => 'maximum',
    );

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
     * $schema as it may be PUBLISHED - every keyword nothing can read left out - and what went.
     *
     * THIS OUTPUT IS NEVER VALIDATED AGAINST. It is called from the `tools/list` emitter and nowhere
     * else; `wpmcp_tools()` keeps the schema as written and `wpmcp_dispatch()` validates against that.
     * Round 2 applied the reduced copy in `wpmcp_tools()` and nine arrangements went from refused to
     * running - see item 4 of the file docblock.
     *
     * TWO REASONS A KEYWORD IS LEFT OUT, and neither can change what core is asked:
     *   1. outside dialect() - `$schema`, `$ref`, `allOf`, `not`, `const`, a typo, `oneOf`. Nothing
     *      here delegates it and core never reads it either.
     *   2. a `required` that is not an array - see REQUIRED_MUST_BE_ARRAY. `required` is OURS and is
     *      never handed to core, so removing it cannot alter a delegated call.
     * Nothing type-gated, nothing partner-gated.
     * tests/unit/SchemaValidatorTest::testPublicationNeverChangesWhatCoreIsAsked() holds that.
     *
     * NO DEPTH CAP, for the same reason check() has none: a schema is the SITE'S code, not the
     * caller's input. The holders are holders() - one list, shared with unreadableConstraint(), so
     * there is no second walker to drift (review 85 S3), and tests/unit/ToolContractTest.php holds the
     * catalog with this method rather than a walker of its own.
     *
     * @param mixed  $schema
     * @param string $pointer where this node sits, for the report
     * @return array{0: mixed, 1: list<string>} [the publishable schema, '<pointer>/<keyword>' each]
     */
    public static function publishable($schema, string $pointer = ''): array
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return array($schema, array());
        }

        $dialect = self::dialect();
        $removed = array();

        foreach (array_keys($map) as $key) {
            $keyword = (string) $key;

            if (!in_array($keyword, $dialect, true)) {
                $removed[] = $keyword;
                continue;
            }
            if ($keyword === self::REQUIRED_MUST_BE_ARRAY && !is_array($map[$keyword])) {
                $removed[] = $keyword;
            }
        }

        $report = array();

        foreach ($removed as $keyword) {
            unset($map[$keyword]);
            $report[] = $pointer . '/' . $keyword;
        }

        foreach (self::holders($map) as [$holder, $key, $sub]) {
            [$reduced, $found] = self::publishable(
                $sub,
                $pointer . '/' . $holder . ($key === null ? '' : '/' . $key)
            );

            // NOTHING REMOVED BELOW, NOTHING TO WRITE BACK. Two things depend on this: a schema with
            // no complaint against it comes back assertSame-identical, which
            // tests/unit/ToolContractTest.php requires of the catalog; and the write-back below - the
            // only line here that can fail - is never reached on a schema it had no business editing.
            if ($found === array()) {
                continue;
            }

            $report = array_merge($report, $found);

            if ($key === null) {
                $map[$holder] = $reduced;
                continue;
            }

            // AN OBJECT HOLDER IS NORMALISED BEFORE THE KEYED WRITE, and review 85 R3-B1 is the whole
            // reason: `properties` may legally be a `stdClass` - asMap()'s docblock says a filter-added
            // tool "still may" write it that way, and site-info used to - so holders() hands one over
            // and `$map[$holder][$key] =` threw "Cannot use object of type stdClass as array". Because
            // this runs in the tools/list emitter, ONE such third-party tool turned the whole site's
            // tools/list into -32603 for every client while tools/call kept working: a client that
            // already knew a tool's name was fine and every client that discovers tools on connect saw
            // none. VERIFIED over HTTPS by the reviewer. The validator itself never had the bug -
            // checkObject() casts - so it is purely this publication path, added in round 2 and moved
            // here in round 3 without being fixed either time.
            //
            // The cast is wire-safe: a non-empty `properties` with string keys encodes as a JSON object
            // either way, and wpmcp_objectify_schema() is what restores the EMPTY one on the way out.
            if (is_object($map[$holder])) {
                $map[$holder] = (array) $map[$holder];
            }

            $map[$holder][$key] = $reduced;
        }

        return array($map, $report);
    }

    /**
     * The first constraint in $schema that CORE READS DIFFERENTLY FROM WHAT IT SAYS, or null.
     *
     * A REFUSAL AND NOT A STRIP, and the difference is round 2's blocker. Removing these would change
     * the verdict core is already giving; publishing them claims something core is not applying.
     * Neither is honest, so the entry does not register and the reason names the spelling core reads.
     *
     *   AN EXCLUSIVE FLAG WITH NO PARTNER - nothing reads it (rest-api.php:2614).
     *   AN EXCLUSIVE FLAG THAT IS NOT A BOOLEAN - core's `! empty()` gate (:2615) makes
     *   `exclusiveMinimum: 5` mean "the bound in `minimum` is exclusive" and `exclusiveMinimum: 0`
     *   mean INCLUSIVE, the opposite of the 2020-12 spelling an `inputSchema` is written in.
     *   `enum: []` - JSON Schema admits no value, core's `! empty( $args['enum'] )` (:2306) admits
     *   every value, and removing it would stop the `enum` group being sent at all.
     *
     * @param mixed $schema
     */
    public static function unreadableConstraint($schema, string $pointer = ''): ?string
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return null;
        }

        if (array_key_exists('enum', $map) && (!is_array($map['enum']) || $map['enum'] === array())) {
            return $pointer . '/enum';
        }

        foreach (self::EXCLUSIVE_NEEDS as $flag => $bound) {
            // array_key_exists for the FLAG and isset for the PARTNER, and the asymmetry is core's:
            // `exclusiveMinimum: null` is declared but unreadable (`! empty( null )` is false), while
            // `minimum: null` is not a bound at all because core's own gate is `isset()`.
            if (!array_key_exists($flag, $map)) {
                continue;
            }
            if (!is_bool($map[$flag]) || !isset($map[$bound])) {
                return $pointer . '/' . $flag;
            }
        }

        foreach (self::holders($map) as [$holder, $key, $sub]) {
            $found = self::unreadableConstraint(
                $sub,
                $pointer . '/' . $holder . ($key === null ? '' : '/' . $key)
            );

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Every sub-schema of one node, as [holder keyword, key inside it or null, the sub-schema].
     *
     * ONE HOLDER LIST, SHARED BY BOTH WALKS, because the holders are a fact about JSON Schema and two
     * copies of it drift - which is exactly what review 85 S3 found between the registry's walk and
     * ToolContractTest's. A `false` or a null is not a map and asMap() stops at it, so
     * `additionalProperties: false` passes through untouched.
     *
     * STRUCTURED RATHER THAN A PATH STRING, because a property NAME may contain a slash - it is a
     * caller-facing argument name, not an identifier - so a "/properties/<name>" key could not be
     * split back into its parts to write the sub-schema home again.
     *
     * `oneOf` IS IN THE LIST although the dialect does not accept it, and that matters only for
     * unreadableConstraint(), which walks a schema it has not reduced: a bad bound inside a `oneOf`
     * branch is still found and still refuses. publishable() removes `oneOf` at the node BEFORE it
     * asks for holders, so its branches are not reported one by one - there is nothing left under a
     * keyword that is gone.
     *
     * @param array<string, mixed> $map
     * @return list<array{0: string, 1: string|int|null, 2: mixed}>
     */
    private static function holders(array $map): array
    {
        $found = array();

        foreach (array('properties', 'patternProperties') as $holder) {
            foreach ((array) self::asMap($map[$holder] ?? null) as $name => $sub) {
                $found[] = array($holder, $name, $sub);
            }
        }

        foreach (array('anyOf', 'oneOf') as $holder) {
            foreach (is_array($map[$holder] ?? null) ? $map[$holder] : array() as $index => $sub) {
                $found[] = array($holder, $index, $sub);
            }
        }

        foreach (array('items', 'additionalProperties') as $holder) {
            if (isset($map[$holder])) {
                $found[] = array($holder, null, $map[$holder]);
            }
        }

        return $found;
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

        // THE EMPTY ARRAY IS ASKED UNDER BOTH READINGS, and that closes review 85's S5 by delegating
        // harder rather than by implementing anything. `json_decode('{}', true)` and
        // `json_decode('[]', true)` are the same PHP value, so a node with no declared `type` had to
        // pick one for askCore() and typeName() picks `object` - which meant `{"minItems": 1}` accepted
        // `[]`, the one value that keyword exists to refuse. MEASURED: core refuses it perfectly well
        // when told `type: array` (`p must contain at least 1 item.`) and ignores `minItems` entirely
        // under `type: object`, so nothing was missing from core - only from what we told it. `[]`
        // satisfies both types by matches()'s own doctrine, so both are asked and a failure under
        // either is a failure. askCore() drops a duplicate line, because a keyword that fails under
        // both readings - `enum` does, measured - must still report once.
        $types = $declared !== null
            ? array($declared)
            : ($value === array() ? array('object', 'array') : array(self::typeName($value)));

        $failures = self::askCore($value, $map, $types, $pointer);

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
     * A TYPE IS ALWAYS SENT AND IS ALWAYS ONE OF TYPES, which is not a nicety: core reads
     * `$args['type']` unconditionally three lines after warning about its absence
     * (rest-api.php:2245-2251), so a schema without one produces an "Undefined array key"
     * warning AND a `_doing_it_wrong()` notice - and this suite fails on either. When the node
     * declares no usable type the value's OWN type is sent, which makes core's type check a
     * tautology and leaves the group's keyword as the only thing being asked.
     *
     * $types HOLDS MORE THAN ONE ONLY FOR THE EMPTY ARRAY, which is both an object and an array in
     * PHP - see check(), where the list is built, for what that cost before. Each group is then asked
     * once per reading and a line repeated across readings is reported once.
     *
     * THE `$param` IS EMPTY ON PURPOSE - see addition 3 in the file docblock. Core interpolates
     * it into every message, and the only name we have for this node is a pointer built from
     * caller-supplied keys. Sending it would put an untruncated, unescaped key in the reply and
     * duplicate the prefix failure() already writes. It is NOT sufficient on its own: core
     * interpolates the caller's property name independently of `$param`, which is what relay()
     * exists for.
     *
     * @param array<string, mixed> $map
     * @return list<string>
     */
    private static function askCore($value, array $map, array $types, string $pointer): array
    {
        $failures = array();

        foreach (self::DELEGATED as $group) {
            $present = array_intersect_key($map, array_flip($group));

            if ($present === array()) {
                continue;
            }

            $lines = array();

            foreach ($types as $type) {
                $verdict = rest_validate_value_from_schema($value, array('type' => $type) + $present, '');

                if (is_wp_error($verdict)) {
                    $lines[] = self::failure($pointer, self::relay($verdict, $group));
                }
            }

            // UNIQUE WITHIN THE GROUP AND NOT ACROSS THE NODE: two readings of one keyword must not
            // report twice, and two different groups that happened to word a failure identically must.
            foreach (array_unique($lines) as $line) {
                $failures[] = $line;
            }
        }

        return $failures;
    }

    /**
     * Core's verdict as one line of ours: bounded, single-line, and carrying no caller bytes.
     *
     * `anyOf` ANSWERS WITH A FIXED SENTENCE - addition 3 in the file docblock has the measurement and
     * the core line numbers. Discarding core's text is the only fix that keeps the promise exactly
     * rather than approximately, and it costs little: core's inner pointers use its own `param[key]`
     * notation and stop at the first branch failure, so they were never a pointer a caller could act
     * on.
     *
     * EVERY OTHER MESSAGE IS COLLAPSED AND CAPPED - two separate guarantees. ONE LINE, because
     * wpmcp_dispatch() joins the failure list with a newline, so a newline inside a message would
     * FORGE a failure line reading like a complaint about a different argument; structural, not
     * cosmetic. BOUNDED at MAX_MESSAGE, cut with mb_strcut for escape()'s reason - a byte cut can
     * split a character and json_encode refuses the whole document on invalid UTF-8.
     *
     * MARKUP IS NOT ESCAPED, deliberately. After those two rules the only bytes a relayed message can
     * carry are the SCHEMA AUTHOR'S - a `pattern`, an `enum` value, a bound - and a pattern
     * legitimately contains `<`, `>` and `&`. Entity-encoding them would corrupt the one sentence the
     * message exists to say, to guard against a client that renders a tool error as HTML. What this
     * class keeps out is the CALLER'S bytes, and they are out.
     *
     * @param \WP_Error    $verdict
     * @param list<string> $group the delegated group that produced it
     */
    private static function relay($verdict, array $group): string
    {
        if ($group === array('anyOf')) {
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
