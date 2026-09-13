<?php
/**
 * One unwrapped MCP tool result: the isError flag and the single text block.
 *
 * On success the text is the tool's own JSON, as a string - that is how this server
 * serialises a tool return value - so data() decodes it. On failure the text is the
 * human message and there is no JSON, which is why data() refuses rather than
 * returning an empty array: a test that asserts on fields of a failed call would
 * otherwise pass by asserting nothing.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class ToolResult
{
    public function __construct(
        public readonly bool $isError,
        public readonly string $text
    ) {
    }

    /** The tool's decoded return value. Only valid when isError is false. */
    public function data(): array
    {
        if ($this->isError) {
            throw new RuntimeException(
                'The tool call failed, so it has no data. Message: ' . $this->text
            );
        }

        $decoded = json_decode($this->text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('The tool result text is not JSON: ' . $this->text);
        }

        return $decoded;
    }

    /** The `items` list of a list-* tool. */
    public function items(): array
    {
        $data = $this->data();

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('The tool result has no items list: ' . $this->text);
        }

        return $data['items'];
    }

    /** Every value of one field across `items`. */
    public function column(string $field): array
    {
        return array_map(
            static fn (array $item) => $item[$field] ?? null,
            $this->items()
        );
    }
}
