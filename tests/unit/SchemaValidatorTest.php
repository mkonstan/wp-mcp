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
     * `enum`, `minimum`/`maximum`, `minLength`/`maxLength`, `items` - the keywords the
     * dialect supports ahead of the built-in schemas using them.
     *
     * NO BUILT-IN USES THESE TODAY (see ToolContractTest::testEveryBuiltInSchemaStaysInsideTheDialect), and they are implemented
     * anyway for one reason: an unsupported keyword is not enforced, so a schema author
     * who reaches for `enum` would otherwise get silence. Tested so the support is real.
     *
     * @group sprint-5
     */
    public function testTheRemainingKeywords(): void
    {
        $enum = ['type' => 'string', 'enum' => ['approve', 'spam']];
        self::assertSame([], SchemaValidator::validate('spam', $enum));
        $failures = SchemaValidator::validate('burn', $enum);
        self::assertCount(1, $failures, implode(' | ', $failures));
        self::assertStringContainsString('approve, spam', $failures[0], $failures[0]);
        // Strictly, so that 0 is not "one of" "0".
        self::assertSame([], SchemaValidator::validate(0, ['enum' => [0, 1]]));
        self::assertCount(1, SchemaValidator::validate('0', ['enum' => [0, 1]]));

        $bounded = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100];
        self::assertSame([], SchemaValidator::validate(1, $bounded));
        self::assertSame([], SchemaValidator::validate(100, $bounded));
        self::assertSame(['(root): must be >= 1'], SchemaValidator::validate(0, $bounded));
        self::assertSame(['(root): must be <= 100'], SchemaValidator::validate(101, $bounded));

        $sized = ['type' => 'string', 'minLength' => 2, 'maxLength' => 4];
        self::assertSame([], SchemaValidator::validate('ab', $sized));
        self::assertSame([], SchemaValidator::validate('abcd', $sized));
        self::assertCount(1, SchemaValidator::validate('a', $sized));
        self::assertCount(1, SchemaValidator::validate('abcde', $sized));

        $list = ['type' => 'array', 'items' => ['type' => 'integer']];
        self::assertSame([], SchemaValidator::validate([1, 2, 3], $list));
        self::assertSame([], SchemaValidator::validate([], $list));
        self::assertSame(
            ['/1: expected integer, got string'],
            SchemaValidator::validate([1, 'two'], $list),
            'items must report the INDEX of the bad element.'
        );
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
