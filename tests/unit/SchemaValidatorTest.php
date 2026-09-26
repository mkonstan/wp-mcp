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
     * Core's own allowed keywords, wp-includes/rest-api.php:2170-2196, in core's order.
     *
     * TRANSCRIBED AND NOT DERIVED, because the unit tier has no WordPress. That is a real
     * weakness and it is covered rather than hidden: tests/integration/SchemaKeywordsTest.php
     * asks the live site for `rest_get_allowed_schema_keywords()` and compares this list against
     * it, so a keyword core adds or drops is a red integration test rather than a stale constant.
     *
     * @var list<string>
     */
    public const CORE_ALLOWED_KEYWORDS = [
        'title', 'description', 'default', 'type', 'format', 'enum', 'items', 'properties',
        'additionalProperties', 'patternProperties', 'minProperties', 'maxProperties', 'minimum',
        'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength',
        'pattern', 'minItems', 'maxItems', 'uniqueItems', 'anyOf', 'oneOf',
    ];

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
     *   Every keyword of `rest_get_allowed_schema_keywords()` is in there. The list below is
     *   core's own, transcribed from wp-includes/rest-api.php:2170-2196 rather than derived,
     *   because this tier has no WordPress to ask - so a keyword core adds shows up as a gap
     *   between this list and core's, which is what the integration tier's live check catches.
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
            [],
            array_values(array_diff(self::CORE_ALLOWED_KEYWORDS, SchemaValidator::dialect())),
            'A keyword core validates is outside the dialect, so a schema using it is refused at'
            . ' registration for no reason and no built-in may use it either.'
        );
        self::assertSame(
            ['required'],
            array_values(array_diff(SchemaValidator::dialect(), self::CORE_ALLOWED_KEYWORDS)),
            "The dialect is core's allowed keywords plus exactly one - `required`, which core"
            . ' handles outside rest_get_allowed_schema_keywords() and whose failure message names'
            . ' the object rather than the missing member. Anything else here is a keyword this'
            . ' class has started claiming on its own again.'
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
    public function testEachNewlyEnforcedKeywordIsHandedToCore(string $keyword, array $schema, $value): void
    {
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
            $keyword,
            $calls[0]['args'],
            "The delegated call does not carry '{$keyword}', so core is being asked about"
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
            array_values(array_diff(array_keys($calls[0]['args']), array_merge(['type'], $group[0]))),
            'The delegated call carries a keyword outside its own group, so core may recurse or'
            . ' early-return past a sibling: ' . implode(', ', array_keys($calls[0]['args']))
        );
    }

    /**
     * One row per keyword core validates and this class did not, plus the five it used to.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: mixed}>
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
            'oneOf'            => ['oneOf', ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]], 'twenty'],

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
     * An unknown keyword anywhere in a schema is NAMED, at any depth and in every holder.
     *
     * This is what `wpmcp_registry_reject_reason()` asks about a tool a filter or a module
     * registers, and the reason it needs asking is that such a tool is in no catalog: nothing
     * like tests/unit/ToolContractTest.php ever sees it, so registration is the only place its
     * schema meets the dialect. A holder this walk does not cover is a hole exactly as wide as
     * the one the sprint closed.
     *
     * @group sprint-validator
     */
    public function testAnUnknownKeywordIsFoundInEveryPlaceASubSchemaCanSit(): void
    {
        self::assertNull(
            SchemaValidator::unknownKeyword([
                'type'                 => 'object',
                'description'          => 'fine',
                'required'             => ['a'],
                'additionalProperties' => false,
                'properties'           => [
                    'a' => ['type' => 'string', 'pattern' => '^x$'],
                    'b' => ['type' => 'array', 'items' => ['type' => 'integer', 'multipleOf' => 2]],
                ],
            ]),
            'A schema using nothing but the dialect was reported as unknown.'
        );

        $holders = [
            'top level'            => ['type' => 'object', '$schema' => 'https://example.invalid/s'],
            'a property'           => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/x']]],
            'items'                => ['type' => 'array', 'items' => ['allOf' => []]],
            'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['not' => []]],
            'patternProperties'    => ['type' => 'object', 'patternProperties' => ['^m_' => ['const' => 1]]],
            'an anyOf branch'      => ['type' => 'string', 'anyOf' => [['type' => 'string'], ['examples' => []]]],
            'a oneOf branch'       => ['type' => 'string', 'oneOf' => [['deprecated' => true]]],
            'two levels down'      => ['properties' => ['a' => ['properties' => ['b' => ['readOnly' => true]]]]],
        ];
        $expected = [
            'top level'            => '$schema',
            'a property'           => '$ref',
            'items'                => 'allOf',
            'additionalProperties' => 'not',
            'patternProperties'    => 'const',
            'an anyOf branch'      => 'examples',
            'a oneOf branch'       => 'deprecated',
            'two levels down'      => 'readOnly',
        ];

        foreach ($holders as $where => $schema) {
            self::assertSame(
                $expected[$where],
                SchemaValidator::unknownKeyword($schema),
                "An unknown keyword in {$where} was not found, so a tool declaring a constraint"
                . ' nothing enforces registers and the argument reaches its body unchecked.'
            );
        }

        // A NON-MAP STOPS THE DESCENT RATHER THAN ERRORING. `additionalProperties: false` and a
        // `properties` written as an empty stdClass are both legal and both reach this walk.
        self::assertNull(SchemaValidator::unknownKeyword(['additionalProperties' => false]));
        self::assertNull(SchemaValidator::unknownKeyword(['properties' => new \stdClass()]));
        self::assertNull(SchemaValidator::unknownKeyword('not a schema at all'));
    }

    /**
     * Several failures come back together, in a fixed order, so a caller fixes one call
     * instead of N.
     *
     * The order is: missing required members, then declared members in SCHEMA order,
     * then undeclared keys. Asserted because a test elsewhere asserts on the exact text.
     *
     * @group sprint-5
     */
    public function testEveryFailureIsReportedInAFixedOrder(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'id'    => ['type' => 'integer'],
                'title' => ['type' => 'string'],
            ],
            'required'   => ['id'],
        ];

        self::assertSame(
            [
                '/id: required property is missing',
                '/title: expected string, got integer',
                '/extra: unknown property - this tool declares no such argument',
            ],
            SchemaValidator::validateArguments(['title' => 7, 'extra' => true], $schema)
        );
    }

    /**
     * The message carries the caller's KEYS but never the caller's VALUES, and both are
     * bounded.
     *
     * An argument key is arbitrary caller-supplied text that ends up in a string sent
     * back, which is the same problem the MCP-Protocol-Version gate truncates its echoed
     * header for. And a body may carry thousands of undeclared keys, so the list of
     * failures is capped too.
     *
     * @group sprint-5
     */
    public function testTheFailureListIsBoundedAndEchoesNoValues(): void
    {
        $secret    = 'SENSITIVE-VALUE-THAT-MUST-NOT-COME-BACK';
        $longKey   = str_repeat('k', 500);
        $arguments = [$longKey => $secret];

        for ($i = 0; $i < 50; $i++) {
            $arguments['key' . $i] = $secret;
        }

        $failures = SchemaValidator::validateArguments($arguments, ['type' => 'object']);
        $text     = implode("\n", $failures);

        self::assertStringNotContainsString(
            $secret,
            $text,
            'A caller-supplied VALUE reached the failure message. Only the type of what'
            . ' arrived and the key it arrived under may.'
        );
        self::assertCount(
            SchemaValidator::MAX_FAILURES + 1,
            $failures,
            'The failure list is not capped: ' . count($failures) . ' entries.'
        );
        self::assertStringContainsString('(and ', (string) end($failures));
        self::assertStringNotContainsString(
            str_repeat('k', 200),
            $text,
            'A 500-character argument key was echoed in full.'
        );
    }

    /**
     * A JSON pointer segment is escaped: `/` and `~` inside a key cannot forge a path.
     *
     * @group sprint-5
     */
    public function testPointerSegmentsAreEscaped(): void
    {
        $failures = SchemaValidator::validateArguments(['a/b' => 1, 'c~d' => 1], ['type' => 'object']);

        self::assertSame(
            [
                '/a~1b: unknown property - this tool declares no such argument',
                '/c~0d: unknown property - this tool declares no such argument',
            ],
            $failures,
            'RFC 6901 escaping is what stops a key containing a slash from reading as two'
            . ' path segments: ' . implode(' | ', $failures)
        );
    }

    /**
     * A long key truncated ON A CHARACTER BOUNDARY, so the message stays encodable.
     *
     * THE CASE IS CONSTRUCTED, NOT SAMPLED: 63 ASCII bytes then a three-byte character,
     * so its first byte sits at offset 63 and a byte-wise `substr($key, 0, 64)` keeps one
     * byte of three. That leaves invalid UTF-8 in the failure line, and `json_encode`
     * refuses the WHOLE document on it (JSON_ERROR_UTF8) rather than the one string -
     * wp_json_encode's sanity check instead strips the bad bytes, so the caller gets a
     * mangled message. A two-byte character happens to cut cleanly at 64, which is why
     * this needs a deliberate width rather than "a long unicode key". Found by review
     * 2026-09-12.
     *
     * @group sprint-5
     */
    public function testALongMultiByteKeyIsTruncatedOnACharacterBoundary(): void
    {
        // U+20AC EURO SIGN: three bytes, e2 82 ac.
        $key = str_repeat('a', 63) . "\u{20AC}" . str_repeat('b', 40);

        // THE PREMISE, asserted rather than assumed: a byte-wise cut at MAX_KEY really
        // does break this key. Without this the test could be green against a key that
        // happens to cut cleanly, and would then prove nothing about the fix.
        // preg_match with /u answers 1 on valid UTF-8 and FALSE on invalid - not 0, which
        // is "no match" - so the premise is "anything but 1".
        self::assertNotSame(
            1,
            preg_match('//u', substr($key, 0, 64)),
            'This key does not straddle the 64-byte boundary with a partial character, so'
            . ' it is the wrong fixture for this test.'
        );

        $failures = SchemaValidator::validateArguments([$key => 1], ['type' => 'object']);

        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertSame(
            1,
            preg_match('//u', $failures[0]),
            'The failure line is not valid UTF-8: a multi-byte character was cut in half'
            . ' by a byte-wise truncation. Bytes: ' . bin2hex($failures[0])
        );
        self::assertIsString(
            json_encode(['text' => $failures[0]]),
            'json_encode refused the failure message, which is what invalid UTF-8 does to'
            . ' the whole response document: ' . bin2hex($failures[0])
        );
        self::assertStringContainsString('...', $failures[0], $failures[0]);
        self::assertStringNotContainsString(
            str_repeat('b', 10),
            $failures[0],
            'The key was not truncated at all.'
        );
    }

    /**
     * An empty `properties` written as `new stdClass()` is still read as "no properties".
     *
     * site-info used to be written that way and a filter-added tool still may - three of
     * the integration suite's own fixture tools do. If the validator read stdClass as
     * "not a map" it would also read it as "no properties declared", which is correct,
     * but the object form must not become an accidental escape from the closed default.
     *
     * @group sprint-5
     */
    public function testAnEmptyPropertiesObjectIsHonoured(): void
    {
        $schema = ['type' => 'object', 'properties' => new \stdClass()];

        self::assertSame([], SchemaValidator::validateArguments([], $schema));
        self::assertSame(
            ['/anything: unknown property - this tool declares no such argument'],
            SchemaValidator::validateArguments(['anything' => 1], $schema)
        );
    }
}
