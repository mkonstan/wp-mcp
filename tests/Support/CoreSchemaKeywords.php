<?php
/**
 * Core's own `rest_get_allowed_schema_keywords()`, transcribed - shared by both tiers.
 *
 * WHY THIS IS A SUPPORT CLASS AND NOT A CONSTANT ON A TEST. It is fixture DATA, not a test: a
 * hand-copied list that the unit tier composes `SchemaValidator::dialect()` against and the
 * integration tier compares to what a live site answers. It lived on
 * `tests/unit/SchemaValidatorTest` until CI run 36229392831 showed why that cannot work -
 * `tests/integration/SchemaKeywordsTest` imported it with `use WpMcp\Tests\Unit\SchemaValidatorTest`,
 * which PSR-4 resolves to `tests/`U`nit/` while the directory is `tests/unit/`. Windows resolves that
 * and Linux does not, so five rounds of green local runs on two sites never saw it and one floor shard
 * did: `Class "WpMcp\Tests\Unit\SchemaValidatorTest" not found`.
 *
 * `tests/Support/` is capital-S and matches `WpMcp\Tests\Support\` exactly, so a class here autoloads
 * on a case-sensitive filesystem. That is the whole reason the data moved rather than the directory
 * being renamed. tests/unit/TierImportTest.php is what stops the import coming back.
 *
 * TRANSCRIBED AND NOT DERIVED, because the unit tier has no WordPress to ask. That is a real weakness
 * and it is covered rather than hidden: tests/integration/SchemaKeywordsTest asks the live site for
 * `rest_get_allowed_schema_keywords()` and compares this list against it in BOTH directions, so a
 * keyword core adds or drops is a red integration test rather than a stale constant.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class CoreSchemaKeywords
{
    /**
     * wp-includes/rest-api.php:2170-2196, in core's order.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'title', 'description', 'default', 'type', 'format', 'enum', 'items', 'properties',
        'additionalProperties', 'patternProperties', 'minProperties', 'maxProperties', 'minimum',
        'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength',
        'pattern', 'minItems', 'maxItems', 'uniqueItems', 'anyOf', 'oneOf',
    ];
}
