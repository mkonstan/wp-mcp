<?php
/**
 * The platform swaps that can be proved without a site (1.1.1, sprint SWAP item 2).
 *
 * FOUR OF THE FIVE SWAPS ARE PURE, in the sense that matters to a test: a function that maps a
 * WP_Error to a WP_Error, a gate that reads a filter, a declaration that produces a schema and
 * a result, and - for the one swap with no observable behaviour at all - the source itself.
 * The two that need a real site (the date parser against real timezones, the widened status
 * list against real capabilities) are asserted over HTTP instead.
 *
 * THE SOURCE ASSERTION IS DELIBERATE AND IS NOT A CHEAT. Swapping
 * `json_decode($req->get_body())` for `$req->get_json_params()` changes NOTHING a client can
 * see - that is precisely why it is safe - so there is no behaviour to go red. What can go red
 * is the double parse coming back, and the only place that fact lives is the file. The pattern
 * is the one tests/unit/SurfaceSweepTest.php already uses for claims of the same shape.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class PlatformApiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    protected function setUp(): void
    {
        parent::setUp();

        WordPressRuntime::install();
    }

    /**
     * The file-mod gate follows the `file_mod_allowed` FILTER, not just the constant.
     *
     * THE DEFECT THIS CLOSES was measured on seosemia.net in the other direction: a site with
     * the code switch on and DISALLOW_FILE_EDIT set advertised all six code tools and refused
     * every call. A hardening plugin does not set a constant - it returns false from
     * `file_mod_allowed`, which is what `map_meta_cap` consults for `edit_themes`
     * (capabilities.php:607-611) - so before this swap such a site was in exactly the same
     * state: six tools listed, six tools refused.
     *
     * @group sprint-14d
     */
    public function testAHardeningPluginsFilterSwitchesTheCodeToolListingOff(): void
    {
        self::assertNull(
            wpmcp_code_constants_forbid(),
            'Nothing forbids file modification in the default stub state, so the assertion'
            . ' below would not be able to tell the filter from the constant.'
        );

        WordPressRuntime::addFilter('file_mod_allowed', static fn ($allowed, $context = '') => false);

        $refusal = wpmcp_code_constants_forbid();

        self::assertNotNull(
            $refusal,
            'A plugin returning false from file_mod_allowed does not stop the code tools being'
            . ' LISTED. WordPress itself denies edit_themes on that filter, so the tools are'
            . ' advertised and then refused - the defect the constants split already fixed for'
            . ' DISALLOW_FILE_EDIT.'
        );
        self::assertSame('wpmcp_forbidden', $refusal->get_error_code());
        self::assertStringContainsString(
            'file_mod_allowed',
            $refusal->get_error_message(),
            'The refusal does not say WHICH of the three switched file editing off, so an'
            . ' operator cannot tell a constant they set from a plugin they installed. Got: '
            . $refusal->get_error_message()
        );
    }

    /**
     * The endpoint reads the body core already decoded, and still reads the raw first byte.
     *
     * TWO CLAIMS, BOTH IN THE SOURCE. The decode is gone (core did it in `has_valid_params()`
     * before the permission callback ran - KB 0.9), and `get_body()` survives for exactly one
     * job: `json_decode('[]')` and `json_decode('{}')` are the same PHP value, so only the
     * first non-whitespace character can tell a zero-length batch from an empty object.
     *
     * @group sprint-14d
     */
    public function testTheEndpointDoesNotDecodeTheBodyASecondTime(): void
    {
        $source = RepoFile::read('endpoint.php');

        self::assertStringContainsString(
            '$body = $req->get_json_params();',
            $source,
            'wpmcp_handle() does not read the parameters core already decoded.'
        );
        self::assertStringNotContainsString(
            'json_decode($raw',
            $source,
            'The double parse is back: core decodes an application/json body in'
            . ' has_valid_params() before the permission callback runs, so a second'
            . ' json_decode() of the same bytes is work nobody asked for.'
        );
        self::assertStringContainsString(
            "substr(ltrim(\$raw), 0, 1) === '['",
            $source,
            'The batch refusal no longer looks at the RAW body. It cannot move to the decoded'
            . ' parameters: `[]` and `{}` decode to the same PHP value, so a zero-length batch'
            . ' would be accepted as an empty object.'
        );
    }

    /**
     * A remote server's REFUSAL becomes a relayable error naming the status - and its body
     * stays behind.
     *
     * CORE'S SHAPE, READ OFF wp-admin/includes/file.php:1193-1219: `download_url()` answers
     * `WP_Error('http_404', <reason phrase>, ['code' => <status>, 'body' => <1 KB sample>])`
     * for EVERY non-2xx, whatever the status actually was - which is why the caller used to be
     * told nothing at all: `http_404` is not on the relay list, so the boundary hid a failure
     * that was not this site's fault.
     *
     * @group sprint-14d
     */
    public function testAFetchRefusalKeepsTheStatusAndDropsTheBody(): void
    {
        foreach ([400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 429 => 'Too Many Requests'] as $status => $reason) {
            $mapped = wpmcp_fetch_error(new \WP_Error('http_404', $reason, [
                'code' => $status,
                'body' => '<html>SECRET-BODY</html>',
            ]));

            self::assertSame(
                'wpmcp_fetch_failed',
                $mapped->get_error_code(),
                'A non-2xx is still wearing core\'s http_404 code, so the boundary will hide it.'
            );
            self::assertStringContainsString('HTTP ' . $status, $mapped->get_error_message());
            self::assertStringContainsString($reason, $mapped->get_error_message());
            self::assertStringNotContainsString('SECRET-BODY', $mapped->get_error_message());
        }
    }

    /**
     * Everything that is NOT that shape comes back untouched, so it stays generic.
     *
     * THE THREE CASES ARE THREE DIFFERENT REASONS. `http_request_failed` is a transport
     * message and can name `WP_PROXY_HOST`; an `http_404` with no `code` in its data is not
     * the shape this function was written for, so there is nothing trustworthy to relay; and
     * a status outside 100-599 is a filter or a broken transport rather than an answer.
     *
     * @group sprint-14d
     */
    public function testEverythingElseStaysGeneric(): void
    {
        $cases = [
            'a transport failure' => new \WP_Error('http_request_failed', 'cURL error 7: Failed to connect to proxy.internal port 8080'),
            'no status in data'   => new \WP_Error('http_404', 'Bad Request', ['body' => 'x']),
            'an absurd status'    => new \WP_Error('http_404', 'Bad Request', ['code' => 0]),
            'no data at all'      => new \WP_Error('http_404', 'Bad Request'),
        ];

        foreach ($cases as $what => $error) {
            $mapped = wpmcp_fetch_error($error);

            self::assertSame(
                $error,
                $mapped,
                $what . ' was rewritten into a relayable error. Only a response with a real'
                . ' HTTP status is relayed; everything else keeps its code and therefore stays'
                . ' "Internal error (trace <id>)" with the detail in the private log.'
            );
        }
    }

    /**
     * The reason phrase is the REMOTE server's string, so it is bounded and stripped.
     *
     * @group sprint-14d
     */
    public function testTheRelayedReasonIsBoundedAndHasNoControlCharacters(): void
    {
        $mapped = wpmcp_fetch_error(new \WP_Error(
            'http_404',
            "Bad\r\nInjected: header\tand " . str_repeat('A', 200),
            ['code' => 400]
        ));

        $message = $mapped->get_error_message();

        self::assertStringNotContainsString("\r", $message, 'A carriage return from a remote server survived.');
        self::assertStringNotContainsString("\n", $message, 'A newline from a remote server survived.');
        self::assertStringNotContainsString("\t", $message, 'A tab from a remote server survived.');
        self::assertStringNotContainsString(
            str_repeat('A', 100),
            $message,
            'The reason phrase is not bounded, so a remote server decides how long this'
            . ' message is.'
        );
    }

    /**
     * The generic failure says the trace id in BOTH places.
     *
     * NOT ASSERTED HERE BUT OVER HTTP, in tests/integration/ErrorSurfaceTest.php: the answer is
     * a WP_REST_Response, and stubbing that class to read one string back would be a stub for
     * the sake of a test rather than for the sake of loading the plugin - the line the stub set
     * holds (tests/Support/WordPressStubs.php). The integration assertion is also the stronger
     * one: it reads the id out of `data`, rebuilds the sentence from it, and compares that with
     * what the wire actually carried.
     */

    /**
     * A tool's outputSchema and its result come from ONE declaration - which is the point of
     * the pilot, not the wire format (D19).
     *
     * ASSERTED ON A SYNTHETIC DECLARATION rather than on a tool, because what is being proved
     * is the mechanism: the schema's property names, their order and its `required` list are
     * derived from the same array the result is built from, so a field cannot exist in one and
     * not the other. The four piloted tools are then checked over HTTP, where their values are.
     *
     * @group sprint-14d
     */
    public function testASchemaAndAResultCannotDisagreeBecauseTheyAreOneDeclaration(): void
    {
        $shape = [
            'id'     => ['type' => 'integer', 'description' => 'An id.', 'get' => fn ($c) => (int) $c['id']],
            'label'  => ['type' => 'string', 'description' => 'A label.', 'get' => fn ($c) => (string) $c['label']],
            'gone'   => ['type' => 'string', 'nullable' => true, 'description' => 'Sometimes nothing.', 'get' => fn ($c) => null],
            'secret' => [
                'type' => 'string', 'description' => 'Only for the privileged.',
                'when' => fn ($c) => !empty($c['full']),
                'get'  => fn ($c) => 'shown',
            ],
            'nested' => [
                'type' => 'object', 'description' => 'An object.',
                'get'  => fn ($c) => $c,
                'fields' => [
                    'inner' => ['type' => 'integer', 'description' => 'Inside.', 'get' => fn ($c) => 1],
                ],
            ],
        ];

        $schema = wpmcp_result_schema($shape);
        $lean   = wpmcp_result_build($shape, ['id' => 5, 'label' => 'x', 'full' => false]);
        $full   = wpmcp_result_build($shape, ['id' => 5, 'label' => 'x', 'full' => true]);

        self::assertSame(
            ['id', 'label', 'gone', 'secret', 'nested'],
            array_keys($schema['properties']),
            'The schema does not describe the declaration\'s fields, in its order.'
        );
        self::assertSame(
            ['id', 'label', 'gone', 'nested'],
            $schema['required'],
            'A field with a `when` is REQUIRED in the schema, so a caller without the'
            . ' capability gets a result that fails the tool\'s own contract. Nullable means'
            . ' "present and empty"; `when` means "may be absent".'
        );
        self::assertSame(['id', 'label', 'gone', 'nested'], array_keys($lean));
        self::assertSame(['id', 'label', 'gone', 'secret', 'nested'], array_keys($full));

        self::assertSame(['string', 'null'], $schema['properties']['gone']['type'], 'nullable did not widen the type.');
        self::assertNull($lean['gone']);

        self::assertSame(['inner' => 1], $lean['nested'], 'A nested declaration was not built from its own fields.');
        self::assertSame(['inner'], array_keys($schema['properties']['nested']['properties']));
        self::assertSame(['inner'], $schema['properties']['nested']['required']);

        foreach (array_keys($schema['properties']) as $field) {
            self::assertArrayHasKey(
                'description',
                $schema['properties'][$field],
                "The schema dropped {$field}'s description, which is where a field list moved to."
            );
        }
    }

    /**
     * REVISION 6'S TWO OPTIONAL COLUMNS DO NOT GATE THE STAMP, so the upgrade cannot run for
     * ever on a host whose ALTER fails (round 2).
     *
     * THE COST THIS STOPS, and it is the largest real-site cost the review found: a gate here
     * means `wpmcp_maybe_upgrade()` never records the revision, so EVERY request runs `dbDelta`
     * twice plus five `SHOW COLUMNS` plus two `UPDATE`s, for ever, on a site where the plugin
     * otherwise works perfectly - and nothing says why it got slower. A database user without
     * ALTER is ordinary shared hosting, and the same hazard is written out in this same function
     * about DROP, which is why gating here contradicted the file's own rule.
     *
     * A SOURCE ASSERTION, because the thing that must not come back is two lines of control
     * flow. The behaviour they caused cannot be reproduced in the unit tier without a real
     * database that refuses an ALTER, and it cannot be reproduced in the integration tier
     * either: driving the real upgrade means dropping a column on somebody's live table, which
     * this suite has refused to do since sprint 1 (see TokenUserIdMigrationTest's docblock).
     *
     * @group sprint-14d
     */
    public function testTheOptionalClientColumnsDoNotGateTheSchemaStamp(): void
    {
        $source = RepoFile::read('wp-mcp.php');

        foreach (['client_name', 'client_version'] as $column) {
            self::assertStringNotContainsString(
                "if (!wpmcp_token_column_exists('{$column}')) { return false; }",
                $source,
                "The installer fails closed on {$column} again. That column is optional - nothing"
                . ' but one admin-table cell reads it, and wpmcp_record_client_info() skips its'
                . ' write when the row has no such field - so a host whose ALTER fails would'
                . ' re-run the whole upgrade on every request for ever.'
            );
        }

        self::assertStringContainsString(
            'wpmcp_note_client_columns();',
            $source,
            'The installer no longer reports the missing columns at all, so their absence is'
            . ' silent: no log line, no option, no admin notice, and an operator with a "Client"'
            . ' column that never fills has nothing to go on.'
        );

        // AND THE REVISION IS STILL STAMPED. Without this the test above would also pass on an
        // installer that had simply stopped stamping.
        self::assertStringContainsString(
            'update_option(WPMCP_DB_VER_OPTION, WPMCP_DB_VER);',
            $source,
            'The installer does not record the schema revision, so wpmcp_maybe_upgrade() re-runs'
            . ' it on every request whatever the columns did.'
        );
    }

    /**
     * And the stamp is what stops the re-run: with the revision recorded, wpmcp_maybe_upgrade()
     * does nothing at all.
     *
     * HOW THIS PROVES IT WITHOUT A DATABASE. `wpmcp_install()` calls `dbDelta()`, which does not
     * exist in the unit tier - so if the guard let the installer run, this test would die with
     * "Call to undefined function dbDelta()". Returning normally IS the assertion; the
     * assertTrue below exists so PHPUnit counts the test rather than calling it risky, and its
     * message says where the proof actually is.
     *
     * @group sprint-14d
     */
    public function testAStampedRevisionMakesTheUpgradeANoOp(): void
    {
        WordPressRuntime::setOption('wpmcp_db_ver', WPMCP_DB_VER);

        wpmcp_maybe_upgrade();

        self::assertTrue(
            true,
            'Reached only because wpmcp_maybe_upgrade() returned without entering the installer.'
        );
    }

    /**
     * What the operator is TOLD, in all four combinations - the decision the review turned over,
     * as a pure function so a test can drive it.
     *
     * @group sprint-14d
     */
    public function testTheClientColumnReportNamesExactlyWhatIsMissing(): void
    {
        self::assertSame('', wpmcp_client_columns_report(true, true), 'Nothing is missing, so there is nothing to say.');
        self::assertSame('client_name', wpmcp_client_columns_report(false, true));
        self::assertSame('client_version', wpmcp_client_columns_report(true, false));
        self::assertSame(
            'client_name and client_version',
            wpmcp_client_columns_report(false, false),
            'Both missing is the case a failed ALTER actually produces, and the message names'
            . ' both - an operator granting a privilege needs to know what did not arrive.'
        );
    }

    /**
     * The relayed fetch sentence is true whether or not a redirect happened (round 2).
     *
     * `download_url()` goes through `wp_safe_remote_get()`, which follows up to five redirects by
     * default (`class-wp-http.php:191`), so the status can have come from a URL the caller never
     * named. "The server at source_url answered HTTP 403" was therefore a sentence that could be
     * false in shipped text, which is a defect by this project's own triage rule.
     *
     * @group sprint-14d
     */
    public function testTheFetchSentenceDoesNotClaimSourceUrlAnswered(): void
    {
        $message = wpmcp_fetch_error(new \WP_Error('http_404', 'Forbidden', ['code' => 403]))
            ->get_error_message();

        self::assertStringNotContainsString(
            'The server at source_url answered',
            $message,
            'The message claims source_url answered. download_url() follows redirects, so the'
            . ' status can be a redirect target\'s.'
        );
        self::assertStringContainsString('The fetch of source_url ended in HTTP 403', $message);
        self::assertStringContainsString(
            'followed any redirects',
            $message,
            'The message does not say a redirect may have happened, so a caller reading "HTTP'
            . ' 403" still has no way to know which server it came from.'
        );
        self::assertStringContainsString('redirect target', $message);
    }

    /**
     * `structuredContent` is DECODED FROM the text block, so the two halves cannot differ -
     * and it is attached only where a tool declared a schema for it.
     *
     * @group sprint-14d
     */
    public function testTheStructuredHalfIsTheTextBlockAndOnlyAppearsWhenAskedFor(): void
    {
        $json = wp_json_encode(['id' => 5, 'terms' => new \stdClass(), 'roles' => ['author']]);

        $plain = wpmcp_tool_result($json, false);

        self::assertArrayNotHasKey(
            'structuredContent',
            $plain,
            'A tool with no outputSchema is sending its data twice, which is the cost the pilot'
            . ' exists to keep off the other tools.'
        );

        $both = wpmcp_tool_result($json, false, true);

        self::assertSame($json, $both['content'][0]['text'], 'The text block changed.');
        self::assertSame(
            $json,
            wp_json_encode($both['structuredContent']),
            'The structured half does not re-encode to the text block, so the two copies of the'
            . ' same data can disagree - about a value, a type, or whether an empty field is'
            . ' `{}` or `[]`.'
        );

        // A result that is not an object gets the text block alone rather than a member that
        // would fail its own schema.
        self::assertArrayNotHasKey('structuredContent', wpmcp_tool_result('[1,2]', false, true));
        self::assertArrayNotHasKey('structuredContent', wpmcp_tool_result('', false, true));
    }
}
