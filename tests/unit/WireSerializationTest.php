<?php
/**
 * The `{}` / `[]` guard, and the tools/list cursor codec. Both asserted on the BYTES.
 *
 * WHY THE BYTES. `json_decode('[]', true)` and `json_decode('{}', true)` are the same PHP
 * value, so an assertion on a decoded structure cannot see this bug at all - which is how
 * `"capabilities": []` survived into Sprint 4 and had to be fixed with an inline
 * `new stdClass()`. Every assertion here encodes and looks at the result.
 *
 * AND WHY A GUARD RATHER THAN MORE INLINE CASTS. The inline fix works exactly where
 * somebody remembered it. `properties` appears once per tool and again inside every
 * nested object schema, so "remember the cast" is a rule that has to hold in twenty
 * places and will not. Position decides instead: wpmcp_objectify_schema() walks the
 * schema and fixes the positions JSON Schema defines as objects - enumerated, because
 * `required` and `enum` are JSON ARRAYS and an empty one of those must stay `[]`.
 *
 * THE CURSOR IS TESTED FOR CANONICALITY, not just round-tripping. `MA==`, `MA=` and `MA`
 * all base64-decode to "0"; a decoder that accepted all three would make one page
 * addressable by three cursors, and "opaque" would mean "guessable".
 *
 * @group sprint-5
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressStubs;

final class WireSerializationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    /** json_encode with the flags that make a failure readable, and no others. */
    private static function encode($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * An empty `properties` serializes as `{}` - the case site-info now relies on.
     *
     * @group sprint-5
     */
    public function testAnEmptyPropertiesMapBecomesAnObject(): void
    {
        $schema = wpmcp_objectify_schema(['type' => 'object', 'properties' => []]);

        self::assertSame('{"type":"object","properties":{}}', self::encode($schema));
    }

    /**
     * AT ANY DEPTH. A nested object property with no members of its own is the case the
     * inline-cast approach could not reach, because nobody writes the cast twice.
     *
     * @group sprint-5
     */
    public function testANestedEmptyPropertiesMapBecomesAnObject(): void
    {
        $schema = wpmcp_objectify_schema([
            'type'       => 'object',
            'properties' => [
                'terms' => [
                    'type'       => 'object',
                    'properties' => [
                        'deeper' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
        ]);

        $json = self::encode($schema);

        self::assertStringNotContainsString(
            '[]',
            $json,
            'An empty array survived somewhere in the schema: ' . $json
        );
        self::assertSame(
            '{"type":"object","properties":{"terms":{"type":"object","properties":'
            . '{"deeper":{"type":"object","properties":{}}}}}}',
            $json
        );
    }

    /**
     * The other object positions: `additionalProperties`, `items`, a `default` under
     * `type: object`, and `_meta`.
     *
     * @group sprint-5
     */
    public function testEveryObjectPositionIsFixed(): void
    {
        $json = self::encode(wpmcp_objectify_schema([
            'type'                 => 'object',
            'properties'           => [
                'nested' => ['type' => 'object', 'default' => [], 'properties' => []],
                'list'   => ['type' => 'array', 'items' => []],
            ],
            'additionalProperties' => [],
            '_meta'                => [],
        ]));

        self::assertStringNotContainsString('[]', $json, $json);
        self::assertStringContainsString('"default":{}', $json, $json);
        self::assertStringContainsString('"items":{}', $json, $json);
        self::assertStringContainsString('"additionalProperties":{}', $json, $json);
        self::assertStringContainsString('"_meta":{}', $json, $json);
    }

    /**
     * AND THE POSITIONS THAT MUST NOT BE TOUCHED. `required` and `enum` are JSON arrays;
     * `additionalProperties: false` is a boolean; a `default` under `type: array` is an
     * array. A blanket "every empty array becomes an object" would break all four, which
     * is why the guard enumerates instead.
     *
     * @group sprint-5
     */
    public function testArrayValuedKeywordsAreLeftAlone(): void
    {
        $json = self::encode(wpmcp_objectify_schema([
            'type'                 => 'object',
            'properties'           => ['a' => ['type' => 'array', 'default' => []]],
            'required'             => [],
            'enum'                 => [],
            'additionalProperties' => false,
        ]));

        self::assertStringContainsString('"required":[]', $json, $json);
        self::assertStringContainsString('"enum":[]', $json, $json);
        self::assertStringContainsString('"additionalProperties":false', $json, $json);
        self::assertStringContainsString(
            '"default":[]',
            $json,
            'A default under `type: array` was turned into an object: ' . $json
        );
    }

    /**
     * A schema already written with `new stdClass()` is left as it is - three of the
     * integration suite's fixture tools are written that way, and so was site-info.
     *
     * @group sprint-5
     */
    public function testAnExistingObjectIsNotDisturbed(): void
    {
        $json = self::encode(wpmcp_objectify_schema([
            'type'       => 'object',
            'properties' => new \stdClass(),
        ]));

        self::assertSame('{"type":"object","properties":{}}', $json);
    }

    /**
     * The map form, which is what `initialize` uses: `capabilities` with an empty `tools`
     * object. Same function, same rule, one implementation.
     *
     * @group sprint-5
     */
    public function testTheObjectMapFormServesTheCapabilitySet(): void
    {
        self::assertSame(
            '{"tools":{}}',
            self::encode(wpmcp_objectify_object_map(['tools' => []])),
            'The capability set must serialize as {"tools":{}}. A strict client rejects'
            . ' the whole initialize result on {"tools":[]}.'
        );
        self::assertSame('{}', self::encode(wpmcp_objectify_object_map([])));
    }

    /**
     * THE GUARD APPLIED TO THE REAL CATALOG: no schema of any built-in tool contains an
     * empty JSON array after the guard has run.
     *
     * This is the assertion that would have caught site-info losing its `new stdClass()`
     * without the guard being wired in, and it keeps holding as tools are added.
     *
     * @group sprint-5
     */
    public function testNoBuiltInSchemaSerializesAnEmptyArray(): void
    {
        foreach (self::builtInSchemas() as $name => $schema) {
            $json = self::encode(wpmcp_objectify_schema($schema));

            self::assertStringNotContainsString(
                '[]',
                $json,
                "The inputSchema of {$name} serializes an empty JSON array. Every empty"
                . ' position in a JSON Schema is an OBJECT. Got: ' . $json
            );
            self::assertStringNotContainsString(
                '"properties":[',
                $json,
                "The inputSchema of {$name} serializes `properties` as an array: " . $json
            );
        }
    }

    /**
     * The cursor round-trips, and nothing else decodes.
     *
     * @group sprint-5
     */
    public function testTheCursorRoundTripsAndRefusesEverythingElse(): void
    {
        foreach ([0, 1, 50, 1000] as $offset) {
            self::assertSame(
                $offset,
                wpmcp_cursor_decode(wpmcp_cursor_encode($offset)),
                'A cursor this server issued did not decode back to its offset.'
            );
        }

        // Absent is page one, not an error: the first tools/list carries no cursor.
        self::assertSame(0, wpmcp_cursor_decode(null));
        self::assertSame(0, wpmcp_cursor_decode(''));

        $refused = [
            'not base64 at all'      => 'garbage',
            'base64 of a word'       => base64_encode('page-two'),
            'base64 of a negative'   => base64_encode('-1'),
            'base64 of a float'      => base64_encode('1.5'),
            'base64 of a space'      => base64_encode(' '),
            // NON-CANONICAL: decodes to "0", but is not the string we would have issued.
            'unpadded'               => 'MA',
            'over-padded'            => 'MA=',
            'leading zero'           => base64_encode('007'),
            'wider than PHP_INT_MAX' => base64_encode('99999999999999999999999'),
            'not a string'           => 7,
            'an array'               => ['0'],
        ];

        foreach ($refused as $why => $cursor) {
            self::assertNull(
                wpmcp_cursor_decode($cursor),
                "A cursor this server never issued was accepted ({$why}): "
                . var_export($cursor, true)
            );
        }
    }

    /**
     * Every built-in tool's inputSchema, by name, without a WordPress runtime.
     *
     * The code tools and sql-select are included: they are part of the catalog whether or
     * not the option that exposes them is on, and their schemas are just as much on the wire.
     *
     * @return array<string, array>
     */
    private static function builtInSchemas(): array
    {
        $schemas = [];

        foreach (self::catalog() as $name => $tool) {
            $schemas[$name] = $tool['inputSchema'];
        }

        return $schemas;
    }

    /**
     * The catalog, assembled from the seven tool functions directly.
     *
     * NOT through wpmcp_tools(), which calls apply_filters() and get_option() - a unit
     * test has no WordPress. The functions themselves only build arrays; the `run`
     * closures are never invoked here.
     *
     * @return array<string, array>
     */
    public static function catalog(): array
    {
        $tools = [];

        foreach ([
            'wpmcp_core_tools',
            'wpmcp_content_tools',
            'wpmcp_revision_tools',
            'wpmcp_meta_tools',
            'wpmcp_taxonomy_tools',
            'wpmcp_media_tools',
            'wpmcp_comment_tools',
            'wpmcp_code_tools',
            'wpmcp_sql_tools',
            'wpmcp_menu_tools',
        ] as $fn) {
            self::assertTrue(function_exists($fn), "{$fn}() is gone from tools.php.");

            $tools = array_merge($tools, $fn());
        }

        self::assertCount(
            33,
            $tools,
            'The catalog is not 33 tools any more. That is the product (see the build'
            . " plan's constraints), so a change in the count is a decision, not a"
            . ' detail - update this number deliberately. Got: ' . implode(', ', array_keys($tools))
        );

        return $tools;
    }
}
