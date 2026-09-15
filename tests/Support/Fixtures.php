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
     * A post with fields createPost() has no parameter for - an excerpt, a chosen
     * post_date, a comment status - in one `wp post create`.
     *
     * $fields are wp-cli's own `post create` flags without the leading dashes, so the
     * set is whatever wp_insert_post accepts and there is no allow-list to keep in step
     * with the tests. `post_title` is required and goes through assertPrefixed() like
     * every other fixture name, so a post created this way is still findable by
     * teardown and by the debris check.
     *
     * @param array<string, string|int> $fields
     * @return int the new post's ID
     */
    public static function createPostWith(array $fields): int
    {
        $title = (string) ($fields['post_title'] ?? '');
        self::assertPrefixed($title);

        $args = ['post', 'create'];

        foreach ($fields as $key => $value) {
            $args[] = '--' . $key . '=' . $value;
        }

        $args[] = '--porcelain';

        $id = self::porcelainId(WpCli::run($args));

        if ($id <= 0) {
            throw new RuntimeException("Could not create the fixture post {$title}.");
        }

        return $id;
    }

    /**
     * The id out of a `--porcelain` run, which is NOT always the whole of stdout.
     *
     * MEASURED ON THE STRESS SITE: a caching plugin there hooks term creation and
     * answers with `Success: Purged all caches successfully.` on the line BEFORE the
     * porcelain id, so `(int) $out` is 0 and the fixture build dies claiming the term
     * could not be created. Any plugin on anybody's site may do the same to any command,
     * so the id is taken as the last all-digit line rather than as the first thing that
     * happens to be there.
     */
    private static function porcelainId(string $out): int
    {
        foreach (array_reverse(preg_split('/\r\n|\r|\n/', trim($out))) as $line) {
            $line = trim((string) $line);

            if ($line !== '' && ctype_digit($line)) { return (int) $line; }
        }

        return 0;
    }

    /**
     * A term in any taxonomy. The NAME carries the run prefix, which is what
     * leftoverTerms() and purge() match on; the slug is derived from it by WordPress
     * unless one is given, and a given slug must carry the prefix too so that a
     * slug-addressed fixture cannot collide with a real term on the stress site.
     *
     * @return int the new term's ID
     */
    public static function createTerm(string $taxonomy, string $name, string $slug = ''): int
    {
        self::assertPrefixed($name);

        $args = ['term', 'create', $taxonomy, $name, '--porcelain'];

        if ($slug !== '') {
            self::assertPrefixed($slug);
            $args[] = '--slug=' . $slug;
        }

        $id = self::porcelainId(WpCli::run($args));

        if ($id <= 0) {
            throw new RuntimeException("Could not create the fixture term {$name} in {$taxonomy}.");
        }

        return $id;
    }

    /**
     * Put a post in terms, addressed by TERM ID.
     *
     * By id and not by slug on purpose: `wp post term set` resolves a name or slug by
     * searching, and on the stress site - a real client's database - a search can find
     * somebody else's term. An id cannot be ambiguous.
     *
     * `set`, not `add`, so the fixture's terms are exactly what the test asked for even
     * when WordPress has already assigned a default category.
     *
     * @param list<int> $termIds
     */
    public static function setPostTerms(int $postId, string $taxonomy, array $termIds): void
    {
        $args = ['post', 'term', 'set', (string) $postId, $taxonomy];

        foreach ($termIds as $id) { $args[] = (string) $id; }

        $args[] = '--by=id';

        WpCli::run($args);
    }

    /** One post meta value. Used for _thumbnail_id and _wp_attached_file. */
    public static function setPostMeta(int $postId, string $key, string $value): void
    {
        WpCli::run(['post', 'meta', 'set', (string) $postId, $key, $value]);
    }

    /** Remove every row under one meta key. Tolerant: there may be none. */
    public static function deletePostMeta(int $postId, string $key): void
    {
        WpCli::tryEvaluate(sprintf(
            'echo (int) delete_post_meta(%d, %s);',
            $postId,
            self::phpString($key)
        ));
    }

    /**
     * A display name that is NOT the login.
     *
     * `wp user create` leaves display_name equal to the login, so a test asserting that
     * get-post returns a display name and not a login would pass on two identical
     * strings and prove nothing at all.
     */
    public static function setDisplayName(int $userId, string $displayName): void
    {
        self::assertPrefixed($displayName);

        WpCli::run(['user', 'update', (string) $userId, '--display_name=' . $displayName]);
    }

    /**
     * Rewrite a post's content, which is how a REVISION is made: wp_update_post stores
     * the pre-update state as a revision whenever the content actually changes.
     */
    public static function updatePostContent(int $postId, string $content): void
    {
        self::assertPrefixed($content);

        WpCli::run(['post', 'update', (string) $postId, '--post_content=' . $content]);
    }

    /**
     * How many revisions the site holds for a post, counted through wp-cli.
     *
     * The tool under test counts them through wp_get_post_revisions() inside a request;
     * this counts them from outside, so an assertion comparing the two is a comparison
     * of two independent paths rather than a restatement of one.
     */
    public static function revisionCount(int $postId): int
    {
        return (int) WpCli::evaluate(sprintf(
            'echo count(wp_get_post_revisions(%d, array("fields" => "ids")));',
            $postId
        ));
    }

    /**
     * An attachment row whose file never lands on anybody's disk.
     *
     * `_wp_attached_file` is all wp_get_attachment_url() and get_attached_file() read, and
     * wp_attachment_is_image() decides from the mime type OR that file's extension - so an
     * `.png` name makes a real image as far as every check the plugin makes is concerned,
     * and a `.pdf` name makes a real non-image. Nothing is written to uploads, which is
     * what makes this safe to run against a site that belongs to somebody.
     *
     * The FILE NAME carries the run prefix like every other fixture: a URL assertion that
     * matched a bare 'featured.png' would match whatever that site already has.
     *
     * @param int $parent post_parent. An attachment's edit_post resolves through its own
     *                    author AND its parent, so a test about that has to choose.
     */
    public static function createAttachment(
        string $title,
        int $author,
        string $fileName,
        string $mimeType = 'image/png',
        int $parent = 0
    ): int {
        self::assertPrefixed($title);
        self::assertPrefixed($fileName);

        $id = self::createPostWith([
            'post_title'      => $title,
            'post_type'       => 'attachment',
            'post_status'     => 'inherit',
            'post_author'     => $author,
            'post_parent'     => $parent,
            'post_mime_type'  => $mimeType,
            'post_content'    => self::name('attachment-body'),
        ]);

        self::setPostMeta($id, '_wp_attached_file', $fileName);

        return $id;
    }

    /**
     * One column of the posts table, read from OUTSIDE the request under test.
     *
     * The tool writes through wp_insert_post/wp_update_post inside an HTTP request; this
     * reads the stored row in a separate process. An assertion comparing the two is a
     * comparison of two paths rather than a restatement of one.
     */
    public static function postField(int $postId, string $field): string
    {
        return trim(WpCli::evaluate(sprintf(
            '$p = get_post(%d); echo $p ? (string) $p->%s : "(no such post)";',
            $postId,
            preg_replace('/[^a-z_]/', '', $field)
        )));
    }

    /** The post's featured image id, or 0. Read from the site, not from the tool. */
    public static function thumbnailId(int $postId): int
    {
        return (int) trim(WpCli::evaluate(sprintf(
            'echo (int) get_post_thumbnail_id(%d);',
            $postId
        )));
    }

    /**
     * Every row stored under one meta key, in order.
     *
     * @return list<string>
     */
    public static function postMetaRows(int $postId, string $key): array
    {
        $raw = WpCli::evaluate(sprintf(
            'echo wp_json_encode(array_map("strval", (array) get_post_meta(%d, %s, false)));',
            $postId,
            self::phpString($key)
        ));

        $rows = json_decode(trim($raw), true);

        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    /**
     * WordPress's OWN local-to-UTC conversion for this site, as get_gmt_from_date() does it.
     *
     * The expectation for post_date_gmt is computed HERE, from the site's configured
     * timezone, and never written into a test as a constant: both Local sites this suite
     * runs against happen to sit at UTC, so a hard-coded pair would assert nothing and
     * would then be wrong on the first site that does not.
     *
     * It is also an independent path from the code under test: wpmcp_parse_post_date()
     * converts with DateTimeImmutable and wp_timezone() and never calls this function.
     */
    public static function gmtFromDate(string $local): string
    {
        return trim(WpCli::evaluate(sprintf(
            'echo get_gmt_from_date(%s);',
            self::phpString($local)
        )));
    }

    /**
     * One field of one term, read from OUTSIDE the request under test.
     *
     * `list-terms` reports id, name, slug, taxonomy, count and parent - so a term's
     * `description` has no read tool at all, and the only honest way to assert what was
     * stored in it is to read the row in another process.
     */
    public static function termField(int $termId, string $taxonomy, string $field): string
    {
        return trim(WpCli::evaluate(sprintf(
            '$t = get_term(%d, %s);'
            . ' echo (!$t || is_wp_error($t)) ? "(no such term)" : (string) $t->%s;',
            $termId,
            self::phpString($taxonomy),
            preg_replace('/[^a-z_]/', '', $field)
        )));
    }

    /** The reverse, as get_date_from_gmt() does it: UTC to this site's wall clock. */
    public static function dateFromGmt(string $gmt): string
    {
        return trim(WpCli::evaluate(sprintf(
            'echo get_date_from_gmt(%s);',
            self::phpString($gmt)
        )));
    }

    /** What this site's timezone is called, for a failure message that can be acted on. */
    public static function siteTimezone(): string
    {
        return trim(WpCli::evaluate('echo wp_timezone()->getName();'));
    }

    /**
     * The taxonomies get-post is allowed to report for a post type: attached to it AND
     * viewable.
     *
     * Read from the site rather than written into the test, so the expectation is
     * whatever THIS site registers - the stress site carries custom taxonomies the bare
     * one does not, and a hard-coded list would either fail there or assert nothing.
     *
     * @return list<string>
     */
    public static function viewableTaxonomies(string $postType): array
    {
        $raw = WpCli::evaluate(sprintf(
            'foreach (get_object_taxonomies(%s) as $t) {'
            . ' if (is_taxonomy_viewable($t)) { echo $t, "\n"; } }',
            self::phpString($postType)
        ));

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw))));
    }

    /**
     * The post ids currently in one term, read straight from the site.
     *
     * A control for the taxonomy tests: "list-posts returned nothing" only means the
     * allow-list refused the taxonomy if the term really does hold a post the caller can
     * otherwise see. Read through get_objects_in_term(), which no allow-list of ours is
     * anywhere near.
     *
     * @return list<int>
     */
    public static function postIdsInTerm(string $taxonomy, int $termId): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$ids = get_objects_in_term(%d, %s);'
            . ' if (is_wp_error($ids)) { echo "ERROR:" . $ids->get_error_message(); return; }'
            . ' echo implode(",", array_map("intval", $ids));',
            $termId,
            self::phpString($taxonomy)
        ));

        return array_values(array_filter(array_map('intval', explode(',', trim($raw)))));
    }

    /**
     * Set a post's `post_modified` to an exact value, and ONLY that column.
     *
     * WHY A FIXTURE HAS TO STATE THIS RATHER THAN LET IT HAPPEN. `post_modified` is written
     * by wp_update_post() as `current_time('mysql')` - to the SECOND. A fixture that makes
     * one post "more recently modified" by touching it after another is therefore relying on
     * two wp-cli invocations landing in different seconds, which is a property of how fast
     * the machine is. On the plugin-heavy stress site each call takes long enough that they
     * do; on a bare site they do not, the two posts TIE, and `orderby: "modified"` is then
     * decided by the ID tie-break - correctly, deterministically, and not in the order the
     * test was written for. Stating the value removes the clock from the test.
     *
     * post_modified_gmt IS DELIBERATELY NOT TOUCHED. A date-floating post carries
     * '0000-00-00 00:00:00' there and get-post maps that to null; writing a real value would
     * quietly destroy the case that asserts it.
     *
     * Direct SQL, then clean_post_cache(): wp_update_post() would overwrite post_modified
     * with the current time, which is the thing being avoided.
     */
    public static function setPostModified(int $postId, string $datetime): void
    {
        WpCli::run([
            'eval',
            sprintf(
                'global $wpdb; $wpdb->update($wpdb->posts, array("post_modified" => %s),'
                . ' array("ID" => %d)); clean_post_cache(%d); echo "ok";',
                self::phpString($datetime),
                $postId,
                $postId
            ),
        ]);
    }

    /**
     * Make a post sticky, and report it back.
     *
     * A STICKY POST IS NOT A DECORATION. WP_Query splices every sticky into the front of any
     * query it considers a HOME query - which is any listing whose only filters are among
     * after/before, status, orderby and the row count, because none of those set a query
     * flag - fetched by post__in with post_status => 'publish' and none of the original
     * query's conditions. A suite whose fixtures are never sticky is blind to all of it.
     *
     * `sticky_posts` IS A SHARED OPTION WITH NO RUN PREFIX, like `wpmcp_db_ver`: purge()
     * matches names, and an option holding ids has none. Two concurrent runs are still safe,
     * because stick_post() appends exactly one id and unstick_post() removes exactly that
     * one, and the two runs stick different posts. The caller must unstick in teardown.
     *
     * @return list<int> the sticky ids AFTER the change, read back from the site
     */
    public static function stickPost(int $postId): array
    {
        return self::stickyIds(sprintf('stick_post(%d);', $postId));
    }

    /** Tolerant: the post may already have been unstuck, or deleted. */
    public static function unstickPost(int $postId): array
    {
        return self::stickyIds(sprintf('unstick_post(%d);', $postId));
    }

    /**
     * The site's sticky post ids, optionally after running one statement first.
     *
     * Read back rather than assumed, so a test can assert that its fixture really is sticky
     * before it asserts that the tool ignores stickiness - otherwise "the sticky post was
     * not injected" passes on a site where nothing was ever stuck.
     *
     * @return list<int>
     */
    public static function stickyIds(string $before = ''): array
    {
        $raw = WpCli::run([
            'eval',
            $before . ' echo implode(",", array_map("intval", (array) get_option("sticky_posts", array())));',
        ]);

        return array_values(array_filter(array_map('intval', explode(',', trim($raw)))));
    }

    /** Tolerant: the term may already be gone. Deletes by id, never by name. */
    public static function deleteTerm(string $taxonomy, int $termId): void
    {
        if ($termId > 0) {
            WpCli::tryRun(['term', 'delete', $taxonomy, (string) $termId]);
        }
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
     * A ONE-HOUR ACTIVE WINDOW INSIDE A THIRTY-DAY LIFETIME. Both are spelled out
     * rather than left to the admin form's defaults, so a change to either default
     * cannot silently change what every integration fixture mints. The tests that care
     * about a closed window or a finished lifetime move the row afterwards - see
     * makeTokensDormantLabelled() and makeTokensDeadLabelled().
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
                '$r = wpmcp_mint(%s, %s, 3600, 30 * DAY_IN_SECONDS, %d);'
                . ' echo is_wp_error($r) ? "MINT-ERROR: " . $r->get_error_message() : $r["raw"];',
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
     * Close the active window of every token with this label: DORMANT, with its hard
     * lifetime still ahead of it.
     *
     * wpmcp_mint() clamps the window to at least 60 seconds, so a token that is dormant
     * on arrival cannot be minted - and waiting a minute in a test is not an option. The
     * row is written by the real mint and only active_until is moved, so the request
     * under test takes the genuine dormant branch and the row stays renewable.
     */
    public static function makeTokensDormantLabelled(string $label): void
    {
        self::assertPrefixed($label);

        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"UPDATE " . wpmcp_table() . " SET active_until = %%s WHERE label = %%s",'
            . ' gmdate("Y-m-d H:i:s", time() - 3600), %s));',
            self::phpString($label)
        ));
    }

    /**
     * Move every token with this label past its hard lifetime: DEAD. Both timers are
     * moved, because a row whose lifetime has passed and whose window has not is a shape
     * the plugin never writes, and a fixture should not invent one.
     *
     * @param int $secondsAgo how long ago the lifetime ended. The cron's own tests pass
     *                        different values on either side of nothing in particular -
     *                        dead is dead - but a test that wants a row clearly in the
     *                        past rather than at this exact second can say so.
     */
    public static function makeTokensDeadLabelled(string $label, int $secondsAgo = 3600): void
    {
        self::assertPrefixed($label);

        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"UPDATE " . wpmcp_table()'
            . ' . " SET active_until = %%s, expires_at = %%s WHERE label = %%s",'
            . ' gmdate("Y-m-d H:i:s", time() - %d), gmdate("Y-m-d H:i:s", time() - %d), %s));',
            $secondsAgo,
            $secondsAgo,
            self::phpString($label)
        ));
    }

    /**
     * How many rows carry this label. The renew and purge tests assert on row SURVIVAL,
     * which is the property that separates dormant from dead.
     */
    public static function countTokensLabelled(string $label): int
    {
        self::assertPrefixed($label);

        return (int) WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('
            . '"SELECT COUNT(*) FROM " . wpmcp_table() . " WHERE label = %%s", %s));',
            self::phpString($label)
        ));
    }

    /** The id of the (single) token carrying this label, or 0. */
    public static function tokenIdLabelled(string $label): int
    {
        self::assertPrefixed($label);

        return (int) WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('
            . '"SELECT id FROM " . wpmcp_table() . " WHERE label = %%s ORDER BY id DESC LIMIT 1", %s));',
            self::phpString($label)
        ));
    }

    /**
     * Write a file into the ACTIVE THEME of the site under test and return its relative
     * path. The code tools' jail is the active theme, so a test of them has nowhere else
     * to stand.
     *
     * THE ACTIVE THEME ON THE STRESS SITE IS A REAL CLIENT THEME. Nothing here reads,
     * moves or overwrites a file that was already there: the name must carry this run's
     * prefix (assertPrefixed), so it cannot collide with a theme file, and
     * deleteThemeFiles() takes back only prefixed names.
     *
     * Through `wp eval` rather than file_put_contents for the reason MuPlugin gives: in
     * CI the site's filesystem is inside a container the host cannot reach.
     */
    public static function writeThemeFile(string $relative, string $content): string
    {
        self::assertPrefixed($relative);

        $written = WpCli::evaluate(sprintf(
            '$p = get_stylesheet_directory() . "/" . %s;'
            . ' if (!wp_mkdir_p(dirname($p))) { echo "NO-DIR"; return; }'
            . ' echo file_put_contents($p, base64_decode(%s)) === false ? "FAIL" : "OK";',
            self::phpString($relative),
            self::phpString(base64_encode($content))
        ));

        if ($written !== 'OK') {
            throw new RuntimeException(
                "Could not write the theme fixture {$relative}: {$written}"
            );
        }

        return $relative;
    }

    /**
     * The bytes of a file in the active theme, base64 round-tripped so CRLF, a BOM and
     * anything else survive the trip through wp-cli's stdout. '' when it is not there;
     * use themeFileExists() to tell an empty file from a missing one.
     */
    public static function readThemeFile(string $relative): string
    {
        $raw = WpCli::evaluate(sprintf(
            '$p = get_stylesheet_directory() . "/" . %s;'
            . ' echo is_file($p) ? base64_encode((string) file_get_contents($p)) : "";',
            self::phpString($relative)
        ));

        return $raw === '' ? '' : (string) base64_decode($raw, true);
    }

    public static function themeFileExists(string $relative): bool
    {
        return WpCli::evaluate(sprintf(
            'echo (int) is_file(get_stylesheet_directory() . "/" . %s);',
            self::phpString($relative)
        )) === '1';
    }

    /**
     * Every entry in one directory of the active theme, as names. The `.bak` assertions
     * need a LISTING and not an is_file() on a name they guessed: "the revert left no
     * backup" is a claim about what is in the directory, and a test that only asks after
     * the one name it expects cannot see a backup written under another.
     *
     * @return list<string>
     */
    public static function themeDirListing(string $relative = ''): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$d = rtrim(get_stylesheet_directory() . "/" . %s, "/");'
            . ' if (!is_dir($d)) { return; }'
            . ' foreach (scandir($d) as $n) {'
            . '  if ($n !== "." && $n !== "..") { echo $n, "\n"; }'
            . ' }',
            self::phpString($relative)
        ));

        $names = array();

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line, "\r\n ");

            if ($line !== '') { $names[] = $line; }
        }

        return $names;
    }

    /**
     * Fixture-named files anywhere in the active theme, of any run.
     *
     * @return array<string, string> relative path => relative path
     */
    public static function leftoverThemeFiles(): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$root = realpath(get_stylesheet_directory());'
            . ' if (!$root) { return; }'
            . ' $it = new RecursiveIteratorIterator('
            . '  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));'
            . ' foreach ($it as $f) {'
            . '  $n = $f->getFilename();'
            . '  if (strpos($n, %s) === 0) {'
            . '   echo ltrim(str_replace("\\\\", "/", substr($f->getPathname(), strlen($root))), "/"), "\n";'
            . '  }'
            . ' }',
            self::phpString(self::PREFIX)
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line, "\r\n ");

            if ($line !== '' && str_contains($line, self::PREFIX)) {
                $found[$line] = basename($line);
            }
        }

        return $found;
    }

    /**
     * The name the code tools used to give a file's previous contents, up to and
     * including 1.1.0: the file's own name with a backup extension appended, written
     * beside it inside the active theme.
     *
     * SPELLED HERE AND NOWHERE ELSE IN THE SUITE, which is what lets
     * tests/unit/SurfaceSweepTest.php grep the whole repository for it and exempt one
     * support file rather than every test that has to name the thing it is proving gone.
     */
    public static function siblingBackupName(string $original): string
    {
        return $original . '.bak';
    }

    /**
     * The names in one directory of the active theme that belong to THIS RUN and carry
     * the old backup extension. Empty is the only correct answer from 1.1.0 on.
     *
     * A LISTING AND NOT AN is_file() ON AN EXPECTED NAME: "no backup was left" is a claim
     * about the directory, and a check that looks only where it already believes cannot
     * see a backup written under another name. Filtered to this run's prefix because the
     * stress site's active theme is a real client's and may contain anything.
     *
     * @return list<string>
     */
    public static function ourSiblingBackupsInTheTheme(string $relativeDir = ''): array
    {
        $suffix = strtolower(self::siblingBackupName(''));

        return array_values(array_filter(
            self::themeDirListing($relativeDir),
            static fn (string $name): bool =>
                str_starts_with($name, self::runPrefix())
                && str_ends_with(strtolower($name), $suffix)
        ));
    }

    /**
     * Fixture-named DIRECTORIES in the active theme, of any run.
     *
     * Separate from leftoverThemeFiles() because an EMPTY one is invisible to a file
     * listing, and an empty prefixed directory is exactly what a purge leaves behind
     * after it has removed the prefixed files inside.
     *
     * @return array<string, string> relative path => basename
     */
    public static function leftoverThemeDirs(): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$root = realpath(get_stylesheet_directory());'
            . ' if (!$root) { return; }'
            . ' foreach ((array) glob($root . "/" . %s . "*", GLOB_ONLYDIR) as $d) {'
            . '  echo basename($d), "\n";'
            . ' }',
            self::phpString(self::PREFIX)
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line, "\r\n ");

            if ($line !== '' && str_starts_with($line, self::PREFIX)) {
                $found[$line] = $line;
            }
        }

        return $found;
    }

    /** Create a directory inside the active theme. Prefixed names only. */
    public static function makeThemeDir(string $relative): string
    {
        self::assertPrefixed($relative);

        $made = WpCli::evaluate(sprintf(
            'echo (int) wp_mkdir_p(get_stylesheet_directory() . "/" . %s);',
            self::phpString($relative)
        ));

        if ($made !== '1') {
            throw new RuntimeException("Could not create the theme fixture directory {$relative}: {$made}");
        }

        return $relative;
    }

    /** Remove a directory from the active theme. Tolerant, and only if it is empty. */
    public static function deleteThemeDir(string $relative): void
    {
        self::assertPrefixed(basename($relative));

        WpCli::tryEvaluate(sprintf(
            '$d = get_stylesheet_directory() . "/" . %s;'
            . ' echo is_dir($d) ? (int) @rmdir($d) : 1;',
            self::phpString($relative)
        ));
    }

    /** Remove one file from the active theme. Tolerant: it may already be gone. */
    /**
     * Fixture-named files anywhere under the uploads directory, of any run.
     *
     * WHY THE HARNESS OWNS THESE BY NAME AND NOT THROUGH THE ATTACHMENT. Measured on the
     * stress site: an image-conversion plugin hooks `wp_handle_upload`, writes a `.webp`
     * beside the uploaded `.png`, and calls update_attached_file() so the attachment
     * points at the `.webp`. wp_delete_attachment($id, true) then deletes the row and the
     * `.webp` and never learns the `.png` existed - one orphan per test run, eight of
     * them before anybody looked, on a real client's site. The bare site has no such
     * plugin and leaks nothing, which is why the debris check had never seen it. So files
     * are found and removed by the run prefix, like theme files, whatever any plugin did
     * to the attachment in between.
     *
     * @return array<string, string> path relative to the uploads basedir => file name
     */
    public static function leftoverUploadFiles(): array
    {
        $raw = WpCli::evaluate(sprintf(
            '$u = wp_upload_dir(null, false); $root = realpath($u["basedir"]);'
            . ' if (!$root) { return; }'
            . ' $it = new RecursiveIteratorIterator('
            . '  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));'
            . ' foreach ($it as $f) {'
            . '  if ($f->isFile() && strpos($f->getFilename(), %s) === 0) {'
            . '   echo ltrim(str_replace("\\\\", "/", substr($f->getPathname(), strlen($root))), "/"), "\n";'
            . '  }'
            . ' }',
            self::phpString(self::PREFIX)
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line, "\r\n ");

            if ($line !== '' && str_starts_with(basename($line), self::PREFIX)) {
                $found[$line] = basename($line);
            }
        }

        return $found;
    }

    /** Delete one fixture-named file under uploads. Refuses a name without the prefix. */
    public static function deleteUploadFile(string $relative): void
    {
        self::assertPrefixed(basename($relative));

        WpCli::tryEvaluate(sprintf(
            '$u = wp_upload_dir(null, false); $p = $u["basedir"] . "/" . %s;'
            . ' echo is_file($p) ? (int) unlink($p) : 1;',
            self::phpString($relative)
        ));
    }

    public static function deleteThemeFile(string $relative): void
    {
        self::assertPrefixed(basename($relative));

        WpCli::tryEvaluate(sprintf(
            '$p = get_stylesheet_directory() . "/" . %s;'
            . ' echo is_file($p) ? (int) unlink($p) : 1;',
            self::phpString($relative)
        ));
    }

    /**
     * Fixture-named rows in the file-versions table, of any run.
     *
     * @return array<int, string> row id => path
     */
    public static function leftoverFileVersions(): array
    {
        $raw = WpCli::tryEvaluate(sprintf(
            'if (!function_exists("wpmcp_versions_table")) { return; }'
            . ' global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('
            . '"SELECT id, path FROM " . wpmcp_versions_table() . " WHERE path LIKE %%s", %s));'
            . ' foreach ((array) $rows as $r) { echo (int) $r->id, "\t", $r->path, "\n"; }',
            self::phpString('%' . self::PREFIX . '%')
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", trim($line, "\r\n"));

            if (count($parts) !== 2 || !str_contains($parts[1], self::PREFIX)) {
                continue;
            }

            $found[(int) $parts[0]] = $parts[1];
        }

        return $found;
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

        // THE ACTIVE THEME AND THE VERSIONS TABLE, which sprint 8's code tools write to.
        // A red run of a code-tool test leaves a fixture file inside somebody's theme -
        // on the stress site, a real client's theme - and a row holding its bytes. Both
        // are this run's alone, matched on the run prefix like everything else.
        foreach (self::ours(self::leftoverThemeFiles()) as $relative => $name) {
            self::deleteThemeFile((string) $relative);
        }

        // UPLOADS, by name - see leftoverUploadFiles() for why the attachment cannot be
        // trusted to take its own file with it on a site that converts images.
        foreach (self::ours(self::leftoverUploadFiles()) as $relative => $name) {
            self::deleteUploadFile((string) $relative);
        }

        // Directories AFTER the files, because rmdir only takes an empty one.
        foreach (self::ours(self::leftoverThemeDirs()) as $relative => $name) {
            self::deleteThemeDir((string) $relative);
        }

        WpCli::tryEvaluate(sprintf(
            'if (!function_exists("wpmcp_versions_table")) { return; }'
            . ' global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"DELETE FROM " . wpmcp_versions_table() . " WHERE path LIKE %%s", %s));',
            self::phpString('%' . self::runPrefix() . '%')
        ));

        // Disarms this run's mu-plugins as well as deleting them.
        MuPlugin::removeOurs();

        foreach (self::ours(self::leftoverTransients()) as $name) {
            WpCli::tryEvaluate(sprintf('echo (int) delete_transient(%s);', self::phpString($name)));
        }

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

        foreach (self::foreign(self::leftoverTransients()) as $name) {
            $lines[] = '  transient         ' . $name;
        }

        // The relative path, not the basename the matching is done on: "which file, in
        // whose theme" is the whole of what a human needs to go and look.
        foreach (self::foreign(self::leftoverThemeFiles()) as $relative => $name) {
            $lines[] = '  theme file        ' . $relative;
        }

        foreach (self::foreign(self::leftoverThemeDirs()) as $relative => $name) {
            $lines[] = '  theme dir         ' . $relative;
        }

        foreach (self::foreign(self::leftoverUploadFiles()) as $relative => $name) {
            $lines[] = '  upload file       ' . $relative;
        }

        foreach (self::foreign(self::leftoverFileVersions()) as $id => $path) {
            $lines[] = sprintf('  version %-5s     %s', (string) $id, $path);
        }

        if ($lines === []) {
            return '';
        }

        $suffixes = [];

        foreach (array_merge(
            array_values(self::foreign(self::leftoverUsers())),
            array_values(self::foreign(self::leftoverPosts())),
            array_values(self::foreign(self::leftoverTokenLabels())),
            array_values(self::foreign(MuPlugin::leftovers())),
            array_values(self::foreign(self::leftoverTransients())),
            array_values(self::foreign(self::leftoverThemeFiles())),
            array_values(self::foreign(self::leftoverUploadFiles())),
            array_values(self::foreign(self::leftoverFileVersions()))
        ) as $name) {
            $suffixes[self::runIdIn($name) ?: '(no run id)'] = true;
        }

        return "FOREIGN FIXTURE DEBRIS on the site under test.\n"
            . 'This run is ' . self::runId() . '; the following carry other run ids ('
            . implode(', ', array_keys($suffixes)) . ").\n"
            . "NOT deleted: another run may be live and still using them.\n"
            . implode("\n", $lines) . "\n";
    }

    /**
     * The one piece of debris that is not a NAME: an opt-in switch left on.
     *
     * '' when the sql-select switch is off, a report when it is on.
     *
     * WHY THIS ONE IS WORTH A CHECK. Every other fixture this suite makes carries the run
     * prefix, so it is findable and attributable. An option is a single shared value with
     * no room for a prefix: turn `wpmcp_sql_enabled` on and you have turned it on for the
     * site, for good, for every admin-scope token - and sql-select reads every table the
     * WordPress database user can read, wp_users among them. A test run that left that
     * behind would be the single most expensive thing this suite could do to a site.
     *
     * SO NO TEST WRITES IT. SqlSelectTest arms the switch with a `pre_option_` filter in a
     * mu-plugin, gated on a per-request header, and asserts the stored option is the same
     * value afterwards as before. This check is the backstop for the day somebody reaches
     * for update_option() instead, and for a run that was killed mid-test.
     *
     * An operator who turned the switch on deliberately on their own site will see this
     * line too. That is the right trade: a false alarm costs one sentence, and the failure
     * it guards against is silent.
     */
    public static function switchesLeftOn(): string
    {
        $report = '';

        $value = trim(WpCli::evaluate(
            'echo get_option("wpmcp_sql_enabled") ? "ON" : "OFF";'
        ));

        if ($value === 'ON') {
            $report .= "OPT-IN SWITCH LEFT ON.\n"
                . "  option wpmcp_sql_enabled is ON - sql-select is exposed to every"
                . " admin-scope token on this site.\n"
                . "  No test in this suite writes that option (they filter it per request), so"
                . " a suite run that turned it\n"
                . "  on is a bug. Turn it off in Settings > WP MCP, or with"
                . " `wp option update wpmcp_sql_enabled 0`.\n";
        }

        // THE OTHER SHARED VALUE, sprint 11: the meta allow-list. It has the same shape of
        // problem - one option, no room for a run prefix - with one difference that makes
        // it checkable: the KEYS inside it are fixture names, so a leftover is findable by
        // the shared prefix even though the option itself is not.
        //
        // Every meta test arms the list through a `pre_option_` filter and writes nothing,
        // exactly as SqlSelectTest does. The one test that DOES write the option is the
        // settings round trip, which restores the operator's value in the same process.
        // This is the backstop for the run that was killed in between.
        $leftover = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', WpCli::evaluate(
                '$keys = get_option("wpmcp_meta_keys", array());'
                . ' foreach ((array) $keys as $k) { echo (string) $k, "\n"; }'
            ))
        )));

        $ours = array_values(array_filter(
            $leftover,
            static fn (string $k) => str_starts_with($k, self::PREFIX)
        ));

        if ($ours !== []) {
            if ($report !== '') { $report .= "\n"; }

            $report .= "FIXTURE META KEYS LEFT IN THE ALLOW-LIST.\n"
                . "  option wpmcp_meta_keys still names " . count($ours) . " test key(s), so"
                . " get-post-meta and set-post-meta are\n"
                . "  exposed on this site and can reach them:\n"
                . '    ' . implode("\n    ", $ours) . "\n"
                . "  Remove them in Settings > WP MCP, or with"
                . " `wp option update wpmcp_meta_keys --format=json '[]'`.\n";
        }

        return $report;
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
     * Fixture-named transients still in the options table.
     *
     * The harness writes two - the mu-plugin arming flag and the recorder's log - and
     * both expire on their own, but a debris check that reports "clean" while two
     * `wpmcp-test-*` rows sit in wp_options is making a claim it has not checked.
     *
     * @return array<string, string> name => name (the name IS the identity here)
     */
    public static function leftoverTransients(): array
    {
        $raw = WpCli::evaluate(sprintf(
            'global $wpdb; $rows = $wpdb->get_col($wpdb->prepare('
            . '"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %%s", %s));'
            . ' foreach ((array) $rows as $n) { echo $n, "\n"; }',
            self::phpString('_transient_' . self::PREFIX . '%')
        ));

        $found = array();

        foreach (explode("\n", $raw) as $line) {
            $name = substr(trim($line, "\r\n "), strlen('_transient_'));

            if (str_starts_with((string) $name, self::PREFIX)) {
                $found[$name] = $name;
            }
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
