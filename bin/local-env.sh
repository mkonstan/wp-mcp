#!/usr/bin/env bash
# wp-mcp local dev environment (Git Bash on Windows, Local by Flywheel toolchain).
#
#   source bin/local-env.sh
#   composer install
#   composer test:unit
#   composer test:integration
#
# Everything here is MACHINE-SPECIFIC and read from environment variables, with the
# values of Max's workstation as defaults. Override any of them before sourcing:
#
#   WPMCP_LOCAL_APPDATA   Windows APPDATA as a POSIX path        (default: cygpath -u "$APPDATA")
#   WPMCP_PHP_SERVICE     Local lightning-services PHP folder    (default: php-8.2.29+0)
#   WPMCP_LOCAL_BIN       Local's extraResources/bin folder      (default: Program Files (x86) path)
#   WPMCP_LOCAL_SITE_ID   Local's per-site run id, for PHPRC     (default: qztNV-K_M)
#   WPMCP_SITE_PATH       WordPress docroot of the test site     (default: jaygroup site)
#   WPMCP_TEST_URL        base URL the integration suite hits    (default: https://jaygroup.local)
#   WPMCP_CAINFO          PEM bundle for composer TLS (see below) (default: unset)
#
# The site id and the PHP service version change when Local updates or the site is
# recreated. If `php` below fails to start, re-read them from Local's UI or from
#   ls "$WPMCP_LOCAL_APPDATA/Local/run"      and
#   ls "$WPMCP_LOCAL_APPDATA/Local/lightning-services"
#
# This script is sourced, not executed: it exports variables and defines functions in
# the calling shell. The `wp` batch shim that Local puts on PATH is broken by the space
# in "Program Files (x86)"; that is why we call the .phar files through PHP directly.
#
# Exported: PHP, WPCLI, COMPOSER_PHAR, PHPRC, WPMCP_SITE_PATH, WPMCP_TEST_URL.
# Defined:  php, wp, composer, phpunit shell functions.

WPMCP_LOCAL_APPDATA="${WPMCP_LOCAL_APPDATA:-$(cygpath -u "$APPDATA" 2>/dev/null || echo "$HOME/AppData/Roaming")}"
WPMCP_PHP_SERVICE="${WPMCP_PHP_SERVICE:-php-8.2.29+0}"
WPMCP_LOCAL_BIN="${WPMCP_LOCAL_BIN:-/c/Program Files (x86)/Local/resources/extraResources/bin}"
WPMCP_LOCAL_SITE_ID="${WPMCP_LOCAL_SITE_ID:-qztNV-K_M}"

export PHP="${PHP:-$WPMCP_LOCAL_APPDATA/Local/lightning-services/$WPMCP_PHP_SERVICE/bin/win64/php.exe}"
export WPCLI="${WPCLI:-$WPMCP_LOCAL_BIN/wp-cli/wp-cli.phar}"

# NOT named COMPOSER. Composer reads the environment variable $COMPOSER as the path to
# the *manifest* it should load instead of ./composer.json, so exporting the phar path
# there makes every composer command die with
#     "...composer.phar" does not contain valid JSON
# Verified on this machine. The phar path lives in COMPOSER_PHAR; COMPOSER is unset so
# a stale value inherited from the parent shell cannot poison the run either.
export COMPOSER_PHAR="${COMPOSER_PHAR:-$WPMCP_LOCAL_BIN/composer/composer.phar}"
unset COMPOSER

# php.ini lives per-site under Local/run/<site id>/conf/php. PHPRC must be a WINDOWS
# path because php.exe is a native Windows binary and cannot read /c/... form.
export PHPRC="${PHPRC:-$(cygpath -w "$WPMCP_LOCAL_APPDATA/Local/run/$WPMCP_LOCAL_SITE_ID/conf/php" 2>/dev/null)}"

export WPMCP_SITE_PATH="${WPMCP_SITE_PATH:-C:/Users/vbwiz/Local Sites/jaygroup/app/public}"
export WPMCP_TEST_URL="${WPMCP_TEST_URL:-https://jaygroup.local}"

export WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1

# TLS trust for composer's outbound HTTPS. Only needed on a machine whose antivirus
# intercepts TLS (Avast/AVG "Web Shield" re-signs every certificate with a local root
# that ships in the Windows store but in no PEM bundle, so PHP's curl reports
# "unable to get local issuer certificate" while Windows-native tools succeed).
#
# Set WPMCP_CAINFO to a PEM bundle that contains that root, e.g. built once with:
#
#   powershell -c '$c = Get-ChildItem Cert:\LocalMachine\Root |
#       ? { $_.Subject -like "*Avast*" } | select -First 1
#     $b = [Convert]::ToBase64String($c.RawData, "InsertLineBreaks")
#     (Get-Content "C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt" -Raw) +
#       "`n-----BEGIN CERTIFICATE-----`n$b`n-----END CERTIFICATE-----`n" |
#       Set-Content $env:USERPROFILE\ca-with-av.pem -Encoding ascii'
#
# Leave it unset on a machine without TLS interception; PHP's own default is used.
# It affects composer only - the integration tests reach a self-signed *.local site
# with verify=false, and CI has no interception at all.
WPMCP_CAINFO="${WPMCP_CAINFO:-}"

# Local's PHP build loads imagick from a DLL it does not ship; the resulting startup
# warning pollutes stdout and would trip beStrictAboutOutputDuringTests. Silence it.
WPMCP_PHP_QUIET="-d display_startup_errors=0 -d error_reporting=0"

# Composer's scripts shell out to a bare `php` (vendor/bin/phpunit.bat does), so a
# `php` has to exist on PATH - and it has to carry WPMCP_PHP_QUIET, or every composer
# script run is prefixed by the imagick startup warning. Generate a one-line cmd shim
# in a gitignored directory and put that ahead of everything on PATH. cmd.exe resolves
# PATH directory-by-directory, so the shim wins over any other php on the machine.
WPMCP_SHIM_DIR="${WPMCP_SHIM_DIR:-$(dirname "${BASH_SOURCE[0]}")/../.local-bin}"
mkdir -p "$WPMCP_SHIM_DIR"
printf '@echo off
"%s" %s %%*
'     "$(cygpath -w "$PHP" 2>/dev/null || echo "$PHP")" "$WPMCP_PHP_QUIET"     > "$WPMCP_SHIM_DIR/php.cmd"
WPMCP_SHIM_DIR="$(cd "$WPMCP_SHIM_DIR" && pwd)"
case ":$PATH:" in
    *":$WPMCP_SHIM_DIR:"*) ;;
    *) PATH="$WPMCP_SHIM_DIR:$PATH"; export PATH ;;
esac

WPMCP_PHP_TLS=""
if [ -n "$WPMCP_CAINFO" ]; then
    WPMCP_PHP_TLS="-d curl.cainfo=$WPMCP_CAINFO -d openssl.cafile=$WPMCP_CAINFO"
fi

php()      { "$PHP" $WPMCP_PHP_QUIET "$@"; }
wp()       { "$PHP" $WPMCP_PHP_QUIET "$WPCLI" --path="$WPMCP_SITE_PATH" "$@"; }
composer() { "$PHP" $WPMCP_PHP_QUIET $WPMCP_PHP_TLS "$COMPOSER_PHAR" "$@"; }
phpunit()  { "$PHP" $WPMCP_PHP_QUIET vendor/phpunit/phpunit/phpunit "$@"; }

if [ ! -x "$PHP" ]; then
    echo "local-env: PHP not found at $PHP" >&2
    echo "local-env: set WPMCP_PHP_SERVICE (see ls \"$WPMCP_LOCAL_APPDATA/Local/lightning-services\")" >&2
fi

echo "local-env: PHP=$PHP"
echo "local-env: WPMCP_SITE_PATH=$WPMCP_SITE_PATH"
echo "local-env: WPMCP_TEST_URL=$WPMCP_TEST_URL"
