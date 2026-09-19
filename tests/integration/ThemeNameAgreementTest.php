<?php
/**
 * site-info and list-themes report the same active theme name (sprint 14d, G5).
 *
 * ONE VALUE, TWO ANSWERS. site-info glued "Name Version" together from WP_Theme, so a theme
 * with no Version header came back as `"JDA "` while list-themes, reading the header itself,
 * said `"JDA"` (Max's live run on seosemia.net, 2026-09-18). Both now read the header through
 * one function, and site-info's version is a key of its own.
 *
 * The live comparison runs against whatever theme the site has; the case that went wrong -
 * a header with NO Version line - cannot be installed on a site somebody else is using, so
 * it is proved on the function both tools call, in the site's own PHP, against a style.css
 * written to the temp directory and removed in the same process.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use RuntimeException;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class ThemeNameAgreementTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('themename'); }
    private static function login(): string { return Fixtures::name('themename-admin'); }

    private static int $userId = 0;
    private static string $token = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
        self::$token  = Fixtures::mintToken('admin', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /**
     * G5. The name site-info reports for the active theme is the name list-themes reports
     * for the theme it marks active, byte for byte, and neither carries trailing space.
     *
     * @group sprint-14d
     */
    public function testSiteInfoAndListThemesNameTheActiveThemeIdentically(): void
    {
        $client = $this->mcp(self::$token);

        $info   = $client->callTool('site-info');
        $themes = $client->callTool('list-themes');

        self::assertFalse($info->isError, $info->text);
        self::assertFalse($themes->isError, $themes->text);

        $active = array_values(array_filter(
            $themes->data()['themes'],
            static fn (array $theme) => $theme['active'] === true
        ));

        self::assertCount(1, $active, 'list-themes marks no theme, or more than one, active.');
        self::assertSame(
            $active[0]['name'],
            $info->data()['active_theme'],
            'site-info and list-themes disagree about the active theme\'s name.'
        );
        self::assertSame(trim((string) $info->data()['active_theme']), $info->data()['active_theme']);
        self::assertSame($active[0]['version'], $info->data()['active_theme_version'], 'The two tools disagree about its version.');
    }

    /**
     * G5, the case that went wrong: a style.css with no Version header gives a name with
     * no trailing space and an empty version, through the function both tools call.
     *
     * @group sprint-14d
     */
    public function testAThemeWithNoVersionHeaderHasNoTrailingSpace(): void
    {
        $out = WpCli::evaluate(sprintf(
            '$f = trailingslashit(get_temp_dir()) . %s . "-style.css";'
            . ' file_put_contents($f, "/*\nTheme Name: JDA \nAuthor: x\n*/\n");'
            . ' $h = wpmcp_theme_header($f); @unlink($f);'
            . ' echo "\n", base64_encode(wp_json_encode(array($h["name"], $h["version"], file_exists($f))));',
            var_export(Fixtures::name('themename'), true)
        ));

        $lines = preg_split('/\r?\n/', trim($out));
        $got   = json_decode((string) base64_decode((string) end($lines), true), true);

        if (!is_array($got)) {
            throw new RuntimeException('Could not read the header: ' . substr($out, 0, 300));
        }

        self::assertSame(['JDA', '', false], $got, 'A header with no Version line does not read as a clean name and an empty version.');
    }
}
