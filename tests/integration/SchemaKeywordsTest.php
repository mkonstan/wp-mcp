<?php
/**
 * SPRINT VALIDATOR, THE HALF THE UNIT TIER CANNOT MAKE: core really does enforce the thirteen.
 *
 * `SchemaValidator` stopped implementing JSON Schema and started handing every keyword core owns
 * to `rest_validate_value_from_schema()`. tests/unit/SchemaValidatorTest.php can prove the
 * hand-over - which keyword goes over, in which call, with which type, and that a strict type
 * failure never reaches core at all - because it drives a recording double. It cannot prove that
 * the keyword is then ENFORCED, because that is a fact about WordPress and the unit tier has no
 * WordPress in it (phpunit.xml.dist: "pure PHP, no WordPress, no Docker").
 *
 * So this class makes that claim the only way it can be made: a mu-plugin registers one tool
 * whose inputSchema uses all thirteen, and every case below is a real `tools/call` over HTTP
 * against a real site. Before this sprint each one of them SUCCEEDED - the keyword was not in the
 * validator's list, nothing looked at it, and the argument reached the tool body unchecked with
 * no error and no log line. THE_TOOL_RAN in the response body is what that looked like, and its
 * absence is what each case asserts.
 *
 * WHY ONE TOOL WITH THIRTEEN PROPERTIES rather than thirteen tools: a tool is registered once per
 * request to the endpoint, so thirteen of them would be thirteen entries in every tools/list this
 * site serves while the fixture is up, including a concurrent runner's. One tool with one property
 * per keyword costs one entry and each case sends exactly one argument, which is also what keeps
 * a failure naming one keyword.
 *
 * NOTHING HERE NEEDS ACF, A NETWORK INSTALL OR REAL CONTENT, so nothing in it skips - the gate
 * group rule. It needs a site, which every test in this suite does.
 *
 * @group sprint-validator
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\CoreSchemaKeywords;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class SchemaKeywordsTest extends FixtureIntegrationTestCase
{
    /** What the fixture tool's run callback returns. Its ABSENCE is the assertion. */
    private const RAN = 'THE-TOOL-RAN';

    private const TOOLS = 'schema-keywords';

    private static int $userId = 0;
    private static string $token = '';

    private static function label(): string { return Fixtures::name('schemakw'); }
    private static function login(): string { return Fixtures::name('schemakw-author'); }

    /** The tool whose inputSchema uses all thirteen keywords. */
    private static function subject(): string { return Fixtures::name('tool-keywords'); }

    /** A tool whose inputSchema uses a keyword the dialect does not contain. */
    private static function unknown(): string { return Fixtures::name('tool-unknown-keyword'); }

    /** A tool whose `enum` holds a value that is not valid UTF-8. */
    private static function badEnum(): string { return Fixtures::name('tool-bad-enum'); }

    /** A tool whose exclusive bound core cannot read as the schema means it. */
    private static function badBound(): string { return Fixtures::name('tool-bad-bound'); }

    /** A tool whose `properties` is a non-empty stdClass - the shape that broke tools/list. */
    private static function objectProps(): string { return Fixtures::name('tool-object-props'); }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        TestRecorder::install();
        MuPlugin::drop(self::TOOLS, self::source());

        self::$userId = Fixtures::createUser(self::login(), 'author');
        self::$token  = Fixtures::mintToken('read', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::TOOLS);
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * THE CONTROL, and every other test in this class is worthless without it: the tool is
     * registered, callable, and RUNS when its arguments satisfy all thirteen keywords.
     *
     * A refusal is only evidence if acceptance is possible. Without this, a validator that
     * refused every call would pass every case below.
     *
     * @group sprint-validator
     */
    public function testTheFixtureToolRunsWhenEveryKeywordIsSatisfied(): void
    {
        $body = $this->call([
            'email'    => 'someone@example.com',
            'slug'     => 'good',
            'pair'     => ['a' => 1, 'b' => 2],
            'gt'       => 6,
            'lt'       => 4,
            'fives'    => 10,
            'few'      => [1, 2],
            'many'     => [1],
            'distinct' => [1, 2, 3],
            'either'   => true,
            'exactly'  => true,
            'meta'     => ['m_hits' => 7],
            'limit'    => 5,
            'shaped'   => ['a' => 1],
            'looseItems'  => [1, 2],
            'looseUnique' => [1, 2],
            'branchBound' => [1, 2],
        ]);

        self::assertStringContainsString(
            self::RAN,
            $body,
            'The fixture tool did not run on arguments that satisfy every keyword, so this class'
            . ' cannot distinguish "the keyword is enforced" from "the tool is broken". Body: '
            . $body
        );
    }

    /**
     * ONE CASE PER NEWLY ENFORCED KEYWORD: the argument is refused and the tool does not run.
     *
     * EVERY ROW HERE PASSED BEFORE SPRINT VALIDATOR, which is the sprint's whole claim. The
     * keyword was absent from the validator's dialect, so it was not an error and not a log line:
     * the tool ran with the bad value. `format` let `not-an-email` through to a tool that would
     * have mailed it; `multipleOf` let 7 through where 5 was the step; `uniqueItems` let a list of
     * duplicates through to a loop that writes once per element.
     *
     * The assertion is deliberately NOT on core's message text. Those sentences are core's, they
     * are localized, and `_n()` picks between a singular and a plural form - so asserting them
     * would be asserting a translation. What is asserted is the refusal, the absence of the run
     * marker, and the POINTER, which is ours and is the part a client acts on.
     *
     * @dataProvider refusedArguments
     * @group sprint-validator
     */
    public function testEachNewlyEnforcedKeywordRefusesABadArgument(
        string $keyword,
        array $arguments,
        string $pointer,
        string $detail = ''
    ): void {
        $body = $this->call($arguments);

        self::assertStringNotContainsString(
            self::RAN,
            $body,
            "'{$keyword}' did not stop the call: the tool ran with an argument that violates it,"
            . ' which is exactly what happened before this keyword was delegated to core. Body: '
            . $body
        );
        self::assertStringContainsString(
            'Invalid arguments for ' . self::subject(),
            $body,
            "'{$keyword}' produced something other than the validator's refusal. Body: " . $body
        );
        self::assertStringContainsString(
            $pointer,
            $body,
            "'{$keyword}' was refused without naming {$pointer}, so the caller cannot tell which"
            . ' argument to fix. Body: ' . $body
        );

        // A ROW MAY NEED TO SAY WHY, because a refusal at the right pointer for the WRONG reason
        // is a pass this test cannot otherwise see. `patternProperties` is the case: before this
        // sprint the pattern was unknown, so `meta.m_hits` was refused as an undeclared key on a
        // closed object - right pointer, nothing to do with the keyword. Only a row whose refusal
        // could be produced by something other than its own keyword carries a $detail.
        if ($detail !== '') {
            self::assertStringContainsString(
                $detail,
                $body,
                "'{$keyword}' was refused at {$pointer} but not for the reason the keyword gives,"
                . ' so the case would pass on a validator that does not know the keyword at all.'
                . ' Body: ' . $body
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3?: string}>
     */
    public static function refusedArguments(): array
    {
        return [
            'format'            => ['format', ['email' => 'not-an-email'], '/email'],
            'pattern'           => ['pattern', ['slug' => 'NOT-LOWER'], '/slug'],
            'minProperties'     => ['minProperties', ['pair' => ['a' => 1]], '/pair'],
            'maxProperties'     => ['maxProperties', ['pair' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]], '/pair'],
            'exclusiveMinimum'  => ['exclusiveMinimum', ['gt' => 5], '/gt'],
            'exclusiveMaximum'  => ['exclusiveMaximum', ['lt' => 5], '/lt'],
            'multipleOf'        => ['multipleOf', ['fives' => 7], '/fives'],
            'minItems'          => ['minItems', ['few' => [1]], '/few'],
            'maxItems'          => ['maxItems', ['many' => [1, 2, 3]], '/many'],
            'uniqueItems'       => ['uniqueItems', ['distinct' => [1, 1]], '/distinct'],
            'anyOf'             => ['anyOf', ['either' => 'twenty'], '/either'],
            // patternProperties has two halves: a matched member is validated against the
            // pattern's schema, and an unmatched one is still refused by additionalProperties.
            'patternProperties' => ['patternProperties', ['meta' => ['m_hits' => 'not-a-number']], '/meta/m_hits', 'expected integer, got string'],

            // REVIEW 85 R2-B1, THE THREE THAT WENT PERMISSIVE ON `4a7c0f7`. Each of these RAN there,
            // because round 2 removed the keyword from the schema dispatch validates against. They
            // are in this provider rather than a test of their own because they are exactly what it
            // already asserts: the keyword refuses a bad argument and names the pointer.
            'minItems, no type'  => ['minItems with no declared type', ['looseItems' => [1]], '/looseItems'],
            'uniqueItems, no type' => ['uniqueItems with no declared type', ['looseUnique' => [1, 1]], '/looseUnique'],
            'a bound in a branch' => ['minItems in a type-less anyOf branch', ['branchBound' => [1]], '/branchBound'],
        ];
    }

    /**
     * Every keyword this sprint claims to have newly enforced has a row above - and the ONE it
     * declines is named here rather than quietly missing.
     *
     * The thirteen are not a round number somebody remembered: they are
     * `rest_get_allowed_schema_keywords()` minus what the validator already enforced. Twelve are
     * delivered. `oneOf` is NOT, and that is a decision with a docblock rather than an omission - see
     * SchemaValidator::DELEGATED, and testOneOfIsNotEnforcedAndNotPublished() below. A row missing
     * from either list is a keyword this sprint claimed and did not demonstrate.
     *
     * @group sprint-validator
     */
    public function testTwelveOfTheThirteenAreDemonstratedAndTheThirteenthIsDeclined(): void
    {
        $covered = [];

        foreach (self::refusedArguments() as [$keyword]) {
            $covered[$keyword] = true;
        }

        $delivered = [
            'format', 'pattern', 'patternProperties', 'minProperties', 'maxProperties',
            'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minItems', 'maxItems',
            'uniqueItems', 'anyOf',
        ];

        foreach ($delivered as $keyword) {
            self::assertArrayHasKey(
                $keyword,
                $covered,
                "The sprint claims '{$keyword}' is now enforced and no case here demonstrates it"
                . ' against a real site.'
            );
        }

        self::assertCount(12, $delivered);
        self::assertArrayNotHasKey(
            'oneOf',
            $covered,
            'There is a row asserting `oneOf` refuses something, so it IS enforced - and then it must'
            . ' be back in the dialect and must enforce EXACTLY ONE, not at-least-one.'
        );
    }

    /**
     * A member that matches a `patternProperties` pattern is NOT an additional property, and one
     * that does not is still refused.
     *
     * Both halves, because either alone is satisfiable by a bug. Core's precedence is declared
     * property, then pattern, then additional; reversed, every pattern-matched key would be
     * refused on a closed object, which is every tool schema.
     *
     * @group sprint-validator
     */
    public function testAPatternMatchedMemberIsNotAnAdditionalProperty(): void
    {
        self::assertStringContainsString(
            self::RAN,
            $this->call(['meta' => ['m_hits' => 7, 'm_misses' => 0]]),
            'A member matching ^m_ was refused on an object that closes additionalProperties, so'
            . " the pattern lost to the closed default - the reverse of core's own precedence."
        );

        $body = $this->call(['meta' => ['unmatched' => 1]]);

        self::assertStringNotContainsString(
            self::RAN,
            $body,
            'A member matching no pattern was accepted on a closed object, so patternProperties'
            . ' has become an escape from additionalProperties: false. Body: ' . $body
        );
        self::assertStringContainsString('/meta/unmatched', $body, $body);
    }

    /**
     * ADDITION 1 ON A REAL SITE: `"20"` is still refused for an integer parameter.
     *
     * This is the one thing delegation could quietly have taken away. `rest_is_integer("20")` is
     * TRUE - core coerces deliberately, because REST arguments arrive from query strings - so a
     * validator that simply called core would accept it, and the tools cast: `(int) "twenty"` is
     * 0, `(int) "5 posts"` is 5. Asserted here rather than only against the double, because the
     * double cannot coerce and therefore cannot fail this.
     *
     * @group sprint-validator
     */
    public function testANumericStringIsStillRefusedForAnIntegerArgument(): void
    {
        $body = $this->call(['limit' => '20']);

        self::assertStringNotContainsString(
            self::RAN,
            $body,
            'A numeric STRING was accepted for an integer argument, so the strict type check is'
            . " no longer ahead of core's coercive one. Body: " . $body
        );
        self::assertStringContainsString('/limit: expected integer, got string', $body, $body);

        // AND A BOOLEAN, which core's own number validator also lets through: `is_numeric(true)`
        // is false, so core answers rest_invalid_type here too - but only because `limit` declares
        // a type. The point of the row is that the refusal is OURS and reads in our words.
        //
        // NOT A WHOLE FLOAT, although rest_is_integer(20.0) is true and the unit tier's type table
        // covers it: `json_encode(20.0)` is `20` without JSON_PRESERVE_ZERO_FRACTION, so the value
        // arrives as an integer and the case cannot be expressed over this wire at all.
        self::assertStringContainsString(
            '/limit: expected integer, got boolean',
            $this->call(['limit' => true]),
            'A boolean was accepted for an integer argument.'
        );
    }

    /**
     * ADDITION 2 ON A REAL SITE: two bad arguments come back in ONE refusal.
     *
     * Core returns the first `WP_Error` and stops. A client fixing one argument per round trip is
     * a worse tool, and this is the observable form of the difference.
     *
     * @group sprint-validator
     */
    public function testEveryBadArgumentComesBackInOneRefusal(): void
    {
        $body = $this->call(['slug' => 'NOT-LOWER', 'fives' => 7, 'limit' => '20']);

        foreach (['/slug', '/fives', '/limit'] as $pointer) {
            self::assertStringContainsString(
                $pointer,
                $body,
                "{$pointer} is missing from the refusal, so the caller has to fix one argument per"
                . ' round trip. Body: ' . $body
            );
        }
    }

    /**
     * ADDITION 3 ON A REAL SITE: no core ERROR CODE and no caller VALUE reaches the client.
     *
     * Core's validator answers `rest_invalid_param`, `rest_too_short`, `rest_not_in_enum` and a
     * dozen more. This plugin's contract is that every error it emits carries the `wpmcp_` prefix,
     * and the resolution for delegation is that core's codes are DROPPED rather than mapped - only
     * the message survives. And core interpolates its `$param` into every message, which is why it
     * is asked with an empty one: a caller-supplied key must not come back untruncated.
     *
     * @group sprint-validator
     */
    public function testNoCoreErrorCodeAndNoCallerValueComesBack(): void
    {
        $secret = 'SENSITIVE-VALUE-THAT-MUST-NOT-COME-BACK';
        $body   = $this->call(['slug' => $secret, 'email' => $secret]);

        self::assertStringNotContainsString(
            'rest_',
            $body,
            "A core error CODE reached the client. Codes are wire-visible and this plugin's"
            . ' contract is a wpmcp_ prefix on every one it emits. Body: ' . $body
        );
        self::assertStringNotContainsString(
            $secret,
            $body,
            'A caller-supplied VALUE came back in the refusal, most likely through one of core\'s'
            . ' own messages. Body: ' . $body
        );
        self::assertStringContainsString('/slug', $body, $body);
    }

    /**
     * A PERMITTED VALUE THAT IS NOT VALID UTF-8 IS STILL NAMED IN THE LIST OF PERMITTED VALUES.
     *
     * MOVED HERE FROM tests/unit/CoreFixTest.php BY SPRINT VALIDATOR, and the move is the finding.
     * Sprint CORE-FIX found that `SchemaValidator::asList()` used bare `json_encode()`, which
     * returns FALSE on JSON_ERROR_UTF8, and `false` concatenates as `''` - so the one value the
     * caller needed to see vanished from the message that lists permitted values. The fix was
     * `wp_json_encode()`, whose `_wp_json_sanity_check()` strips the bad bytes.
     *
     * `asList()` is gone. `enum` is core's now, and core's `rest_validate_enum()` builds the same
     * list with the same rule - `is_scalar($v) ? $v : wp_json_encode($v)`
     * (wp-includes/rest-api.php:2148-2151). So the guarantee is unchanged and is no longer ours,
     * which is D32 exactly: the restatement is gone and the platform's own decision is what holds.
     * The test had to move tiers because a stubbed answer in the unit tier would be asserting our
     * double's behaviour, not core's.
     *
     * @group sprint-validator
     * @group sprint-core-fix
     */
    public function testAnEnumValueCannotVanishFromTheListOfPermittedValues(): void
    {
        $text = $this->textOf(self::badEnum(), ['shape' => 'nope']);

        self::assertStringNotContainsString(
            self::RAN,
            $text,
            'A value outside the enum was accepted: ' . $text
        );
        // EXACTLY ONE FAILURE LINE, which is the assertion the move out of CoreFixTest lost
        // (review 85 S6). The old unit test said `assertCount(1, $failures)`; over the wire the
        // failure list arrives as `Invalid arguments for <tool>:` followed by one line per failure,
        // so one failure is two lines and no more. It is not decoration: a second line would mean
        // the enum node produced another complaint as well, and "the permitted value survives
        // encoding" would then be a claim about whichever line happened to be first.
        self::assertCount(
            2,
            explode("\n", $text),
            'Expected exactly one failure line under the header, got: ' . $text
        );
        self::assertStringContainsString(
            'shape',
            $text,
            'The permitted value is missing from the message that lists permitted values, which is'
            . ' what bare json_encode() does to a value that is not valid UTF-8: it answers false,'
            . ' and false concatenates as the empty string. Got: ' . $text
        );

        // BOTH SIDES OF THE BAD BYTE, which is what tells a mangled value from a lost one. The
        // sanity check does not DELETE the byte, it converts the string and leaves `?` where the
        // byte was - measured: the message reads `{"shape":"bad?value"}`. So "bad" alone would also
        // be true of a value truncated at the bad byte, and "value" is the half that would be gone.
        foreach (['bad', 'value'] as $half) {
            self::assertStringContainsString(
                $half,
                $text,
                "The '{$half}' half of the permitted value did not survive encoding, so the value"
                . ' arrived truncated rather than sanitized. Got: ' . $text
            );
        }
    }

    /**
     * THIRD-PARTY TOOLS: a keyword this server cannot enforce is STRIPPED from what is published,
     * the tool survives, and an event says which keyword went.
     *
     * ROUND 1 REFUSED THE WHOLE TOOL AND THE LEDGER ROW WAS MISFILED (review 85 S4). WordPress had
     * already decided how to treat a schema keyword it cannot validate, and it decided to STRIP and
     * keep going - `rest_get_endpoint_args_for_schema()` (rest-api.php:3395-3426) at our floor, and
     * WP 7.1's `wp_prepare_json_schema_for_client()` for the exact context `tools/list` is. A
     * decision is inherited.
     *
     * WHAT IS ASSERTED IS THE INVARIANT, NOT THE MECHANISM: the published schema contains no
     * constraint this server does not apply. Four shapes, one per reason - a keyword outside the
     * dialect, a lone exclusive bound flag, the 2020-12 numeric form core misreads, and a
     * type-specific keyword on the wrong type.
     *
     * THIS FAILS ON `1adb96e` ON ALL FOUR. `const` refused the tool outright, so it was absent from
     * the listing; the other three were in the dialect, passed registration, and were PUBLISHED with
     * nothing applying them - which is review 85 B1, measured over HTTPS with a probe tool that ran
     * with `-5` against `exclusiveMinimum: 0`.
     *
     * @group sprint-validator
     */
    public function testAnUnenforceableKeywordIsStrippedFromWhatIsPublishedAndTheToolSurvives(): void
    {
        TestRecorder::reset();

        $listing = $this->listing();

        self::assertArrayHasKey(
            self::subject(),
            $listing,
            'The subject tool is missing from the listing, so the wpmcp_tools filter never ran and'
            . ' this test proves nothing.'
        );
        self::assertArrayHasKey(
            self::unknown(),
            $listing,
            'A tool carrying an unenforceable keyword was REFUSED rather than stripped. Core strips;'
            . ' refusing costs a third party its whole tool over a keyword that was doing nothing.'
        );

        $published  = (string) json_encode($listing[self::unknown()]['inputSchema'] ?? null);
        $properties = $listing[self::unknown()]['inputSchema']['properties'] ?? [];

        foreach (['const', 'required'] as $keyword) {
            self::assertStringNotContainsString(
                '"' . $keyword . '"',
                $published,
                "tools/list publishes `{$keyword}` on a tool where nothing applies it, so the schema"
                . ' claims a constraint the server does not keep. Published: ' . $published
            );
        }

        // AND THE ENFORCEABLE PART SURVIVED, or "strip" would just be "delete the schema". `minimum`
        // beside the stripped numeric flag still constrains, and every property is still declared.
        self::assertSame(
            ['a', 'legacy'],
            array_keys($properties),
            'The reduction removed a PROPERTY rather than a keyword: ' . $published
        );
        self::assertSame(
            2,
            $properties['a']['minLength'] ?? null,
            'The enforced sibling of the unpublished keyword went with it, so the reduction removes'
            . ' more than it reports: ' . $published
        );
        self::assertSame('string', $properties['a']['type'] ?? null, $published);

        // The tool still runs, which is the whole point of stripping rather than refusing.
        self::assertStringContainsString(
            self::RAN,
            $this->textOf(self::unknown(), ['a' => 'xx', 'legacy' => 'y']),
            'A tool whose published schema was reduced is not callable, so the reduction refused it'
            . ' by another route.'
        );

        // AND THE ABSENCE IS EXPLICABLE. Inheriting core's behaviour is not inheriting its silence.
        $stripped = '';

        foreach (TestRecorder::detailsOf(TestRecorder::AUTH . 'registry_strip') as $context) {
            if ((string) ($context['tool'] ?? '') === self::unknown()) {
                $stripped = (string) ($context['keywords'] ?? '');
            }
        }

        foreach (['/properties/a/const', '/properties/legacy/required'] as $path) {
            self::assertStringContainsString(
                $path,
                $stripped,
                "No registry_strip event named {$path}, so its author cannot find out why the"
                . ' constraint never fires. Event: ' . $stripped
            );
        }
        self::assertSame(
            [],
            array_filter(
                TestRecorder::detailsOf(TestRecorder::AUTH . 'registry_strip'),
                static fn (array $c): bool => !str_starts_with((string) ($c['tool'] ?? ''), 'wpmcp-test-')
            ),
            'A registry_strip event named a tool that is not one of this run\'s fixtures - so a'
            . ' BUILT-IN publishes a keyword nothing enforces, which tests/unit/ToolContractTest.php'
            . ' is supposed to make impossible.'
        );
    }

    /**
     * A caller's KEY does not reach the wire through a combinator's object branch.
     *
     * REVIEW 85 B2, and it is the one place the shipped invariant was false. `$param` is sent empty
     * so core cannot interpolate our pointer, but core builds `%1$s is not a valid property of
     * Object` from the caller's own `$property` when it validates an object BRANCH
     * (rest-api.php:2467), and `rest_format_combining_operation_error()` relays that verbatim as
     * "Reason: ..." (:1909). Measured over HTTPS on `1adb96e`: a 400-character key carrying
     * `<script>` came back whole. So this test FAILS on `1adb96e`, through exactly that path.
     *
     * THE KEY IS THE ATTACKER-CONTROLLED PART, by construction - it is an arbitrary JSON object
     * member. Values were never the leak here and still are not; asserted anyway, because a fix that
     * traded one for the other would otherwise look green.
     *
     * @group sprint-validator
     */
    public function testACallerKeyDoesNotReachTheWireThroughACombinatorObjectBranch(): void
    {
        $key    = str_repeat('K', 400) . '<script>alert(1)</script>';
        $secret = 'SENSITIVE-VALUE-THAT-MUST-NOT-COME-BACK';

        // `shaped`'s first branch is a CLOSED object, so an undeclared member is the refusal core
        // builds from the property name. The second branch is a boolean, so neither branch matches
        // and the combinator really does fail.
        $body = $this->call(['shaped' => [$key => $secret]]);

        self::assertStringNotContainsString(
            self::RAN,
            $body,
            'The combinator accepted an object that matches neither branch: ' . $body
        );
        self::assertStringNotContainsString(
            str_repeat('K', 20),
            $body,
            'A caller-supplied KEY came back through a combinator branch message. It is not'
            . ' truncated, not escaped, and unbounded in length - the docblock and the CHANGELOG'
            . ' both promise otherwise. Body: ' . $body
        );
        self::assertStringNotContainsString('<script>', $body, $body);
        self::assertStringNotContainsString($secret, $body, $body);

        // And the refusal still NAMES the argument, or the fix would have thrown away the one part
        // of the message a caller can act on.
        self::assertStringContainsString('/shaped', $body, $body);
    }

    /**
     * `oneOf` IS NEITHER ENFORCED NOR PUBLISHED, which is the only arrangement of the three that is
     * honest.
     *
     * THE TWO ALTERNATIVES WERE BOTH MEASURED ON A REAL SITE AND BOTH ARE WRONG. Asking core for
     * `oneOf` enforces exactly-one over its COERCIVE per-branch type checks, so `[integer, boolean]`
     * refuses the integer `1` (`rest_is_boolean(1)` is true) - a false refusal the caller cannot
     * comply with (round 1 S2). Round 2 asked core for `anyOf` instead while still PUBLISHING
     * `oneOf`, and review 85 R2-S1 measured the gap with no coercion in it at all: `5` against
     * `[{integer,minimum:0},{integer,maximum:10}]` matches both branches, core's real `oneOf` refuses
     * it, and round 2 accepted it. A schema that says one thing while the server does another is the
     * defect this whole sprint exists to remove.
     *
     * So `oneOf` is declined: nothing enforces it, and nothing claims it either. Both halves are
     * asserted, because the first alone is what round 1 shipped for thirteen keywords.
     *
     * @group sprint-validator
     */
    public function testOneOfIsNotEnforcedAndNotPublished(): void
    {
        // NOT ENFORCED: `exactly` is oneOf [integer, boolean] and a string satisfies neither branch.
        self::assertStringContainsString(
            self::RAN,
            $this->call(['exactly' => 'twenty']),
            'A value satisfying no `oneOf` branch was refused, so something enforces the keyword. If'
            . ' that is deliberate it has to enforce EXACTLY ONE - the coercion artefact and R2-S1'
            . ' both come back otherwise - and `oneOf` has to rejoin the dialect.'
        );

        // AND NOT PUBLISHED: the client is never told a constraint is there.
        $published = (string) json_encode($this->listing()[self::subject()]['inputSchema'] ?? null);

        self::assertStringNotContainsString(
            '"oneOf"',
            $published,
            'tools/list advertises `oneOf` on a tool where nothing enforces it - which is exactly the'
            . ' round-2 defect, one keyword over. Published: ' . $published
        );
        // The property itself survives; only the unenforced keyword is left out.
        self::assertStringContainsString('"exactly"', $published, $published);
    }

    /**
     * PUBLICATION AND VALIDATION SEE DIFFERENT SCHEMAS, AND THAT IS THE POINT OF ROUND 3.
     *
     * Round 2 reduced the schema in `wpmcp_tools()`, whose output is also what `wpmcp_dispatch()`
     * validates against, so leaving a keyword out of the listing also stopped it being enforced -
     * and the tables it left keywords out by were wrong toward permissive, so nine arrangements went
     * from refused to running (review 85 R2-B1). The reduction now happens in the `tools/list`
     * emitter alone.
     *
     * The observable is a pair: `const` is ABSENT from the published schema, and the sibling
     * `minLength` on the same property is still ENFORCED. If the reduction had been applied to the
     * validated copy as well, the second assertion would still pass - `minLength` was never removed -
     * which is why the case that proves it is `looseItems` in the refusal provider above, a keyword
     * round 2 DID remove. This test is the publication half.
     *
     * @group sprint-validator
     */
    public function testWhatIsPublishedIsReducedAndWhatIsValidatedIsNot(): void
    {
        $published = $this->listing()[self::unknown()]['inputSchema'] ?? [];

        self::assertStringNotContainsString(
            '"const"',
            (string) json_encode($published),
            'tools/list publishes a keyword nothing can read.'
        );
        self::assertSame(
            2,
            $published['properties']['a']['minLength'] ?? null,
            'The sibling keyword went with it, so the reduction is removing more than it reports: '
            . json_encode($published)
        );

        $body = $this->textOf(self::unknown(), ['a' => 'x']);

        self::assertStringNotContainsString(
            self::RAN,
            $body,
            'The `minLength: 2` beside the unpublished `const` is not enforced, so validation is'
            . ' running against the reduced schema rather than the one the tool declared - which is'
            . ' review 85 R2-B1. Got: ' . $body
        );
        self::assertStringContainsString('/a', $body, $body);
    }

    /**
     * A CONSTRAINT CORE READS DIFFERENTLY FROM WHAT IT SAYS REFUSES REGISTRATION - it is not dropped.
     *
     * The one schema shape that costs a tool its registration, and review 85 R2-B1 is why it cannot
     * be handled like the others: with its partner present core IS enforcing something from a
     * numeric `exclusiveMinimum` (`! empty()`, rest-api.php:2615, so `5` reads as the draft-04
     * boolean and enforces `> minimum`), so dropping it would LOOSEN validation. And publishing it is
     * a false claim, because JSON Schema 2020-12 says `exclusiveMinimum: 5` means `> 5`. Neither is
     * honest, so the entry does not register and the reason names the spelling core does read.
     *
     * @group sprint-validator
     */
    public function testAToolWhoseBoundCoreCannotReadAsWrittenDoesNotRegister(): void
    {
        TestRecorder::reset();

        $listing = $this->listing();

        self::assertArrayHasKey(
            self::subject(),
            $listing,
            'The subject tool is missing, so the filter never ran and this test proves nothing.'
        );
        self::assertArrayNotHasKey(
            self::badBound(),
            $listing,
            'A tool declaring `minimum: 0, exclusiveMinimum: 5` registered. Core enforces `> 0` from'
            . ' that and the schema claims `> 5`, so the tool advertises a bound this server does not'
            . ' keep.'
        );

        $rejected = [];

        foreach (TestRecorder::detailsOf(TestRecorder::AUTH . 'registry_reject') as $context) {
            $rejected[(string) ($context['tool'] ?? '')] = (string) ($context['reason'] ?? '');
        }

        self::assertSame(
            'schema_constraint_unreadable',
            $rejected[self::badBound()] ?? null,
            'No registry_reject event named the tool with the unreadable bound, so its author learns'
            . ' nothing. Events: ' . json_encode($rejected)
        );
        self::assertStringContainsString(
            'Unknown tool',
            $this->rawCall(self::badBound(), []),
            'A tool refused at registration was still callable by name.'
        );
    }

    /**
     * ONE TOOL WITH AN OBJECT-FORM `properties` DOES NOT TAKE `tools/list` DOWN FOR THE SITE.
     *
     * REVIEW 85 R3-B1, VERIFIED OVER HTTPS THERE AND HERE. `properties` may legally be a `stdClass`;
     * the publication walk accepted one through asMap() and then wrote the reduced child back with an
     * array subscript, which throws. Because the walk runs in the `tools/list` emitter, the error
     * boundary turned the entire listing into `-32603 Internal error` - for every client of the site,
     * from one third-party tool - while `tools/call` kept working. So a client that already knew a
     * tool's name was fine and every client that discovers tools on connect saw nothing at all.
     *
     * THE ASSERTION IS THE LISTING ITSELF, not the one tool: `listing()` already fails on a non-200 or
     * an undecodable body, so any test here that calls it would have gone red - which is the point.
     * What this adds is the SHAPE, named, so the reason is legible instead of being a mystery -32603.
     *
     * @group sprint-validator
     */
    public function testAnObjectFormPropertiesDoesNotBreakTheListingForEveryone(): void
    {
        $listing = $this->listing();

        self::assertArrayHasKey(
            self::objectProps(),
            $listing,
            'The tool whose `properties` is a non-empty object is missing from the listing.'
        );
        self::assertArrayHasKey(
            self::subject(),
            $listing,
            'The subject tool is missing too, so the listing is broken rather than this one entry.'
        );

        $properties = $listing[self::objectProps()]['inputSchema']['properties'] ?? null;

        self::assertIsArray($properties, 'The published properties are not readable: ' . json_encode($listing[self::objectProps()]['inputSchema'] ?? null));
        self::assertArrayNotHasKey(
            'const',
            (array) ($properties['a'] ?? []),
            'The unenforceable keyword survived inside an object-form holder, so the walk descended'
            . ' into it and then failed to write the reduction back.'
        );
        self::assertSame(
            2,
            ($properties['a']['minLength'] ?? null),
            'The enforced sibling went with it: ' . json_encode($properties)
        );

        // And the tool still validates against the schema AS WRITTEN, object holder and all.
        self::assertStringContainsString(
            '/a',
            $this->textOf(self::objectProps(), ['a' => 'x']),
            'The `minLength: 2` inside an object-form `properties` is not enforced.'
        );
    }

    /**
     * A TYPE-LESS ARRAY BOUND STILL REFUSES THE EMPTY LIST.
     *
     * REVIEW 85's S5, carried from round 1 and the last real residual. `{"minItems": 2}` with no
     * declared `type` accepted `[]` - the value the keyword exists to forbid - because
     * `json_decode('{}')` and `json_decode('[]')` are the same PHP value and the validator had to pick
     * one type to tell core, picking `object`. MEASURED against real core before fixing: `[]` against
     * `{type: array, minItems: 1}` is REFUSED and against `{type: object, minItems: 1}` is accepted, so
     * core had the constraint all along and we were telling it the wrong type. Both readings are now
     * asked.
     *
     * @group sprint-validator
     */
    public function testATypeLessArrayBoundStillRefusesTheEmptyList(): void
    {
        $body = $this->call(['looseItems' => []]);

        self::assertStringNotContainsString(
            self::RAN,
            $body,
            'The empty list was accepted for `{"minItems": 2}`, which is the one value that keyword'
            . ' exists to refuse. Body: ' . $body
        );
        self::assertStringContainsString('/looseItems', $body, $body);

        // AND THE EMPTY OBJECT READING IS NOT LOST, or the fix would have traded one for the other:
        // `pair` is `{type: object, minProperties: 2}` and `{}` arrives as the same PHP value.
        self::assertStringNotContainsString(
            self::RAN,
            $this->call(['pair' => []]),
            'The empty value stopped being checked as an OBJECT, so minProperties no longer refuses it.'
        );
    }

    /**
     * THE LIVE CHECK ON A TRANSCRIPTION: this site's `rest_get_allowed_schema_keywords()` is
     * exactly the list the unit tier holds.
     *
     * The unit tier composes the dialect against a hand-transcribed copy of core's list
     * (tests/Support/CoreSchemaKeywords), because that tier has no WordPress to ask. That copy is in
     * tests/Support/ and not on a test class because importing one across tiers is what took CI down on
     * `4b138aa` - see tests/unit/TierImportTest.php. A transcription goes stale silently,
     * and the whole sprint rests on it: a keyword core ADDS is one we would ignore again, and one
     * core DROPS is one we refuse a third-party tool for with nothing behind it. So the list is
     * asked of the site here, where a real WordPress can answer.
     *
     * READ FROM THE SITE, NOT FROM THE PLUGIN. This runs `wp eval` rather than loading plugin code,
     * because the point is what CORE says on the WordPress this suite is pointed at - which is a
     * different version on the floor leg than on the current-core leg.
     *
     * @group sprint-validator
     */
    public function testCoresOwnAllowedKeywordListIsWhatTheUnitTierTranscribed(): void
    {
        $json = WpCli::evaluate('echo wp_json_encode(rest_get_allowed_schema_keywords());');
        $live = json_decode(trim($json), true);

        self::assertIsArray(
            $live,
            'The site did not answer with a list of allowed schema keywords: ' . $json
        );

        self::assertSame(
            [],
            array_values(array_diff($live, CoreSchemaKeywords::ALLOWED)),
            'This WordPress validates a keyword CoreSchemaKeywords::ALLOWED does not'
            . ' list, so SchemaValidator does not delegate it and a schema using it is ignored -'
            . ' the exact defect sprint VALIDATOR removed, returned by a core upgrade. Add it to'
            . ' SchemaValidator::DELEGATED and to both transcriptions. Live: ' . $json
        );
        self::assertSame(
            [],
            array_values(array_diff(CoreSchemaKeywords::ALLOWED, $live)),
            'The unit tier lists a keyword this WordPress does NOT validate, so the dialect permits'
            . ' something nothing enforces and a third-party tool using it registers unchecked.'
            . ' Live: ' . $json
        );
    }

    /**
     * THE DOCUMENTED LIMITATION, RECORDED AGAINST A REAL SITE: strict types do not reach inside
     * `anyOf`.
     *
     * Core validates each combinator branch itself, with its own coercive type checks, so `"20"`
     * satisfies a branch declaring `{"type":"integer"}` where a top-level `"type":"integer"` would
     * refuse it. Walking the branches here would be re-implementing the combinators, which is the
     * overbuild this sprint exists to undo.
     *
     * THIS TEST EXISTS SO THE LIMITATION CANNOT BECOME A SURPRISE. It is the only place in the
     * plugin where a caller's value is coerced, no built-in schema uses `anyOf` or `oneOf`
     * (tests/unit/ToolContractTest.php holds that), and a third-party tool that does now gets
     * branch validation where it previously got none. If somebody later makes strict types reach
     * inside, this is the test that goes red and says where the docblock has to change.
     *
     * @group sprint-validator
     */
    public function testCoercionSurvivesOnlyInsideTheCombinators(): void
    {
        self::assertStringContainsString(
            self::RAN,
            $this->call(['either' => '20']),
            'A numeric string was refused inside an anyOf integer branch. That is stricter than'
            . " core and stricter than this file documents - which is fine, but SchemaValidator's"
            . ' docblock and tests/unit/SchemaValidatorTest.php now say something false.'
        );

        // And the limitation is exactly that narrow: outside a combinator the same value is
        // refused, so this is a property of anyOf and not of the validator having given up.
        self::assertStringNotContainsString(self::RAN, $this->call(['limit' => '20']));
    }

    /**
     * `tools/list`, decoded, keyed by tool name - so a test can read a PUBLISHED inputSchema.
     *
     * Reading the published schema rather than grepping the raw body is the point: "this server does
     * not publish a constraint it does not apply" is a claim about the schema a client receives, and
     * a substring search over the envelope cannot tell a keyword in a schema from one in a
     * description.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listing(): array
    {
        $response = $this->mcp(self::$token)->post('tools/list');
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);

        $tools   = json_decode($body, true)['result']['tools'] ?? null;
        $byName  = [];

        self::assertIsArray($tools, 'tools/list did not answer with a tool list: ' . $body);

        foreach ($tools as $tool) {
            $byName[(string) ($tool['name'] ?? '')] = $tool;
        }

        return $byName;
    }

    /** `tools/call` on $tool, returning the RAW body - for a call with no result to decode. */
    private function rawCall(string $tool, array $arguments): string
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => $tool,
            'arguments' => $arguments,
        ]);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);

        return $body;
    }

    /** `tools/call` on the subject tool; the TEXT block of the result. */
    private function call(array $arguments): string
    {
        return $this->textOf(self::subject(), $arguments);
    }

    /**
     * `tools/call` on $tool, returning the result's text block.
     *
     * THE TEXT BLOCK AND NOT THE RAW BODY, because the raw body is JSON and JSON escapes `/` as
     * `\/` - so every assertion about a JSON POINTER would have had to be written in escaped form,
     * which reads as an encoding detail and passes for the wrong reason as soon as the framing
     * changes. Decoding is also what a client does.
     */
    private function textOf(string $tool, array $arguments): string
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => $tool,
            'arguments' => $arguments,
        ]);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);

        $decoded = json_decode($body, true);

        self::assertIsString(
            $decoded['result']['content'][0]['text'] ?? null,
            'The response carries no text block, so there is nothing to read: ' . $body
        );

        return (string) $decoded['result']['content'][0]['text'];
    }

    /**
     * The mu-plugin: one tool using all thirteen keywords, one using a keyword nothing enforces,
     * and one whose `enum` holds a value that is not valid UTF-8.
     *
     * EVERY PROPERTY IS OPTIONAL - no `required` - so each case can send exactly one argument and
     * a failure names one keyword. `additionalProperties` is left unstated at the top level, which
     * is how every built-in is written: validateArguments() closes it.
     */
    private static function source(): string
    {
        $subject = self::subject();
        $unknown = self::unknown();
        $badEnum  = self::badEnum();
        $badBound = self::badBound();
        $objectProps = self::objectProps();
        $ran     = self::RAN;

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$annotations = array(
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    );
    \$run = static function (\$args) { return array('ran' => '{$ran}', 'got' => \$args); };

    \$tools['{$subject}'] = array(
        'write'       => false,
        'annotations' => \$annotations,
        'description' => 'wp-mcp test fixture: every schema keyword core validates.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array(
                'email'    => array('type' => 'string', 'format' => 'email'),
                'slug'     => array('type' => 'string', 'pattern' => '^[a-z]+\$'),
                'pair'     => array('type' => 'object', 'minProperties' => 2, 'maxProperties' => 3),
                'gt'       => array('type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true),
                'lt'       => array('type' => 'integer', 'maximum' => 5, 'exclusiveMaximum' => true),
                'fives'    => array('type' => 'integer', 'multipleOf' => 5),
                'few'      => array('type' => 'array', 'minItems' => 2, 'items' => array('type' => 'integer')),
                'many'     => array('type' => 'array', 'maxItems' => 2, 'items' => array('type' => 'integer')),
                'distinct' => array('type' => 'array', 'uniqueItems' => true, 'items' => array('type' => 'integer')),
                'either'   => array('anyOf' => array(array('type' => 'integer'), array('type' => 'boolean'))),
                'exactly'  => array('oneOf' => array(array('type' => 'integer'), array('type' => 'boolean'))),
                // REVIEW 85 B2: an OBJECT branch inside a combinator. Core validates the branch
                // itself and interpolates the caller's own property name into the message it builds
                // for a closed object - the one path on which a caller's KEY reached the wire.
                'shaped'   => array('anyOf' => array(
                    array(
                        'type'                 => 'object',
                        'properties'           => array('a' => array('type' => 'integer')),
                        'additionalProperties' => false,
                    ),
                    array('type' => 'boolean'),
                )),
                // REVIEW 85 R2-B1: THREE ARRANGEMENTS CORE ENFORCES AND ROUND 2 STRIPPED, so a call
                // the validator refused on 1adb96e reached the tool body on 4a7c0f7. No declared
                // `type` on the first two - typeName() answers `array` for a non-empty list, so
                // core's array validator reads the bound; and a type-less branch INHERITS the
                // parent's type in core (rest-api.php:1996-1998), which is the third.
                'looseItems'  => array('minItems' => 2),
                'looseUnique' => array('uniqueItems' => true),
                'branchBound' => array(
                    'type'  => 'array',
                    'anyOf' => array(array('minItems' => 2), array('maxItems' => 0)),
                ),
                'meta'     => array(
                    'type'                 => 'object',
                    'patternProperties'    => array('^m_' => array('type' => 'integer')),
                    'additionalProperties' => false,
                ),
                // The strict-type control: nothing but a type, so a refusal here is ours.
                'limit'    => array('type' => 'integer'),
            ),
        ),
        'run'         => \$run,
    );

    // FOUR SHAPES THIS SERVER CANNOT ENFORCE, one per reason enforceable() has. Each is stripped
    // from what tools/list publishes; the TOOL survives, because that is what core does with a
    // keyword it cannot validate.
    \$tools['{$unknown}'] = array(
        'write'       => false,
        'annotations' => \$annotations,
        'description' => 'wp-mcp test fixture: keywords nothing enforces.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array(
                // (1) OUTSIDE THE DIALECT, beside a sibling that IS enforced - the pair is what
                //     testWhatIsPublishedIsReducedAndWhatIsValidatedIsNot() reads.
                'a'      => array('type' => 'string', 'const' => 'x', 'minLength' => 2),
                // (2) DRAFT-03's PER-PROPERTY `required`, which core WOULD enforce
                //     (rest-api.php:2432-2440) and this validator does not, because `required` is
                //     ours and is never handed over. Review 85 R2-S2's third row.
                'legacy' => array('type' => 'string', 'required' => true),
            ),
        ),
        'run'         => \$run,
    );

    // `properties` AS A NON-EMPTY stdClass, which is legal and which asMap()'s docblock says a
    // filter-added tool "still may" write. Review 85 R3-B1: the publication walk wrote the reduced
    // child back with an array subscript into the object and threw, so tools/list answered -32603 for
    // the WHOLE SITE. The `const` gives the walk something to remove, which is what reaches the write.
    \$tools['{$objectProps}'] = array(
        'write'       => false,
        'annotations' => \$annotations,
        'description' => 'wp-mcp test fixture: properties written as a non-empty object.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => (object) array('a' => array('type' => 'string', 'const' => 'x', 'minLength' => 2)),
        ),
        'run'         => \$run,
    );

    // AND THE ONE SHAPE THAT REFUSES REGISTRATION RATHER THAN BEING LEFT OUT OF THE LISTING. Core
    // reads a numeric flag through `! empty()` as the draft-04 boolean and enforces `> minimum`, so
    // dropping it would loosen validation; 2020-12 says it means `> 5`, so publishing it is false.
    \$tools['{$badBound}'] = array(
        'write'       => false,
        'annotations' => \$annotations,
        'description' => 'wp-mcp test fixture: a bound core cannot read as written.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array('n' => array('type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 5)),
        ),
        'run'         => \$run,
    );

    // A lone 0xB1 is a continuation byte with no lead byte: not valid UTF-8, which is what
    // json_encode refuses the WHOLE document on. wp_json_encode strips it instead.
    \$tools['{$badEnum}'] = array(
        'write'       => false,
        'annotations' => \$annotations,
        'description' => 'wp-mcp test fixture: an enum value that is not valid UTF-8.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array(
                'shape' => array('enum' => array(array('shape' => "bad\\xB1value"))),
            ),
        ),
        'run'         => \$run,
    );

    return \$tools;
});
PHP;
    }
}
