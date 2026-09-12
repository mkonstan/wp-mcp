<?php
/**
 * Fixtures for the integration tier, on a real site, through wp-cli.
 *
 * THE RULES THIS CLASS EXISTS TO ENFORCE. The integration tier runs against a live
 * WordPress that belongs to somebody, so:
 *
 *   1. Everything created is named with the PREFIX. Nothing else is ever touched -
 *      no pre-existing post, user, comment or option is read-modify-written.
 *   2. Everything created is deleted in teardown, and teardown tolerates a fixture
 *      that is already gone (one test deletes its own user on purpose).
 *   3. purge() runs BEFORE creation as well as after. A run killed halfway leaves
 *      `wpmcp-test-editor` behind, and `wp user create` on an existing login fails -
 *      so the next run would fail at setup for a reason that has nothing to do with
 *      the code. Purging first makes the suite re-runnable.
 *   4. leftoverUsers()/leftoverPosts() are the same listings a human would run to
 *      check for debris, so the suite can assert its own cleanliness.
 *
 * AND THE RULE THAT MAKES TWO RUNNERS POSSIBLE AT ONCE (sprint 2, item 0).
 *
 * Every fixture name carries a PER-RUN suffix: `wpmcp-test-<8 hex>-<what it is>`. The
 * eight hex digits come from random_bytes once per process and are exported in
 * WPMCP_TEST_RUN_ID, which every `wp eval` child inherits, so one run's names cannot
 * collide with another's and - the part that actually bit - one run's purge() cannot
 * delete another run's live fixtures. Before this, purge() matched the shared
 * `wpmcp-test-` prefix, and two agents verifying the same site destroyed each other's
 * users, posts and tokens mid-test: rows vanished between an insert and the read that
 * asserted on it. The reviewer saw exactly that and could not call it a code bug.
 *
 * The debris checks still match the SHARED prefix, because the question "is anything
 * of mine left" and the question "is anything of any run left" are both worth
 * answering. Anything prefixed but carrying somebody else's run id is FOREIGN DEBRIS:
 * reported on STDERR, never deleted - another run may be live and still need it.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class Fixtures
{
    /** Every fixture name of every run starts with this. The debris checks key off it. */
    public const PREFIX = 'wpmcp-test-';

    /** The environment variable that carries this run's id to every `wp eval` child. */
    public const RUN_ID_ENV = 'WPMCP_TEST_RUN_ID';

    /** Lazily generated once per process; see runId(). */
    private static string $runId = '';

    /** Foreign-debris reports already written to STDERR, so they are not repeated. */
    private static array $reported = [];

    /**
     * This run's id: eight hex digits, generated ONCE per process.
     *
     * Inherited, not regenerated, when WPMCP_TEST_RUN_ID is already set - that is how
     * a `wp eval` child, and anything else this process spawns, ends up agreeing with
     * the PHPUnit process that spawned it. putenv() is what makes the inheritance
     * work: proc_open hands the child the current process environment.
     */
    public static function runId(): string
    {
        if (self::$runId === '') {
            $inherited = trim((string) (getenv(self::RUN_ID_ENV)
                ?: ($_ENV[self::RUN_ID_ENV] ?? $_SERVER[self::RUN_ID_ENV] ?? '')));

            self::$runId = (strlen($inherited) === 8 && ctype_xdigit($inherited))
                ? strtolower($inherited)
                : bin2hex(random_bytes(4));

            putenv(self::RUN_ID_ENV . '=' . self::$runId);
            $_ENV[self::RUN_ID_ENV]    = self::$runId;
            $_SERVER[self::RUN_ID_ENV] = self::$runId;
        }

        return self::$runId;
    }

    /** `wpmcp-test-<run id>-` : what every name THIS run creates begins with. */
    public static function runPrefix(): string
    {
        return self::PREFIX . self::runId() . '-';
    }

    /**
     * The name this run uses for $what. Every fixture name goes through here.
     *
     * Not a const, which is why the test classes that used to write
     * `Fixtures::PREFIX . 'editor'` in a const now call this from a static method: a
     * per-run value cannot be a compile-time constant expression.
     */
    public static function name(string $what): string
    {
        return self::runPrefix() . $what;
    }

    /**
     * The run id embedded in a fixture name, or '' when there is none.
     *
     * '' covers both "not one of ours at all" and the legacy shape
     * `wpmcp-test-<what>` that predates this suffix - a name the purge of an older
     * checkout would have cleaned and this one deliberately will not.
     */
    public static function runIdIn(string $name): string
    {
        if (!str_starts_with($name, self::PREFIX)) {
            return '';
        }

        $rest = substr($name, strlen(self::PREFIX));

        if (strlen($rest) < 9 || $rest[8] !== '-' || !ctype_xdigit(substr($rest, 0, 8))) {
            return '';
        }

        return strtolower(substr($rest, 0, 8));
    }

    /**
     * The rows of a debris listing that belong to THIS run - the only ones purge()
     * may delete.
     *
     * @param array<int|string, string> $rows id-or-key => name
     * @return array<int|string, string>
     */
    public static function ours(array $rows): array
    {
        return array_filter($rows, static fn (string $n) => str_starts_with($n, self::runPrefix()));
    }

    /**
     * The rows that are prefixed but not ours: another run's, or a legacy name.
     *
     * @param array<int|string, string> $rows id-or-key => name
     * @return array<int|string, string>
     */
    public static function foreign(array $rows): array
    {
        return array_filter($rows, static fn (string $n) => !str_starts_with($n, self::runPrefix()));
    }

    /** @return int the new user's ID */
    public static function createUser(string $login, string $role): int
    {
        self::assertPrefixed($login);

        $id = (int) WpCli::run([
            'user', 'create', $login, $login . '@example.invalid',
            '--role=' . $role,
            '--user_pass=' . bin2hex(random_bytes(16)),
            '--porcelain',
        ]);

        if ($id <= 0) {
            throw new RuntimeException("Could not create the fixture user {$login}.");
        }

        return $id;
    }

    /**
     * @param string $type   post type; 'attachment' is allowed so the media tools and
     *                       get-post's post-type refusal can be tested.
     * @param int    $parent post_parent, which is what gives an attachment its
     *                       effective status - an attachment of a private post is
     *                       private, and that is the whole point of the media checks.
     * @param string $commentStatus 'open' or 'closed', or '' to take the site default.
     *                       Pass it EXPLICITLY whenever a test's outcome depends on
     *                       whether the thread is open: reply-comment's refusal was
     *                       red only because this site's default_comment_status
     *                       happens to be open, which is a property of somebody's
     *                       site and not of the code under test.
     * @return int the new post's ID
     */
    public static function createPost(
        string $title,
        string $status,
        int $author,
        string $content,
        string $type = 'post',
        int $parent = 0,
        string $commentStatus = ''
    ): int {
        self::assertPrefixed($title);

        $args = [
            'post', 'create',
            '--post_type=' . $type,
            '--post_title=' . $title,
            '--post_status=' . $status,
            '--post_author=' . $author,
            '--post_content=' . $content,
            '--porcelain',
        ];

        if ($parent > 0) { $args[] = '--post_parent=' . $parent; }
        if ($commentStatus !== '') { $args[] = '--comment_status=' . $commentStatus; }

        $id = (int) WpCli::run($args);

        if ($id <= 0) {
            throw new RuntimeException("Could not create the fixture post {$title}.");
        }

        return $id;
    }

    /**
     * Create $count posts named "<prefix>1".."<prefix>N" in one `wp eval`.
     *
     * One process instead of N. A test that needs more published posts than
     * list-posts' default limit needs ~25 of them, and 25 wp-cli spawns cost about
     * fifteen seconds locally and considerably more through `wp-env run`. They are
     * created in ascending order, so the last one has the highest ID.
     *
     * @return list<int> the new post IDs, in creation order
     */
    public static function createPosts(int $count, string $titlePrefix, string $status, int $author): array
    {
        self::assertPrefixed($titlePrefix);

        $ids = WpCli::evaluate(sprintf(
            'for ($i = 1; $i <= %d; $i++) {'
            . ' $id = wp_insert_post(array('
            . '  "post_title" => %s . $i,'
            . '  "post_status" => %s,'
            . '  "post_type" => "post",'
            . '  "post_author" => %d,'
            . '  "post_content" => "wpmcp-test-bulk"'
            . ' ), true);'
            . ' if (is_wp_error($id)) { echo "ERROR:" . $id->get_error_message(); exit(1); }'
            . ' echo (int) $id, ",";'
            . '}',
            $count,
            self::phpString($titlePrefix),
            self::phpString($status),
            $author
        ));

        $out = array_values(array_filter(array_map('intval', explode(',', $ids))));

        if (count($out) !== $count) {
            throw new RuntimeException(
                "Expected {$count} bulk fixture posts, got " . count($out) . ": {$ids}"
            );
        }

        return $out;
    }

    /**
     * @param string $email author email, or '' to leave it empty. Set it only when a
     *                      test needs to prove the email is NOT reachable - it is
     *                      never returned by any tool.
     * @return int the new comment's ID
     */
    public static function createComment(int $postId, string $content, bool $approved, string $email = ''): int
    {
        self::assertPrefixed($content);

        $args = [
            'comment', 'create',
            '--comment_post_ID=' . $postId,
            '--comment_content=' . $content,
            '--comment_approved=' . ($approved ? '1' : '0'),
            '--porcelain',
        ];

        if ($email !== '') {
            self::assertPrefixed($email);
            $args[] = '--comment_author_email=' . $email;
        }

        $id = (int) WpCli::run($args);

        if ($id <= 0) {
            throw new RuntimeException("Could not create the fixture comment on post {$postId}.");
        }

        return $id;
    }

    /**
     * Mint a token for $userId and return the raw value.
     *
     * The raw token exists exactly once, in wpmcp_mint()'s return value - only its
     * SHA-256 is stored - so it has to be captured from the mint call itself. `wp
     * eval` echoes it on stdout; there is no other way to get one without
     * reimplementing the hash.
     *
     * AS USER 1. wpmcp_mint() requires edit_user over the target when minting for
     * somebody else, and wp-cli has no current user at all - so an unattributed
     * `wp eval` is refused, correctly. User 1 is the site's original administrator,
     * which is who does the minting in the admin UI too, so this mirrors reality
     * rather than working around the check: created_by lands on 1 exactly as it
     * would from Settings > WP MCP.
     */
    public static function mintToken(string $scope, string $label, int $userId): string
    {
        self::assertPrefixed($label);

        $raw = WpCli::evaluate(
            sprintf(
                '$r = wpmcp_mint(%s, %s, 3600, %d); echo is_wp_error($r) ? "MINT-ERROR: " . $r->get_error_message() : $r["raw"];',
                self::phpString($scope),
                self::phpString($label),
                $userId
            ),
            1
        );

        if (strlen($raw) !== 64 || !ctype_xdigit($raw)) {
            throw new RuntimeException("wpmcp_mint() did not return a token: {$raw}");
        }

        return $raw;
    }

    /** Tolerant: the user may already have been deleted by the test itself. */
    public static function deleteUser(int $id): void
    {
        if ($id > 1) {
            WpCli::tryRun(['user', 'delete', (string) $id, '--reassign=1', '--yes']);
        }
    }

    /** Tolerant. Force, so nothing is left in the trash. */
    public static function deletePost(int $id): void
    {
        if ($id > 0) {
            WpCli::tryRun(['post', 'delete', (string) $id, '--force']);
        }
    }

    /**
     * Backdate every token with this label so it is expired on the next request.
     *
     * wpmcp_mint() clamps the TTL to at least 60 seconds, so an already-expired token
     * cannot be minted - and waiting a minute in a test is not an option. The row is
     * written by the real mint and only its expires_at is moved, so the request under
     * test takes the genuine expiry branch.
     */
    public static function expireTokensLabelled(string $label): void
    {
        self::assertPrefixed($label);

        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"UPDATE " . wpmcp_table() . " SET expires_at = %%s WHERE label = %%s",'
            . ' gmdate("Y-m-d H:i:s", time() - 3600), %s));',
            self::phpString($label)
        ));
    }

    /**
     * Pin every token with this label to $ip, so a request from anywhere else takes
     * the IP-mismatch branch. The TOFU bind is normally made by the first tool call
     * from the caller's own address, which is the one address a test cannot use.
     */
    public static function bindTokensLabelled(string $label, string $ip): void
    {
        self::assertPrefixed($label);

        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"UPDATE " . wpmcp_table() . " SET bound_ip = %%s WHERE label = %%s",'
            . ' %s, %s));',
            self::phpString($ip),
            self::phpString($label)
        ));
    }

    /**
     * Delete tokens by label. Via `wp eval` and $wpdb->delete rather than `wp db
     * query`, because the mysql client is not on PATH on every machine that can run
     * this suite - notably not on the one it was written on.
     */
    public static function deleteTokensLabelled(string $label): void
    {
        self::assertPrefixed($label);

        WpCli::tryEvaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->delete(wpmcp_table(), array("label" => %s), array("%%s"));',
            self::phpString($label)
        ));
    }

    /**
     * Delete every user, post, term, token and mu-plugin THIS RUN created, and report
     * - without deleting - anything another run left behind.
     *
     * Safe to call at any time, and safe to call while another runner is mid-test,
     * which is the whole point of the run suffix.
     */
    public static function purge(): void
    {
        foreach (self::ours(self::leftoverPosts()) as $id => $title) {
            self::deletePost((int) $id);
        }

        foreach (self::ours(self::leftoverUsers()) as $id => $login) {
            self::deleteUser((int) $id);
        }

        // TERMS TOO. A red run of the create-post {terms} test left
        // `wpmcp-test-term-via-create-post` on the site, because the debris check and
        // the purge only knew about posts, users and tokens - the tools that can create
        // a term were the ones that had no capability check, so nothing had ever
        // created one before. Every taxonomy, not just category.
        foreach (self::leftoverTerms() as $taxonomy => $terms) {
            foreach (self::ours($terms) as $id => $name) {
                WpCli::tryRun(['term', 'delete', $taxonomy, (string) $id]);
            }
        }

        WpCli::tryEvaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"DELETE FROM " . wpmcp_table() . " WHERE label LIKE %%s", %s));',
            self::phpString(self::runPrefix() . '%')
        ));

        MuPlugin::removeOurs();

        self::warnAboutForeignDebris();
    }

    /**
     * Everything on the site that is fixture-named but not this run's, as a human-
     * readable report - '' when there is none.
     *
     * WARN, NEVER DELETE. A name carrying another run id may belong to a suite that is
     * running right now; deleting it would reproduce the exact cross-run destruction
     * the run suffix exists to stop. A name with no run id at all is older debris, and
     * equally not ours to remove without being asked.
     */
    public static function foreignDebris(): string
    {
        $lines = [];

        foreach (self::foreign(self::leftoverUsers()) as $id => $login) {
            $lines[] = sprintf('  user %-8s %s', (string) $id, $login);
        }

        foreach (self::foreign(self::leftoverPosts()) as $id => $title) {
            $lines[] = sprintf('  post %-8s %s', (string) $id, $title);
        }

        foreach (self::leftoverTerms() as $taxonomy => $terms) {
            foreach (self::foreign($terms) as $id => $name) {
                $lines[] = sprintf('  term %-8s %s (%s)', (string) $id, $name, $taxonomy);
            }
        }

        foreach (self::foreign(self::leftoverTokenLabels()) as $id => $label) {
            $lines[] = sprintf('  token %-7s %s', (string) $id, $label);
        }

        foreach (self::foreign(MuPlugin::leftovers()) as $file) {
            $lines[] = '  mu-plugin         ' . $file;
        }

        if ($lines === []) {
            return '';
        }

        $suffixes = [];

        foreach (array_merge(
            array_values(self::foreign(self::leftoverUsers())),
            array_values(self::foreign(self::leftoverPosts())),
            array_values(self::foreign(self::leftoverTokenLabels())),
            array_values(self::foreign(MuPlugin::leftovers()))
        ) as $name) {
            $suffixes[self::runIdIn($name) ?: '(no run id)'] = true;
        }

        return "FOREIGN FIXTURE DEBRIS on the site under test.\n"
            . 'This run is ' . self::runId() . '; the following carry other run ids ('
            . implode(', ', array_keys($suffixes)) . ").\n"
            . "NOT deleted: another run may be live and still using them.\n"
            . implode("\n", $lines) . "\n";
    }

    /** foreignDebris() to STDERR, at most once per distinct report per process. */
    public static function warnAboutForeignDebris(): void
    {
        $report = self::foreignDebris();

        if ($report === '' || isset(self::$reported[$report])) {
            return;
        }

        self::$reported[$report] = true;

        // STDERR, not echo: beStrictAboutOutputDuringTests turns anything on stdout
        // into a failed test, and a debris warning must not be the thing that goes red.
        fwrite(STDERR, "\n" . $report);
    }

    /**
     * Fixture-named users still on the site.
     *
     * @return array<int, string> id => user_login
     */
    public static function leftoverUsers(): array
    {
        return self::matchingRows(
            ['user', 'list', '--format=csv', '--fields=ID,user_login']
        );
    }

    /**
     * Fixture-named posts still on the site, of ANY post type and in any status
     * INCLUDING trash.
     *
     * `--post_status=any` is WP_Query's `any`, which excludes trash and auto-draft, so
     * a fixture post that something trashed rather than force-deleted would be
     * invisible to the debris check. The statuses are listed explicitly instead.
     *
     * `--post_type=any` matters just as much and was missing: `wp post list` defaults
     * to `post`, so the attachment fixture (and any future page or CPT one) was
     * invisible here. destroy() deletes it by id, but after a crashed
     * setUpBeforeClass only purge() runs - and purge() reads this listing.
     *
     * @return array<int, string> id => post_title
     */
    public static function leftoverPosts(): array
    {
        return self::matchingRows([
            'post', 'list',
            '--post_type=any',
            '--post_status=publish,future,draft,pending,private,trash,auto-draft,inherit',
            '--format=csv', '--fields=ID,post_title',
        ]);
    }

    /**
     * Fixture-named terms still on the site, in every taxonomy.
     *
     * `wp term list` needs a taxonomy, so the whole lot is done in one `wp eval` with
     * get_terms over every registered taxonomy - cheaper than one spawn per taxonomy,
     * and it cannot miss a taxonomy somebody registers later.
     *
     * The NAME comes back with the id, because the run suffix lives in the name: an
     * id alone cannot say whose term it is.
     *
     * @return array<string, array<int, string>> taxonomy => (term id => name)
     */
    public static function leftoverTerms(): array
    {
        $raw = WpCli::evaluate(sprintf(
            'foreach (get_taxonomies(array(), "names") as $tax) {'
            . '  $terms = get_terms(array("taxonomy" => $tax, "hide_empty" => false,'
            . '   "name__like" => %s));'
            . '  if (!is_wp_error($terms)) {'
            . '   foreach ($terms as $t) {'
            . '    echo $tax, "\t", (int) $t->term_id, "\t", $t->name, "\n";'
            . '   }'
            . '  }'
            . ' }',
            self::phpString(self::PREFIX)
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", trim($line, "\r\n"));

            if (count($parts) !== 3 || !str_starts_with($parts[2], self::PREFIX)) {
                continue;
            }

            $found[$parts[0]][(int) $parts[1]] = $parts[2];
        }

        return $found;
    }

    /**
     * Fixture-labelled token rows still in the table.
     *
     * @return array<int, string> row id => label
     */
    public static function leftoverTokenLabels(): array
    {
        $raw = WpCli::evaluate(sprintf(
            'global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('
            . '"SELECT id, label FROM " . wpmcp_table() . " WHERE label LIKE %%s", %s));'
            . ' foreach ((array) $rows as $r) { echo (int) $r->id, "\t", $r->label, "\n"; }',
            self::phpString(self::PREFIX . '%')
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", trim($line, "\r\n"));

            if (count($parts) !== 2 || !str_starts_with($parts[1], self::PREFIX)) {
                continue;
            }

            $found[(int) $parts[0]] = $parts[1];
        }

        return $found;
    }

    /**
     * Run a two-column `--format=csv` listing and keep the rows whose second column
     * starts with the prefix. str_getcsv, not explode: a post title may contain a
     * comma, and a debris check that silently mis-parses is worse than none.
     *
     * @param list<string> $args
     * @return array<int, string>
     */
    private static function matchingRows(array $args): array
    {
        $csv  = WpCli::run($args);
        $kept = [];

        foreach (explode("\n", $csv) as $index => $line) {
            $line = trim($line, "\r\n ");

            if ($index === 0 || $line === '') {
                continue; // header, or a blank trailing line
            }

            // All four arguments given: PHP 8.4 deprecates relying on the default
            // $escape, and this suite runs with failOnDeprecation.
            $row = str_getcsv($line, ',', '"', '\\');

            if (count($row) < 2) {
                continue;
            }

            if (str_starts_with((string) $row[1], self::PREFIX)) {
                $kept[(int) $row[0]] = (string) $row[1];
            }
        }

        return $kept;
    }

    /**
     * A fixture name that does not carry the prefix is a bug in the test, not in the
     * plugin: teardown would not find it and purge() would not clean it. Refuse at
     * the point of creation rather than leaving debris on somebody's site.
     */
    private static function assertPrefixed(string $name): void
    {
        if (!str_contains($name, self::runPrefix())) {
            throw new RuntimeException(
                "Fixture name '{$name}' does not contain '" . self::runPrefix()
                . "'. Every fixture must carry THIS RUN's prefix - build it with"
                . ' Fixtures::name() - or teardown cannot find it and a concurrent'
                . " run's purge would be entitled to delete it."
            );
        }
    }

    /** $value as a single-quoted PHP literal, for embedding in `wp eval` source. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
