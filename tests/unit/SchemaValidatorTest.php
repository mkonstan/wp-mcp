<?php
/**
 * The input validator, keyword by keyword, positive and negative.
 *
 * WHY EVERY KEYWORD GETS BOTH DIRECTIONS. A validator that rejects everything passes
 * every negative test, and a validator that rejects nothing passes every positive one.
 * Neither failure is visible from one side, and "wrong-but-plausible when untested" is
 * precisely what the build plan puts in this tier.
 *
 * THE THREE CLAIMS THAT ARE THE SPRINT, rather than JSON Schema trivia:
 *
 *   `"20"` IS NOT 20. The tools cast - `(int) $a['limit']` - and a cast cannot fail:
 *   `(int) "twenty"` is 0, `(int) "5 posts"` is 5. The boundary is the last place the
 *   caller's mistake is still distinguishable from a legitimate value.
 *
 *   AN UNDECLARED ARGUMENT KEY IS REFUSED. `additionalProperties: false` is the default
 *   at a tool's top level, so a misspelled `limit` is an error instead of a silent
 *   default - Max's explicit-scope rule, applied to argument keys.
 *
 *   THE FAILURE NAMES ITS PLACE. `/limit: expected integer, got string` is what an agent
 *   can act on; "invalid arguments" is not. Nesting is asserted for the same reason: a
 *   pointer that stops at the top level is no better than no pointer.
 *
 * @group sprint-5
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\SchemaValidator;
use WpMcp\Tests\Support\CoreSchemaKeywords;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class SchemaValidatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Nothing requires src/SchemaValidator.php by path; the plugin's own
        // spl_autoload_register is what finds it, so this also proves that still works.
        WordPressStubs::loadPlugin();
    }

    /**
     * THE RUNTIME STUBS, AND THIS CLASS OWES THEM TO ITSELF (sprint CORE-FIX round 2, review 81 B2).
     *
     * `SchemaValidator` called no WordPress function at all until this sprint made `asList()` use
     * `wp_json_encode()`, which lives in the RUNTIME stub set. Without this line the class ERRORS
     * when run alone - `Call to undefined function WpMcp\wp_json_encode()` - and is green inside
     * `--testsuite unit` only because `AcfCoreFixTest` sorts first and installs the stubs into the
     * process before this class runs. So the tier's 303 green was true for ONE ORDERING, and the
     * one class that exists to hold this file was the class that could not prove it.
     *
     * tests/bootstrap.php states the rule this breaks: stubs are a per-test-case concern, because
     * defining them globally "would silently mask a future load-time dependency on a real WordPress
     * function - exactly the drift this harness exists to catch". A sibling class masked it instead,
     * which is the same failure with an extra step. Asking for the stubs HERE is the per-test-case
     * answer, not a widening of the global set.
     */
    protected function setUp(): void
    {
        parent::setUp();

        WordPressRuntime::install();
    }

    /**
     * `type`, over every type name the validator claims to know, in both directions.
     *
     * The table is the contract: one row per (type, value) pair that must pass and one
     * per pair that must not. A type added to SchemaValidator::TYPES without a row here
     * is caught by testEveryDeclaredTypeIsExercised() below.
     *
     * @dataProvider typeCases
     * @group sprint-5
     */
    public function testTypeIsCheckedWithoutCoercion(string $type, $value, bool $valid): void
    {
        $failures = SchemaValidator::validate($value, ['type' => $type]);

        if ($valid) {
            self::assertSame(
                [],
                $failures,
                "A legitimate {$type} was refused: " . var_export($value, true)
                . ' -> ' . implode(' | ', $failures)
            );

            return;
        }

        self::assertCount(
            1,
            $failures,
            'Expected exactly one type failure for ' . var_export($value, true)
            . " against {$type}, got: " . implode(' | ', $failures)
        );
        self::assertStringContainsString(
            'expected ' . $type . ', got ',
            $failures[0],
            'The failure must name both what was wanted and what arrived: ' . $failures[0]
        );
    }

    /** @return array<string, array{0: string, 1: mixed, 2: bool}> */
    public static function typeCases(): array
    {
        return [
            'string accepts a string'        => ['string', 'twenty', true],
            'string refuses an integer'      => ['string', 20, false],
            'string refuses null'            => ['string', null, false],

            // THE ONE THIS SPRINT IS ABOUT.
            'integer accepts an integer'     => ['integer', 20, true],
            'integer refuses a numeric string' => ['integer', '20', false],
            'integer refuses a float'        => ['integer', 20.5, false],
            'integer refuses a whole float'  => ['integer', 20.0, false],
            'integer refuses a boolean'      => ['integer', true, false],

            'number accepts an integer'      => ['number', 20, true],
            'number accepts a float'         => ['number', 20.5, true],
            'number refuses a numeric string' => ['number', '20.5', false],

            'boolean accepts true'           => ['boolean', true, true],
            'boolean accepts false'          => ['boolean', false, true],
            'boolean refuses the string'     => ['boolean', 'true', false],
            'boolean refuses 1'              => ['boolean', 1, false],

            'null accepts null'              => ['null', null, true],
            'null refuses the empty string'  => ['null', '', false],

            'object accepts a map'           => ['object', ['a' => 1], true],
            // json_decode('{}') and json_decode('[]') are the same PHP value, so the
            // empty array has to satisfy both - see matches().
            'object accepts the empty array' => ['object', [], true],
            'object refuses a list'          => ['object', [1, 2], false],
            'object refuses a string'        => ['object', 'x', false],

            'array accepts a list'           => ['array', [1, 2], true],
            'array accepts the empty array'  => ['array', [], true],
            'array refuses a map'            => ['array', ['a' => 1], false],
        ];
    }

    /**
     * Every type in SchemaValidator::TYPES appears in the table above, in both
     * directions.
     *
     * Without this, adding `integer` to TYPES and forgetting to implement it would leave
     * the dialect claiming a keyword nothing tests.
     *
     * @group sprint-5
     */
    public function testEveryDeclaredTypeIsExercised(): void
    {
        $accepted = [];
        $refused  = [];

        foreach (self::typeCases() as [$type, $value, $valid]) {
            if ($valid) {
                $accepted[$type] = true;
            } else {
                $refused[$type] = true;
            }
        }

        foreach (SchemaValidator::TYPES as $type) {
            self::assertArrayHasKey(
                $type,
                $accepted,
                "SchemaValidator::TYPES declares '{$type}' but typeCases() has no value"
                . ' that must be ACCEPTED for it.'
            );
            self::assertArrayHasKey(
                $type,
                $refused,
                "SchemaValidator::TYPES declares '{$type}' but typeCases() has no value"
                . ' that must be REFUSED for it. A type checked in one direction only is'
                . ' a type that could be accepting everything.'
            );
        }
    }

    /**
     * `required`: the pointer names the MISSING member, not the object it is missing from.
     *
     * @group sprint-5
     */
    public function testRequiredNamesTheMissingMember(): void
    {
        $schema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];

        self::assertSame([], SchemaValidator::validate(['id' => 7], $schema));

        $failures = SchemaValidator::validate([], $schema);

        self::assertSame(
            ['/id: required property is missing'],
            $failures,
            'A missing required member must be reported at its own pointer: ' . implode(' | ', $failures)
        );
    }

    /**
     * `additionalProperties: false` refuses a key nobody declared - and
     * validateArguments() applies it by DEFAULT, which is the whole explicit-scope rule.
     *
     * Both halves matter. The first is the keyword; the second is that a schema which
     * says nothing about additional properties - which is all 20 of them - still refuses
     * an undeclared key.
     *
     * @group sprint-5
     */
    public function testAnUndeclaredArgumentKeyIsRefusedByDefault(): void
    {
        $schema = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]];

        // validate() alone: the schema says nothing, so an extra key is permitted.
        self::assertSame(
            [],
            SchemaValidator::validate(['limit' => 5, 'lmit' => 5], $schema),
            'validate() must NOT invent additionalProperties: false. The default belongs'
            . ' to validateArguments(), where the decision is about a tool call.'
        );

        // validateArguments(): the same call, refused.
        $failures = SchemaValidator::validateArguments(['limit' => 5, 'lmit' => 5], $schema);

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertStringStartsWith(
            '/lmit: unknown property',
            $failures[0],
            'A misspelled argument key must be refused and NAMED, or the caller gets the'
            . ' default and believes it got what it asked for. Got: ' . $failures[0]
        );

        // And an explicit `true` turns it back off, for a tool that means to be open.
        self::assertSame(
            [],
            SchemaValidator::validateArguments(
                ['limit' => 5, 'anything' => 'goes'],
                $schema + ['additionalProperties' => true]
            )
        );
    }

    /**
     * The default is TOP LEVEL ONLY, and create-post's `terms` is why.
     *
     * `terms` is `{"type":"object"}` with no `properties`: its members are taxonomy names
     * nobody can enumerate in advance. A default that closed the whole tree would refuse
     * every one of them, and the sprint-1 test that creates a post with a category would
     * have gone red for a reason that is not a bug.
     *
     * @group sprint-5
     */
    public function testTheClosedDefaultDoesNotReachNestedObjects(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'terms' => ['type' => 'object'],
            ],
        ];

        self::assertSame(
            [],
            SchemaValidator::validateArguments(
                ['title' => 'x', 'terms' => ['category' => ['news'], 'post_tag' => [7]]],
                $schema
            ),
            'An open nested object refused its members, so the additionalProperties'
            . ' default leaked past the top level.'
        );
    }

    /**
     * A nested object is validated, and the pointer says where.
     *
     * @group sprint-5
     */
    public function testNestedObjectsAreValidatedAndThePointerIsAPath(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'page' => [
                    'type'                 => 'object',
                    'properties'           => ['size' => ['type' => 'integer']],
                    'required'             => ['size'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        self::assertSame([], SchemaValidator::validate(['page' => ['size' => 10]], $schema));

        self::assertSame(
            ['/page/size: expected integer, got string'],
            SchemaValidator::validate(['page' => ['size' => '10']], $schema)
        );
        self::assertSame(
            ['/page/size: required property is missing'],
            SchemaValidator::validate(['page' => []], $schema)
        );
        self::assertSame(
            ['/page/slice: unknown property - this tool declares no such argument'],
            SchemaValidator::validate(['page' => ['size' => 1, 'slice' => 2]], $schema)
        );
        // The wrong kind of thing at the nesting point stops the descent: one sentence
        // about the node, not five about members it cannot have.
        self::assertSame(
            ['/page: expected object, got string'],
            SchemaValidator::validate(['page' => 'ten'], $schema)
        );
    }

    /**
     * WHAT REPLACED THE KEYWORD-BY-KEYWORD BODY, and where its claim is now made.
     *
     * `enum`, `minimum`, `maximum`, `minLength`, `maxLength` were five hand-written checks in
     * this class until sprint VALIDATOR; they are now five entries in
     * SchemaValidator::DELEGATED, so what this tier can assert about them is that they are
     * handed over - testEachNewlyEnforcedKeywordIsHandedToCore() below, which covers every
     * delegated group by data provider, including these. That core then enforces them is
     * proven in tests/integration/SchemaKeywordsTest.php, against real WordPress over HTTP,
     * because a stubbed answer cannot prove anything about core's own behaviour.
     *
     * WHAT IS STILL OURS AND STILL BELONGS HERE is `items`: the recursion is the addition, not
     * the keyword, because core's own array validator reports `param[0]` and stops at the first
     * bad element.
     *
     * @group sprint-validator
     */
    public function testItemsAreWalkedHereSoEveryBadElementGetsItsOwnIndex(): void
    {
        $list = ['type' => 'array', 'items' => ['type' => 'integer']];

        self::assertSame([], SchemaValidator::validate([1, 2, 3], $list));
        self::assertSame([], SchemaValidator::validate([], $list));
        self::assertSame(
            ['/1: expected integer, got string', '/3: expected integer, got null'],
            SchemaValidator::validate([1, 'two', 3, null], $list),
            'items must report the INDEX of EVERY bad element. Core returns the first and stops,'
            . ' which is the round trip per element this class exists to avoid.'
        );
        self::assertSame(
            [],
            WordPressRuntime::schemaCalls(),
            'A pure type-and-items schema has no delegated keyword in it, so core should not have'
            . ' been asked anything at all.'
        );
    }

    /**
     * THE DIALECT IS COMPOSED, NOT LISTED, AND THE THREE PARTS DO NOT OVERLAP.
     *
     * Widening the permitted keyword set from ten to twenty-six is what would otherwise have made
     * tests/unit/ToolContractTest.php a weaker test - it permits far more than it did. The weight
     * moved here. Three claims:
     *
     *   dialect() is exactly OURS + DELEGATED + ANNOTATIONS, so a keyword cannot be permitted
     *   without something enforcing it or being declared decoration. That is the hole the old
     *   hand-written KEYWORDS list could contain and this one cannot.
     *
     *   The three sets are DISJOINT. A keyword in both OURS and DELEGATED would be checked twice
     *   and report the same failure twice; one in ANNOTATIONS and DELEGATED would be described as
     *   decoration while constraining.
     *
     *   Every keyword of `rest_get_allowed_schema_keywords()` is in there, bar the one we decline.
     *   The list is tests/Support/CoreSchemaKeywords::ALLOWED - core's own, transcribed rather than
     *   derived, because this tier has no WordPress to ask - so a keyword core adds shows up as a gap
     *   between that list and core's, which is what the integration tier's live check catches. It sits
     *   in tests/Support/ rather than on this class because the integration tier reads it too, and an
     *   `use WpMcp\Tests\Unit\…` import is what took CI down on `4b138aa`; see tests/unit/TierImportTest.php.
     *
     * @group sprint-validator
     */
    public function testTheDialectIsTheDisjointUnionOfWhatEnforcesIt(): void
    {
        $delegated = array_merge(...SchemaValidator::DELEGATED);

        self::assertSame(
            array_merge(SchemaValidator::OURS, $delegated, SchemaValidator::ANNOTATIONS),
            SchemaValidator::dialect(),
            'dialect() is no longer the union of the three sets that do the work, so a keyword can'
            . ' be permitted with nothing enforcing it - which is the silence this sprint removed.'
        );

        foreach ([
            ['OURS', SchemaValidator::OURS, 'DELEGATED', $delegated],
            ['OURS', SchemaValidator::OURS, 'ANNOTATIONS', SchemaValidator::ANNOTATIONS],
            ['ANNOTATIONS', SchemaValidator::ANNOTATIONS, 'DELEGATED', $delegated],
        ] as [$leftName, $left, $rightName, $right]) {
            self::assertSame(
                [],
                array_values(array_intersect($left, $right)),
                "A keyword is in both {$leftName} and {$rightName}: "
                . implode(', ', array_intersect($left, $right))
            );
        }

        self::assertSame(
            ['required'],
            array_values(array_diff(SchemaValidator::dialect(), CoreSchemaKeywords::ALLOWED)),
            "The dialect is core's allowed keywords plus exactly one - `required`, which core"
            . ' handles outside rest_get_allowed_schema_keywords() and whose failure message names'
            . ' the object rather than the missing member. Anything else here is a keyword this'
            . ' class has started claiming on its own again.'
        );
        // AND ONE DELIBERATE OMISSION IN THE OTHER DIRECTION. `oneOf` is core's and is NOT accepted
        // here - see testOneOfIsDeclinedRatherThanMisEnforced(). Asserted as an exact list so that
        // dropping a second keyword has to come past this line and say why.
        self::assertSame(
            ['oneOf'],
            array_values(array_diff(CoreSchemaKeywords::ALLOWED, SchemaValidator::dialect())),
            'The set of core keywords this server declines has changed. `oneOf` is declined because'
            . ' core enforces exactly-one over coercive branch matching, which refuses legal values;'
            . ' any other omission is a keyword core validates and we silently ignore, which is the'
            . ' defect this whole sprint removed.'
        );
    }

    /**
     * THE SPRINT'S WHOLE CLAIM, one row per delegated keyword: the keyword REACHES CORE.
     *
     * Before this sprint every row here made ZERO calls - the keyword was not in the validator's
     * list, so nothing looked at it and the argument reached the tool body unchecked. So every
     * one of these rows fails on the pre-change code by asserting a call that never happened,
     * which is what makes "thirteen keywords are now enforced" a claim rather than a story.
     *
     * FOUR THINGS ARE ASSERTED PER ROW, and each one is a way the delegation could be wired and
     * still be useless:
     *
     *   Exactly ONE call. Two would report one violation twice.
     *   The call carries the KEYWORD. A call that dropped it enforces nothing.
     *   The call carries a `type` core can dispatch on. Core reads `$args['type']`
     *   unconditionally (rest-api.php:2245-2251), so a missing one is an undefined-key warning
     *   plus a `_doing_it_wrong()` notice, and this suite fails on either - but only on a schema
     *   that reaches the code path, so a row without this assertion would pass while being a
     *   warning on a real site.
     *   Nothing ELSE from the schema goes with it, so core cannot recurse and cannot early-return
     *   past a sibling keyword.
     *
     * `exclusiveMinimum` and `exclusiveMaximum` travel with `minimum`/`maximum` and the row says
     * so: core only reads them when the inclusive bound is also present (rest-api.php:2614-2710),
     * so a call carrying the exclusive flag alone enforces nothing at all, and asserting the
     * GROUP is asserting the only arrangement in which the keyword does anything.
     *
     * @dataProvider delegatedKeywordCases
     * @group sprint-validator
     */
    public function testEachNewlyEnforcedKeywordIsHandedToCore(
        string $keyword,
        array $schema,
        $value,
        string $sentAs = ''
    ): void {
        $sentAs = $sentAs === '' ? $keyword : $sentAs;

        $failures = SchemaValidator::validate(
            ['x' => $value],
            ['type' => 'object', 'properties' => ['x' => $schema]]
        );

        self::assertSame([], $failures, 'The double answered true, so nothing should have failed.');

        $calls = WordPressRuntime::schemaCalls();

        self::assertCount(
            1,
            $calls,
            "'{$keyword}' produced " . count($calls) . ' delegated calls rather than one.'
            . ' Before sprint VALIDATOR it produced none and the keyword was silently ignored.'
        );
        self::assertArrayHasKey(
            $sentAs,
            $calls[0]['args'],
            "The delegated call does not carry '{$sentAs}', so core is being asked about"
            . ' something else: ' . implode(', ', array_keys($calls[0]['args']))
        );
        self::assertContains(
            $calls[0]['args']['type'] ?? null,
            SchemaValidator::TYPES,
            'The delegated schema has no type core can dispatch on, which is an undefined-key'
            . ' warning and a _doing_it_wrong() notice inside core rather than a validation.'
        );
        self::assertSame(
            $value,
            $calls[0]['value'],
            'Core was handed something other than the value the caller sent.'
        );

        $group = array_values(array_filter(
            SchemaValidator::DELEGATED,
            static fn (array $g): bool => in_array($keyword, $g, true)
        ));

        self::assertCount(1, $group, "'{$keyword}' is in " . count($group) . ' delegated groups.');
        self::assertSame(
            [],
            array_values(array_diff(
                array_keys($calls[0]['args']),
                array_merge(['type', $sentAs], $group[0])
            )),
            'The delegated call carries a keyword outside its own group, so core may recurse or'
            . ' early-return past a sibling: ' . implode(', ', array_keys($calls[0]['args']))
        );
    }

    /**
     * One row per keyword core validates and this class did not, plus the five it used to.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: mixed, 3?: string}>
     */
    public static function delegatedKeywordCases(): array
    {
        return [
            // The thirteen core validated and this file ignored, minus patternProperties, which is
            // not a delegated group - see testPatternPropertiesAreWalkedHereAndMatchedByCore().
            'format'           => ['format', ['type' => 'string', 'format' => 'email'], 'not-an-email'],
            'pattern'          => ['pattern', ['type' => 'string', 'pattern' => '^[a-z]+$'], 'NOPE'],
            'minProperties'    => ['minProperties', ['type' => 'object', 'minProperties' => 2], ['a' => 1]],
            'maxProperties'    => ['maxProperties', ['type' => 'object', 'maxProperties' => 1], ['a' => 1, 'b' => 2]],
            'exclusiveMinimum' => ['exclusiveMinimum', ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true], 5],
            'exclusiveMaximum' => ['exclusiveMaximum', ['type' => 'integer', 'maximum' => 5, 'exclusiveMaximum' => true], 5],
            'multipleOf'       => ['multipleOf', ['type' => 'integer', 'multipleOf' => 5], 7],
            'minItems'         => ['minItems', ['type' => 'array', 'minItems' => 2], [1]],
            'maxItems'         => ['maxItems', ['type' => 'array', 'maxItems' => 1], [1, 2]],
            'uniqueItems'      => ['uniqueItems', ['type' => 'array', 'uniqueItems' => true], [1, 1]],
            'anyOf'            => ['anyOf', ['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]], 'twenty'],

            // The five this class used to implement, now core's. Same assertions, because "it is
            // delegated" is the only thing that changed about them.
            'enum'             => ['enum', ['type' => 'string', 'enum' => ['approve', 'spam']], 'burn'],
            'minimum'          => ['minimum', ['type' => 'integer', 'minimum' => 1], 0],
            'maximum'          => ['maximum', ['type' => 'integer', 'maximum' => 100], 101],
            'minLength'        => ['minLength', ['type' => 'string', 'minLength' => 2], 'a'],
            'maxLength'        => ['maxLength', ['type' => 'string', 'maxLength' => 4], 'abcde'],
        ];
    }

    /**
     * Every delegated group is exercised by the provider above.
     *
     * Without this, adding a group to DELEGATED and forgetting the row would leave a keyword whose
     * hand-over nothing checks - the same shape of gap as testEveryDeclaredTypeIsExercised()
     * guards for TYPES.
     *
     * @group sprint-validator
     */
    public function testEveryDelegatedGroupHasARow(): void
    {
        $covered = [];

        foreach (self::delegatedKeywordCases() as [$keyword]) {
            $covered[$keyword] = true;
        }

        foreach (array_merge(...SchemaValidator::DELEGATED) as $keyword) {
            self::assertArrayHasKey(
                $keyword,
                $covered,
                "SchemaValidator::DELEGATED hands '{$keyword}' to core and"
                . ' delegatedKeywordCases() has no row for it, so nothing checks that it arrives.'
            );
        }
    }

    /**
     * ADDITION 1, IN ITS ONLY OBSERVABLE FORM: `"20"` is refused and CORE IS NEVER ASKED.
     *
     * `rest_is_integer("20")` is true - core coerces on purpose, because REST arguments arrive
     * from query strings. So "the type check happens first" is not a preference about ordering:
     * it is the whole of the difference between this validator and core's, and the only way to
     * observe it from outside is that the delegated call did not happen. Delete the type check
     * from check() and this test goes red on the second assertion while the first still passes,
     * which is exactly the failure a test asserting only the refusal could not see.
     *
     * @group sprint-validator
     */
    public function testAStrictTypeFailureNeverReachesCore(): void
    {
        // A bound as well as a type, so there IS a delegated group at this node to not be asked.
        $schema = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1]]];

        self::assertSame(
            ['/limit: expected integer, got string'],
            SchemaValidator::validateArguments(['limit' => '20'], $schema),
            'A numeric string was accepted for an integer parameter. The tools cast, and a cast'
            . ' cannot fail: (int) "twenty" is 0.'
        );
        self::assertSame(
            [],
            WordPressRuntime::schemaCalls(),
            "The value reached core after failing our type check, so core's coercive"
            . ' rest_is_integer() got a say about it - which is the one thing this class exists to'
            . ' prevent.'
        );

        // And the legitimate value DOES reach core, or the assertion above would pass on a class
        // that never delegates anything at all.
        self::assertSame([], SchemaValidator::validateArguments(['limit' => 20], $schema));
        self::assertCount(
            1,
            WordPressRuntime::schemaCalls(),
            'A value that passed the type check was not handed to core, so `minimum` is not being'
            . ' enforced by anything.'
        );
    }

    /**
     * ADDITION 2, WITHIN a node: two violated groups produce two failures.
     *
     * Core returns the first WP_Error and stops, so a single call carrying the whole node would
     * report one of these two and the caller would come back for the other. Collapse
     * SchemaValidator::DELEGATED into one group and this goes red.
     *
     * @group sprint-validator
     */
    public function testTwoViolatedGroupsAtOneNodeProduceTwoFailures(): void
    {
        WordPressRuntime::answerSchemaWith(static function ($value, array $args) {
            if (isset($args['pattern'])) {
                return new \WP_Error('rest_invalid_pattern', ' does not match pattern ^[a-z]+$.');
            }

            return isset($args['maxLength'])
                ? new \WP_Error('rest_too_long', ' must be at most 2 characters long.')
                : true;
        });

        $failures = SchemaValidator::validate(
            ['slug' => 'NOPE'],
            [
                'type'       => 'object',
                'properties' => ['slug' => ['type' => 'string', 'pattern' => '^[a-z]+$', 'maxLength' => 2]],
            ]
        );

        self::assertSame(
            [
                '/slug: does not match pattern ^[a-z]+$.',
                '/slug: must be at most 2 characters long.',
            ],
            $failures,
            'Both violated constraints must come back at once, each behind the same pointer, in'
            . ' DELEGATED order. Got: ' . implode(' | ', $failures)
        );
    }

    /**
     * ADDITION 3 AT THE DELEGATION BOUNDARY: core is asked with an EMPTY `$param`.
     *
     * Core interpolates `$param` into every message it builds. The only name this class has for a
     * node is a JSON pointer assembled from caller-supplied keys, so handing it over would put an
     * untruncated, unescaped, attacker-chosen key into a string sent back - defeating escape() by
     * going around it - and would print the pointer twice, since failure() already writes it.
     *
     * @group sprint-validator
     */
    public function testCoreIsAskedWithAnEmptyParamSoNoCallerKeyIsEchoedThroughIt(): void
    {
        $key = str_repeat('K', 300) . '/~';

        WordPressRuntime::answerSchemaWith(
            static fn ($value, array $args, string $param) => new \WP_Error(
                'rest_too_short',
                'param was [' . $param . ']'
            )
        );

        $failures = SchemaValidator::validate(
            [$key => 'a'],
            ['type' => 'object', 'properties' => [$key => ['type' => 'string', 'minLength' => 2]]]
        );

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertSame(
            '',
            WordPressRuntime::schemaCalls()[0]['param'],
            'Core was handed a parameter name. Whatever it is, it came from the caller.'
        );
        self::assertStringContainsString('param was []', $failures[0], $failures[0]);
        self::assertStringNotContainsString(
            str_repeat('K', 100),
            $failures[0],
            "The 300-character key came back through core's message instead of through escape()."
        );
    }

    /**
     * NO CORE ERROR CODE REACHES THE CALLER - the wire-visible half of the swap.
     *
     * Core answers `rest_invalid_param` and a dozen siblings; this plugin's contract is that
     * every WP_Error it emits carries the `wpmcp_` prefix. The resolution is that the code is
     * DROPPED rather than mapped: only the message survives, behind our own pointer. A line
     * carrying `rest_too_short` would be this plugin relaying a code it does not own.
     *
     * The leading space in the staged message is core's own: core builds every one of these by
     * interpolating `$param` at the front, and this class asks with an empty `$param`, so the
     * message really does arrive with a space on it and trim() is what removes it.
     *
     * @group sprint-validator
     */
    public function testNoCoreErrorCodeReachesTheFailureList(): void
    {
        WordPressRuntime::answerSchemaWith(
            static fn () => new \WP_Error('rest_too_short', ' must be at least 2 characters long.')
        );

        $failures = SchemaValidator::validate('a', ['type' => 'string', 'minLength' => 2]);

        self::assertSame(['(root): must be at least 2 characters long.'], $failures);
        self::assertStringNotContainsString(
            'rest_',
            $failures[0],
            'A core error CODE reached the failure list. Codes are wire-visible and this plugin'
            . " promises a wpmcp_ prefix on every one it emits, so core's are dropped here."
        );
    }

    /**
     * `patternProperties`: the MATCHING is core's, the WALK is ours, and a matched member is not
     * an additional property.
     *
     * Core's precedence is declared property, then pattern, then additional
     * (rest-api.php:2444-2478). Getting it wrong the other way round would refuse every
     * pattern-matched key on a closed object - which is every tool schema, since
     * validateArguments() closes the top level by default. The regex half is core's and is proven
     * against real core in tests/integration/SchemaKeywordsTest.php; what is asserted here is the
     * precedence and the pointer.
     *
     * @group sprint-validator
     */
    public function testPatternPropertiesAreWalkedHereAndMatchedByCore(): void
    {
        WordPressRuntime::matchPatternProperty('meta_colour', ['type' => 'string']);

        $schema = [
            'type'              => 'object',
            'patternProperties' => ['^meta_' => ['type' => 'string']],
        ];

        // A matched member is validated at its own pointer...
        self::assertSame([], SchemaValidator::validateArguments(['meta_colour' => 'red'], $schema));
        self::assertSame(
            ['/meta_colour: expected string, got integer'],
            SchemaValidator::validateArguments(['meta_colour' => 7], $schema),
            'A pattern-matched member was not validated against its pattern schema at its own'
            . ' pointer.'
        );

        // ...and is NOT refused as an undeclared key, although the top level is closed.
        self::assertSame(
            ['/other: unknown property - this tool declares no such argument'],
            SchemaValidator::validateArguments(['meta_colour' => 'red', 'other' => 1], $schema),
            'Either a pattern-matched member was refused as additional, or an unmatched one was'
            . " let through. The precedence is core's: declared, then pattern, then additional."
        );
    }

    /**
     * THE DECLARED FLOOR IS NEWER THAN EVERY KEYWORD BEING DELEGATED, which is what makes
     * "delegate" a decision rather than a per-site gamble.
     *
     * Core grew these keywords over three releases and the LAST of them is `@since 5.6.0`:
     * `minProperties`, `maxProperties`, `multipleOf`, `patternProperties`, `anyOf` and `oneOf`
     * (wp-includes/rest-api.php:2211-2214). A plugin whose floor were below that would validate
     * `multipleOf` on one site and silently ignore it on another - the exact defect this sprint
     * is fixing, one level up - so the floor is the resolution and this is the assertion that
     * holds it. It reads the header rather than a constant because `Requires at least` is what
     * core enforces on activation.
     *
     * A FLOOR DROP IS WHAT THIS CATCHES. Raising the floor cannot break it; lowering it below 5.6
     * turns every delegated keyword into a per-version coin flip, and the fix is then feature
     * detection rather than a wider assertion here.
     *
     * @group sprint-validator
     */
    public function testTheDeclaredFloorIsNewerThanEveryDelegatedKeyword(): void
    {
        $header = (string) file_get_contents(WPMCP_PLUGIN_DIR . '/wp-mcp.php');

        self::assertSame(
            1,
            preg_match('/^\s*\*\s*Requires at least:\s*(\d+)\.(\d+)/m', $header, $m),
            'wp-mcp.php has no "Requires at least" header, so there is no floor to compare.'
        );

        self::assertGreaterThanOrEqual(
            0,
            [(int) $m[1], (int) $m[2]] <=> [5, 6],
            "The declared WordPress floor is {$m[1]}.{$m[2]}, older than 5.6 - the release in"
            . ' which core gained the last of the keywords SchemaValidator delegates'
            . ' (minProperties, maxProperties, multipleOf, patternProperties, anyOf, oneOf). Below'
            . ' 5.6 those keywords are enforced on some sites and silently ignored on others, and'
            . ' the answer is feature detection, not a lower bar here.'
        );
    }

    /**
     * THE DOCUMENTED LIMITATION, HELD SO IT CANNOT DRIFT INTO A SURPRISE: strict types do NOT
     * reach inside `anyOf` and `oneOf`.
     *
     * Core validates each branch itself (rest_find_any_matching_schema, rest-api.php:1993-2010),
     * so a branch declaring `{"type":"integer"}` accepts `"20"` where a top-level
     * `"type":"integer"` would not. Walking the branches here would be re-implementing the
     * combinators, which is the overbuild this sprint exists to undo. What this asserts is that
     * the combinator goes to core WHOLE and this class does not check the branches - so the
     * limitation is a measured property of the design and not an accident. The live consequence
     * is asserted in tests/integration/SchemaKeywordsTest.php, which sends `"20"` through an
     * integer anyOf branch and records what a real site does with it.
     *
     * @group sprint-validator
     */
    public function testStrictTypesDoNotReachInsideTheCombinators(): void
    {
        $schema = ['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]];

        self::assertSame(
            [],
            SchemaValidator::validate('20', $schema),
            'This class refused a combinator branch itself, which means it is now walking anyOf -'
            . ' the re-implementation this sprint removed. If that is deliberate, this test is'
            . ' what has to be rewritten, and the file docblock with it.'
        );

        $calls = WordPressRuntime::schemaCalls();

        self::assertCount(1, $calls, 'The combinator was not handed to core in one piece.');
        self::assertSame(
            [['type' => 'integer'], ['type' => 'boolean']],
            $calls[0]['args']['anyOf'],
            'The branches reached core altered.'
        );
        self::assertSame(
            'string',
            $calls[0]['args']['type'],
            "A node with no type of its own must be delegated under the VALUE's type, or core"
            . ' reads an undefined array key. "20" is a string.'
        );
    }

    /**
     * WHAT IS PUBLISHED LEAVES OUT EVERY KEYWORD NOTHING CAN READ, at any depth and in every holder -
     * and leaves everything else exactly where it was.
     *
     * TWO HALVES AND THE SECOND IS THE ONE ROUND 2 GOT WRONG. A strip test with only "this went"
     * rows passes on a method that removes too much, and removing too much is how round 2 shipped a
     * blocker: its tables took out `minItems` on a type-less node and a numeric `exclusiveMinimum`
     * beside its partner, both of which core READS, and because the reduced schema was also what
     * dispatch validated against, nine arrangements went from refused to running (review 85 R2-B1).
     * So the LEFT ALONE rows below are not padding - each one is an arrangement round 2 removed, or
     * one a future table would be tempted to remove, and the `[]` they expect is the assertion.
     *
     * NOTHING TYPE-GATED AND NOTHING PARTNER-GATED IS IN HERE ANY MORE. `publishable()` removes a
     * keyword outside the dialect, an empty `enum` and a non-array `required`, and nothing else -
     * three rules whose answer does not depend on the value being validated, which is what makes them
     * safe to apply where round 2's guesses about core's dispatch were not.
     *
     * @dataProvider publishableCases
     * @group sprint-validator
     */
    public function testPublicationLeavesOutOnlyWhatNothingCanRead(string $where, array $schema, array $expected): void
    {
        [$published, $removed] = SchemaValidator::publishable($schema);

        self::assertSame(
            $expected,
            $removed,
            "In {$where}, publishable() did not report what it left out - so either tools/list"
            . ' advertises a constraint nothing applies, or it drops one that core does apply.'
        );

        if ($expected === []) {
            self::assertSame(
                $schema,
                $published,
                "In {$where}, the schema came back CHANGED although nothing was reported as removed."
                . ' A silent edit to a published schema is the defect this test exists for.'
            );

            return;
        }

        foreach ($expected as $path) {
            $keyword = substr($path, strrpos($path, '/') + 1);

            self::assertStringNotContainsString(
                '"' . $keyword . '"',
                (string) json_encode($published),
                "In {$where}, '{$keyword}' was reported but is still in the published schema."
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function publishableCases(): array
    {
        return [
            // ---- LEFT ALONE: the whole dialect, correctly written. ----
            'a clean schema' => [
                'a clean schema',
                [
                    'type'                 => 'object',
                    'description'          => 'fine',
                    'required'             => ['a'],
                    'additionalProperties' => false,
                    'properties'           => [
                        'a' => ['type' => 'string', 'pattern' => '^x$', 'minLength' => 1],
                        'b' => ['type' => 'array', 'items' => ['type' => 'integer', 'multipleOf' => 2]],
                        'c' => ['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => true],
                        'd' => ['enum' => ['x', 'y']],
                    ],
                ],
                [],
            ],

            // ---- REMOVED (1): outside the dialect, in every holder a sub-schema can sit in. ----
            'unknown at the top level'   => ['the top level', ['type' => 'object', '$schema' => 'x'], ['/$schema']],
            'unknown in a property'      => ['a property', ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/x']]], ['/properties/a/$ref']],
            'unknown in items'           => ['items', ['type' => 'array', 'items' => ['allOf' => []]], ['/items/allOf']],
            'unknown in addlProperties'  => ['additionalProperties', ['type' => 'object', 'additionalProperties' => ['not' => []]], ['/additionalProperties/not']],
            'unknown in patternProps'    => ['patternProperties', ['type' => 'object', 'patternProperties' => ['^m_' => ['const' => 1]]], ['/patternProperties/^m_/const']],
            'unknown in an anyOf branch' => ['an anyOf branch', ['type' => 'string', 'anyOf' => [['type' => 'string'], ['examples' => []]]], ['/anyOf/1/examples']],
            'unknown two levels down'    => ['two levels down', ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['readOnly' => true]]]]], ['/properties/a/properties/b/readOnly']],

            // `oneOf` is outside the dialect BY OUR OWN CHOICE - see SchemaValidator::DELEGATED - so a
            // schema using it is not advertised as constraining anything. Its branches are still
            // walked, which is what the second row proves.
            'oneOf itself'               => ['a oneOf node', ['type' => 'string', 'oneOf' => [['type' => 'string']]], ['/oneOf']],
            // Nothing UNDER a removed keyword is reported one by one - there is no holder left.
            'inside a oneOf branch'      => ['a oneOf branch', ['type' => 'string', 'oneOf' => [['deprecated' => true]]], ['/oneOf']],

            // ---- REMOVED (2): the keyword's own VALUE is not the shape the enforcing line reads. ----
            // REVIEW 85 R2-S2: both of these stayed green in ToolContractTest and both are published
            // while nothing applies them.
            'required as a boolean'      => ['required: true on the object', ['type' => 'object', 'required' => true], ['/required']],
            // DRAFT-03's PER-PROPERTY SPELLING, which is the interesting one: core WOULD enforce it
            // (rest-api.php:2432-2440) and this validator does not, because `required` is OURS and is
            // never handed over. So it is published-and-unenforced, exactly like the other two.
            'draft-03 per-property'      => [
                'draft-03 per-property required',
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'required' => true]]],
                ['/properties/a/required'],
            ],

            // ---- LEFT ALONE, AND EVERY ROW HERE IS ONE ROUND 2 REMOVED. ----
            // `{minItems: 2}` with `[1]`: typeName() answers `array`, askCore() sends `type: array`,
            // core's array validator reads minItems (rest-api.php:2541-2568). Round 2 stripped it and
            // the call went from REFUSED to RUN over HTTPS.
            'minItems with no type'      => ['minItems with no type', ['minItems' => 2], []],
            'uniqueItems with no type'   => ['uniqueItems with no type', ['uniqueItems' => true], []],
            // A type-less branch INHERITS the parent's type in core (rest-api.php:1996-1998), so this
            // is a fully-typed constraint there. Round 2's walk never inherited and stripped it.
            'a bound in a typed branch'  => [
                'minItems in a type-less anyOf branch under a typed parent',
                ['type' => 'array', 'anyOf' => [['minItems' => 2], ['maxItems' => 0]]],
                [],
            ],
            // `format` beside `type: integer` IS unread by core - round 2's one correct table row -
            // but it is still left alone now, because the rule that removed it was the same rule that
            // removed the three above and a table that is wrong toward permissive is worse than none.
            'format on an integer'       => ['format on an integer', ['type' => 'integer', 'format' => 'email'], []],
            'minItems on a string'       => ['minItems on a string', ['type' => 'string', 'minItems' => 2], []],
            // A NON-BOOLEAN FLAG BESIDE ITS PARTNER: core reads it through `! empty()` and enforces
            // `> minimum`. Removing it loosened validation; it refuses at REGISTRATION instead. See
            // testAConstraintCoreReadsDifferentlyFromWhatItSaysRefusesRegistration().
            'a numeric flag with bound'  => ['a numeric exclusiveMinimum beside its bound', ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 5], []],
            'a lone exclusive flag'      => ['a lone exclusiveMinimum', ['type' => 'integer', 'exclusiveMinimum' => true], []],

            // ---- LEFT ALONE: what this class enforces itself, which never needs a `type`. ----
            'properties need no type'    => ['properties with no type', ['properties' => ['a' => ['type' => 'string']], 'required' => ['a']], []],
            'items need no type'         => ['items with no type', ['items' => ['type' => 'integer']], []],
            'additionalProperties false' => ['additionalProperties: false', ['additionalProperties' => false], []],
            'patternProperties no type'  => ['patternProperties with no type', ['patternProperties' => ['^m_' => ['type' => 'integer']]], []],
            'enum needs no type'         => ['enum with no type', ['enum' => [1, 2]], []],
            'anyOf needs no type'        => ['anyOf with no type', ['anyOf' => [['type' => 'string']]], []],

            // ---- A HOLDER WRITTEN AS AN OBJECT, AND NON-EMPTY - review 85 R3-B1. ----
            // `properties` may legally be a stdClass: asMap()'s docblock says a filter-added tool
            // "still may" write it that way and site-info used to. holders() accepts one, and the
            // keyed write-back then threw `Cannot use object of type stdClass as array` - which, in the
            // tools/list emitter, turned the WHOLE site's tools/list into -32603 for every client while
            // tools/call kept working. VERIFIED over HTTPS by the reviewer.
            //
            // THE ROW THAT EXISTED USED AN EMPTY OBJECT, whose loop body never runs, so it could not
            // fail - the second time this sprint a row was too weak to see its own subject. These are
            // non-empty, and each one has something to remove so the write-back is actually reached.
            'object properties'          => [
                'a non-empty object-form properties',
                ['type' => 'object', 'properties' => (object) ['a' => ['type' => 'string', 'const' => 'x']]],
                ['/properties/a/const'],
            ],
            'object patternProperties'   => [
                'a non-empty object-form patternProperties',
                ['type' => 'object', 'patternProperties' => (object) ['^m_' => ['type' => 'integer', '$ref' => '#/x']]],
                ['/patternProperties/^m_/$ref'],
            ],
            'object properties, deep'    => [
                'an object-form properties two levels down',
                ['type' => 'object', 'properties' => (object) ['a' => ['type' => 'object', 'properties' => (object) ['b' => ['allOf' => []]]]]],
                ['/properties/a/properties/b/allOf'],
            ],
            // AND A CLEAN ONE IS NOT TOUCHED, which is what stops the fix from being "cast everything":
            // the write-back is skipped when nothing was removed below, so the stdClass survives.
            'a clean object properties'  => [
                'a clean object-form properties',
                ['type' => 'object', 'properties' => (object) ['a' => ['type' => 'string']]],
                [],
            ],
        ];
    }

    /**
     * THE PROPERTY ROUND 2 BROKE, ASSERTED DIRECTLY: publication never changes what core is asked.
     *
     * This is the guard, not the rows above. Round 2's blocker was not "the wrong keyword was
     * stripped" - it was that a REDUCED schema reached the validator at all, so any disagreement
     * between the strip's idea of what core reads and core's own became a loosened verdict. The
     * strip is publication-only now, and the way to hold that in this tier is to validate the same
     * value against the schema AS WRITTEN and against `publishable()`'s output and require core to
     * receive IDENTICAL calls. If the calls are identical the verdict is identical, whatever core
     * would have answered.
     *
     * THE ROWS ARE THE REVIEWER'S NINE MEASURED ARRANGEMENTS (review 85 R2-B1), which is the point:
     * every one of them was a permissive flip on `4a7c0f7`, and each is now held by the
     * strongest statement available here rather than by a table that agrees with core today.
     *
     * AND ITS BLIND SPOT, STATED SO NOBODY OVER-TRUSTS IT (review 85, round 3 item 3): this sees only
     * DELEGATED calls, so a publication that dropped `required`, `type`, `properties` or `items` -
     * SchemaValidator::OURS, which never reach core - would keep it GREEN while changing a verdict.
     * The belt against that is structural rather than a test: `wpmcp_tools()` hands `wpmcp_dispatch()`
     * the schema as written and publishable() is called only from the `tools/list` emitter, so a
     * reduction cannot reach the validator at all. This test is the braces. Do not read it as covering
     * more than the delegated half.
     *
     * @dataProvider verdictNeutralCases
     * @group sprint-validator
     */
    public function testPublicationNeverChangesWhatCoreIsAsked(array $schema, $value): void
    {
        $asWritten = self::delegatedCallsFor($schema, $value);
        $published = self::delegatedCallsFor(SchemaValidator::publishable($schema)[0], $value);

        self::assertSame(
            $asWritten,
            $published,
            'Validating against the published schema asks core something different from validating'
            . ' against the schema as written, so publication can change a verdict. That is review 85'
            . " R2-B1: on 4a7c0f7 this made nine arrangements permissive.
as written: "
            . json_encode($asWritten) . "
published:  " . json_encode($published)
        );
    }

    /** The delegated calls one validation makes, with the recorder reset around it. */
    private static function delegatedCallsFor(array $schema, $value): array
    {
        WordPressRuntime::install();
        SchemaValidator::validate($value, $schema);

        return array_map(
            static fn (array $call): array => ['args' => $call['args'], 'value' => $call['value']],
            WordPressRuntime::schemaCalls()
        );
    }

    /**
     * The nine arrangements review 85 R2-B1 measured going from REFUSED to RUN, plus the shapes the
     * strip does remove - because "identical calls" has to hold for those too.
     *
     * @return array<string, array{0: array<string, mixed>, 1: mixed}>
     */
    public static function verdictNeutralCases(): array
    {
        return [
            'minItems, no type'            => [['minItems' => 2], [1]],
            'uniqueItems, no type'         => [['uniqueItems' => true], [1, 1]],
            'maxItems, no type'            => [['maxItems' => 1], [1, 2]],
            'minItems in a branch'         => [['anyOf' => [['minItems' => 2], ['type' => 'boolean']]], [1]],
            'bounds in typed branches'     => [['type' => 'array', 'anyOf' => [['minItems' => 2], ['maxItems' => 0]]], [1]],
            'numeric flag, bound present'  => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 5], 0],
            'numeric flag of 1'            => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 1], 0],
            'exclusiveMaximum numeric'     => [['type' => 'integer', 'maximum' => 10, 'exclusiveMaximum' => 10], 10],
            'a string flag'                => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 'true'], 0],

            // And the shapes publication DOES reduce: core is asked the same either way, because
            // nothing reads them.
            'an unknown keyword'           => [['type' => 'string', 'const' => 'x', 'minLength' => 2], 'a'],
            'required as a boolean'        => [['type' => 'object', 'required' => true, 'minProperties' => 2], ['a' => 1]],
            'oneOf'                        => [['type' => 'string', 'oneOf' => [['type' => 'string']]], 'a'],
        ];
    }

    /**
     * THE EMPTY ARRAY IS CHECKED AS AN ARRAY AS WELL AS AN OBJECT, so a type-less `minItems` refuses it.
     *
     * REVIEW 85's S5, carried from round 1 and closed here. `json_decode('{}', true)` and
     * `json_decode('[]', true)` are the same PHP value, so a node with no declared `type` had to pick
     * one to tell core and typeName() picks `object` - which meant `{"minItems": 1}` ACCEPTED `[]`, the
     * single value that keyword exists to forbid. A constraint that admits exactly what it forbids is
     * worth more than its line count.
     *
     * MEASURED BEFORE FIXING, against real core on WP 7.1.2: `[]` against
     * `{type: array, minItems: 1}` is REFUSED (`p must contain at least 1 item.`) and against
     * `{type: object, minItems: 1}` is accepted. So core was never missing the constraint - we were
     * telling it the wrong type. This is therefore delegated harder rather than implemented here: `[]`
     * satisfies both types by matches()'s own doctrine, so both readings are asked.
     *
     * WHAT THIS TIER CAN SEE is the pair of calls, which is the fix; that core then refuses is
     * tests/integration/SchemaKeywordsTest::testATypeLessArrayBoundStillRefusesTheEmptyList().
     *
     * @group sprint-validator
     */
    public function testTheEmptyArrayIsAskedAboutUnderBothOfItsReadings(): void
    {
        SchemaValidator::validate([], ['minItems' => 1]);

        $asked = array_map(
            static fn (array $call): string => (string) $call['args']['type'],
            WordPressRuntime::schemaCalls()
        );

        self::assertSame(
            ['object', 'array'],
            $asked,
            'A type-less node holding the empty array was asked about under one reading only, so'
            . ' whichever keyword belongs to the other reading is unenforced. Asked: '
            . implode(', ', $asked)
        );

        // A NON-EMPTY LIST IS NOT AMBIGUOUS and must still be one call, or every list-valued argument
        // on every tool doubles its delegated calls for nothing.
        WordPressRuntime::install();
        SchemaValidator::validate([1], ['minItems' => 1]);

        self::assertCount(
            1,
            WordPressRuntime::schemaCalls(),
            'A non-empty list was asked about twice. Only the EMPTY array is both types.'
        );

        // AND A DECLARED TYPE IS OBEYED, ambiguous value or not.
        WordPressRuntime::install();
        SchemaValidator::validate([], ['type' => 'object', 'minProperties' => 1]);

        self::assertSame(
            ['object'],
            array_map(
                static fn (array $call): string => (string) $call['args']['type'],
                WordPressRuntime::schemaCalls()
            ),
            'A node that declared its type was asked about under another one as well.'
        );
    }

    /**
     * A KEYWORD THAT FAILS UNDER BOTH READINGS REPORTS ONCE.
     *
     * `enum` is the measured case: `[]` against `{enum: [[1]]}` is refused under `type: object` AND
     * under `type: array`, with the same sentence. Two identical failure lines for one keyword would be
     * a worse message than one, and the caller has one thing to fix.
     *
     * @group sprint-validator
     */
    public function testAKeywordFailingUnderBothReadingsIsReportedOnce(): void
    {
        WordPressRuntime::answerSchemaWith(
            static fn () => new \WP_Error('rest_not_in_enum', 'is not [1]')
        );

        $failures = SchemaValidator::validate([], ['enum' => [[1]]]);

        self::assertCount(
            2,
            WordPressRuntime::schemaCalls(),
            'The empty array was not asked about under both readings, so this proves nothing.'
        );
        self::assertSame(['(root): is not [1]'], $failures, implode(' | ', $failures));
    }

    /**
     * `publishable()` survives a non-map in a holder, and answers about a non-schema at all.
     *
     * @group sprint-validator
     */
    public function testPublishableSurvivesANonMap(): void
    {
        self::assertSame([['type' => 'object'], []], SchemaValidator::publishable(['type' => 'object']));
        self::assertSame(['not a schema at all', []], SchemaValidator::publishable('not a schema at all'));
        // ONE INSTANCE, because assertSame compares objects by IDENTITY - two `new stdClass()` are
        // not the same object, and the first draft of this assertion failed on that rather than on
        // anything publishable() did.
        //
        // AND THIS ROW CANNOT CATCH THE BUG AN OBJECT HOLDER ACTUALLY HAD (review 85 R3-B1): the
        // holder is EMPTY, so publishable()'s descent loop never runs and the write-back that threw is
        // never reached. The non-empty rows in publishableCases() are what covers it. Kept, because
        // "an empty properties survives untouched" is its own claim - site-info used to write one.
        $empty = new \stdClass();

        self::assertSame(
            [['type' => 'object', 'properties' => $empty], []],
            SchemaValidator::publishable(['type' => 'object', 'properties' => $empty]),
            'An empty `properties` written as a stdClass - which site-info used to be and a'
            . ' filter-added tool still may - did not survive publication untouched.'
        );
    }

    /**
     * AN EXCLUSIVE BOUND FLAG CORE CANNOT READ AS WRITTEN REFUSES REGISTRATION - it is not stripped.
     *
     * THE ONLY SCHEMA SHAPE THAT REFUSES, and the reason it cannot be handled like the others is
     * review 85 R2-B1. With its partner present core IS enforcing something from a non-boolean flag -
     * `! empty( $args['exclusiveMinimum'] )` (rest-api.php:2615) reads `5` and even the string
     * `"true"` as the draft-04 boolean and enforces `> minimum` - so removing it LOOSENS validation,
     * which is exactly what round 2 did. And leaving it published is a false claim, because 2020-12
     * says `exclusiveMinimum: 5` means `> 5`. Neither is honest, so the entry does not register.
     *
     * @dataProvider boundCases
     * @group sprint-validator
     */
    public function testAConstraintCoreReadsDifferentlyFromWhatItSaysRefusesRegistration(string $where, array $schema, ?string $expected): void
    {
        self::assertSame(
            $expected,
            SchemaValidator::unreadableConstraint($schema),
            $expected === null
                ? "In {$where}, a bound core reads exactly as written was refused anyway."
                : "In {$where}, a bound core cannot read as written was accepted, so the tool either"
                . ' publishes a constraint that does nothing or enforces one it does not claim.'
        );
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}> */
    public static function boundCases(): array
    {
        return [
            // NO PARTNER: `isset($args['minimum'])` gates every branch, so nothing reads the flag.
            'a lone boolean flag'       => ['a lone exclusiveMinimum', ['type' => 'integer', 'exclusiveMinimum' => true], '/exclusiveMinimum'],
            'a lone maximum flag'      => ['a lone exclusiveMaximum', ['type' => 'integer', 'exclusiveMaximum' => true], '/exclusiveMaximum'],
            'a lone numeric flag'      => ['a lone numeric flag', ['type' => 'integer', 'exclusiveMinimum' => 5], '/exclusiveMinimum'],

            // NON-BOOLEAN WITH A PARTNER: core reads it, as something other than what it says.
            'the 2020-12 numeric form' => ['the numeric form', ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 5], '/exclusiveMinimum'],
            'the numeric zero'         => ['exclusiveMinimum: 0', ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 0], '/exclusiveMinimum'],
            'a string flag'            => ['exclusiveMinimum: "true"', ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 'true'], '/exclusiveMinimum'],
            'a null flag'              => ['exclusiveMinimum: null', ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => null], '/exclusiveMinimum'],

            // AN EMPTY `enum`, which is the same disagreement in a different keyword: JSON Schema says
            // it admits no value, core's `! empty()` says it admits every value. Review 85 R2-S2
            // measured ToolContractTest staying green on it.
            'an empty enum'            => ['an empty enum', ['type' => 'string', 'enum' => []], '/enum'],
            'an enum that is a string' => ['enum as a string', ['type' => 'string', 'enum' => 'x'], '/enum'],
            'an enum in a property'    => ['an empty enum at depth', ['type' => 'object', 'properties' => ['a' => ['enum' => []]]], '/properties/a/enum'],
            'a real enum'              => ['a non-empty enum', ['type' => 'string', 'enum' => ['x']], null],

            // CORE'S OWN SPELLING, at depth, in each holder - accepted, because what is written and
            // what is enforced agree.
            'the pair core reads'      => ['a boolean flag beside its bound', ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true], null],
            'the maximum pair'         => ['a boolean flag beside maximum', ['type' => 'integer', 'maximum' => 5, 'exclusiveMaximum' => true], null],
            'no flag at all'           => ['a plain minimum', ['type' => 'integer', 'minimum' => 5], null],

            // AND IT IS FOUND AT DEPTH, or a third party would only have to nest it one level.
            'in a property'            => ['a property', ['type' => 'object', 'properties' => ['a' => ['type' => 'integer', 'exclusiveMinimum' => 1]]], '/properties/a/exclusiveMinimum'],
            'in items'                 => ['items', ['type' => 'array', 'items' => ['type' => 'integer', 'exclusiveMaximum' => 2]], '/items/exclusiveMaximum'],
            'in an anyOf branch'       => ['an anyOf branch', ['anyOf' => [['type' => 'integer', 'exclusiveMinimum' => 0]]], '/anyOf/0/exclusiveMinimum'],
        ];
    }

    /**
     * `oneOf` IS NOT IN THE DIALECT: core is not asked about it, and it is not published either.
     *
     * BOTH AVAILABLE BEHAVIOURS WERE MEASURED AND BOTH ARE BAD, which is why the third option is to
     * decline the keyword. Asking core for `oneOf` enforces exactly-one over its COERCIVE per-branch
     * type checks, so `[integer, boolean]` refuses the integer `1` (`rest_is_boolean(1)` is true) -
     * a false refusal a caller cannot comply with, verified on a real site in round 1. Asking core
     * for `anyOf` instead - which round 2 shipped - enforces at-least-one while the published schema
     * still says `oneOf`: review 85 R2-S1 measured `5` against
     * `[{integer,minimum:0},{integer,maximum:10}]` being accepted where core's real `oneOf` refuses
     * it, with no coercion involved at all. A schema that says one thing while the server does
     * another is the defect this sprint exists to remove.
     *
     * SO IT IS DECLINED, LOUDLY RATHER THAN QUIETLY: nothing is enforced, nothing is claimed, and
     * `publishable()` reports the removal so a `registry_strip` event names it. Twelve of core's
     * thirteen newly-enforced keywords are delivered; this is the thirteenth and it is a documented
     * non-delivery. `anyOf` has no exactly-one count and therefore no coercion artefact - it is the
     * keyword to reach for, and it is enforced.
     *
     * @group sprint-validator
     */
    public function testOneOfIsDeclinedRatherThanMisEnforced(): void
    {
        self::assertNotContains(
            'oneOf',
            SchemaValidator::dialect(),
            'oneOf is back in the dialect, so tools/list advertises it. Whatever now enforces it has'
            . ' to enforce EXACTLY ONE, or the claim is false - see this test\'s docblock.'
        );

        $schema = ['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'integer', 'maximum' => 10]]];

        self::assertSame(
            [],
            SchemaValidator::validate(5, $schema),
            'A value was refused for a keyword this server does not enforce.'
        );
        self::assertSame(
            [],
            WordPressRuntime::schemaCalls(),
            'Core was asked about a oneOf schema. If that is deliberate it must be asked for oneOf'
            . ' and not anyOf, and the dialect has to accept the keyword again: '
            . json_encode(WordPressRuntime::schemaCalls())
        );
        self::assertSame(
            ['/oneOf'],
            SchemaValidator::publishable($schema)[1],
            'oneOf is not enforced and is still published, which is the round-2 defect exactly.'
        );
    }

    /**
     * ADDITION 3, THE HALF AN EMPTY `$param` DOES NOT COVER: a combinator failure carries a FIXED
     * sentence, and no caller byte of any kind.
     *
     * REVIEW 85 B2, VERIFIED over HTTPS on round 1's code with a 400-byte key: core interpolates the
     * caller's PROPERTY NAME into `%1$s is not a valid property of Object` (rest-api.php:2467) and
     * `rest_format_combining_operation_error()` relays it as "Reason: ..." (:1909). None of that
     * comes through `$param`, so sending `$param` empty did not stop it and two unit tests plus the
     * CHANGELOG promised something false. Discarding the combinator's message text is the fix that
     * keeps the promise exactly.
     *
     * @group sprint-validator
     */
    public function testACombinatorFailureCarriesAFixedSentenceAndNoCallerBytes(): void
    {
        $key = str_repeat('K', 400) . '<script>alert(1)</script>';

        // Core's real shape for this case, reproduced from rest-api.php:1909 + :2467.
        WordPressRuntime::answerSchemaWith(
            static fn ($value, array $args) => new \WP_Error(
                'rest_no_matching_schema',
                ' does not match the expected format. Reason: ' . $key . ' is not a valid property of Object.'
            )
        );

        $failures = SchemaValidator::validate(
            ['x' => [$key => 1]],
            [
                'type'       => 'object',
                'properties' => ['x' => ['anyOf' => [['type' => 'object'], ['type' => 'boolean']]]],
            ]
        );

        self::assertSame(
            ['/x: does not match any of the shapes this argument permits'],
            $failures,
            "Core's combinator message was relayed instead of replaced, so whatever core chose to"
            . ' interpolate into it reached the caller: ' . implode(' | ', $failures)
        );
        self::assertStringNotContainsString('<script>', $failures[0], $failures[0]);
        self::assertStringNotContainsString(str_repeat('K', 20), $failures[0], $failures[0]);
    }

    /**
     * ADDITION 3, EVERY OTHER PATH: a relayed message is ONE LINE and BOUNDED.
     *
     * TWO SEPARATE GUARANTEES AND THE FIRST IS STRUCTURAL. `wpmcp_dispatch()` joins the failure list
     * with a newline, so a newline inside a relayed message FORGES a failure line - a caller could
     * make the refusal appear to say `/id: required property is missing`, which is a sentence about
     * a different argument entirely. Collapsing whitespace is what makes "one failure, one line"
     * true of the wire and not just of this array. The cap is the second: no core message, however
     * built, can carry an unbounded number of bytes back.
     *
     * @group sprint-validator
     */
    public function testARelayedMessageIsOneLineAndBounded(): void
    {
        WordPressRuntime::answerSchemaWith(
            static fn () => new \WP_Error(
                'rest_invalid_pattern',
                "does not match pattern\n/id: required property is missing\r\tand " . str_repeat('Z', 400)
            )
        );

        $failures = SchemaValidator::validate('x', ['type' => 'string', 'pattern' => '^y$']);

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertStringNotContainsString(
            "\n",
            $failures[0],
            'A newline survived in a relayed message, so a caller can forge an extra failure line'
            . ' in the refusal wpmcp_dispatch() assembles.'
        );
        self::assertStringNotContainsString("\r", $failures[0], $failures[0]);
        self::assertStringNotContainsString("\t", $failures[0], $failures[0]);
        self::assertLessThanOrEqual(
            240,
            strlen($failures[0]),
            'A relayed message is not bounded: ' . strlen($failures[0]) . ' bytes.'
        );
        self::assertStringEndsWith('...', $failures[0], 'A capped message must say it was cut.');
        // The part that matters still arrives - a cap that ate the sentence would be worse.
        self::assertStringContainsString('does not match pattern', $failures[0], $failures[0]);
    }

    /**
     * A CAPPED MESSAGE IS STILL VALID UTF-8, which is escape()'s reason applied to the other string
     * this class relays.
     *
     * A byte cut at MAX_MESSAGE can split a multi-byte character, and json_encode refuses the WHOLE
     * response document on invalid UTF-8 (JSON_ERROR_UTF8) rather than the one string. The fixture
     * puts a three-byte character so its first byte lands on the boundary, and the premise is
     * asserted rather than assumed.
     *
     * @group sprint-validator
     */
    public function testACappedMessageIsStillValidUtf8(): void
    {
        $message = str_repeat('m', 199) . "\u{20AC}" . str_repeat('n', 40);

        self::assertNotSame(
            1,
            preg_match('//u', substr($message, 0, 200)),
            'This message does not straddle the cap with a partial character, so it is the wrong'
            . ' fixture for this test.'
        );

        WordPressRuntime::answerSchemaWith(static fn () => new \WP_Error('rest_invalid_pattern', $message));

        $failures = SchemaValidator::validate('x', ['type' => 'string', 'pattern' => '^y$']);

        self::assertSame(
            1,
            preg_match('//u', $failures[0]),
            'The relayed message is not valid UTF-8: bytes ' . bin2hex($failures[0])
        );
        self::assertIsString(json_encode(['text' => $failures[0]]));
    }

    /**
     * A MESSAGE WITH A BAD BYTE IS COLLAPSED, NOT ERASED.
     *
     * `preg_replace` with the `/u` modifier answers NULL on a subject that is not valid UTF-8, and
     * `(string) null` is the empty string - so the whole sentence would VANISH, which is exactly the
     * failure sprint CORE-FIX found in `json_encode()` returning false. The collapse therefore runs
     * without `/u`, and this is the test that would catch somebody adding it.
     *
     * @group sprint-validator
     */
    public function testAMessageCarryingAnInvalidByteIsNotErasedByTheCollapse(): void
    {
        WordPressRuntime::answerSchemaWith(
            static fn () => new \WP_Error('rest_not_in_enum', "is not one of bad\xB1value")
        );

        $failures = SchemaValidator::validate('x', ['type' => 'string', 'enum' => ['y']]);

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertStringContainsString(
            'is not one of bad',
            $failures[0],
            'The relayed message was erased rather than collapsed, which is what preg_replace with'
            . ' /u does to a subject carrying a byte that is not valid UTF-8. Got: ' . $failures[0]
        );
        self::assertStringContainsString('value', $failures[0], $failures[0]);
    }

    /**
     * The map handed to core's pattern matcher is THIS NODE'S, carrying its own `patternProperties`.
     *
     * REVIEW 85 S7: the double used to answer from the property name alone and ignore `$args`
     * entirely, so the unit tier would have stayed green if checkObject() had handed core the wrong
     * array - the instrument grading itself. The double now answers null without
     * `$args['patternProperties']`, which is the only key core reads
     * (rest-api.php:1870-1880), and records every call so this can assert which map arrived.
     *
     * @group sprint-validator
     */
    public function testCoresPatternMatcherIsHandedThisNodesOwnPatternProperties(): void
    {
        WordPressRuntime::matchPatternProperty('m_hits', ['type' => 'integer']);

        $patterns = ['^m_' => ['type' => 'integer']];

        self::assertSame(
            ['/m_hits: expected integer, got string'],
            SchemaValidator::validateArguments(
                ['m_hits' => 'nope'],
                ['type' => 'object', 'patternProperties' => $patterns]
            )
        );

        $calls = WordPressRuntime::patternCalls();

        self::assertCount(1, $calls, 'Core\'s pattern matcher was asked ' . count($calls) . ' times.');
        self::assertSame('m_hits', $calls[0]['property']);
        self::assertSame(
            $patterns,
            $calls[0]['args']['patternProperties'] ?? null,
            'Core was handed a map without this node\'s own patternProperties, so the matching it'
            . ' does is not the matching this schema asked for.'
        );
    }
}
