<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * The MCP revisions this server speaks, as a closed set.
 *
 * AN ENUM RATHER THAN AN ARRAY OF STRINGS, because every question asked of this list is
 * a membership question and an enum answers it with the type system instead of with a
 * `in_array(..., true)` whose third argument somebody will forget. A case that does not
 * exist cannot be constructed, so "is this version supported" has exactly one
 * implementation and no second place to drift.
 *
 * DECLARATION ORDER IS NEWEST FIRST, AND THAT IS LOAD-BEARING: latest() returns
 * cases()[0]. Adding a revision means adding its case ABOVE the others and nothing else
 * - no constant to bump, no list to re-sort, no comparison function that has to know
 * that these date strings happen to sort lexicographically (they do, but relying on it
 * would be relying on a coincidence of the format rather than on a decision we made).
 *
 * WHY THREE. `2025-11-25` is what this server negotiates toward and what its feature set
 * was built against. `2025-06-18` and `2025-03-26` are kept because real clients still
 * send them and nothing this server serves - tools only, one JSON response per request,
 * no sessions - behaves differently under any of the three. A revision whose presence
 * would change a response does not belong in this list; it belongs behind a branch.
 *
 * The modern era (`2026-07-28`) is deliberately absent - see the build plan's decisions
 * log, 2026-09-12. It arrives as ONE MORE CASE at the top when a client speaks it.
 */

declare(strict_types=1);

namespace WpMcp;

enum ProtocolVersion: string
{
    case V2025_11_25 = '2025-11-25';
    case V2025_06_18 = '2025-06-18';
    case V2025_03_26 = '2025-03-26';

    /**
     * The newest revision this server speaks - the one `initialize` offers when the
     * client asked for something we do not know.
     *
     * cases() returns them in declaration order, so this is the first case. See the
     * class docblock: that order is the single place the answer is recorded.
     */
    public static function latest(): self
    {
        return self::cases()[0];
    }

    /**
     * A client-supplied string, or null, turned into a case or null.
     *
     * Takes `?string` because every caller's input is "the header that may not be
     * there" or "the param that may not be there", and an absent value is not an
     * unsupported one - the caller has to be able to tell those apart without first
     * having to guard the call.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value);
    }

    /** Do we speak this revision? The membership question, asked once. */
    public static function isSupported(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
