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
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class Fixtures
{
    /** Every fixture name starts with this. Teardown and the debris check key off it. */
    public const PREFIX = 'wpmcp-test-';

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
     * @return int the new post's ID
     */
    public static function createPost(
        string $title,
        string $status,
        int $author,
        string $content,
        string $type = 'post',
        int $parent = 0
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
     * Delete every fixture-named user, post and token. Safe to call at any time: it
     * only ever matches the PREFIX.
     */
    public static function purge(): void
    {
        foreach (self::leftoverPosts() as $id => $title) {
            self::deletePost($id);
        }

        foreach (self::leftoverUsers() as $id => $login) {
            self::deleteUser($id);
        }

        // TERMS TOO. A red run of the create-post {terms} test left
        // `wpmcp-test-term-via-create-post` on the site, because the debris check and
        // the purge only knew about posts, users and tokens - the tools that can create
        // a term were the ones that had no capability check, so nothing had ever
        // created one before. Every taxonomy, not just category.
        foreach (self::leftoverTerms() as $taxonomy => $ids) {
            foreach ($ids as $id) {
                WpCli::tryRun(['term', 'delete', $taxonomy, (string) $id]);
            }
        }

        WpCli::tryEvaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"DELETE FROM " . wpmcp_table() . " WHERE label LIKE %%s", %s));',
            self::phpString(self::PREFIX . '%')
        ));
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
     * Fixture-named posts still on the site, in any status INCLUDING trash.
     *
     * `--post_status=any` is WP_Query's `any`, which excludes trash and auto-draft, so
     * a fixture post that something trashed rather than force-deleted would be
     * invisible to the debris check. The statuses are listed explicitly instead.
     *
     * @return array<int, string> id => post_title
     */
    public static function leftoverPosts(): array
    {
        return self::matchingRows([
            'post', 'list',
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
     * @return array<string, list<int>> taxonomy => term ids
     */
    public static function leftoverTerms(): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$out = array();'
            . ' foreach (get_taxonomies(array(), "names") as $tax) {'
            . '  $terms = get_terms(array("taxonomy" => $tax, "hide_empty" => false,'
            . '   "name__like" => %s, "fields" => "ids"));'
            . '  if (!is_wp_error($terms)) {'
            . '   foreach ($terms as $id) { echo $tax, ":", (int) $id, ","; }'
            . '  }'
            . ' }',
            self::phpString(self::PREFIX)
        ));

        $found = array();

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }

            [$taxonomy, $id] = explode(':', $pair, 2);
            $found[$taxonomy][] = (int) $id;
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
        if (!str_contains($name, self::PREFIX)) {
            throw new RuntimeException(
                "Fixture name '{$name}' does not contain '" . self::PREFIX
                . "'. Every fixture must, or teardown cannot find it."
            );
        }
    }

    /** $value as a single-quoted PHP literal, for embedding in `wp eval` source. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
