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
            // ASKED AS `anyOf`, and the row says so rather than being quietly exempted. See
            // testOneOfIsAskedOfCoreAsAnyOfSoALegitimateValueIsNotRefused() for the reason.
            'oneOf'            => ['oneOf', ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]], 'twenty', 'anyOf'],

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
     * EVERY KEYWORD THIS SERVER CANNOT ENFORCE AS WRITTEN IS REMOVED, at any depth and in every
     * holder a sub-schema can sit in - and the rest of the schema survives untouched.
     *
     * THIS IS THE INVARIANT ROUND 1 CLAIMED AND DID NOT HOLD (review 85 B1). A keyword in the
     * dialect is not the same as a keyword core applies: `exclusiveMinimum` without `minimum` is
     * read by nothing (rest-api.php:2614), and neither is `format` beside `type: integer`, because
     * core dispatches on type first. Round 1 published both and enforced neither, which is the
     * silent decoration this sprint exists to remove - one level out, in the schema rather than in
     * the validator.
     *
     * A HOLDER THIS WALK DOES NOT COVER IS A HOLE EXACTLY AS WIDE, which is why every holder gets a
     * row: `wpmcp_tools()` and tests/unit/ToolContractTest.php both stand on this one method, so a
     * missed holder is missed for the catalog AND for every third party at once.
     *
     * @dataProvider unenforceableCases
     * @group sprint-validator
     */
    public function testEveryUnenforceableKeywordIsStrippedWhereverItSits(string $where, array $schema, array $expected): void
    {
        [$stripped, $removed] = SchemaValidator::enforceable($schema);

        self::assertSame(
            $expected,
            $removed,
            "In {$where}, enforceable() did not report what it cannot enforce. A keyword it does not"
            . ' report is one tools/list publishes with nothing applying it.'
        );

        foreach ($expected as $path) {
            $keyword = substr($path, strrpos($path, '/') + 1);

            self::assertStringNotContainsString(
                '"' . $keyword . '"',
                (string) json_encode($stripped),
                "In {$where}, '{$keyword}' was reported but is still in the schema, so tools/list"
                . ' still publishes it.'
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function unenforceableCases(): array
    {
        return [
            // Nothing wrong: the whole dialect, correctly typed, survives.
            'a schema that is entirely enforceable' => [
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

            // (1) OUTSIDE THE DIALECT, in each holder a sub-schema can sit in.
            'unknown at the top level'    => ['the top level', ['type' => 'object', '$schema' => 'x'], ['/$schema']],
            'unknown in a property'       => ['a property', ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/x']]], ['/properties/a/$ref']],
            'unknown in items'            => ['items', ['type' => 'array', 'items' => ['allOf' => []]], ['/items/allOf']],
            'unknown in addlProperties'   => ['additionalProperties', ['type' => 'object', 'additionalProperties' => ['not' => []]], ['/additionalProperties/not']],
            'unknown in patternProps'     => ['patternProperties', ['type' => 'object', 'patternProperties' => ['^m_' => ['const' => 1]]], ['/patternProperties/^m_/const']],
            'unknown in an anyOf branch'  => ['an anyOf branch', ['type' => 'string', 'anyOf' => [['type' => 'string'], ['examples' => []]]], ['/anyOf/1/examples']],
            'unknown in a oneOf branch'   => ['a oneOf branch', ['type' => 'string', 'oneOf' => [['deprecated' => true]]], ['/oneOf/0/deprecated']],
            'unknown two levels down'     => ['two levels down', ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['readOnly' => true]]]]], ['/properties/a/properties/b/readOnly']],

            // (2) THE EXCLUSIVE BOUND FLAGS - review 85 B1, VERIFIED over HTTPS on round 1's code.
            'exclusiveMinimum with no minimum' => [
                'a lone exclusiveMinimum',
                ['type' => 'integer', 'exclusiveMinimum' => true],
                ['/exclusiveMinimum'],
            ],
            'exclusiveMaximum with no maximum' => [
                'a lone exclusiveMaximum',
                ['type' => 'integer', 'exclusiveMaximum' => true],
                ['/exclusiveMaximum'],
            ],
            // The 2020-12 NUMERIC form. Core is draft-04 and reads `exclusiveMinimum: 0` through
            // `! empty( 0 )`, which is false - so it treats the bound as INCLUSIVE and accepts 0.
            // Worse than unenforced: silently the opposite of what the schema says.
            'the numeric 2020-12 form'         => [
                'the numeric exclusiveMinimum form',
                ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 0],
                ['/exclusiveMinimum'],
            ],
            'a numeric form with no bound'     => [
                'a numeric exclusiveMaximum with no maximum',
                ['type' => 'integer', 'exclusiveMaximum' => 5],
                ['/exclusiveMaximum'],
            ],
            // And the flag survives when it is written the way core reads it.
            'the pair core actually reads'      => [
                'a correctly paired exclusiveMinimum',
                ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true],
                [],
            ],

            // (3) A TYPE-SPECIFIC KEYWORD CORE'S TYPE DISPATCH NEVER REACHES.
            'format beside a non-string type' => [
                'format on an integer',
                ['type' => 'integer', 'format' => 'email'],
                ['/format'],
            ],
            'minItems beside a string type'  => [
                'minItems on a string',
                ['type' => 'string', 'minItems' => 2],
                ['/minItems'],
            ],
            'minProperties on an array'      => [
                'minProperties on an array',
                ['type' => 'array', 'minProperties' => 1],
                ['/minProperties'],
            ],
            // AN ARRAY KEYWORD WITH NO `type` AT ALL, which review 85 S5 measured: askCore() sends
            // the VALUE's type, `typeName([])` is `object` because json_decode('{}') and
            // json_decode('[]') are the same PHP value, so `{minItems: 1}` with `[]` reaches core's
            // OBJECT validator and the empty list - the one value it exists to refuse - is accepted.
            'minItems with no type'          => ['minItems with no type', ['minItems' => 1], ['/minItems']],
            'uniqueItems with no type'       => ['uniqueItems with no type', ['uniqueItems' => true], ['/uniqueItems']],

            // BUT A MISSING `type` IS NOT A DEFECT FOR THE OTHER GROUPS, and these rows are what
            // stops the rule above from being widened into one. typeName() is exact for a string and
            // a number, so `{pattern: ...}` is enforced for a string and skipped for an integer -
            // which is what JSON Schema says `pattern` does. Nothing to strip.
            'pattern needs no type'          => ['pattern with no type', ['pattern' => '^x$'], []],
            'minimum needs no type'          => ['minimum with no type', ['minimum' => 1], []],
            'minProperties needs no type'    => ['minProperties with no type', ['minProperties' => 1], []],

            // AND THE KEYWORDS THIS CLASS ENFORCES ITSELF NEED NO `type` EITHER, because checkObject()
            // and check() work from the SHAPE OF THE VALUE and never read the declared type. An
            // earlier draft of this round had them in APPLIES_TO, which would have stripped
            // `properties` off any third-party schema that omits `type: object` - and
            // validateArguments() closes the top level by default, so every argument the tool has
            // would then have been refused as undeclared. No built-in omits it, so the catalog would
            // not have shown it. These rows are that near-miss, held.
            'properties need no type'        => [
                'properties on a node with no type',
                ['properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
                [],
            ],
            'items need no type'             => ['items with no type', ['items' => ['type' => 'integer']], []],
            'additionalProperties no type'   => ['additionalProperties with no type', ['additionalProperties' => false], []],
            'patternProperties no type'      => [
                'patternProperties on a node with no type',
                ['patternProperties' => ['^m_' => ['type' => 'integer']]],
                [],
            ],

            // A type-INDEPENDENT keyword is never touched by any of it.
            'enum needs no type'             => ['enum with no type', ['enum' => [1, 2]], []],
            'anyOf needs no type'            => ['anyOf with no type', ['anyOf' => [['type' => 'string']]], []],
        ];
    }

    /**
     * A non-map stops the descent rather than erroring, and a clean schema comes back IDENTICAL.
     *
     * The identity half is what `wpmcp_tools()` leans on to stay cheap - it publishes the returned
     * array, so a walk that rebuilt every schema into an equal-but-different one would still be
     * correct and would still be worth knowing about.
     *
     * @group sprint-validator
     */
    public function testEnforceableLeavesACleanSchemaAloneAndSurvivesANonMap(): void
    {
        $clean = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['a' => ['type' => 'string']],
        ];

        self::assertSame([$clean, []], SchemaValidator::enforceable($clean));

        // `additionalProperties: false` is a BOOLEAN in a holder that usually carries a schema.
        self::assertSame(
            [['type' => 'object', 'additionalProperties' => false], []],
            SchemaValidator::enforceable(['type' => 'object', 'additionalProperties' => false])
        );
        self::assertSame([['type' => 'object'], []], SchemaValidator::enforceable(['type' => 'object']));
        self::assertSame(['not a schema at all', []], SchemaValidator::enforceable('not a schema at all'));
    }

    /**
     * `oneOf` IS ASKED OF CORE AS `anyOf`, so a value legal under one branch is not refused.
     *
     * REVIEW 85 S2, VERIFIED on a real site: `oneOf: [integer, boolean]` refused the integer `1`.
     * `rest_is_boolean(1)` is true (rest-api.php:1556-1577), so core counted two matching branches
     * and answered "matches more than one of the expected formats" to a caller who had done nothing
     * wrong and had no way to comply. A false refusal is worse than a missing constraint. Under a
     * COERCIVE branch matcher the exactly-one count is a property of core's coercions rather than of
     * the value, so branch membership is the part worth enforcing.
     *
     * The observable form is what core is ASKED, which is the only thing this tier can see; the
     * consequence - `1` accepted on a real site - is
     * tests/integration/SchemaKeywordsTest::testOneOfAcceptsAValueLegalUnderAnyBranch().
     *
     * @group sprint-validator
     */
    public function testOneOfIsAskedOfCoreAsAnyOfSoALegitimateValueIsNotRefused(): void
    {
        $branches = [['type' => 'integer'], ['type' => 'boolean']];

        SchemaValidator::validate(1, ['oneOf' => $branches]);

        $calls = WordPressRuntime::schemaCalls();

        self::assertCount(1, $calls, 'The combinator was not handed to core in one piece.');
        self::assertArrayNotHasKey(
            'oneOf',
            $calls[0]['args'],
            'Core was asked about `oneOf`, so it will count matching branches with its own coercive'
            . ' type checks and refuse the integer 1 for a schema that permits integers.'
        );
        self::assertSame(
            $branches,
            $calls[0]['args']['anyOf'] ?? null,
            'The oneOf branches did not reach core as anyOf, unchanged.'
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
