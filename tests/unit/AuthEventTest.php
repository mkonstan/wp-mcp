<?php
/**
 * The auth-event contract: what fires, what the line looks like, what never reaches it.
 *
 * These are the parts of item 5 that are decidable without WordPress, which is why
 * they are here and not in the integration tier: the format of a log line, the
 * redaction list, and the fact that wpmcp_mint() fires exactly one event carrying the
 * token's ROW ID rather than the token.
 *
 * The redaction test is the one that matters most. Sprint 2's claim about logging is
 * "an operator can see the auth layer without the log becoming a credential store",
 * and redaction BY KEY is what makes that checkable by reading a list instead of
 * trusting a regex over values.
 *
 * @group sprint-2
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class AuthEventTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        $this->wpdb = WordPressRuntime::install();
    }

    /**
     * One mint, one event, carrying the row id and the bound user - and no token.
     *
     * The raw token exists in exactly one place, wpmcp_mint()'s return value. If it
     * ever appears in an event context it is in the log of every site that installs
     * this, which is strictly worse than the database column that deliberately holds
     * only a hash.
     *
     * @group sprint-2
     */
    public function testMintFiresExactlyOneEventCarryingTheRowIdAndNeverTheToken(): void
    {
        WordPressRuntime::logInAs(7, 'wpmcp-unit-admin');

        $minted = \wpmcp_mint('admin', 'unit mint event', 3600);
        self::assertIsArray($minted, 'Minting for the current user should succeed.');

        $fired = WordPressRuntime::firedActions('wpmcp_auth_event');

        self::assertCount(1, $fired, 'wpmcp_mint() did not fire exactly one auth event.');
        self::assertSame('mint', $fired[0][0], 'The event type is not `mint`.');

        $context = $fired[0][1];
        self::assertIsArray($context);
        self::assertSame($minted['id'], $context['token_id'], 'The event does not carry the token row id.');
        self::assertSame(7, $context['user_id'], 'The event does not carry the bound user.');
        self::assertSame('admin', $context['scope']);

        $line = \wpmcp_format_auth_event($fired[0][0], $context);

        self::assertStringNotContainsString(
            $minted['raw'],
            $line,
            'The minted token itself appears in the auth event. Events carry the row id.'
        );
        self::assertStringNotContainsString(
            \wpmcp_hash($minted['raw']),
            $line,
            'The token HASH appears in the auth event. A hash of a 256-bit token is'
            . ' still the credential: it is exactly what the table stores and what the'
            . ' lookup compares against.'
        );
    }

    /**
     * A refusal reason is in the event, because it is nowhere else: the wire answer to
     * all six token failures is one byte-identical 401.
     *
     * @group sprint-2
     */
    public function testValidateFailureFiresAnEventCarryingTheInternalReason(): void
    {
        // No database call can happen here: the shape check refuses before the lookup.
        $error = \wpmcp_validate('not-a-token', '203.0.113.9');

        self::assertTrue(\is_wp_error($error));
        self::assertSame('malformed', $error->get_error_code());

        $fired = WordPressRuntime::firedActions('wpmcp_auth_event');
        self::assertCount(1, $fired, 'A refused token did not fire exactly one auth event.');
        self::assertSame('validate_fail', $fired[0][0]);
        self::assertSame('malformed', $fired[0][1]['reason'], 'The event carries no reason.');
        self::assertSame('203.0.113.9', $fired[0][1]['ip']);
    }

    /**
     * An absent token - no path segment, no Authorization header - is its own reason.
     * `missing` and `malformed` are the same 401 on the wire and two different things
     * to an operator reading a log: one is a client that never sent a credential, the
     * other is one that sent a broken one.
     *
     * @group sprint-2
     */
    public function testAnAbsentTokenIsReportedAsMissingRatherThanMalformed(): void
    {
        $error = \wpmcp_validate('', '203.0.113.9');

        self::assertSame('missing', $error->get_error_code());
        self::assertSame('missing', WordPressRuntime::firedActions('wpmcp_auth_event')[0][1]['reason']);
    }

    /**
     * The line format: `wp-mcp auth <type>` then sorted key=value pairs, one line.
     *
     * Sorted, because an operator greps it and a log shipper parses it; a field order
     * that depends on the order a caller happened to build its array is not a format.
     *
     * @group sprint-2
     */
    public function testTheLogLineIsOneSortedStableLine(): void
    {
        $line = \wpmcp_format_auth_event('validate_fail', [
            'reason'   => 'ip_mismatch',
            'ip'       => '198.51.100.4',
            'token_id' => 12,
        ]);

        self::assertSame(
            'wp-mcp auth validate_fail ip=198.51.100.4 reason=ip_mismatch token_id=12',
            $line
        );
    }

    /**
     * A newline in a value cannot split one event across two log lines, nor forge a
     * second one.
     *
     * @group sprint-2
     */
    public function testNewlinesInAValueAreFlattened(): void
    {
        $line = \wpmcp_format_auth_event(
            'origin_deny',
            ['origin' => "https://evil.test\nwp-mcp auth mint"]
        );

        self::assertStringNotContainsString("\n", $line);
        self::assertSame(
            'wp-mcp auth origin_deny origin=https://evil.test wp-mcp auth mint',
            $line
        );
    }

    /**
     * Redaction is BY KEY. Every name on the fixed list is replaced wherever it
     * appears, and a key that is not on the list survives - because guessing at values
     * fails in both directions, and a list is checkable by reading it.
     *
     * @group sprint-2
     */
    public function testEveryRedactedKeyIsRedactedAndOtherKeysAreNot(): void
    {
        $keys = \wpmcp_auth_event_redacted_keys();

        self::assertContains('token', $keys);
        self::assertContains('token_hash', $keys);
        self::assertContains('authorization', $keys);

        $context = ['tool' => 'create-post'];

        foreach ($keys as $key) {
            $context[$key] = 'THE-SECRET-VALUE';
        }

        $line = \wpmcp_format_auth_event('mint', $context);

        self::assertStringNotContainsString(
            'THE-SECRET-VALUE',
            $line,
            'A key on the redaction list reached the log line with its value.'
        );

        foreach ($keys as $key) {
            self::assertStringContainsString(
                $key . '=[redacted]',
                $line,
                "The key {$key} is on the redaction list but was not redacted."
            );
        }

        self::assertStringContainsString(
            'tool=create-post',
            $line,
            'Redaction ate a key that is not on the list.'
        );
    }

    /**
     * Redaction goes all the way down, not just the top level.
     *
     * The plugin's own contexts are flat, so nothing leaked. But a site that adds its
     * own listener and passes a nested array - `['request' => ['authorization' => …]]` -
     * had it written out verbatim, because the formatter json_encoded a nested value
     * without looking inside it.
     *
     * @group sprint-2
     */
    public function testRedactionRecursesIntoNestedArrays(): void
    {
        $line = \wpmcp_format_auth_event('validate_fail', [
            'request' => [
                'headers' => ['authorization' => 'Bearer THE-SECRET-VALUE'],
                'method'  => 'tools/call',
            ],
        ]);

        self::assertStringNotContainsString(
            'THE-SECRET-VALUE',
            $line,
            'A redacted key nested two levels down reached the log with its value.'
        );
        self::assertStringContainsString('[redacted]', $line);
        self::assertStringContainsString(
            'tools',
            $line,
            'Recursion ate the keys that are not on the list.'
        );
    }

    /**
     * Every logged value is bounded.
     *
     * `origin`, `content_type` and `tool` are attacker-controlled - two request headers
     * and params.name - so without a cap a 100 KB Origin header is a 100 KB log line,
     * once per request, for free.
     *
     * @group sprint-2
     */
    public function testLongValuesAreTruncated(): void
    {
        $line = \wpmcp_format_auth_event('origin_deny', [
            'origin' => 'https://' . str_repeat('a', 100000) . '.example',
        ]);

        self::assertLessThan(
            400,
            strlen($line),
            'A 100 KB Origin header became a 100 KB log line.'
        );
        self::assertStringEndsWith('...', $line, 'The truncation is not marked.');
    }

    /**
     * A nested structure is bounded too, after redaction rather than before.
     *
     * @group sprint-2
     */
    public function testALongNestedValueIsTruncated(): void
    {
        $line = \wpmcp_format_auth_event('origin_deny', [
            'detail' => ['note' => str_repeat('b', 100000)],
        ]);

        self::assertLessThan(600, strlen($line));
    }

    /**
     * An empty value is written as "" rather than as nothing.
     *
     * `content_type= ip=127.0.0.1` reads, to any key=value parser, as content_type
     * holding the string "ip=127.0.0.1" - and an absent Content-Type header is the
     * common case of that event.
     *
     * @group sprint-2
     */
    public function testAnEmptyValueIsNotAmbiguous(): void
    {
        self::assertSame(
            'wp-mcp auth content_type_deny content_type="" ip=127.0.0.1',
            \wpmcp_format_auth_event('content_type_deny', [
                'content_type' => '',
                'ip'           => '127.0.0.1',
            ])
        );
    }

    /**
     * Case does not get a value past the list, and a key that is NOT on it stays put
     * even when its value is 64 hex digits.
     *
     * @group sprint-2
     */
    public function testRedactionIsByKeyNotBySubstringOfTheValue(): void
    {
        $hexish = str_repeat('ab', 32);

        $line = \wpmcp_format_auth_event('mint', [
            'Token' => $hexish,
            'title' => $hexish,
        ]);

        self::assertStringContainsString(
            'Token=[redacted]',
            $line,
            'Key matching is case-sensitive, so a capitalised secret key escaped.'
        );
        self::assertStringContainsString(
            'title=' . $hexish,
            $line,
            'A value was redacted for looking secret, which is the guessing this avoids.'
        );
    }
}
