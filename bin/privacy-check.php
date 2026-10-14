<?php
/**
 * privacy-check - refuse to publish machine-specific or personal information.
 *
 * WHY THIS EXISTS AND WHAT IT IS NOT. GitHub's secret scanning and push protection are
 * ON for this repository and they are good at what they do: they recognise the SHAPE of
 * a credential issued by a known provider and block the push. They will never flag a
 * Windows username, a client's hostname or a real database table prefix, because none of
 * those is a secret. They are private information, which is a different thing, and on
 * 2026-10-13 this repository publicly carried all three.
 *
 * THE DESIGN CONSTRAINT THAT SHAPES EVERYTHING HERE: a checker that looks for the literal
 * strings "vbwiz" or "a hosted staging site" would have to CONTAIN them, and this file is
 * public - so the check would publish the very thing it exists to suppress. Every pattern
 * below therefore matches a SHAPE, never a value: any Windows user directory, any home
 * path, any `.local` host, any address. It knows nothing private and so can leak nothing.
 *
 * FOR THE SAME REASON IT NEVER PRINTS WHAT IT MATCHED. A CI log is public on a public
 * repository. It prints the rule and the location and stops there; whoever fixes it opens
 * the file.
 *
 * Scans the files git actually tracks, because untracked files are not published.
 *
 *   php bin/privacy-check.php            scan, print findings, exit 1 if any
 *   php bin/privacy-check.php --list     print the rules and exit 0
 *
 * Exit: 0 clean, 1 findings, 2 could not run (which is NOT clean - see the gate note).
 */

declare(strict_types=1);

const RULES = [
    'windows-user-path' => [
        'pattern' => '~[A-Za-z]:[\\\\/]Users[\\\\/][^\\\\/\s"\']+~',
        'why'     => 'a Windows user directory carries the account name',
    ],
    'unix-home-path' => [
        'pattern' => '~(?<![\w.])/(?:home|Users)/[a-z][\w.-]*~i',
        'why'     => 'a home directory carries the account name',
    ],
    'dot-local-host' => [
        'pattern' => '~(?<![\w.-])[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.local(?![\w.-])~i',
        'why'     => 'a .local host names a real site on someone machine',
    ],
    'email-address' => [
        'pattern' => '~(?<![\w.+-])[\w.+-]+@[\w-]+\.[a-z]{2,}~i',
        'why'     => 'an address identifies a person',
    ],
    'public-hostname' => [
        'pattern' => '~(?<![\w.-])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:com|net|org|io|co|dev|app|site)(?![\w-])~i',
        'why'     => 'a real hostname identifies a site or a client',
    ],
];

/**
 * Hosts that are legitimately named in documentation and comments. Shapes and
 * standards bodies only - never anything belonging to a user of this plugin.
 */
const ALLOWED_HOSTS = [
    'wordpress.org', 'developer.wordpress.org', 'core.trac.wordpress.org',
    'make.wordpress.org', 'codex.wordpress.org', 'wordpress.com',
    'github.com', 'docs.github.com', 'raw.githubusercontent.com',
    'gnu.org', 'www.gnu.org', 'php.net', 'www.php.net', 'secure.php.net',
    'getcomposer.org', 'packagist.org', 'phpunit.de', 'schema.org',
    'json-schema.org', 'modelcontextprotocol.io', 'spec.modelcontextprotocol.io',
    'example.com', 'www.example.com', 'example.net', 'example.org',
    'example.local', 'localhost.local', 'site.local', 'test.local',
    'mysql.com', 'dev.mysql.com', 'mariadb.com', 'mariadb.org',
    'apache.org', 'nginx.org', 'ietf.org', 'rfc-editor.org', 'w3.org',
    'unicode.org', 'iana.org', 'oracle.com', 'microsoft.com', 'anthropic.com',
    'openai.com', 'advancedcustomfields.com', 'wpbeginner.com',
];

/**
 * Placeholder account names. A documented example path MUST use one of these; a real
 * account name is what this check exists to catch, so the rule is "write a placeholder",
 * not "avoid showing paths".
 */
const ALLOWED_PATH_USERS = ['x', 'you', 'user', 'users', 'example', 'username', 'youruser', 'name'];

/** Placeholder site names for .local hosts in documentation and templates. */
const ALLOWED_LOCAL_HOSTS = ['primary', 'secondary', 'example', 'site', 'test', 'my-site', 'mysite', 'yoursite'];

/** Host suffixes always legitimate - standards bodies and the platform's own domains. */
const ALLOWED_HOST_SUFFIXES = ['.wordpress.org', '.wp.org', '.github.com', '.php.net', '.w3.org', '.gnu.org'];

/** Addresses that identify a role rather than a person. */
const ALLOWED_EMAILS = ['noreply@', 'no-reply@', 'security@', 'support@', 'admin@example', 'user@example'];

/** Paths never published, or published as third-party bytes we do not author. */
const SKIP_PATHS = ['vendor/', 'node_modules/', 'composer.lock', 'package-lock.json'];

$listOnly = in_array('--list', $argv, true);
if ($listOnly) {
    foreach (RULES as $name => $rule) {
        printf("  %-20s %s\n", $name, $rule['why']);
    }
    exit(0);
}

// --root is REQUIRED and has no default. The tree this scans decides what the answer
// means, so it is stated at the call site rather than inferred from where this file
// happens to live - a default here would be a scan of something nobody chose.
$root = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--root') {
        $root = $argv[$i + 1] ?? null;
    }
}
if ($root === null || $root === '') {
    fwrite(STDERR, "privacy-check: --root <dir> is required and has no default.\n");
    fwrite(STDERR, "  the repository root to scan, e.g.  --root .\n");
    exit(2);
}
if (!is_dir($root)) {
    fwrite(STDERR, "privacy-check: --root '{$root}' is not a directory.\n");
    exit(2);
}
chdir($root);

exec('git ls-files', $files, $code);
if ($code !== 0 || $files === []) {
    fwrite(STDERR, "privacy-check: could not list tracked files (git ls-files exit {$code}).\n");
    fwrite(STDERR, "This is NOT a pass. A check that did not run must not read as clean.\n");
    exit(2);
}

$findings = 0;
$scanned  = 0;

foreach ($files as $file) {
    foreach (SKIP_PATHS as $skip) {
        if (str_starts_with($file, $skip) || $file === $skip) {
            continue 2;
        }
    }
    if ($file === 'bin/privacy-check.php') {
        continue; // its own rules describe the shapes it hunts
    }
    if (!is_file($file) || !is_readable($file)) {
        continue;
    }
    $text = @file_get_contents($file);
    if ($text === false || $text === '' || str_contains(substr($text, 0, 4096), "\0")) {
        continue; // unreadable or binary
    }

    $scanned++;
    $lines = preg_split('~\R~', $text) ?: [];

    foreach ($lines as $i => $line) {
        foreach (RULES as $name => $rule) {
            if (!preg_match_all($rule['pattern'], $line, $m)) {
                continue;
            }
            foreach ($m[0] as $hit) {
                $low = strtolower($hit);

                if ($name === 'public-hostname') {
                    $bare = str_starts_with($low, 'www.') ? substr($low, 4) : $low;
                    if (in_array($bare, ALLOWED_HOSTS, true)) {
                        continue;
                    }
                    foreach (ALLOWED_HOST_SUFFIXES as $suffix) {
                        if (str_ends_with($bare, $suffix)) {
                            continue 2;
                        }
                    }
                }
                if ($name === 'dot-local-host') {
                    $label = substr($low, 0, strrpos($low, '.local'));
                    if (in_array($label, ALLOWED_LOCAL_HOSTS, true)) {
                        continue;
                    }
                }
                if ($name === 'windows-user-path' || $name === 'unix-home-path') {
                    // In a PHP single-quoted string '\\' is ONE backslash, so the class
                    // below genuinely holds backslash AND slash. Written '[\\/]' it
                    // collapses to "slash only" and silently never splits a Windows path
                    // - which is how this allowance failed the first time it was written.
                    $parts   = preg_split('~[\\\\/]~', $low);
                    $account = $parts ? (string) end($parts) : '';
                    // The match carries whatever punctuation followed it in the prose -
                    // a backtick, a quote, a comma - so take the account name itself.
                    $account = preg_match('~^[\w.-]+~', $account, $mm) ? $mm[0] : $account;
                    if (in_array($account, ALLOWED_PATH_USERS, true)) {
                        continue;
                    }
                }
                if ($name === 'email-address') {
                    $lower = strtolower($hit);
                    foreach (ALLOWED_EMAILS as $ok) {
                        if (str_starts_with($lower, $ok)) {
                            continue 2;
                        }
                    }
                    // an address at an allowed host is a documentation example
                    $host = strtolower(substr($hit, strpos($hit, '@') + 1));
                    if (in_array($host, ALLOWED_HOSTS, true)) {
                        continue;
                    }
                }
                // The VALUE is deliberately not printed: a CI log on a public repo is public.
                printf("%s:%d  [%s] %s\n", $file, $i + 1, $name, $rule['why']);
                $findings++;
            }
        }
    }
}

if ($findings > 0) {
    printf("\nprivacy-check: %d finding(s) across %d tracked file(s).\n", $findings, $scanned);
    echo "Open each location and decide: remove it, neutralise it, or - if it genuinely\n";
    echo "belongs in public - add the host to ALLOWED_HOSTS with a reason.\n";
    exit(1);
}

printf("privacy-check: clean - %d tracked files, no machine-specific or personal information.\n", $scanned);
exit(0);
