<?php
/**
 * PHPUnit bootstrap for wp-mcp.
 *
 * Two jobs:
 *   1. Composer's dev autoloader (PSR-4 WpMcp\Tests\ -> tests/).
 *   2. WPMCP_PLUGIN_DIR, so tests can find the plugin files without guessing.
 *
 * It deliberately does NOT define WordPress stubs at bootstrap time. Stubs are a
 * per-test-case concern: a unit test that wants the plugin *loaded* calls
 * WpMcp\Tests\Unit\WordPressStubs::load(), which defines the minimum set (see that
 * class) and requires wp-mcp.php once. Defining them here would silently mask a
 * future load-time dependency on a real WordPress function - exactly the drift this
 * harness exists to catch.
 *
 * Integration tests need no stubs at all: they are black-box HTTP against a running
 * site and never load plugin code in-process.
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "wp-mcp tests: vendor/autoload.php is missing.\n"
        . "Run `composer install` first (locally: source bin/local-env.sh && composer install).\n"
    );
    exit(1);
}

require $autoload;

define('WPMCP_PLUGIN_DIR', dirname(__DIR__));
