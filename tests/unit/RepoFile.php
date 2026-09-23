<?php
/**
 * READ A FILE OF THIS REPOSITORY THE WAY AN ASSERTION SHOULD SEE IT: with LF line endings,
 * whatever git checked out.
 *
 * WHY THIS EXISTS, and it is not a tidiness helper. `.gitattributes` says `* text=auto` and this
 * project's own machine has `core.autocrlf=true`, so the WORKING COPY of a text file is CRLF on
 * Windows and LF on the Linux runner - `git ls-files --eol` reports `i/lf w/crlf` for
 * `.github/workflows/ci.yml` here and `i/lf w/lf` there. Any assertion anchored on a line end
 * (`/…$/m`) therefore passes in CI and fails on a laptop, because `$` in multiline mode does not
 * match before a `\r`.
 *
 * That is exactly what happened: `CiShardsTest`'s shard-matrix assertion was green on every CI
 * run of round 4 and RED on the owner's checkout - one failure in 209 - and it was found by a
 * reviewer running the tier where it is supposed to be runnable rather than by the tier itself.
 * The unit suite's whole promise is "pure PHP, no WordPress, no Docker, runs on a laptop in a
 * second"; a tier that is red on the only laptop in the project has stopped keeping it, and a red
 * people learn to wave through is how the next real one gets waved through.
 *
 * NORMALISE WHAT YOU READ; DO NOT LOOSEN WHAT YOU ASSERT. The tempting fix is `\r?$` in every
 * pattern, and it is the wrong one twice over: it has to be remembered in every future pattern,
 * and it makes each assertion slightly weaker than the thing it means to say. Reading through
 * here costs one call and leaves the patterns saying precisely what they mean.
 *
 * Only for files IN the repository, and only where the answer is text. A test that must see the
 * bytes as they sit on disk - a build stamp, a zip, anything hashed - reads them itself.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\Assert;

final class RepoFile
{
    /**
     * @param string $relative path from the plugin root, e.g. `.github/workflows/ci.yml`
     */
    public static function read(string $relative): string
    {
        $path = \WPMCP_PLUGIN_DIR . '/' . $relative;

        Assert::assertFileExists($path, "The test wants to read {$relative} and it is not there.");

        $contents = file_get_contents($path);

        Assert::assertIsString($contents, "Could not read {$relative} at {$path}.");

        // CRLF and a lone CR both become LF. The lone CR is not theoretical on a checkout that
        // has been through more than one tool.
        return (string) preg_replace('/\r\n?/', "\n", (string) $contents);
    }
}
