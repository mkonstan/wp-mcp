<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - tool catalog.
 *
 * wpmcp_core_tools():     site-info / list-posts / get-post.
 * wpmcp_content_tools():  create-post / update-post / delete-post.
 * wpmcp_taxonomy_tools(): list-terms / create-term / delete-term.
 * wpmcp_media_tools():    list-media / get-media / upload-media / delete-media.
 * wpmcp_comment_tools():  list-comments / moderate-comment / reply-comment.
 * wpmcp_code_tools():     the six jailed code-edit tools (active theme only).
 * wpmcp_sql_tools():      sql-select, one read-only SQL statement (opt-in, off by default).
 * Each tool = array('write'=>bool, 'annotations'=>array, 'description'=>str,
 *                   'inputSchema'=>array, 'run'=>callable).
 * Merged into the registry by endpoint.php's wpmcp_tools(), which REFUSES an entry
 * missing any of those - annotations included, all four hints, each a real boolean.
 *
 * THE ANNOTATIONS ARE AUTHORED HERE, ONE ENTRY AT A TIME, and that is the point of them:
 * `readOnlyHint` is !write (one fact, one declaration), but `destructiveHint`,
 * `idempotentHint` and `openWorldHint` are judgements about what the tool does that no
 * flag already carries. See wpmcp_annotation_hints() in endpoint.php for what each one
 * means and why an unstated `destructiveHint` defaults to true. The judgements made here:
 *
 *   destructiveHint true   update-post, delete-post (force=true permanently deletes),
 *                          delete-term, delete-media, moderate-comment (spam and trash
 *                          destroy the comment's place in the thread), code-write
 *                          (overwrites a theme file), code-delete.
 *                  false   every read tool, and create-post / create-term /
 *                          upload-media / reply-comment, which only ADD: each call
 *                          brings a new post, term, attachment or comment into being
 *                          and replaces nothing that was there.
 *
 *                  THE TEST IS MCP'S OWN AND IT IS NARROW: `false` promises the update
 *                  is ADDITIVE. update-post was false, and that was wrong - it replaces
 *                  every field it touches, and its `terms` path replaces the post's
 *                  terms in that taxonomy rather than adding to them. Found by review
 *                  2026-09-12. "Only the fields the caller named" is scope, not
 *                  additivity, and a client honouring the hint would have let an agent
 *                  overwrite a published body without asking. When in doubt, true.
 *   idempotentHint  false  the four tools that CREATE a new object per call
 *                          (create-post, create-term, upload-media, reply-comment), and
 *                          code-write and code-restore, whose second call stores another
 *                          version of the file - the file ends up the same, the history
 *                          does not.
 *                   true   everything else: reading twice, deleting twice, setting the
 *                          same status twice, writing the same fields twice.
 *   openWorldHint   true   upload-media ALONE. It fetches a URL the caller supplies;
 *                          every other tool's reach ends at this site's database and
 *                          active theme.
 *
 * WHY NOT DERIVE THEM. Because `write` does not know the difference between creating a
 * post and deleting one, and that difference is exactly what a client asks the human
 * about. A derived annotation would be a restatement of the scope gate wearing the
 * clothes of a safety hint.
 *
 * EVERY WP_Error CONSTRUCTED IN THIS FILE CARRIES A `wpmcp_` CODE, and it is load-bearing.
 * endpoint.php's wpmcp_tool_error_response() uses that prefix as the allow-list that
 * decides what a client is told: a `wpmcp_` code is a sentence an author wrote for the
 * caller and reaches the wire as `isError: true` with its message, while any other code -
 * a wpdb error, wp_insert_post's, wp_handle_upload's, anything from core - is treated
 * exactly as a thrown throwable: generic -32603 plus a trace id, detail to the private log.
 * Drop the prefix from a new error here and its message stops reaching the client.
 */
if (!defined('ABSPATH')) { exit; }

/* ============================================================
 * Code-editing config + jail helpers
 * ========================================================== */
function wpmcp_code_enabled() {
    return (bool) get_option('wpmcp_code_enabled', false);
}

/**
 * May the current user touch theme files at all? Null when yes, a WP_Error when no.
 *
 * Every one of the six code tools begins with this, READS INCLUDED: code-read, code-list
 * and code-history hand back theme PHP, the shape of the theme directory, and who changed
 * which file when - which is source code, not content. wp-admin's theme editor is gated
 * on exactly this trio, and before this the tools were gated on nothing but admin scope
 * plus the wpmcp_code_enabled option - so an admin-scope token minted "Runs as:
 * some-subscriber" on a site with code editing on could read, rewrite and delete
 * files in the active theme.
 *
 * DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS are honoured the way core honours them:
 * they turn the capability off for everyone, administrators included, and an operator
 * who sets them means it. (Core does this in map_meta_cap, so current_user_can alone
 * would already answer false - the explicit check is here so the refusal says which
 * of the three it was, and so it holds if that mapping ever moves.)
 */
function wpmcp_code_forbidden() {
    $constant = wpmcp_code_constants_forbid();
    if ($constant) { return $constant; }
    if (!current_user_can('edit_themes')) {
        return wpmcp_cannot('edit theme files');
    }
    return null;
}

/**
 * The half of the code gate that does not depend on who is asking: the two constants.
 * A WP_Error naming which one it was, or null.
 *
 * SPLIT OUT SO THE LISTING AND THE RUN CANNOT DRIFT. wpmcp_tools() decides whether the
 * code tools appear in tools/call and tools/list at all, and it used to ask only
 * wpmcp_code_enabled() - so a site with the switch on and DISALLOW_FILE_EDIT true in
 * wp-config ADVERTISED all six and refused every one of them. Measured on seosemia.net,
 * a real public site: tools/list carried code-list, code-read, code-write, code-delete,
 * code-history and code-restore, and code-list answered "Theme file editing is disabled
 * on this site (DISALLOW_FILE_EDIT)."
 *
 * A tool that cannot run must not be listed. An agent reads a listing as a statement of
 * what it may do, plans on it, and spends a call per tool discovering otherwise - and an
 * operator who set DISALLOW_FILE_EDIT deliberately has just been told by their own server
 * that theme editing is on offer.
 *
 * THE CAPABILITY HALF STAYS PER-REQUEST and out of here, because it is a property of the
 * token's user rather than of the site: the registry is built once per request but the
 * answer is the same for every caller, while `edit_themes` is not. Listing on the
 * constants and refusing on the capability is the same split endpoint.php already makes
 * between the scope gate and the capability checks inside each tool.
 */
function wpmcp_code_constants_forbid() {
    if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
        return new WP_Error('wpmcp_forbidden', 'File modification is disabled on this site (DISALLOW_FILE_MODS).');
    }
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        return new WP_Error('wpmcp_forbidden', 'Theme file editing is disabled on this site (DISALLOW_FILE_EDIT).');
    }
    return null;
}

function wpmcp_code_denylist() {
    $d = get_option('wpmcp_code_denylist', null);
    if (!is_array($d)) {
        $d = array('functions.php', 'index.php', 'inc/', 'includes/', 'lib/');
    }
    return $d;
}

function wpmcp_code_root() {
    $root = realpath(get_stylesheet_directory());
    return $root ? $root : '';
}

function wpmcp_code_allowed_ext() {
    return array('php', 'css', 'js', 'html', 'json', 'txt', 'md', 'svg');
}

/** True if $abs is the root or sits inside it (both already realpath'd). */
function wpmcp_path_within($abs, $root) {
    $abs  = str_replace('\\', '/', (string) $abs);
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    return ($abs === $root) || (strpos($abs, $root . '/') === 0);
}

/**
 * Resolve a user path inside the theme jail.
 * Returns array('ok'=>true,'abs'=>..,'rel'=>..) or array('ok'=>false,'error'=>..).
 * $mustExist true = file must already exist; false = only parent must be in jail.
 *
 * `rel` IS DERIVED FROM THE RESOLVED ABSOLUTE PATH, NOT FROM WHAT THE CALLER TYPED, and
 * that is the whole of two defects at once.
 *
 * THE DENYLIST BYPASS. `wpmcp_code_denied()` matches a directory rule by prefix -
 * `inc/` blocks anything starting `inc/`. This function used to hand it the caller's own
 * spelling with nothing but a `..` and an absolute-path check applied, so `./inc/x.php`
 * did not start with `inc/` and walked straight past the rule, while `inc/x.php` and
 * `inc/./x.php` were both refused. Measured on jaygroup's live theme by the sprint-8
 * review: `code-target('inc/ajax.php')` refused, `code-target('./inc/ajax.php')` allowed.
 * The bare-filename rules (`functions.php`) were never affected, because those match on
 * basename. Every code tool goes through here, so one canonical spelling closes it for
 * all six rather than teaching the denylist about prefixes it might meet.
 *
 * THE SPELLING AS A DATABASE KEY. From sprint 8 `rel` is also the `path` column of the
 * version table and the key `code-history` and the retention cap group by. The caller's
 * raw string made `./style.css`, `style.css` and `style.//css` three histories of one
 * file, each with its own cap of twenty, and the upgrade sweep - which keys on the
 * on-disk name - a fourth. Deriving from the resolved path means one file has one
 * history however the caller spells it.
 *
 * Both `abs` branches below are already canonical: `realpath()` of the target when it
 * must exist, and `realpath()` of the parent plus the basename when it need not. So the
 * derivation is a substring, not a second parser - and there is no second set of rules
 * for a reviewer to check against the first.
 */
function wpmcp_code_resolve($path, $mustExist) {
    $root = wpmcp_code_root();
    if ($root === '') { return array('ok' => false, 'error' => 'Active theme directory not found.'); }
    $path = (string) $path;
    if ($path === '') { return array('ok' => false, 'error' => 'Path required.'); }
    if (strpos($path, "\0") !== false || strpos($path, '..') !== false) {
        return array('ok' => false, 'error' => 'Illegal path.');
    }
    if ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:#', $path)) {
        return array('ok' => false, 'error' => 'Path must be relative to the theme root.');
    }
    $rel  = ltrim(str_replace('\\', '/', $path), '/');
    $cand = $root . '/' . $rel;

    if ($mustExist) {
        $abs = realpath($cand);
        if ($abs === false) { return array('ok' => false, 'error' => 'No such file.'); }
    } else {
        $parent = realpath(dirname($cand));
        if ($parent === false) { return array('ok' => false, 'error' => 'Parent directory does not exist.'); }
        if (!wpmcp_path_within($parent, $root)) {
            return array('ok' => false, 'error' => 'Path escapes the theme directory.');
        }
        $abs = $parent . '/' . basename($cand);
        // If the target already exists, never follow a symlink out of the jail:
        // realpath the real target and re-check it sits inside the theme root.
        if (is_link($abs)) {
            return array('ok' => false, 'error' => 'Refusing to write through a symlink.');
        }
        if (file_exists($abs)) {
            $real = realpath($abs);
            if ($real === false || !wpmcp_path_within($real, $root)) {
                return array('ok' => false, 'error' => 'Path escapes the theme directory.');
            }
            $abs = $real;
        }
    }
    if (!wpmcp_path_within($abs, $root)) {
        return array('ok' => false, 'error' => 'Path escapes the theme directory.');
    }

    // THE CANONICAL SPELLING, and from here on the only one. See the docblock.
    $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');

    // Empty means the caller named the theme directory itself - `.`, `/`, `./`. There is
    // no file there to read, write, delete or version, and an empty `path` column would
    // be a version row belonging to nothing.
    if ($rel === '') {
        return array('ok' => false, 'error' => 'Path must name a file inside the theme, not the theme directory.');
    }

    return array('ok' => true, 'abs' => $abs, 'rel' => $rel);
}

/** True if a relative path is blocked by the denylist. */
function wpmcp_code_denied($rel) {
    $rel  = ltrim(str_replace('\\', '/', (string) $rel), '/');
    $base = strtolower(basename($rel));
    $low  = strtolower($rel);
    foreach (wpmcp_code_denylist() as $entry) {
        $e = strtolower(trim((string) $entry));
        if ($e === '') { continue; }
        $e = ltrim(str_replace('\\', '/', $e), '/');
        if (substr($e, -1) === '/') {                 // directory prefix
            $dir = rtrim($e, '/');
            if (strpos($low . '/', $dir . '/') === 0) { return true; }
        } elseif (strpos($e, '/') !== false) {        // explicit relative path
            if ($low === $e) { return true; }
        } else {                                       // bare filename anywhere
            if ($base === $e) { return true; }
        }
    }
    return false;
}

function wpmcp_code_ext_ok($rel) {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, wpmcp_code_allowed_ext(), true);
}

/**
 * Put the bytes that are on disk at $abs into the version store, under the tool-relative
 * path $rel, before anything changes them. Returns array('id'=>int,'content'=>string) or
 * a WP_Error the calling tool must return as-is.
 *
 * CALLED BY EVERY TOOL THAT MUTATES A FILE UNDER THE CODE ROOT, and its failure stops the
 * mutation. A write whose undo could not be stored is a write that cannot be taken back,
 * and the whole point of this sprint is that one always can be. Fail closed.
 *
 * It hands the CONTENT back as well as the row id because code-write's parse-error revert
 * needs those exact bytes a few lines later, and reading them out of the table again to
 * get what is already in a local variable would be a second thing that can fail in the
 * middle of an abort.
 *
 * The 512KB cap is the store's, and it is the same number code-write enforces on the way
 * in. A theme file larger than that cannot be versioned, so it cannot be deleted either -
 * which is the safe direction: refusing to delete something is recoverable, and deleting
 * the only copy is not.
 */
function wpmcp_code_version_current($abs, $rel, $reason) {
    $content = @file_get_contents($abs);

    if ($content === false) {
        return new WP_Error('wpmcp_version_failed', 'Could not read the current file, so nothing was changed.');
    }

    if (strlen($content) > wpmcp_version_max_bytes()) {
        return new WP_Error('wpmcp_too_big', 'The current file exceeds 512KB, so no version of it can be stored and it was left alone.');
    }

    $id = wpmcp_file_version_save($rel, $content, $reason);

    if ($id === false) {
        return new WP_Error('wpmcp_version_failed', 'Could not store a version of the current file, so nothing was changed.');
    }

    return array('id' => $id, 'content' => $content);
}

/**
 * Syntax-check PHP source. Returns true, or a short description of where it broke.
 *
 * THE PARSER'S OWN MESSAGE IS NOT RETURNED, and that is the disclosure boundary rather
 * than taste: the ParseError's message carries the absolute filesystem path of the file it
 * was given, and this string is put on the wire by code-write. The LINE is the part the
 * caller can act on - it is a line of source the caller itself just sent - and it leaks
 * nothing about this server.
 *
 * EVEN THE LINE IS ASKED FOR RATHER THAN REACHED FOR: wpmcp_throwable_line() lives in
 * trace.php, which is the one file allowed to introspect a throwable, and a unit test greps
 * this one to keep it that way. A file permitted to read one safe property off a throwable
 * is a file permitted to read the unsafe ones by the next person's judgement; one
 * reviewable place for all of it is the rule.
 */
function wpmcp_php_parse_ok($code) {
    if (!defined('TOKEN_PARSE')) { return true; } // can't check on this runtime
    try {
        // Intentional: TOKEN_PARSE makes the tokenizer THROW on invalid PHP; the catch below drives code-write's auto-revert. Do not "simplify" it; the return value is unused on purpose.
        token_get_all($code, TOKEN_PARSE); // @phpstan-ignore-line
        return true;
    } catch (ParseError $e) {
        return 'syntax error on line ' . wpmcp_throwable_line($e);
    } catch (Throwable $e) {
        return 'the source could not be parsed';
    }
}

/**
 * Limit the post tools to real, viewable content types. Excludes internal types
 * (revisions, nav_menu_item, wp_template, wp_global_styles, etc.) and attachments
 * (those have their own media tools). Public CPTs are allowed automatically.
 */
function wpmcp_post_type_ok($type) {
    $type = sanitize_key((string) $type);
    if ($type === '' || $type === 'attachment') { return false; }
    if (!post_type_exists($type)) { return false; }
    return is_post_type_viewable($type);
}

/**
 * The post statuses of $post_type the current user is allowed to see listed.
 *
 * WHY THIS EXISTS RATHER THAN 'any'. WP_Query's `perm => 'readable'` is not the guard
 * it looks like: it scopes exactly one bucket - the `private` status - and only when
 * `post_status` is an explicit list. The 'any' keyword takes a different branch that
 * merely excludes `exclude_from_search` statuses, so `perm` never applies at all, and
 * `draft` sits in the bucket only `perm => 'editable'` scopes. Passing 'any' therefore
 * listed every author's private and draft posts - id, title, status, slug, link - to
 * any token. Deciding the statuses here, from capabilities, is what actually closes it.
 *
 * Narrower than 'any' by one deliberate margin: a site with CUSTOM post statuses will
 * not see them listed, because this returns only the five core ones.
 */
function wpmcp_listable_statuses($post_type) {
    $pto      = get_post_type_object($post_type);
    $statuses = array('publish');
    if (!$pto) { return $statuses; }

    if (current_user_can($pto->cap->read_private_posts)) {
        $statuses[] = 'private';
    }
    // Unpublished work is other people's drafts. Seeing it is an editorial
    // capability, and edit_others_posts is the one WordPress uses for that.
    if (current_user_can($pto->cap->edit_others_posts)) {
        $statuses[] = 'draft';
        $statuses[] = 'pending';
        $statuses[] = 'future';
    }
    return $statuses;
}

/**
 * The statuses of $post_type the current user may see on their OWN posts but not on
 * other people's - the complement of wpmcp_listable_statuses().
 *
 * An Author holds neither read_private_posts nor edit_others_posts, so the list above
 * gives them `publish` alone and their own drafts vanish from their own token's
 * listing. WP_Query cannot express "everybody's publish OR only my drafts" in one
 * query: the post_status buckets are OR'd, but `perm` scopes them by author
 * all-or-nothing, so `perm => 'editable'` would hide other people's PUBLISHED posts
 * too. Hence a second, author-scoped query, and hence this list.
 *
 * Empty for anyone who cannot author posts at all, and empty for an Editor or an
 * Administrator - they already see every status, so they run one query exactly as
 * before.
 */
function wpmcp_own_listable_statuses($post_type) {
    $pto = get_post_type_object($post_type);
    if (!$pto || !current_user_can($pto->cap->edit_posts)) { return array(); }

    return array_values(array_diff(
        array('private', 'draft', 'pending', 'future'),
        wpmcp_listable_statuses($post_type)
    ));
}
/**
 * ISO 8601 for one WordPress datetime column, or null when the column holds no date.
 *
 * A date-floating status - draft, pending, auto-draft - is stored by wp_insert_post
 * with post_date_gmt AND post_modified_gmt set to '0000-00-00 00:00:00'; only the
 * non-GMT columns are populated (measured on WP 7.1, and the same measurement the
 * list-posts merge sorts on). That is not a date: formatting it yields
 * '-0001-11-30T00:00:00', which a client will happily parse as the year 1 BC. It is
 * reported as null instead, so "this post has no GMT date" is sayable.
 *
 * NOT EVERY DRAFT, SINCE SPRINT 11. A draft GIVEN a `date` carries a real post_date_gmt -
 * wp_insert_post stores a supplied non-empty GMT whatever the status, which is what
 * Gutenberg's fixed-date picker does too - so the zeroed pair now means "a draft nobody
 * dated" rather than "a draft". The guard is unchanged; only the sentence describing when
 * it fires was.
 *
 * mysql_to_rfc3339() is the function the REST API formats these same columns with, so
 * a client that already reads WordPress dates gets the identical string here - local
 * wall-clock time with NO offset suffix, which is all post_date stores.
 *
 * TWO GUARDS, AND THEY ARE NOT THE SAME GUARD. The first is the named case: the exact
 * string WordPress writes for a date-floating status. The second is everything else that
 * is not a date - measured, mysql_to_rfc3339('0000-00-00 00:00:00') does not fail, it
 * answers '-0001-11-30T00:00:00', and it answers false for an empty string - so any
 * column a plugin has filtered into some other kind of nonsense comes back as null
 * rather than as a year no client will question.
 */
function wpmcp_iso_date($value) {
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) { return null; }

    $out = mysql_to_rfc3339($value);
    return (!is_string($out) || $out === '' || str_starts_with($out, '-')) ? null : $out;
}

/**
 * A caller-supplied ISO 8601 date or datetime, normalised for WP_Date_Query - or null
 * when the shape is wrong, which list-posts turns into wpmcp_bad_arg.
 *
 * Deliberately strict and deliberately small: `YYYY-MM-DD`, optionally followed by a
 * `T` or a space and `HH:MM` or `HH:MM:SS`. NO offset and no trailing `Z`, because
 * WP_Date_Query compares against post_date, which is site-local wall-clock time with
 * no offset stored anywhere - accepting '+05:00' would mean silently ignoring it and
 * answering with a window five hours away from the one that was asked for.
 *
 * strtotime() is not used on purpose. It accepts 'next tuesday', 'now', '@1700000000'
 * and '2026-13-45' (which it rolls over into 2027), so it cannot tell a caller that
 * their date is malformed - and a filter that silently means something else is the
 * failure this whole tool is trying not to have.
 */
function wpmcp_parse_iso_datetime($value) {
    $value = trim((string) $value);

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
    if (!isset($m[4])) { return $m[1] . '-' . $m[2] . '-' . $m[3]; }

    $hour   = (int) $m[4];
    $minute = (int) $m[5];
    $second = isset($m[6]) ? (int) $m[6] : 0;

    if ($hour > 23 || $minute > 59 || $second > 59) { return null; }

    return sprintf('%s-%s-%s %02d:%02d:%02d', $m[1], $m[2], $m[3], $hour, $minute, $second);
}

/**
 * The three orderings list-posts offers, and the COLUMN each one sorts on.
 *
 * post_date and post_modified, never the _gmt pair: an UNDATED draft, pending or
 * auto-draft carries '0000-00-00 00:00:00' in both GMT columns (one given an explicit
 * date does not - see wpmcp_iso_date), so a merge that compared them would put those
 * drafts behind every dated post and the page slice would drop them. The
 * non-GMT columns are the ones WP_Query's own `orderby => date` and `=> modified` use,
 * which is what keeps the merged comparator and the un-merged query in agreement.
 */
function wpmcp_list_orderby_columns() {
    return array('date' => 'post_date', 'modified' => 'post_modified', 'title' => 'post_title');
}

/**
 * The comparator that re-imposes ONE ordering across the two listing queries' merge.
 *
 * It has to follow the same column and the same direction the two WP_Query calls were
 * given, or the merge silently reorders a listing the caller asked to be sorted some
 * other way. Before this it was hard-coded to post_date descending, which was right
 * only because there was nothing else to ask for.
 *
 * IT READS THE ARGUMENT THE QUERIES WERE GIVEN, not a second copy of the decision.
 * wpmcp_list_posts_filters() hands WP_Query the ARRAY form of `orderby` -
 * array('title' => 'ASC', 'ID' => 'ASC') - and this function takes the first key and its
 * direction straight out of that array. There is therefore no way for the SQL to sort on
 * one column and the merge on another; before this the two were written out separately and
 * a future edit to one would not have touched the other.
 *
 * TIE-BREAK ON ID, in the same direction - and the SQL now does it too, which is the half
 * that was missing. MySQL's sort is not stable, so for rows tied on the sort column a
 * `LIMIT 21` and a `LIMIT 41` may return a different relative order AND a different SUBSET
 * of the tied group. Paging over an import that shares a post_date to the second could then
 * show one row twice and another never - which the comparator alone cannot fix, because it
 * only ever sees the rows the LIMIT already chose.
 *
 * strcasecmp FOR TITLES, not strcmp. MySQL sorts post_title under a *_ci collation, so
 * 'apple' comes before 'Zebra'; PHP's strcmp is byte order, where every capital sorts
 * before every lowercase. The half of the list that never went through the merge is
 * already in the collation's order, so a byte-order comparator would interleave the two
 * halves wrongly, and only on titles whose case differs - the kind of bug that reads as
 * flakiness.
 */
function wpmcp_post_order_comparator($orderby, $order) {
    // WP_Query's array form, which is what both queries are actually given. The first key is
    // the column, its value is that key's direction; the scalar form stays supported so the
    // function can be reasoned about on its own.
    if (is_array($orderby)) {
        $keys    = array_keys($orderby);
        $order   = reset($orderby);
        $orderby = isset($keys[0]) ? (string) $keys[0] : 'date';
    }

    $columns = wpmcp_list_orderby_columns();
    $column  = isset($columns[$orderby]) ? $columns[$orderby] : 'post_date';
    $sign    = (strtoupper((string) $order) === 'ASC') ? 1 : -1;

    return function ($a, $b) use ($column, $sign) {
        $left  = (string) $a->$column;
        $right = (string) $b->$column;

        $cmp = ($column === 'post_title') ? strcasecmp($left, $right) : strcmp($left, $right);

        if ($cmp === 0) { $cmp = (int) $a->ID <=> (int) $b->ID; }

        return $sign * $cmp;
    };
}

/**
 * The user id behind list-posts' `author` argument - an integer id or a user login -
 * or 0 when there is no such user.
 *
 * 0 IS NOT AN ERROR. An unknown login has to answer exactly as a known one with nothing
 * the caller may see does, or list-posts becomes a user-enumeration oracle: somebody
 * could probe logins one at a time and read "no such user" off the difference. The
 * value is never echoed back either.
 *
 * Login, not display name and not email. A display name is not unique, and an email is
 * not something a read tool should confirm one guess at a time.
 */
function wpmcp_list_author_id($value) {
    $raw = trim((string) $value);
    if ($raw === '') { return 0; }

    if (is_int($value) || ctype_digit($raw)) {
        $user = get_userdata((int) $raw);
        return $user ? (int) $user->ID : 0;
    }

    $user = get_user_by('login', $raw);
    return $user ? (int) $user->ID : 0;
}

/**
 * The term id behind a taxonomy plus a slug-or-id, or 0 when there is no such term ON A
 * TAXONOMY THIS POST TYPE ACTUALLY USES.
 *
 * The taxonomy allow-list is wpmcp_post_type_ok()'s idea applied one level down: the
 * taxonomy must exist, it must be VIEWABLE (is_taxonomy_viewable), and it must be
 * attached to the post type being listed.
 *
 * VIEWABILITY IS THE CHECK WITH TEETH, and the only one of the three that WP_Query does
 * not already enforce by accident. A private taxonomy is a plugin's or a theme's internal
 * bookkeeping - customer segments, licence tiers, workflow states - and WP_Query will
 * filter a perfectly ordinary post listing by one of its terms without complaint. This is
 * what stops `term` being a way to read a taxonomy nobody publishes.
 *
 * ATTACHMENT AND EXISTENCE ARE AN ALLOW-LIST, NOT A BUG FIX, and this says so because the
 * tempting claim is false. MEASURED ON WP 7.1: `cat` on a post type that has no category
 * taxonomy is NOT ignored - WP_Query builds the term_relationships join anyway and
 * returns nothing - and an unknown term id returns nothing too. Both checks are therefore
 * belt and braces over behaviour that already happens to be right. They stay for two
 * reasons: "nothing matched" becomes a decision this tool made rather than a property of
 * a join that could change, and resolving here is what makes the OTHER shape impossible -
 * silently DROPPING a filter that cannot be resolved, which answers a question about one
 * category with every post on the site.
 *
 * 0 for all four misses - wrong taxonomy, unviewable taxonomy, unattached taxonomy,
 * missing term - because the caller may learn nothing from any of them.
 */
function wpmcp_list_term_id($taxonomy, $slugOrId, $postType) {
    $taxonomy = sanitize_key((string) $taxonomy);

    if ($taxonomy === '' || !taxonomy_exists($taxonomy) || !is_taxonomy_viewable($taxonomy)) {
        return 0;
    }
    if (!in_array($taxonomy, get_object_taxonomies($postType), true)) { return 0; }

    $value = trim((string) $slugOrId);
    if ($value === '') { return 0; }

    $term = ctype_digit($value)
        ? get_term((int) $value, $taxonomy)
        : get_term_by('slug', $value, $taxonomy);

    return ($term && !is_wp_error($term)) ? (int) $term->term_id : 0;
}

/**
 * THE CLASS OF FILTER: every list-posts filter, built ONCE, as WP_Query arguments.
 *
 * ONE PLACE BUILDS THEM AND BOTH QUERIES CONSUME THEM. list-posts runs two WP_Query
 * calls - the permitted-status one and the own-status one (see
 * wpmcp_own_listable_statuses() for why it cannot be one query). A filter applied to
 * one and not the other is a disclosure with a plausible shape: an Author filtering by
 * `category` would get that category's published posts PLUS every one of their own
 * drafts, in any category at all, and would read that as the filter working. So the
 * arguments are assembled here and array_merge'd into both call sites, and nothing in
 * the tool body builds a WP_Query argument except the three the STATUS SPLIT owns -
 * post_status, the own-query author scope, and the row count. A filter added anywhere
 * else is that bug.
 *
 * NO CALLER VALUE REACHES WP_Query UNSHAPED. Every filter is a NAMED tool argument that
 * this function maps to exactly one allow-listed WP_Query key; the caller never names a
 * WP_Query key, and no value is passed on without being validated, resolved against the
 * database, or cast. The complete set of keys this may return is:
 *
 *     s  cat  tag_id  tax_query  author  date_query  orderby  order
 *
 * The arguments that are true of every listing WHATEVER the caller said - the row count,
 * `no_found_rows`, `ignore_sticky_posts` and `update_post_meta_cache` - are NOT here. They
 * are not filters and nothing the caller sends can change them, so they live in
 * wpmcp_list_query_guards(), which both queries also merge.
 *
 * AND NO FILTER MAY WIDEN. The two status sets wpmcp_listable_statuses() and
 * wpmcp_own_listable_statuses() decide from capabilities ARE the guard; filters only
 * narrow inside it. Nothing here returns post_status, post_type, perm, post__in,
 * author__in, meta_query or suppress_filters - and a future filter that needs one of
 * those needs the capability argument that goes with it, made here, in this docblock,
 * and not in a caller's argument.
 *
 * THREE ANSWERS, and the difference between them IS the disclosure rule:
 *
 *   array()    the filters, for both queries.
 *   false      NOTHING CAN MATCH: an unknown category, tag, term, taxonomy, or author.
 *              The tool answers with an empty list - byte-identical to the answer for a
 *              real category that happens to hold nothing, and to the answer for a
 *              category holding only another author's draft. "There is no such thing",
 *              "it is empty" and "it is not yours to see" have to be ONE answer, or the
 *              filter is an oracle for the site's user logins and term names.
 *   WP_Error   the argument's SHAPE is wrong: a date that is not a date, an orderby
 *              that is not one of three words. That is the caller's own mistake about
 *              this protocol, it says nothing whatever about the site, and an agent
 *              answered with an empty list instead concludes the site is empty and
 *              stops.
 *
 * @return array|false|WP_Error
 */
function wpmcp_list_posts_filters($args, $postType) {
    $query = array();

    // ORDERING FIRST, because it is the one filter always present: both queries are
    // given it explicitly so neither can pick an ordering of its own. `s` is why that
    // matters - WP_Query switches to relevance ordering the moment a search term
    // appears, and it would switch on only one of the two halves.
    //
    // THE TWO REFUSALS BELOW ARE BELT AND BRACES, and deliberately so. Over the wire the
    // dispatcher's always-on schema validation runs first and the `enum` on these two
    // arguments answers an unknown value before this function is entered, which is why
    // the integration test sees the validator's message rather than these. They stay
    // because this function's contract is that NOTHING reaches WP_Query unshaped, and a
    // contract that holds only while somebody else's validator is switched on is not one:
    // `wpmcp_tools` is a filter, and a plugin that replaces this inputSchema would
    // otherwise be handing an arbitrary string to WP_Query's ORDER BY builder.
    $columns = wpmcp_list_orderby_columns();
    $orderby = isset($args['orderby']) ? strtolower(trim((string) $args['orderby'])) : 'date';

    if (!isset($columns[$orderby])) {
        return new WP_Error(
            'wpmcp_bad_arg',
            'orderby must be one of: ' . implode(', ', array_keys($columns)) . '.'
        );
    }

    $order = isset($args['order']) ? strtolower(trim((string) $args['order'])) : 'desc';

    if ($order !== 'asc' && $order !== 'desc') {
        return new WP_Error('wpmcp_bad_arg', 'order must be one of: asc, desc.');
    }

    // THE ARRAY FORM, WITH ID AS THE SECONDARY KEY. A scalar `orderby` becomes
    // `ORDER BY post_date DESC` with no tie-break, and the depth fetch means page 1 runs
    // LIMIT 21 and page 2 LIMIT 41 - two statements MySQL may answer with a different
    // relative order, and a different subset, of any group of rows tied on that column. The
    // ID is unique, so it turns the ordering into a total one and the page windows line up.
    // wpmcp_post_order_comparator() reads this same array, so the merge cannot disagree.
    $query['orderby'] = array($orderby => strtoupper($order), 'ID' => strtoupper($order));
    $query['order']   = strtoupper($order);

    // SEARCH. WP_Query's own `s`, which matches post_title, post_excerpt and
    // post_content - no author email, no comment, no column outside the posts table.
    // It searches only within the rows the status split already allowed.
    //
    // ITS SYNTAX COMES WITH IT, and the description says so rather than stripping it: core's
    // parse_search() reads a leading `-` on a term as EXCLUDE, so `search: "-2021"` means
    // "posts that do not contain 2021". Stripping it would silently turn an exclusion into
    // its opposite, which is the one thing worse than a syntax an agent has to be told about.
    if (isset($args['search'])) {
        $search = trim((string) $args['search']);
        if ($search !== '') { $query['s'] = $search; }
    }

    // AUTHOR. Resolved to an id HERE so the own-status query can compare it against
    // get_current_user_id() and skip itself when they differ - see the tool body.
    if (isset($args['author']) && trim((string) $args['author']) !== '') {
        $author = wpmcp_list_author_id($args['author']);
        if ($author === 0) { return false; }
        $query['author'] = $author;
    }

    // CATEGORY and TAG, the two shorthands. `cat` and `tag_id` take term IDS, so a slug
    // has to be resolved here whatever else is true. A miss ENDS the query; it does not
    // quietly drop the filter, which is the shape that would answer a question about one
    // category with every post on the site.
    if (isset($args['category']) && trim((string) $args['category']) !== '') {
        $term = wpmcp_list_term_id('category', $args['category'], $postType);
        if ($term === 0) { return false; }
        $query['cat'] = $term;
    }

    if (isset($args['tag']) && trim((string) $args['tag']) !== '') {
        $term = wpmcp_list_term_id('post_tag', $args['tag'], $postType);
        if ($term === 0) { return false; }
        $query['tag_id'] = $term;
    }

    // ANY OTHER TAXONOMY, as one string "taxonomy:slug". One argument rather than two so
    // the pair cannot arrive half-specified; a string with no colon resolves to the
    // empty taxonomy, which is not a taxonomy, which is an empty list - the same answer
    // a private taxonomy gets.
    if (isset($args['term']) && trim((string) $args['term']) !== '') {
        $raw   = trim((string) $args['term']);
        $colon = strpos($raw, ':');
        $tax   = $colon === false ? '' : substr($raw, 0, $colon);
        $slug  = $colon === false ? '' : substr($raw, $colon + 1);

        $term = wpmcp_list_term_id($tax, $slug, $postType);
        if ($term === 0) { return false; }

        $query['tax_query'] = array(array(
            'taxonomy' => sanitize_key($tax),
            'field'    => 'term_id',
            'terms'    => array($term),
        ));
    }

    // AFTER / BEFORE, inclusive, on post_date.
    //
    // post_date and not post_date_gmt, for the same measured reason the merge sorts on
    // it: the GMT column is '0000-00-00 00:00:00' for every draft, so a date window on
    // it would quietly exclude the caller's own unpublished work from every dated
    // search - the one thing the second query exists to include.
    //
    // WP_Date_Query's `inclusive` turns its `>` and `<` into `>=` and `<=` AND fills a
    // date-only bound out to the correct end of the day (00:00:00 for after, 23:59:59
    // for before), so `before: "2026-01-31"` means all of the 31st.
    $dateQuery = array('column' => 'post_date', 'inclusive' => true);

    foreach (array('after', 'before') as $bound) {
        if (!isset($args[$bound]) || trim((string) $args[$bound]) === '') { continue; }

        $parsed = wpmcp_parse_iso_datetime($args[$bound]);

        if ($parsed === null) {
            return new WP_Error(
                'wpmcp_bad_arg',
                $bound . ' must be an ISO 8601 date or datetime, e.g. 2026-01-31 or'
                . ' 2026-01-31T14:30:00.'
            );
        }

        $dateQuery[$bound] = $parsed;
    }

    if (isset($dateQuery['after']) || isset($dateQuery['before'])) {
        $query['date_query'] = array($dateQuery);
    }

    return $query;
}

/**
 * The WP_Query arguments EVERY listing query carries, whatever the caller asked for.
 *
 * SEPARATE FROM wpmcp_list_posts_filters() ON PURPOSE, and the separation is the point.
 * That function maps CALLER arguments to query keys and its allow-list is a security
 * boundary. These three are the opposite: no caller argument can reach them, and each one
 * has to be on EVERY listing query or the listing stops meaning what it says. One named
 * array, merged by both call sites, so a third query cannot be added without them.
 *
 * ignore_sticky_posts IS THE ONE THAT WAS A BUG, and it is worth the paragraph. WP_Query
 * decides `is_home` from the QUERY VARS, not from the request: a query is `is_home` unless
 * something marks it as singular, an archive, a search or a feed. `s`, `cat`, `tag_id`,
 * `tax_query` and `author` all mark it; `date_query`, `post_status`, `orderby`,
 * `posts_per_page`, `no_found_rows` and `perm` mark NOTHING. So a listing filtered only by
 * `after`/`before`, `status` or `orderby` is a home query, and on a home query at page 1
 * core SPLICES EVERY STICKY POST IN AT THE FRONT - fetched by post__in with
 * post_status => 'publish', with no date, status or ordering condition from the original
 * query (class-wp-query.php 3582-3629, measured against WP 7.1).
 *
 * The result was an answer that was not false about permissions but was false about the
 * question: `after: "2030-01-01"` returned every sticky post on the site, and `status:
 * "draft"` on an Editor's token returned the drafts PLUS every published sticky. This tool
 * exists to not do that, and the tool never passes `paged`, so page 1 is every page.
 *
 * update_post_meta_cache IS A SIZE FUSE. The depth fetch is page * limit + 1, up to 10,001
 * rows, on each query - and WP_Query primes the postmeta cache for every row it returns.
 * The listing reads ID, title, type, status, slug and the permalink and no meta at all, so
 * on a site carrying ACF or SEO meta that priming is a memory-exhaustion shape rather than a
 * slow one, for data nobody looks at. The TERM cache stays ON: get_permalink() reads it on a
 * %category% permalink structure, and switching it off would trade one query for N.
 */
function wpmcp_list_query_guards() {
    return array(
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
    );
}

/**
 * The taxonomy terms on a post, keyed by taxonomy, for get-post.
 *
 * VIEWABLE TAXONOMIES ONLY, and only the ones attached to this post type - the same
 * allow-list wpmcp_list_term_id() applies to the filter side, so a caller cannot read
 * through get-post what they cannot filter on. A private taxonomy is a plugin's or a
 * theme's internal bookkeeping; its term names are frequently customer segments,
 * licence tiers or workflow states, and none of that is the post's content.
 *
 * Every allowed taxonomy gets a key even when the post has no terms in it, so the shape
 * of the answer does not depend on the data; an empty object rather than an empty array
 * when there are none at all, because PHP's [] and {} are the same value and a client
 * reading `terms.category` should not have to cope with a list (KB 9.2).
 */
function wpmcp_post_terms($post) {
    $found = array();

    foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
        if (!is_taxonomy_viewable($taxonomy)) { continue; }

        $found[$taxonomy] = array();
        $terms            = get_the_terms($post, $taxonomy);

        if (is_wp_error($terms) || !$terms) { continue; }

        foreach ($terms as $term) {
            $found[$taxonomy][] = array(
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            );
        }
    }

    return $found === array() ? new stdClass() : $found;
}

/**
 * Statuses that count as publishing, so they need publish_posts rather than merely
 * edit_posts. `private` is one of them - core's wp-admin/includes/post.php requires
 * publish_posts for it, because publishing privately is still publishing, and a
 * Contributor who could set it would be putting live content on the site.
 */
function wpmcp_publishing_statuses() {
    return array('publish', 'future', 'private');
}

/**
 * The refusal every write tool returns when the token's user lacks a capability.
 *
 * WHY EVERY WRITE TOOL NEEDS ONE. The low-level WordPress functions these tools call -
 * wp_insert_post, wp_update_post, wp_delete_post, wp_insert_term, wp_delete_term,
 * media_handle_sideload, wp_delete_attachment, wp_set_comment_status,
 * wp_insert_comment - perform NO capability checks. They are the storage layer;
 * wp-admin and the REST controllers do the checking before calling them. So before
 * this, an admin-scope token minted with "Runs as: some-subscriber" could publish,
 * rewrite and delete any post on the site, while the mint form told the operator that
 * the user's capabilities were the ceiling. They now are.
 *
 * Unlike the read tools, a write refusal is explicit rather than disguised as
 * not-found: the caller already named the thing it wants to change, so there is
 * nothing left to disclose, and an agent needs to know the difference between "gone"
 * and "not allowed" to stop retrying.
 */
function wpmcp_cannot($what) {
    return new WP_Error(
        'wpmcp_forbidden',
        "This token's user is not allowed to " . $what . '.'
    );
}

/**
 * Apply {taxonomy:[id|name,...]} to a post, creating missing terms by name.
 *
 * TWO CAPABILITIES, NOT ONE, and this helper is where the term gates were being
 * walked around: create-term requires edit_terms, but `create-post {terms: {category:
 * ["something new"]}}` reached wp_insert_term through here with no check at all, so an
 * Author could create categories. wp_set_object_terms checks nothing either, while
 * core's own wp_insert_post requires assign_terms for its tax_input.
 *
 *   assign_terms  to attach a term that already exists
 *   edit_terms    to bring a new one into being by name
 *
 * A caller with assign_terms but not edit_terms gets the existing terms attached and
 * the unknown names REFUSED - reported back, never silently created and never
 * silently dropped, because "I asked for three categories and got two" has to be
 * visible to whatever asked.
 *
 * @return array{assigned: array<string, list<int>>, refused: array<string, list<string>>, failed: array<string, string>}
 *   refused lists, per taxonomy, the names that would have needed edit_terms, and the
 *   marker '*' for a taxonomy the caller cannot assign in at all.
 */
function wpmcp_apply_terms($post_id, $terms) {
    $assigned = array();
    $refused  = array();
    $failed   = array();

    foreach ((array) $terms as $tax => $vals) {
        $tax = sanitize_key($tax);
        if (!taxonomy_exists($tax)) { continue; }

        $tax_obj = get_taxonomy($tax);
        if (!current_user_can($tax_obj->cap->assign_terms)) {
            // '*' rather than the names: the whole taxonomy is out of reach, which is
            // a different answer from "these particular names are new".
            $refused[$tax] = array('*');
            continue;
        }
        $may_create = current_user_can($tax_obj->cap->edit_terms);

        $ids = array();
        foreach ((array) $vals as $v) {
            if (is_numeric($v)) {
                // term_exists IN THIS TAXONOMY, because wp_set_object_terms does not
                // complain about an id that is not: core's loop does `if ( ! $term_info
                // ) { if ( is_int( $term ) ) continue; }`, so a numeric id belonging to
                // another taxonomy - or to nothing - is silently skipped and the caller
                // is told the assignment succeeded. Refuse it here instead, where it
                // can be named.
                if (term_exists((int) $v, $tax)) {
                    $ids[] = (int) $v;
                } else {
                    $refused[$tax][] = (string) $v;
                }
                continue;
            }
            $t = get_term_by('name', (string) $v, $tax);
            if ($t) {
                $ids[] = (int) $t->term_id;
                continue;
            }
            if (!$may_create) {
                $refused[$tax][] = (string) $v;
                continue;
            }
            $new = wp_insert_term((string) $v, $tax);
            if (!is_wp_error($new)) { $ids[] = (int) $new['term_id']; }
        }

        if ($ids) {
            // The return was ignored. wp_set_object_terms answers with a WP_Error on a
            // failed insert or a broken taxonomy, and swallowing it reported terms as
            // assigned that are not on the post. A failure is not a refusal - the
            // caller was allowed to do this and it did not happen - so it gets its own
            // key rather than being folded into `refused`.
            $set = wp_set_object_terms($post_id, $ids, $tax, false);

            if (is_wp_error($set)) {
                $failed[$tax] = $set->get_error_message();
            } else {
                $assigned[$tax] = $ids;
            }
        }
    }

    return array('assigned' => $assigned, 'refused' => $refused, 'failed' => $failed);
}

/**
 * An ISO 8601 date or datetime, with or without a UTC offset, as the PAIR of MySQL
 * strings WordPress stores - or null when it is not one.
 *
 * WITHOUT AN OFFSET IT IS SITE-LOCAL TIME. `2026-03-04T09:30:00` means half past nine on
 * the wall clock of whoever runs this site, because that is what `post_date` holds and
 * what the operator sees in wp-admin. With an offset - `Z`, `+02:00`, `-0500` - the
 * instant is fixed by the caller and the site's zone only decides how it is written down.
 * Both branches end at the same place: one instant, expressed twice.
 *
 * WHY NOT strtotime(), AND WHY NOT wpmcp_parse_iso_datetime(). strtotime() accepts 'next
 * tuesday', '@1700000000' and '2026-13-45' (which it rolls into 2027), so it cannot tell a
 * caller their date is malformed - the exact failure this plugin's `after`/`before` filter
 * already refuses to have. wpmcp_parse_iso_datetime() is that refusal, but it rejects an
 * offset outright, which is right for a date FILTER (a window on stored local columns) and
 * wrong for a date a caller is SETTING. Two shapes, two parsers, and neither loosened.
 *
 * THE CONVERSION IS get_gmt_from_date()'S OWN - `wp_timezone()` to UTC - done once on the
 * parsed instant rather than by formatting to local and re-parsing that. A round trip
 * through a local string is lossy exactly where it matters: in the repeated hour of a DST
 * fall-back, two different instants share one local spelling, so re-parsing picks one of
 * them and an offset-bearing input can land an hour away from the instant it named.
 *
 * @return array{local: string, gmt: string}|null
 */
function wpmcp_parse_post_date($value) {
    $value = trim((string) $value);

    if (!preg_match(
        '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?(Z|z|[+-]\d{2}:?\d{2})?$/',
        $value,
        $m
    )) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }

    $hour   = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
    $minute = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
    $second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;

    if ($hour > 23 || $minute > 59 || $second > 59) { return null; }

    $stamp  = sprintf('%s-%s-%s %02d:%02d:%02d', $m[1], $m[2], $m[3], $hour, $minute, $second);
    $offset = isset($m[7]) ? $m[7] : '';

    if ($offset !== '') {
        if ($offset === 'Z' || $offset === 'z') {
            $offset = '+00:00';
        } elseif (strlen($offset) === 5) {
            $offset = substr($offset, 0, 3) . ':' . substr($offset, 3);
        }
        // +25:00 parses in PHP and means nothing. The real range is -12:00..+14:00.
        if ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59) { return null; }

        $stamp .= $offset;
    }

    try {
        // The second argument is consulted ONLY when the string carries no offset of its
        // own, which is precisely the site-local branch.
        $dt = new DateTimeImmutable($stamp, wp_timezone());
    } catch (Exception $e) {
        return null;
    }

    return array(
        'local' => $dt->setTimezone(wp_timezone())->format('Y-m-d H:i:s'),
        'gmt'   => $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    );
}

/**
 * THE ONE PLACE create-post AND update-post SHAPE AND GATE A POST FIELD.
 *
 * Every argument here maps to exactly one wp_insert_post field or one core setter, is
 * shaped by this code rather than passed through, and carries the capability WordPress
 * itself puts in front of that field in wp-admin. Both write tools call this, so the two
 * cannot drift: before it, `excerpt` and `slug` were written out twice, once per tool,
 * and a third field would have been written out twice again.
 *
 * REFUSALS ARE LOUD, which is the established rule for writes (see wpmcp_cannot): the
 * caller already named the thing it wants to change, so there is nothing left to
 * disclose, and an agent needs "not allowed" to be distinguishable from "gone".
 *
 * THE THREE GATES, AND WHOSE THEY ARE:
 *
 *   date            none of its own. Scheduling IS publishing, and `future` is already
 *                   in wpmcp_publishing_statuses(), so the publish_posts gate each tool
 *                   applies to `status` is the gate. A malformed date is wpmcp_bad_arg.
 *   author          $pto->cap->edit_others_posts, the capability wp-admin gates the
 *                   Author box on. The TARGET must be able to edit_posts of this type -
 *                   wp-admin's dropdown lists exactly those users - and a user who
 *                   cannot, or who is not there at all, gets one message that says
 *                   nothing else about them.
 *   featured_image  edit_post ON THE ATTACHMENT, which resolves through the attachment's
 *                   OWN AUTHOR exactly as a post's does - THE PARENT PLAYS NO PART.
 *                   map_meta_cap consults post_parent only when the post type is
 *                   `revision` (wp-includes/capabilities.php:215-221, WP 7.1); for an
 *                   attachment it takes the ordinary own-vs-others' branch, and an
 *                   attachment's `inherit` status is none of publish/future/private, so
 *                   the answer is edit_posts for the owner and edit_others_posts for
 *                   everybody else. MEASURED, four roles by four attachments: an Author
 *                   holds it on their own upload, parented or not, and on nobody else's,
 *                   parented or not; an Editor holds it on every one. That is the
 *                   difference between "add a picture to my post" and "reach into
 *                   somebody else's media library".
 *
 * WHY `after` IS SEPARATE FROM `insert`. set_post_thumbnail() needs a post id, which on
 * create does not exist until wp_insert_post() has run - but its capability check does
 * not, so the refusal happens here, before anything is written, and only the writing is
 * deferred. A create that would have been refused for its featured image therefore does
 * not leave a post behind.
 *
 * @param array        $a        the tool's arguments
 * @param string       $postType the type the row will have
 * @return array{insert: array, after: array, changed: list<string>}|WP_Error
 */
function wpmcp_post_fields($a, $postType) {
    $pto     = get_post_type_object($postType);
    $insert  = array();
    $after   = array();
    $changed = array();

    if (isset($a['excerpt'])) {
        $insert['post_excerpt'] = (string) $a['excerpt'];
        $changed[] = 'excerpt';
    }

    if (isset($a['slug'])) {
        $insert['post_name'] = sanitize_title((string) $a['slug']);
        $changed[] = 'slug';
    }

    if (array_key_exists('date', $a)) {
        $date = wpmcp_parse_post_date($a['date']);

        if ($date === null) {
            return new WP_Error(
                'wpmcp_bad_arg',
                'date must be an ISO 8601 date or datetime: 2026-03-04,'
                . ' 2026-03-04T09:30:00, or 2026-03-04T09:30:00+02:00. Without an offset'
                . " it is read as this site's local time."
            );
        }

        $insert['post_date']     = $date['local'];
        $insert['post_date_gmt'] = $date['gmt'];
        // MEASURED ON WP 7.1: wp_update_post() REPLACES the post_date of a draft,
        // pending or auto-draft with the current time unless `edit_date` is set - the
        // "drafts shouldn't be assigned a date unless the user did so" branch. Passing a
        // date and not passing this is therefore a silent no-op, which is the worst
        // possible shape for a scheduling argument. wp_insert_post ignores the key.
        $insert['edit_date']     = true;
        $changed[] = 'date';
    }

    if (isset($a['author'])) {
        if (!$pto || !current_user_can($pto->cap->edit_others_posts)) {
            return wpmcp_cannot('set the author of ' . $postType . ' content');
        }

        // THE SHAPE, CHECKED HERE BECAUSE THE SCHEMA CANNOT SAY IT. This argument
        // declares no `type` - the dialect SchemaValidator enforces has no way to say
        // "integer or string" - so the validator lets a boolean or a float through, and
        // `wpmcp_list_author_id(true)` resolved `(string) true === '1'` to user 1. A
        // silent cast to whoever installed the site is not an answer to `author: true`.
        if (!is_int($a['author']) && !(is_string($a['author']) && trim($a['author']) !== '')) {
            return new WP_Error(
                'wpmcp_bad_arg',
                'author must be a user id (integer) or a user login (non-empty string).'
            );
        }

        $authorId = wpmcp_list_author_id($a['author']);
        $target   = $authorId ? get_userdata($authorId) : null;

        // ONE MESSAGE FOR BOTH MISSES. "no such user" and "that user cannot write here"
        // are two facts about somebody's account, and an id-or-login argument is exactly
        // the shape that would be used to enumerate them one guess at a time.
        if (!$target || !user_can($target, $pto->cap->edit_posts)) {
            return new WP_Error(
                'wpmcp_bad_arg',
                'author is not an eligible author for this post type.'
            );
        }

        $insert['post_author'] = $authorId;
        $after['author']       = $authorId;
        $changed[]             = 'author';
    }

    if (isset($a['featured_image'])) {
        $thumb = (int) $a['featured_image'];

        // The same sentence for "no such id", "not an attachment" and "not an image", so
        // that probing ids through this argument learns nothing a caller did not send.
        $notAnImage = new WP_Error(
            'wpmcp_bad_arg',
            'featured_image must be 0, or the id of an image attachment on this site.'
        );

        if ($thumb < 0) { return $notAnImage; }

        if ($thumb > 0) {
            $att = get_post($thumb);

            if (!$att || $att->post_type !== 'attachment') { return $notAnImage; }
            if (!current_user_can('edit_post', $thumb)) {
                return wpmcp_cannot('use attachment ' . $thumb . ' as a featured image');
            }
            if (!wp_attachment_is_image($thumb)) { return $notAnImage; }
        }

        $after['featured_image'] = $thumb;
        $changed[]               = 'featured_image';
    }

    return array('insert' => $insert, 'after' => $after, 'changed' => $changed);
}

/**
 * Apply the deferred setters, and report back what the ROW now says.
 *
 * READ BACK, NEVER ECHOED. Everything here is re-read from the post after the write,
 * because core is entitled to have done something else with what it was given - and for
 * `date` it routinely has. MEASURED ON WP 7.1:
 *
 *   status publish + a future date   ->  core stores `future`. It is a schedule.
 *   status future  + a past date     ->  core stores `publish`. It is published now.
 *
 * Both are core's own branch in wp_insert_post(), and both are silent. `status` in the
 * result is what says which one happened, and `date` next to it is what it happened at.
 *
 * @param list<string> $changed the field names wpmcp_post_fields() shaped
 * @return array the fragment to merge into the tool's result
 */
function wpmcp_apply_post_fields($postId, $after, $changed) {
    $out = array();

    if (array_key_exists('featured_image', $after)) {
        if ($after['featured_image'] > 0) {
            set_post_thumbnail($postId, $after['featured_image']);
        } else {
            delete_post_thumbnail($postId);
        }
    }

    $p = get_post($postId);

    if (array_key_exists('featured_image', $after)) {
        $thumb = (int) get_post_thumbnail_id($postId);
        $url   = $thumb ? wp_get_attachment_url($thumb) : false;

        $out['featured_image'] = $thumb
            ? array('id' => $thumb, 'url' => $url === false ? null : $url)
            : null;
    }

    if (array_key_exists('author', $after)) {
        // display_name and the id, never the login or the email - the same ceiling
        // get-post reports an author at, for the same reason.
        $user = $p ? get_userdata((int) $p->post_author) : null;

        $out['author'] = array(
            'id'   => $p ? (int) $p->post_author : 0,
            'name' => $user ? $user->display_name : null,
        );
    }

    if (in_array('date', $changed, true) && $p) {
        $out['date']     = wpmcp_iso_date($p->post_date);
        $out['date_gmt'] = wpmcp_iso_date($p->post_date_gmt);
    }

    return $out;
}

/**
 * The post meta keys the meta tools may read and write on THIS site, normalised.
 *
 * AN OPERATOR'S DECLARATION, NOT A DISCOVERY. Post meta is where a WordPress site keeps
 * everything that is not a post field - ACF values, page-builder payloads, a plugin's
 * internal bookkeeping, `_edit_lock` - and there is no capability that separates the
 * three. So the tools do not enumerate: an administrator writes the exact key names into
 * Settings > WP MCP, one per line, and those are the only keys that exist for MCP. A bare
 * site has an empty list, and on a bare site these tools do not appear at all.
 *
 * NORMALISED ON EVERY READ AS WELL AS ON SAVE. The option is an ordinary row that wp-cli,
 * another plugin or a restored backup can write, so the rules cannot live only in the
 * settings form: blanks and duplicates are dropped, and so is any key
 * `is_protected_meta()` refuses - core's own rule, which is "leading underscore" plus
 * whatever the site's plugins add to it. `_thumbnail_id` is a post field with a tool of
 * its own (`featured_image`); `_edit_lock` is wp-admin's; neither is content.
 *
 * @param array|string $value the stored array, or the textarea's text
 * @return list<string>
 */
function wpmcp_meta_keys_normalise($value) {
    $items = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);
    $keys  = array();

    foreach ((array) $items as $item) {
        if (!is_scalar($item)) { continue; }

        $key = trim((string) $item);

        if ($key === '' || in_array($key, $keys, true)) { continue; }
        // A BACKSLASH IS DROPPED BEFORE THE PROTECTED TEST, not after it, and it is the
        // same reason wpmcp_meta_key_allowed() refuses one: the meta API unslashes the
        // key on the way in, so `\_thumbnail_id` is not protected by this test and is
        // `_thumbnail_id` by the time it reaches the database.
        if (strpos($key, '\\') !== false) { continue; }
        if (is_protected_meta($key, 'post')) { continue; }

        $keys[] = $key;
    }

    return $keys;
}

/** This site's meta allow-list. */
function wpmcp_meta_keys() {
    return wpmcp_meta_keys_normalise(get_option('wpmcp_meta_keys', array()));
}

/**
 * Can the meta tools do anything at all here?
 *
 * With an empty allow-list every call would be refused, so endpoint.php's wpmcp_tools()
 * asks this before it merges the group in and the tools are absent from tools/list -
 * the same invariant the code tools and sql-select follow: a tool that cannot run is not
 * listed, and calling it by name answers the "Unknown tool" every unregistered name gets.
 */
function wpmcp_meta_enabled() {
    return wpmcp_meta_keys() !== array();
}

/**
 * May these tools touch $key on this site? true, or the refusal that says why.
 *
 * NAMES ONLY THE KEY THE CALLER SENT. Never the list, never a count, never "try one of
 * these" - the allow-list is the operator's configuration, and a caller that guessed a
 * key wrong has no business learning what the right ones are.
 *
 * PROTECTED FIRST, and it is a separate answer rather than a fold into "not allowed",
 * because it is a different fact: `_secret` is refused on a site where somebody typed it
 * into the settings box, and the honest sentence says so. The normaliser drops it on save
 * as well; both, because the option is writable from outside the settings form.
 */
function wpmcp_meta_key_allowed($key) {
    $key = trim((string) $key);

    if ($key === '') {
        return new WP_Error('wpmcp_bad_arg', 'key must be a post meta key.');
    }
    // BEFORE THE PROTECTED CHECK, because it is what makes the protected check true.
    // The meta API unslashes the key it is given (meta.php:62, :220, :420), so
    // `\_thumbnail_id` is not protected here - is_protected_meta() sees a leading
    // backslash, not a leading underscore - and arrives at the database as
    // `_thumbnail_id`. wp_slash() at the write sites closes that on its own; this closes
    // it again, independently, so a call site that forgets to slash cannot reopen it.
    // No legitimate post meta key contains a backslash.
    if (strpos($key, '\\') !== false) {
        return new WP_Error(
            'wpmcp_forbidden',
            'A post meta key may not contain a backslash, so these tools never read or'
            . ' write one.'
        );
    }
    if (is_protected_meta($key, 'post')) {
        return new WP_Error(
            'wpmcp_forbidden',
            'The meta key ' . $key . ' is protected by WordPress, so these tools never'
            . ' read or write it.'
        );
    }
    if (!in_array($key, wpmcp_meta_keys(), true)) {
        return new WP_Error(
            'wpmcp_forbidden',
            'The meta key ' . $key . " is not on this site's allow-list for MCP."
            . ' An administrator adds keys in Settings > WP MCP.'
        );
    }

    return true;
}

/**
 * One stored meta value, as something JSON can carry.
 *
 * get_post_meta() has already run maybe_unserialize(), so what arrives is a string, or
 * whatever a plugin serialised into that row - an array, or an object of a class this
 * request may not even have loaded. Scalars pass through, a list of scalars passes
 * through, and anything else becomes null rather than being coerced into a shape that
 * would misrepresent it.
 */
function wpmcp_meta_value($value) {
    if ($value === null || is_scalar($value)) { return $value; }

    if (is_array($value)) {
        $out = array();

        foreach ($value as $k => $v) {
            $out[$k] = ($v === null || is_scalar($v)) ? $v : null;
        }

        return $out;
    }

    return null;
}

/** Resolve an editable post by id, or a WP_Error. $badTypeMsg is the bad_type message. */
function wpmcp_get_editable_post($id, $badTypeMsg) {
    $id = (int) $id;
    $p0 = $id ? get_post($id) : null;
    if (!$p0) { return new WP_Error('wpmcp_not_found', 'No post with that ID.'); }
    if (!wpmcp_post_type_ok($p0->post_type)) { return new WP_Error('wpmcp_bad_type', $badTypeMsg); }
    return $p0;
}

/** Resolve an attachment by id, or a WP_Error. */
function wpmcp_get_attachment($id) {
    $id = (int) $id;
    $p = $id ? get_post($id) : null;
    if (!$p || $p->post_type !== 'attachment') { return new WP_Error('wpmcp_not_found', 'No attachment with that ID.'); }
    return $p;
}

/** Resolve+denylist a code target. Returns array('abs'=>..,'rel'=>..) or a WP_Error. */
function wpmcp_code_target($a, $mustExist) {
    $r = wpmcp_code_resolve(isset($a['path']) ? $a['path'] : '', $mustExist);
    if (!$r['ok']) { return new WP_Error('wpmcp_path', $r['error']); }
    if (wpmcp_code_denied($r['rel'])) { return new WP_Error('wpmcp_denied', 'That file is on the denylist.'); }
    return array('abs' => $r['abs'], 'rel' => $r['rel']);
}

/* ============================================================
 * Core read tools (site-info / list-posts / get-post)
 * ========================================================== */
function wpmcp_core_tools() {
    return array(
        'site-info' => array(
            'write' => false,
            'annotations' => array(
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ),
            'description' => 'Get name, URL, WP version, theme, plugin count.',
            // array() and not new stdClass(): endpoint.php's wpmcp_objectify_schema()
            // makes an empty `properties` serialize as `{}` wherever it appears, at any
            // depth, so the inline cast this used to carry is no longer the thing
            // keeping the listing valid - and a second way of saying it would drift.
            'inputSchema' => array('type' => 'object', 'properties' => array()),
            'run' => function ($args) {
                $theme = wp_get_theme();
                return array(
                    'name'           => get_bloginfo('name'),
                    'url'            => home_url(),
                    'wp_version'     => get_bloginfo('version'),
                    'active_theme'   => $theme ? ($theme->get('Name') . ' ' . $theme->get('Version')) : null,
                    'active_plugins' => count((array) get_option('active_plugins', array())),
                );
            },
        ),
        /**
         * FILTERS NARROW; THE STATUS SPLIT GUARDS. Read wpmcp_list_posts_filters()
         * before adding anything here: every filter is a named argument mapped by our
         * code to one allow-listed WP_Query key, built in that ONE function, and
         * merged into BOTH queries. A filter that reaches only one of them hands an
         * Author their own drafts back under somebody else's category, and it looks
         * like the filter working. A filter that reaches WP_Query unshaped is a query
         * argument the caller chose. Neither is possible while this body builds only
         * post_status, the own-query author scope, and the row count - and merges
         * wpmcp_list_query_guards(), which is the other half of the same idea: the
         * arguments no caller may touch, in one place, on every query.
         */
        'list-posts' => array(
            'write' => false,
            'annotations' => array(
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ),
            'description' => 'Find content the caller may see. Filter and page it.'
                . ' Args: post_type (default "post"), status (default: every status the'
                . ' caller may see), search (title, excerpt and content; a leading "-"'
                . ' on a word EXCLUDES it), category and'
                . ' tag (slug or id), term ("taxonomy:slug" for any other taxonomy),'
                . ' author (id or login), after and before (ISO 8601 date or datetime,'
                . ' inclusive), orderby ("date", "modified" or "title"; default "date"),'
                . ' order ("asc" or "desc"; default "desc"), limit (default 20, max 100)'
                . ' and page (default 1, max 100). A filter naming something that does'
                . ' not exist, or something the caller may not see, returns an empty'
                . ' list rather than an error. Returns count, page, limit, has_more and'
                . ' items; there is no total.',
            'inputSchema' => array('type' => 'object', 'properties' => array(
                'post_type' => array('type' => 'string', 'description' => 'Post type to list. Default "post".'),
                'status'    => array('type' => 'string', 'description' => 'One post status. Default: every status the caller may see.'),
                'search'    => array('type' => 'string', 'description' => 'Match title, excerpt or content. A leading "-" on a word excludes it.'),
                'category'  => array('type' => 'string', 'description' => 'Category slug or term id.'),
                'tag'       => array('type' => 'string', 'description' => 'Tag slug or term id.'),
                'term'      => array('type' => 'string', 'description' => 'Any other taxonomy, as "taxonomy:slug".'),
                'author'    => array('type' => 'string', 'description' => 'Author user id or login.'),
                'after'     => array('type' => 'string', 'description' => 'Posted on or after this ISO 8601 date or datetime.'),
                'before'    => array('type' => 'string', 'description' => 'Posted on or before this ISO 8601 date or datetime.'),
                'orderby'   => array('type' => 'string', 'enum' => array('date', 'modified', 'title'), 'description' => 'Sort column. Default "date".'),
                'order'     => array('type' => 'string', 'enum' => array('asc', 'desc'), 'description' => 'Sort direction. Default "desc".'),
                'limit'     => array('type' => 'integer', 'description' => 'Items per page. Clamped to 1-100. Default 20.'),
                'page'      => array('type' => 'integer', 'description' => 'Page number. Clamped to 1-100. Default 1.'),
            )),
            'run' => function ($args) {
                $type = isset($args['post_type']) ? sanitize_key($args['post_type']) : 'post';
                // Same allow-list the write tools use: no revisions, no nav_menu_item,
                // no wp_template, no attachments (media has its own tools).
                if (!wpmcp_post_type_ok($type)) {
                    return new WP_Error('wpmcp_bad_type', 'Not a listable post type: ' . $type);
                }
                $limit = isset($args['limit']) ? min(100, max(1, (int) $args['limit'])) : 20;
                $page  = isset($args['page']) ? min(100, max(1, (int) $args['page'])) : 1;

                // 'any' is never handed to WP_Query: it takes a branch where `perm` is
                // not consulted at all, so it lists every author's private and draft
                // posts. The permitted set is decided from capabilities instead.
                $permitted = wpmcp_listable_statuses($type);
                $own       = wpmcp_own_listable_statuses($type);
                $asked     = isset($args['status']) ? sanitize_key($args['status']) : '';

                if ($asked !== '' && $asked !== 'any') {
                    // Intersection, not a refusal: asking for a status you may not see
                    // is answered with an empty list, the same answer as a status with
                    // nothing in it. An error here would say "that status exists and is
                    // being withheld", which is the disclosure get-post avoids too.
                    $permitted = array_values(array_intersect($permitted, array($asked)));
                    $own       = array_values(array_intersect($own, array($asked)));
                }

                $filters = wpmcp_list_posts_filters($args, $type);
                // A malformed argument SHAPE - not a thing that cannot be found. See
                // wpmcp_list_posts_filters() for why those are two different answers.
                if (is_wp_error($filters)) { return $filters; }
                // `false` is "nothing can match": an unknown term, taxonomy or author.
                // Both queries are skipped and the empty page is returned below, which
                // is the same answer a real-but-empty filter produces.
                $matchable = $filters !== false;

                // HOW DEEP EACH QUERY HAS TO GO for the merge to be able to fill page
                // $page and still know whether a page after it exists. Any row in the
                // global first N must be in one list's own first N, so N rows from each
                // side is exactly enough - and the +1 is the has_more probe, which is
                // why no total is needed and `no_found_rows` stays on. `page` is
                // capped at 100 alongside `limit`, so this is bounded at 10,001 rows.
                $depth = $page * $limit + 1;

                $posts = array();
                if ($matchable && $permitted) {
                    $q = new WP_Query(array_merge($filters, wpmcp_list_query_guards(), array(
                        'post_type'      => $type,
                        'post_status'    => $permitted,
                        'posts_per_page' => $depth,
                        // Belt and braces over the list above: on an explicit status
                        // list this also scopes `private` to the user's own posts when
                        // they lack read_private_posts.
                        'perm'           => 'readable',
                    )));
                    $posts = $q->posts;
                }

                // Own unpublished work, which the first query cannot reach - see
                // wpmcp_own_listable_statuses(). Disjoint status sets, so no duplicates.
                //
                // AN `author` FILTER NAMING SOMEBODY ELSE SKIPS THIS QUERY ENTIRELY.
                // It is author-scoped to the current user by construction, so letting
                // the base array overwrite the filter's author would answer "the
                // Editor's posts" with the Author's own drafts - a filter that returns
                // what was not asked for, which is the same failure as a leak from the
                // reader's side.
                $me = get_current_user_id();
                $runOwn = $matchable && $own
                    && (!isset($filters['author']) || (int) $filters['author'] === (int) $me);

                if ($runOwn) {
                    $q2 = new WP_Query(array_merge($filters, wpmcp_list_query_guards(), array(
                        'post_type'      => $type,
                        'post_status'    => $own,
                        'author'         => $me,
                        'posts_per_page' => $depth,
                    )));

                    if ($q2->posts) {
                        $posts = array_merge($posts, $q2->posts);
                        // Re-impose ONE ordering across the merge - the same column and
                        // the same direction both queries were given, which is what
                        // wpmcp_post_order_comparator() exists to guarantee. Sorted
                        // only when there is something to merge: a single query's rows
                        // are already in the collation's order, and re-sorting them in
                        // PHP could only disagree with it.
                        usort($posts, wpmcp_post_order_comparator($filters['orderby'], $filters['order']));
                    }
                }

                // The extra row, before the slice eats it. `has_more` and not a total:
                // a count is a fact about posts the caller has not been shown, and on
                // the own-status side it would be a count of somebody's drafts.
                $hasMore = count($posts) > $page * $limit;
                $posts   = array_slice($posts, ($page - 1) * $limit, $limit);

                $items = array();
                foreach ($posts as $p) {
                    $items[] = array(
                        'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                        'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink($p),
                    );
                }
                return array(
                    'count'    => count($items),
                    'page'     => $page,
                    'limit'    => $limit,
                    'has_more' => $hasMore,
                    'items'    => $items,
                );
            },
        ),
        'get-post' => array(
            'write' => false,
            'annotations' => array(
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ),
            'description' => 'Read one post or page in full. Args: id (integer,'
                . ' required). Returns id, title, type, status, slug, link, raw content,'
                . ' raw excerpt, author {id, name}, date, date_gmt, modified and'
                . ' modified_gmt as ISO 8601, or null where the column holds no date -'
                . ' a draft nobody dated. featured_image {id, url} or null, terms'
                . ' keyed by taxonomy for every viewable taxonomy on the post type, each'
                . ' entry {id, name, slug}, and revisions - the number of stored'
                . ' revisions, or null when the caller may read the post but not edit'
                . ' it. A post the caller may not read, a post that is not there, and an'
                . ' id of the wrong kind of thing all answer identically.',
            'inputSchema' => array('type' => 'object',
                'properties' => array('id' => array('type' => 'integer', 'description' => 'Post ID.')),
                'required' => array('id')),
            'run' => function ($args) {
                $id = isset($args['id']) ? (int) $args['id'] : 0;
                $p = $id ? get_post($id) : null;
                if (!$p) { return new WP_Error('wpmcp_not_found', 'No post with that ID.'); }
                // Deliberately the SAME error as a missing post, not a distinct
                // "forbidden": a caller who cannot read the post must not be able to
                // learn that it exists by probing IDs.
                if (!current_user_can('read_post', $id)) {
                    return new WP_Error('wpmcp_not_found', 'No post with that ID.');
                }
                // Also not_found, not a third message. `bad_type` here named the type
                // it had refused, so probing ids told a Subscriber "exists, and is a
                // revision / attachment / wp_block / nav_menu_item" - read_post on
                // those maps to the parent's or the published item's `read`, so the
                // cap check above does not stop the probe. No content was returned
                // either way; the shape was the leak.
                if (!wpmcp_post_type_ok($p->post_type)) {
                    return new WP_Error('wpmcp_not_found', 'No post with that ID.');
                }

                // THE AUTHOR IS A DISPLAY NAME AND AN ID, AND NOTHING ELSE. Not the
                // login, which is half of a credential and the thing a brute-forcer is
                // missing; not the email, which is the other half of a password reset.
                // wp-admin shows a display name on the post list for exactly this
                // reason, and that is the ceiling a read tool should copy.
                $author = get_userdata((int) $p->post_author);

                // REVISIONS ARE EDITORIAL DATA, so they follow the editorial
                // capability. wp-admin puts the revisions panel behind edit_post; a
                // reader who may see the published text has no business knowing how
                // many times it was rewritten, or - through the count alone - that it
                // was rewritten at all. Null rather than 0: 0 would be a claim.
                //
                // IDS ONLY. `fields => ids` means the bodies never load, so the count
                // costs one small query and no revision text can escape through here.
                $revisions = null;
                if (current_user_can('edit_post', $p->ID)) {
                    $revisions = count(wp_get_post_revisions($p->ID, array('fields' => 'ids')));
                }

                $thumbnail = (int) get_post_thumbnail_id($p);
                $thumbnailUrl = $thumbnail ? wp_get_attachment_url($thumbnail) : false;

                return array(
                    'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                    'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink($p),
                    'content' => $p->post_content,
                    'excerpt' => $p->post_excerpt,
                    'author' => array(
                        'id'   => (int) $p->post_author,
                        'name' => $author ? $author->display_name : null,
                    ),
                    'date'         => wpmcp_iso_date($p->post_date),
                    'date_gmt'     => wpmcp_iso_date($p->post_date_gmt),
                    'modified'     => wpmcp_iso_date($p->post_modified),
                    'modified_gmt' => wpmcp_iso_date($p->post_modified_gmt),
                    'featured_image' => $thumbnail
                        ? array('id' => $thumbnail, 'url' => $thumbnailUrl === false ? null : $thumbnailUrl)
                        : null,
                    'terms'     => wpmcp_post_terms($p),
                    'revisions' => $revisions,
                );
            },
        ),
    );
}

/* ============================================================
 * Content tools (create-post / update-post / delete-post)
 * ========================================================== */
function wpmcp_content_tools() {
    return array(

    'create-post' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ),
        'description' => 'Create a post or page. Args: title, content, post_type'
            . ' (default "post"), status (default "draft"), excerpt, slug, terms'
            . ' {taxonomy: [id or name]}, date, author and featured_image. `date` is ISO'
            . ' 8601; without a UTC offset it means this site\'s local time. To SCHEDULE,'
            . ' send a future date with status "future" - status "publish" plus a future'
            . ' date becomes "future" anyway, and "future" plus a past date publishes now,'
            . ' so read `status` and `date` in the result for what actually happened.'
            . ' `author` is a user id or login and needs the capability to edit others\''
            . ' posts. `featured_image` is an image attachment id you may edit, or 0 for'
            . ' none. Returns id, link, status and changed.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'title' => array('type' => 'string'), 'content' => array('type' => 'string'),
            'post_type' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
            'date' => array('type' => 'string', 'description' => 'ISO 8601 date or datetime: 2026-03-04, 2026-03-04T09:30:00, or 2026-03-04T09:30:00+02:00. Without an offset it is this site\'s local time. Pair a future date with status "future" to schedule.'),
            // NO `type`, because there is no way to say "integer or string" in the
            // dialect SchemaValidator enforces and a declared type it cannot express is
            // worse than none: `type: string` would refuse the integer id an agent
            // naturally sends. Shaped by wpmcp_list_author_id(), which takes either.
            'author' => array('description' => 'User id (integer) or user login (string). Needs the capability to edit other people\'s posts of this type, and the target must be able to write them.'),
            'featured_image' => array('type' => 'integer', 'description' => 'Attachment id of an image you are allowed to edit, or 0 for no featured image.'),
        )),
        'run' => function ($a) {
            $postarr = array(
                'post_title'   => isset($a['title']) ? wp_strip_all_tags((string) $a['title']) : '',
                'post_content' => isset($a['content']) ? (string) $a['content'] : '',
                'post_type'    => isset($a['post_type']) ? sanitize_key($a['post_type']) : 'post',
                'post_status'  => isset($a['status']) ? sanitize_key($a['status']) : 'draft',
            );
            if (!wpmcp_post_type_ok($postarr['post_type'])) {
                return new WP_Error('wpmcp_bad_type', 'Unsupported post_type (use a public content type; attachments use the media tools).');
            }
            // wp_insert_post checks nothing. create_posts is the cap wp-admin gates
            // the "Add New" screen on; publishing is a second, separate capability,
            // which is the whole difference between a Contributor and an Author.
            $pto = get_post_type_object($postarr['post_type']);
            if (!current_user_can($pto->cap->create_posts)) {
                return wpmcp_cannot('create ' . $postarr['post_type'] . ' content');
            }
            // `private` belongs with publish/future: core's own post.php requires
            // publish_posts for it, because publishing privately is still publishing.
            if (in_array($postarr['post_status'], wpmcp_publishing_statuses(), true)
                && !current_user_can($pto->cap->publish_posts)) {
                return wpmcp_cannot('publish ' . $postarr['post_type'] . ' content');
            }
            // THE SHARED STEP. excerpt, slug, date, author and featured_image are shaped
            // and gated in wpmcp_post_fields() so that this tool and update-post cannot
            // disagree about any of them - see its docblock for whose capability each one
            // carries. It refuses BEFORE anything is written, so a create that cannot
            // have its featured image leaves no post behind.
            $fields = wpmcp_post_fields($a, $postarr['post_type']);
            if (is_wp_error($fields)) { return $fields; }

            $postarr = array_merge($postarr, $fields['insert']);
            // `changed` IS THE FIELDS THIS CALL NAMED, on both tools and in the same
            // order. It used to be seeded from the shared step alone here, so a create
            // that set a title and a date reported `["date"]` - and README says `changed`
            // is what the call touched. title, content and status are the three this tool
            // handles itself; everything else comes from the shared step.
            $changed = array();
            if (isset($a['title']))   { $changed[] = 'title'; }
            if (isset($a['content'])) { $changed[] = 'content'; }
            if (isset($a['status']))  { $changed[] = 'status'; }
            $changed = array_merge($changed, $fields['changed']);

            $id = wp_insert_post($postarr, true);
            if (is_wp_error($id)) { return $id; }
            $out = array('id' => (int) $id, 'link' => get_permalink($id));
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                $changed[] = 'terms';
                // Reported, not swallowed: a caller that asked for three categories
                // and got two has to be able to see which one did not happen.
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }
            $out = array_merge($out, wpmcp_apply_post_fields($id, $fields['after'], $changed));
            $p = get_post($id);
            $out['status']  = $p ? $p->post_status : null;
            $out['changed'] = $changed;
            return $out;
        },
    ),

    'update-post' => array(
        'write' => true,
        // destructiveHint TRUE. MCP's `false` means the tool performs only ADDITIVE
        // updates, and this one does not: every field it touches REPLACES what was
        // there - post_title, post_content, post_status, post_excerpt, post_name - and
        // `terms` reaches wp_set_object_terms($id, $ids, $tax, false), whose trailing
        // `false` means replace rather than append, so naming one category removes the
        // others. "Only the fields the caller named" is a statement about SCOPE, and
        // scoped is not additive.
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Update a post or page. Args: id (required) plus any of title,'
            . ' content, status, excerpt, slug, terms, date, author and featured_image.'
            . ' Only the fields you send change, and each REPLACES what was there. Set'
            . ' status "publish" to publish. `date` is ISO 8601; without a UTC offset it'
            . ' means this site\'s local time, and it is kept even on a draft. To SCHEDULE,'
            . ' send a future date with status "future" - status "publish" plus a future'
            . ' date becomes "future" anyway, and "future" plus a past date publishes now,'
            . ' so read `status` and `date` in the result. `author` is a user id or login'
            . ' and needs the capability to edit others\' posts. `featured_image` is an'
            . ' image attachment id you may edit, or 0 to remove it.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'title' => array('type' => 'string'),
            'content' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
            'date' => array('type' => 'string', 'description' => 'ISO 8601 date or datetime: 2026-03-04, 2026-03-04T09:30:00, or 2026-03-04T09:30:00+02:00. Without an offset it is this site\'s local time. Kept on a draft, which WordPress would otherwise re-date.'),
            // See create-post: no `type` because the dialect cannot say "integer or
            // string", and this argument is honestly both.
            'author' => array('description' => 'User id (integer) or user login (string). Needs the capability to edit other people\'s posts of this type, and the target must be able to write them.'),
            'featured_image' => array('type' => 'integer', 'description' => 'Attachment id of an image you are allowed to edit, or 0 to remove the featured image.'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = wpmcp_get_editable_post($id, 'That item is not an editable content type.');
            if (is_wp_error($p0)) { return $p0; }
            // wp_update_post checks nothing. edit_post is the meta cap, so it resolves
            // per post: own vs others', published vs not, via map_meta_cap.
            if (!current_user_can('edit_post', $id)) {
                return wpmcp_cannot('edit post ' . $id);
            }
            $upd = array('ID' => $id); $changed = array();
            if (isset($a['title']))   { $upd['post_title'] = wp_strip_all_tags((string) $a['title']); $changed[] = 'title'; }
            if (isset($a['content'])) { $upd['post_content'] = (string) $a['content']; $changed[] = 'content'; }
            if (isset($a['status'])) {
                $upd['post_status'] = sanitize_key($a['status']);
                $changed[] = 'status';
                // Publishing somebody else's draft is a capability of its own, and
                // edit_post does not imply it (a Contributor may edit, never publish).
                // `private` is in that set too - see wpmcp_publishing_statuses().
                if (in_array($upd['post_status'], wpmcp_publishing_statuses(), true)) {
                    $pto = get_post_type_object($p0->post_type);
                    if (!current_user_can($pto->cap->publish_posts)) {
                        return wpmcp_cannot('publish ' . $p0->post_type . ' content');
                    }
                }
                // Trashing through update-post is a delete by another name, so it
                // answers to delete_post, not edit_post - otherwise delete-post's
                // gate is one argument away from being bypassed.
                if ($upd['post_status'] === 'trash' && !current_user_can('delete_post', $id)) {
                    return wpmcp_cannot('trash post ' . $id);
                }
            }
            // THE SHARED STEP - the same one create-post calls, which is what keeps the
            // two tools' idea of excerpt, slug, date, author and featured_image identical.
            // It runs AFTER the edit_post gate above, so a caller who may not touch this
            // post at all is never handed a verdict about an attachment or a user.
            $fields = wpmcp_post_fields($a, $p0->post_type);
            if (is_wp_error($fields)) { return $fields; }

            $upd     = array_merge($upd, $fields['insert']);
            $changed = array_merge($changed, $fields['changed']);

            $r = wp_update_post($upd, true);
            if (is_wp_error($r)) { return $r; }
            $out = array('id' => $id, 'link' => get_permalink($id));
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                $changed[] = 'terms';
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }
            $out = array_merge($out, wpmcp_apply_post_fields($id, $fields['after'], $changed));
            $p = get_post($id);
            $out['status']  = $p->post_status;
            $out['changed'] = $changed;
            return $out;
        },
    ),

    'delete-post' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Delete a post/page. Args: id (required), force (default false). force=false trashes; force=true permanently deletes.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'force' => array('type' => 'boolean'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = wpmcp_get_editable_post($id, 'That item is not a deletable content type (attachments use delete-media).');
            if (is_wp_error($p0)) { return $p0; }
            // wp_delete_post checks nothing. delete_post is a meta cap, so it resolves
            // per post - own vs others', published vs not.
            if (!current_user_can('delete_post', $id)) {
                return wpmcp_cannot('delete post ' . $id);
            }
            $force = !empty($a['force']);
            $r = wp_delete_post($id, $force);
            if (!$r) { return new WP_Error('wpmcp_delete_failed', 'Could not delete.'); }
            return array('id' => $id, 'deleted' => $force, 'trashed' => !$force);
        },
    ),

    );
}

/* ============================================================
 * Post meta tools (get-post-meta / set-post-meta)
 *
 * THE ALLOW-LIST IS THE WHOLE DESIGN. Post meta has no capability of its own that
 * separates "the subtitle a marketing user writes" from "_edit_lock" or from a plugin's
 * private state, so nothing here discovers keys: an administrator names them in Settings
 * > WP MCP and those are the only keys these tools can see. With an empty list neither
 * tool can succeed, so wpmcp_tools() does not list either - the same rule sql-select and
 * the code tools follow, and the reason a bare site sees no meta tools at all.
 *
 * ACF, WHICH IS WHY THIS EXISTS. An ACF field's value is an ordinary meta row under the
 * field NAME, so `set-post-meta {key: "video_url"}` writes the field. ACF also keeps a
 * reference row `_video_url` holding the field KEY, and these tools write only the value.
 * MEASURED on a site running ACF Pro (WP 7.1), reading in a LATER request than the write:
 *
 *   field that has been set through ACF before   get_field() returns our new value,
 *   (reference row present)                      formatted by the field type. Correct.
 *   field that never had a value                 get_field() returns the raw string. For
 *   (no reference row)                           text/url that is right; for an image or
 *                                                a relationship the caller gets the id as
 *                                                a string instead of the shaped array.
 *
 * No ACF-specific code, by decision: it is one vendor's convention and the plugin does
 * not carry vendor conventions. README says the limitation out loud instead.
 * ========================================================== */
function wpmcp_meta_tools() {
    return array(

    'get-post-meta' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Read a post\'s custom fields. Args: id (required), key'
            . ' (optional). Returns `meta` as an object of key to value for every meta key'
            . ' this site allows MCP to touch that has a value on the post - one value when'
            . ' the key holds ONE row, a list of N values when it holds N rows - or just'
            . ' the one key you name. An administrator sets which keys those are in Settings > WP MCP;'
            . ' a key outside that list is refused by name and nothing else about the'
            . ' site\'s other keys is said. A post the caller may not read, a post that is'
            . ' not there, and an id of the wrong kind of thing all answer identically.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id'  => array('type' => 'integer', 'description' => 'Post ID.'),
            'key' => array('type' => 'string', 'description' => 'One allowed meta key. Omit for every allowed key that has a value.'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p  = $id ? get_post($id) : null;

            // BYTE-IDENTICAL TO get-post's THREE REFUSALS, and deliberately so: this tool
            // reads the same object behind the same capability, so a caller must not be
            // able to learn from the meta tool what the post tool refuses to tell it.
            if (!$p) { return new WP_Error('wpmcp_not_found', 'No post with that ID.'); }
            if (!current_user_can('read_post', $id)) {
                return new WP_Error('wpmcp_not_found', 'No post with that ID.');
            }
            if (!wpmcp_post_type_ok($p->post_type)) {
                return new WP_Error('wpmcp_not_found', 'No post with that ID.');
            }

            $keys = wpmcp_meta_keys();

            if (isset($a['key'])) {
                $allowed = wpmcp_meta_key_allowed($a['key']);
                if (is_wp_error($allowed)) { return $allowed; }

                $keys = array(trim((string) $a['key']));
            }

            $meta = array();

            foreach ($keys as $key) {
                // `false` for the third argument: every row, not the first. A key with
                // two rows is a list, and reporting only one of them would be a quiet
                // lie about what the post holds.
                $rows = get_post_meta($id, $key, false);

                if (!is_array($rows) || $rows === array()) { continue; }

                $meta[$key] = count($rows) === 1
                    ? wpmcp_meta_value($rows[0])
                    : array_map('wpmcp_meta_value', array_values($rows));
            }

            return array(
                'id' => $p->ID,
                // stdClass so that "no allowed key has a value" serialises as {} and not
                // as []. An empty PHP array is a list to json_encode, and a client that
                // reads meta as an object would see the type change under it.
                'meta' => $meta === array() ? new stdClass() : $meta,
            );
        },
    ),

    'set-post-meta' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            // TRUE. This REPLACES the key: every row under it is removed and what you
            // sent is written, so a key holding three rows and given one scalar keeps
            // one. destructiveHint: false is MCP's promise that an update is additive,
            // and this is not additive - the same reasoning update-post carries.
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Write one of a post\'s custom fields. Args: id, key and value,'
            . ' all required. The value REPLACES every row under that key: send a JSON'
            . ' scalar for ONE row, a flat list of N scalars for N SEPARATE rows, or null'
            . ' to delete the key. A field that expects one serialised array rather than'
            . ' several rows - an ACF repeater or gallery, or any key registered'
            . ' single=true - is not writable this way. An object, a list holding one, and'
            . ' an empty list or object are all refused; only null deletes. The key must be'
            . ' one an administrator allowed in Settings > WP MCP, and you need to be able'
            . ' to edit the post. WordPress stores meta as text, so a number or a boolean'
            . ' comes back as its string form. Returns id, key and the value as re-read.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id'  => array('type' => 'integer', 'description' => 'Post ID.'),
            'key' => array('type' => 'string', 'description' => 'One meta key this site allows MCP to write.'),
            // NO `type`: the point of this argument is that it is any JSON scalar, a
            // flat list of them, or null, and the dialect SchemaValidator enforces has
            // no way to say that. The shaping and the refusal are in the run body, where
            // they can name what was actually wrong.
            'value' => array('description' => 'A JSON scalar (one meta row), a non-empty flat list of scalars (one row per element), or null to delete the key. An object, or an empty list or object, is refused.'),
        ), 'required' => array('id', 'key', 'value')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = wpmcp_get_editable_post($id, 'That item is not an editable content type.');
            if (is_wp_error($p0)) { return $p0; }

            // THREE GATES, NOT ONE. edit_post is the post; edit_post_meta is the KEY on
            // that post, which is the meta cap core's own REST meta fields check and the
            // one a plugin filters to protect a key it owns; the allow-list is the
            // operator's. All three, in that order, so the most general refusal comes
            // first and a caller who cannot edit the post learns nothing about its keys.
            if (!current_user_can('edit_post', $id)) {
                return wpmcp_cannot('edit post ' . $id);
            }

            $key     = isset($a['key']) ? trim((string) $a['key']) : '';
            $allowed = wpmcp_meta_key_allowed($key);
            if (is_wp_error($allowed)) { return $allowed; }

            if (!current_user_can('edit_post_meta', $id, $key)) {
                return wpmcp_cannot('edit the meta key ' . $key . ' on post ' . $id);
            }

            $value = array_key_exists('value', $a) ? $a['value'] : null;

            // EVERYTHING BELOW GOES IN SLASHED, because the meta API takes slashed input
            // and unslashes it: add_metadata(), update_metadata() and delete_metadata()
            // each open with `// expected_slashed ($meta_key)` and then
            // `wp_unslash($meta_key); wp_unslash($meta_value);`
            // (wp-includes/meta.php:61-63, :218-222, :419-421, WP 7.1). Core's own REST
            // meta layer therefore slashes at every one of its five call sites
            // (class-wp-rest-meta-fields.php). Handing raw JSON straight in silently ate
            // one backslash from every value that had one - `C:\Users\max` stored as
            // `C:Usersmax`, `\d+` as `d+` - and, worse, turned a key `\_thumbnail_id`
            // that passed the protected-key check into the protected row `_thumbnail_id`
            // on the way to the database. The key check below refuses a backslash outright
            // as well: two independent answers, because a future call site that forgets
            // wp_slash() must not reopen that door.
            $slashedKey = wp_slash($key);

            if ($value === null) {
                delete_post_meta($id, $slashedKey);
            } elseif (is_scalar($value)) {
                update_post_meta($id, $slashedKey, wp_slash($value));
            } elseif (is_array($value) && array_is_list($value) && $value !== array()) {
                foreach ($value as $element) {
                    if (!is_scalar($element)) {
                        return new WP_Error(
                            'wpmcp_bad_arg',
                            'value must be a JSON scalar, a non-empty flat list of scalars,'
                            . ' or null. One element of the list is neither.'
                        );
                    }
                }
                // Replace, not append: delete every row first, then add one per element.
                // update_post_meta() cannot express "these N rows" at all - it rewrites
                // the first row and leaves the rest - so this is the only honest shape.
                delete_post_meta($id, $slashedKey);

                foreach ($value as $element) {
                    add_post_meta($id, $slashedKey, wp_slash($element), false);
                }
            } else {
                // AN EMPTY LIST AND AN EMPTY OBJECT ARE THE SAME THING HERE and both are
                // refused. `json_decode($body, true)` turns `{}` into `[]`, and
                // `array_is_list([])` is true, so `value: {}` used to take the list branch,
                // delete every row and add none - an agent that sent an empty object
                // meaning "an empty object" silently deleted the field. There is exactly
                // one way to delete, and it is `null`.
                return new WP_Error(
                    'wpmcp_bad_arg',
                    'value must be a JSON scalar, a non-empty flat list of scalars, or'
                    . ' null to delete the key. An object is not one of those - post meta'
                    . ' has no schema, so a nested structure would be stored as'
                    . ' PHP-serialised text that only this site can read back - and an'
                    . ' empty list or object is not a value; send null to delete.'
                );
            }

            // RE-READ, never echoed. WordPress stores meta as text and sanitises it on
            // the way in, so what came back out is the only truthful answer about what
            // is now on the post.
            $rows = get_post_meta($id, $key, false);

            if (!is_array($rows) || $rows === array()) {
                $stored = null;
            } elseif (count($rows) === 1) {
                $stored = wpmcp_meta_value($rows[0]);
            } else {
                $stored = array_map('wpmcp_meta_value', array_values($rows));
            }

            return array('id' => $id, 'key' => $key, 'value' => $stored);
        },
    ),

    );
}

/* ============================================================
 * Taxonomy tools (list-terms / create-term / delete-term)
 * ========================================================== */
function wpmcp_taxonomy_tools() {
    return array(

    'list-terms' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List taxonomy terms. Args: taxonomy (default category), search, hide_empty (default false).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'search' => array('type' => 'string'),
            'hide_empty' => array('type' => 'boolean'),
        )),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : 'category';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            $terms = get_terms(array(
                'taxonomy' => $tax, 'hide_empty' => !empty($a['hide_empty']),
                'search' => isset($a['search']) ? (string) $a['search'] : '',
            ));
            if (is_wp_error($terms)) { return $terms; }
            $out = array();
            foreach ($terms as $t) {
                $out[] = array('id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug,
                    'taxonomy' => $t->taxonomy, 'count' => $t->count, 'parent' => $t->parent);
            }
            return array('count' => count($out), 'terms' => $out);
        },
    ),

    'create-term' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ),
        'description' => 'Create a taxonomy term. Args: taxonomy (required), name (required), slug, parent, description.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'name' => array('type' => 'string'),
            'slug' => array('type' => 'string'), 'parent' => array('type' => 'integer'),
            'description' => array('type' => 'string'),
        ), 'required' => array('taxonomy', 'name')),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : '';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            // wp_insert_term checks nothing. edit_terms is the cap WordPress maps for
            // creating and editing a term (manage_terms gates the admin LIST screen);
            // for the core taxonomies all three resolve to manage_categories anyway.
            $tax_obj = get_taxonomy($tax);
            if (!current_user_can($tax_obj->cap->edit_terms)) {
                return wpmcp_cannot('create terms in ' . $tax);
            }
            $args = array();
            if (isset($a['slug']))        { $args['slug'] = sanitize_title((string) $a['slug']); }
            if (isset($a['parent']))      { $args['parent'] = (int) $a['parent']; }
            if (isset($a['description'])) { $args['description'] = (string) $a['description']; }
            $r = wp_insert_term((string) $a['name'], $tax, $args);
            if (is_wp_error($r)) { return $r; }
            $t = get_term($r['term_id'], $tax);
            return array('id' => (int) $r['term_id'], 'name' => $t->name, 'slug' => $t->slug);
        },
    ),

    'delete-term' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Delete a taxonomy term. Args: taxonomy (required), id (required).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'id' => array('type' => 'integer'),
        ), 'required' => array('taxonomy', 'id')),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : '';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            // wp_delete_term checks nothing.
            $tax_obj = get_taxonomy($tax);
            if (!current_user_can($tax_obj->cap->delete_terms)) {
                return wpmcp_cannot('delete terms in ' . $tax);
            }
            $r = wp_delete_term((int) $a['id'], $tax);
            if (is_wp_error($r)) { return $r; }
            if (!$r) { return new WP_Error('wpmcp_not_found', 'Term not found.'); }
            return array('id' => (int) $a['id'], 'deleted' => true);
        },
    ),

    );
}

/* ============================================================
 * Media tools (list-media / get-media / upload-media / delete-media)
 * ========================================================== */
function wpmcp_media_tools() {
    return array(

    'list-media' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List media attachments. Args: search, mime_type, page (default 1), per_page (default 20, max 100).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'search' => array('type' => 'string'), 'mime_type' => array('type' => 'string'),
            'page' => array('type' => 'integer'), 'per_page' => array('type' => 'integer'),
        )),
        'run' => function ($a) {
            $q = new WP_Query(array(
                'post_type' => 'attachment', 'post_status' => 'inherit',
                's' => isset($a['search']) ? (string) $a['search'] : '',
                'post_mime_type' => isset($a['mime_type']) ? (string) $a['mime_type'] : '',
                'paged' => isset($a['page']) ? max(1, (int) $a['page']) : 1,
                'posts_per_page' => isset($a['per_page']) ? min(100, max(1, (int) $a['per_page'])) : 20,
            ));
            $out = array();
            foreach ($q->posts as $p) {
                // An attachment's read_post resolves through its parent post's status,
                // so this is what keeps the media of a private or draft post - titles
                // and, worse, direct file URLs - out of a token that cannot read the
                // post it belongs to. WP_Query has no perm handling for
                // post_status=inherit, so the filter has to be here.
                if (!current_user_can('read_post', (int) $p->ID)) { continue; }

                $out[] = array('id' => $p->ID, 'title' => get_the_title($p), 'mime' => $p->post_mime_type,
                    'url' => wp_get_attachment_url($p->ID), 'date' => $p->post_date_gmt);
            }
            return array('count' => count($out), 'items' => $out);
        },
    ),

    'get-media' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Get one media item. Args: id (required).',
        'inputSchema' => array('type' => 'object',
            'properties' => array('id' => array('type' => 'integer')), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = wpmcp_get_attachment($id);
            if (is_wp_error($p)) { return $p; }
            // Same non-disclosing refusal get-post uses: identical to the message a
            // missing id returns, so ids cannot be probed for existence.
            if (!current_user_can('read_post', $id)) {
                return new WP_Error('wpmcp_not_found', 'No attachment with that ID.');
            }
            $meta = wp_get_attachment_metadata($id);
            $file = get_attached_file($id);
            return array(
                'id' => $id, 'title' => get_the_title($p), 'mime' => $p->post_mime_type,
                'url' => wp_get_attachment_url($id),
                'alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
                'caption' => $p->post_excerpt,
                'filesize' => ($file && file_exists($file)) ? filesize($file) : null,
                'width' => isset($meta['width']) ? $meta['width'] : null,
                'height' => isset($meta['height']) ? $meta['height'] : null,
            );
        },
    ),

    'upload-media' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ),
        'description' => 'Upload media by sideloading a URL. Args: source_url (required, http/https), filename, title, alt, post (attach to post id).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'source_url' => array('type' => 'string'), 'filename' => array('type' => 'string'),
            'title' => array('type' => 'string'), 'alt' => array('type' => 'string'),
            'post' => array('type' => 'integer'),
        ), 'required' => array('source_url')),
        'run' => function ($a) {
            // media_handle_sideload gates file TYPES, never the user. Checked before
            // the download, so a refused caller cannot use this tool to make the site
            // fetch arbitrary URLs.
            if (!current_user_can('upload_files')) {
                return wpmcp_cannot('upload files');
            }
            // Attaching to a post is a change to THAT post, which is why core's own
            // wp_ajax_upload_attachment requires edit_post on the parent. Checked here
            // too, and before the download, for the same reason as upload_files.
            $post = isset($a['post']) ? (int) $a['post'] : 0;
            if ($post > 0 && !current_user_can('edit_post', $post)) {
                return wpmcp_cannot('attach media to post ' . $post);
            }
            $url = isset($a['source_url']) ? esc_url_raw((string) $a['source_url']) : '';
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true)) {
                return new WP_Error('wpmcp_bad_url', 'source_url must be http or https.');
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $tmp = download_url($url, 20); // 20s timeout, not the 300s default
            if (is_wp_error($tmp)) { return $tmp; }
            $max = (int) wp_max_upload_size();
            $sz  = @filesize($tmp);
            if ($max > 0 && ($sz === false || $sz > $max)) {
                @unlink($tmp);
                return new WP_Error('wpmcp_too_big', 'Downloaded file exceeds the upload size limit.');
            }
            $name = isset($a['filename']) ? sanitize_file_name((string) $a['filename'])
                : sanitize_file_name(basename((string) wp_parse_url($url, PHP_URL_PATH)));
            if ($name === '') { $name = 'upload'; }
            $file = array('name' => $name, 'tmp_name' => $tmp);
            // $post was read and cap-checked above, before the download.
            $id = media_handle_sideload($file, $post, isset($a['title']) ? (string) $a['title'] : null);
            if (is_wp_error($id)) { @unlink($tmp); return $id; }
            if (isset($a['alt'])) { update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $a['alt'])); }
            return array('id' => (int) $id, 'url' => wp_get_attachment_url($id), 'mime' => get_post_mime_type($id));
        },
    ),

    'delete-media' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Delete a media attachment. Args: id (required), force (default false).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'force' => array('type' => 'boolean'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = wpmcp_get_attachment($id);
            if (is_wp_error($p)) { return $p; }
            // wp_delete_attachment checks nothing. delete_post on an attachment maps
            // through its own post type's caps (delete_posts / delete_others_posts).
            if (!current_user_can('delete_post', $id)) {
                return wpmcp_cannot('delete attachment ' . $id);
            }
            $r = wp_delete_attachment($id, !empty($a['force']));
            if (!$r) { return new WP_Error('wpmcp_delete_failed', 'Could not delete.'); }
            return array('id' => $id, 'deleted' => true);
        },
    ),

    );
}

/* ============================================================
 * Comment tools (list-comments / moderate-comment / reply-comment)
 * ========================================================== */
function wpmcp_comment_tools() {
    return array(

    'list-comments' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List comments the caller may read. Emails and IPs are never returned. Args: post (id), status (default "approve"; hold|spam|trash|all need moderate_comments and are otherwise treated as "approve"), search (matches comment text and author name), page, per_page.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'post' => array('type' => 'integer'), 'status' => array('type' => 'string'),
            'search' => array('type' => 'string'), 'page' => array('type' => 'integer'),
            'per_page' => array('type' => 'integer'),
        )),
        'run' => function ($a) {
            // WP_Comment_Query performs no capability checks of any kind (zero
            // current_user_can calls in the class), so every restriction here is this
            // tool's own. wp-admin gates unapproved comments on moderate_comments and
            // core's REST controller checks read_post per comment; match both.
            $status = isset($a['status']) ? sanitize_key($a['status']) : 'approve';
            if ($status !== 'approve' && !current_user_can('moderate_comments')) {
                // Fall back rather than refuse: an error would confirm that held or
                // spam comments exist and are being withheld. Same stance as
                // list-posts on an unpermitted status, and get-post on read_post.
                $status = 'approve';
            }

            $args = array(
                // Approved only unless asked otherwise. The old default of 'all' handed
                // spam and held-for-moderation text - unreviewed, attacker-supplied
                // content - to every read token without anyone asking for it.
                'status' => $status,
                'number' => isset($a['per_page']) ? min(100, max(1, (int) $a['per_page'])) : 20,
                'paged'  => isset($a['page']) ? max(1, (int) $a['page']) : 1,
            );
            if (isset($a['post'])) { $args['post_id'] = (int) $a['post']; }

            // `search` is NOT passed to WP_Comment_Query: it hard-codes the columns
            // comment_author, comment_author_email, comment_author_url,
            // comment_author_IP and comment_content, with no filter to narrow them
            // (verified in class-wp-comment-query.php). A tool that says "emails
            // omitted" while letting a caller prefix-probe them by search does not
            // omit them. The clause is built here over the two safe columns instead,
            // which keeps the filtering - and therefore the pagination - in SQL.
            $search = isset($a['search']) ? trim((string) $a['search']) : '';
            $filter = null;
            if ($search !== '') {
                // WP_Comment_Query keys its cache on the query vars it RECOGNISES, and
                // a clause injected through comments_clauses is not one of them - so
                // with a persistent object cache an unfiltered call and a search with
                // the same status/number/paged would share a cached id list. No leak
                // (the read_post filter runs after the fetch) but the search would be
                // wrong. cache_domain is a recognised var that exists for exactly this.
                $args['cache_domain'] = 'wpmcp-search-' . md5($search);

                $filter = function ($clauses) use ($search) {
                    global $wpdb;
                    $like = '%' . $wpdb->esc_like($search) . '%';
                    $clauses['where'] .= $wpdb->prepare(
                        " AND ({$wpdb->comments}.comment_content LIKE %s"
                        . " OR {$wpdb->comments}.comment_author LIKE %s)",
                        $like,
                        $like
                    );
                    return $clauses;
                };
                add_filter('comments_clauses', $filter);
            }

            try {
                $cs = get_comments($args);
            } finally {
                if ($filter) { remove_filter('comments_clauses', $filter); }
            }

            $out = array();
            foreach ($cs as $c) {
                // A comment on a post the caller cannot read is a read of that post:
                // it leaks the post's existence plus author names, text and dates.
                // This is the same leak get-post closes, one indirection away.
                if (!current_user_can('read_post', (int) $c->comment_post_ID)) { continue; }

                $out[] = array('id' => (int) $c->comment_ID, 'post' => (int) $c->comment_post_ID,
                    'author_name' => $c->comment_author, 'content' => $c->comment_content,
                    'status' => wp_get_comment_status((int) $c->comment_ID), 'date' => $c->comment_date_gmt);
            }
            // count is what the caller may see, so it is smaller than per_page when
            // the page held comments on unreadable posts. Paging is still by per_page.
            return array('count' => count($out), 'items' => $out);
        },
    ),

    'moderate-comment' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Moderate a comment. Args: id (required), action (approve|unapprove|spam|trash|untrash).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'action' => array('type' => 'string'),
        ), 'required' => array('id', 'action')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            if (!$id || !get_comment($id)) { return new WP_Error('wpmcp_not_found', 'No comment with that ID.'); }
            // wp_set_comment_status and friends check nothing. moderate_comments is
            // the cap wp-admin requires for the whole moderation queue, and
            // edit_comment maps to it on the post's editors.
            if (!current_user_can('moderate_comments') && !current_user_can('edit_comment', $id)) {
                return wpmcp_cannot('moderate comment ' . $id);
            }
            $action = isset($a['action']) ? sanitize_key($a['action']) : '';
            $valid = array('approve', 'unapprove', 'spam', 'trash', 'untrash');
            if (!in_array($action, $valid, true)) { return new WP_Error('wpmcp_bad_action', 'Unknown action.'); }
            if ($action === 'trash')        { $ok = wp_trash_comment($id); }
            elseif ($action === 'untrash')  { $ok = wp_untrash_comment($id); }
            elseif ($action === 'spam')     { $ok = wp_spam_comment($id); }
            elseif ($action === 'unapprove'){ $ok = wp_set_comment_status($id, 'hold'); }
            else                            { $ok = wp_set_comment_status($id, 'approve'); }
            if (!$ok) { return new WP_Error('wpmcp_failed', 'Action failed.'); }
            return array('id' => $id, 'status' => wp_get_comment_status($id));
        },
    ),

    'reply-comment' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ),
        'description' => 'Reply to a comment. Args: id (required, parent comment), content (required).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'content' => array('type' => 'string'),
        ), 'required' => array('id', 'content')),
        'run' => function ($a) {
            $parent = isset($a['id']) ? (int) $a['id'] : 0;
            $pc = $parent ? get_comment($parent) : null;
            if (!$pc) { return new WP_Error('wpmcp_not_found', 'No parent comment.'); }
            $post_id  = (int) $pc->comment_post_ID;
            $moderator = current_user_can('moderate_comments');

            // Neither wp_insert_comment nor wp_new_comment checks a capability, and
            // neither checks comments_open: core's comments_open() guard lives in
            // wp_handle_comment_submission, the front-end path, which this never
            // reaches. read_post was too low a bar - this writes, it does not read.
            // wp-admin's reply requires edit_post on the post being replied on; match
            // that. These three gates stay IN FRONT of core's pipeline below, because
            // core's pipeline decides approval, not authorisation.
            //
            // not_found rather than a forbidden, and checked with read_post first, so a
            // caller who cannot even see the post does not learn the comment exists.
            if (!current_user_can('read_post', $post_id)) {
                return new WP_Error('wpmcp_not_found', 'No parent comment.');
            }
            if (!current_user_can('edit_post', $post_id)) {
                return wpmcp_cannot('reply to comments on post ' . $post_id);
            }

            // A closed thread is a decision someone made. A moderator may still reply
            // (wp-admin lets them); nobody else reopens it by calling a tool.
            if (!comments_open($post_id) && !$moderator) {
                return wpmcp_cannot('reply on post ' . $post_id . ', where comments are closed');
            }

            $u   = wp_get_current_user();
            $now = current_time('mysql');
            // Every key wp_new_comment() and wp_allow_comment() read is supplied,
            // including the ones core would otherwise fill from $_SERVER:
            // comment_author_IP goes through wpmcp_client_ip() so the proxy filter
            // applies, and comment_agent names this plugin rather than whatever
            // User-Agent the MCP client happened to send.
            $comment = array(
                'comment_post_ID'      => $post_id,
                'comment_parent'       => $parent,
                'comment_content'      => (string) $a['content'],
                'user_id'              => $u ? $u->ID : 0,
                'comment_author'       => $u ? $u->display_name : '',
                'comment_author_email' => $u ? $u->user_email : '',
                'comment_author_url'   => $u ? $u->user_url : '',
                'comment_author_IP'    => wpmcp_client_ip(),
                'comment_agent'        => 'wp-mcp/' . WPMCP_VER,
                'comment_date'         => $now,
                'comment_date_gmt'     => current_time('mysql', true),
                'comment_type'         => 'comment',
            );

            // CORE'S PIPELINE, not a hand-rolled one.
            //
            // This used to call wp_allow_comment() and then wp_insert_comment()
            // directly, and claimed in a commit message that "Akismet still applies".
            // It did not. Akismet, and every other spam or moderation plugin, hooks
            // `preprocess_comment` - applied in wp_new_comment()
            // (wp-includes/comment.php, first thing it does) - and `comment_post`,
            // which is where the moderator and post-author notification mails come
            // from. Neither fires from wp_insert_comment(). wp_allow_comment() alone
            // gives the blocklist, the moderation option, the duplicate check and the
            // flood check, and nothing else.
            //
            // comment_approved is deliberately NOT set here. wp_new_comment() ->
            // wp_allow_comment() -> wp_check_comment_data() already answers 1 for a
            // user who holds moderate_comments or who owns the post, and runs
            // everybody else past check_comment and the blocklist - so the two-branch
            // version of this collapsed into core's own decision, which is the one the
            // front end and the REST controller both use.
            //
            // $wp_error = true, so a duplicate (409) or a flood (429) comes back as a
            // WP_Error with its message instead of wp_die()ing inside a REST request.
            $cid = wp_new_comment($comment, true);

            if (is_wp_error($cid)) { return $cid; }
            if (!$cid) { return new WP_Error('wpmcp_failed', 'Could not create reply.'); }

            return array(
                'id'     => (int) $cid,
                'status' => wp_get_comment_status((int) $cid),
            );
        },
    ),

    );
}

/* ============================================================
 * Code-edit tools. Listed only when the switch in Settings > WP MCP is on AND neither
 * DISALLOW_FILE_EDIT nor DISALLOW_FILE_MODS is set - see wpmcp_code_constants_forbid(),
 * which endpoint.php's wpmcp_tools() asks before it merges these in. The third gate,
 * `edit_themes`, is per-caller and is checked by each run closure through
 * wpmcp_code_forbidden(); a tool that this token's user may not use is still LISTED,
 * because another token's user may.
 * ========================================================== */
function wpmcp_code_tools() {
    return array(

    'code-list' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List files/dirs in the active theme. Args: path (relative, default ""). Denylisted entries show blocked=true.',
        'inputSchema' => array('type' => 'object', 'properties' => array('path' => array('type' => 'string'))),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $root = wpmcp_code_root();
            if ($root === '') { return new WP_Error('wpmcp_no_theme', 'Active theme directory not found.'); }
            $rel = isset($a['path']) ? ltrim(str_replace('\\', '/', (string) $a['path']), '/') : '';
            if (strpos($rel, '..') !== false) { return new WP_Error('wpmcp_illegal', 'Illegal path.'); }
            $dir = $root . ($rel !== '' ? '/' . $rel : '');
            $abs = realpath($dir);
            if ($abs === false || !wpmcp_path_within($abs, $root) || !is_dir($abs)) {
                return new WP_Error('wpmcp_not_found', 'No such directory.');
            }
            // THE CANONICAL PREFIX, from the resolved directory and not from what the
            // caller typed - the same derivation wpmcp_code_resolve() makes, for the same
            // reason. `blocked` is a denylist answer, and the denylist matches a directory
            // rule by prefix: listing `./inc` and asking about `./inc/x.php` said
            // blocked=false for files code-read then refused. A label that disagrees with
            // the gate it describes is worse than no label, because an agent believes it.
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');

            $entries = array();
            foreach (scandir($abs) as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $full = $abs . '/' . $name;
                $erel = ($rel !== '' ? $rel . '/' : '') . $name;
                $entries[] = array(
                    'name' => $name, 'type' => is_dir($full) ? 'dir' : 'file',
                    'size' => is_file($full) ? filesize($full) : 0,
                    'blocked' => wpmcp_code_denied($erel),
                );
            }
            return array('path' => $rel, 'entries' => $entries);
        },
    ),

    'code-read' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Read a text file in the active theme. Args: path (required). Denylisted/binary/oversized files are refused.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $r = wpmcp_code_target($a, true);
            if (is_wp_error($r)) { return $r; }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('wpmcp_ext', 'Only text files may be read.'); }
            if (!is_file($r['abs'])) { return new WP_Error('wpmcp_not_found', 'Not a file.'); }
            if (filesize($r['abs']) > 524288) { return new WP_Error('wpmcp_too_big', 'File exceeds 512KB.'); }
            return array('path' => $r['rel'], 'content' => file_get_contents($r['abs']));
        },
    ),

    'code-write' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ),
        'description' => 'Create or overwrite a text file in the theme. Active theme only. Args: path (required), content (required). The previous contents are stored as a version first (see code-history); PHP is parse-checked and auto-reverted on a syntax error.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'path' => array('type' => 'string'), 'content' => array('type' => 'string'),
        ), 'required' => array('path', 'content')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $r = wpmcp_code_target($a, false);
            if (is_wp_error($r)) { return $r; }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('wpmcp_ext', 'Only text files may be written.'); }
            $content = isset($a['content']) ? (string) $a['content'] : '';
            if (strlen($content) > 524288) { return new WP_Error('wpmcp_too_big', 'Content exceeds 512KB.'); }

            // THE VERSION IS TAKEN BEFORE THE DISK CHANGES, and a file that is not there
            // has no previous contents, so it gets no row: a version of nothing is an
            // undo that restores an empty file.
            $existed  = is_file($r['abs']);
            $prior    = null;
            $versionId = null;
            if ($existed) {
                $saved = wpmcp_code_version_current($r['abs'], $r['rel'], 'write');
                if (is_wp_error($saved)) { return $saved; }
                $prior     = $saved['content'];
                $versionId = $saved['id'];
            }

            $bytes = file_put_contents($r['abs'], $content);
            if ($bytes === false) { return new WP_Error('wpmcp_write_failed', 'Could not write file.'); }

            $reverted = false; $perr = null;
            if (strtolower(pathinfo($r['rel'], PATHINFO_EXTENSION)) === 'php') {
                $chk = wpmcp_php_parse_ok($content);
                if ($chk !== true) {
                    $perr = $chk;
                    // FROM MEMORY, not from a file beside it. $prior is the byte string
                    // that was just stored, so the revert and the stored version cannot
                    // disagree, and nothing under the document root is involved.
                    if ($existed) { file_put_contents($r['abs'], $prior); } else { @unlink($r['abs']); }
                    $reverted = true;
                }
            }
            $out = array(
                'path' => $r['rel'], 'bytes' => $reverted ? 0 : (int) $bytes,
                'created' => ($existed ? false : !$reverted), 'reverted' => $reverted,
                'version_id' => $versionId,
            );
            if ($perr !== null) { $out['error'] = 'PHP parse error (reverted): ' . $perr; }
            return $out;
        },
    ),

    'code-delete' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Delete a file in the theme. Active theme only; its contents are stored as a version first, so code-history and code-restore can bring it back. Args: path (required).',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            $r = wpmcp_code_target($a, true);
            if (is_wp_error($r)) { return $r; }
            if (!is_file($r['abs'])) { return new WP_Error('wpmcp_not_found', 'Not a file.'); }

            // Stored first, and the delete does not happen if it could not be.
            $saved = wpmcp_code_version_current($r['abs'], $r['rel'], 'delete');
            if (is_wp_error($saved)) { return $saved; }

            // UNLINKED, not renamed. The rename left the whole file, readable, one
            // extension away, inside the document root - which is what the version store
            // exists to stop.
            if (!@unlink($r['abs'])) { return new WP_Error('wpmcp_delete_failed', 'Could not delete the file.'); }

            return array('path' => $r['rel'], 'deleted' => true, 'version_id' => $saved['id']);
        },
    ),

    'code-history' => array(
        // `write` IS THE ADMIN-SCOPE GATE in this plugin, which is why a tool that only
        // reads carries it - exactly as code-list and code-read do. The theme is source
        // code, not content, and a listing of who changed which file when is the shape of
        // it. readOnlyHint stays the inverse of the gate, so it is false here and the
        // README's paragraph about that covers one more row.
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'List stored versions of a theme file. Args: path (required). Newest first, with id, saved_at, size, sha256, reason (write, delete, restore or sweep) and saved_by. A path with no stored versions returns an empty list, which is not an error. Pass an id to code-restore to put that version back.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }
            // mustExist FALSE: a deleted file has versions and is the case most worth
            // asking about. The jail and the denylist still apply.
            $r = wpmcp_code_target($a, false);
            if (is_wp_error($r)) { return $r; }

            $versions = array();
            foreach (wpmcp_file_versions_for($r['rel']) as $row) {
                $versions[] = array(
                    'id'       => (int) $row->id,
                    'saved_at' => $row->saved_at,
                    'size'     => (int) $row->size,
                    'sha256'   => $row->sha256,
                    'reason'   => $row->reason,
                    // A LOGIN AND NOT AN EMAIL. The only audience for this listing is a
                    // person deciding what to put back, an id tells them nothing, and no
                    // other tool on this surface returns an address. See
                    // wpmcp_version_author_login() for the two values that are not logins.
                    'saved_by' => wpmcp_version_author_login((int) $row->saved_by),
                );
            }

            return array('path' => $r['rel'], 'versions' => $versions);
        },
    ),

    'code-restore' => array(
        'write' => true,
        'annotations' => array(
            'readOnlyHint' => false,
            // It overwrites a theme file with something else. That is the same act
            // code-write performs and it carries the same hint.
            'destructiveHint' => true,
            // False for code-write's reason: the file ends up the same, the history does
            // not - a second restore stores another version of what it replaced.
            'idempotentHint' => false,
            'openWorldHint' => false,
        ),
        'description' => 'Restore a stored version of a theme file. Args: version_id (required), from code-history. Writes the stored bytes back to the path the version was taken from; the current contents are stored as a version first. PHP is parse-checked and auto-reverted on a syntax error. Returns path, bytes, sha256 and whether the bytes written match the stored hash.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('version_id' => array('type' => 'integer')), 'required' => array('version_id')),
        'run' => function ($a) {
            $denied = wpmcp_code_forbidden();
            if ($denied) { return $denied; }

            $row = wpmcp_file_version_get(isset($a['version_id']) ? (int) $a['version_id'] : 0);
            if (!$row) { return new WP_Error('wpmcp_not_found', 'No such version. Call code-history for the ids of a path.'); }

            // A VERSION BELONGS TO THE THEME IT WAS TAKEN FROM. The jail is "the active
            // theme", so the row's `path` means a different file once the theme changes -
            // and restoring it would write one theme's bytes into another theme's file
            // under the same name. Refused, and the message says which theme it wants,
            // because "no such version" would be a lie about a row that plainly exists.
            $theme = (string) get_stylesheet();
            if ((string) $row->theme !== $theme) {
                return new WP_Error(
                    'wpmcp_other_theme',
                    'That version was taken from the theme "' . $row->theme . '", and the active theme is "'
                    . $theme . '". Switch to that theme to restore it.'
                );
            }

            // THE STORED PATH GOES THROUGH THE SAME JAIL AND DENYLIST AS A CALLER'S,
            // rather than being trusted because this server wrote it. A row is a value in
            // a database, and the denylist can be widened after a version was stored - in
            // which case restoring it is exactly what the operator has just forbidden.
            $r = wpmcp_code_target(array('path' => $row->path), false);
            if (is_wp_error($r)) { return $r; }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('wpmcp_ext', 'Only text files may be written.'); }

            $content = (string) $row->content;

            $existed   = is_file($r['abs']);
            $prior     = null;
            $versionId = null;
            if ($existed) {
                $saved = wpmcp_code_version_current($r['abs'], $r['rel'], 'restore');
                if (is_wp_error($saved)) { return $saved; }
                $prior     = $saved['content'];
                $versionId = $saved['id'];
            }

            $bytes = file_put_contents($r['abs'], $content);
            if ($bytes === false) { return new WP_Error('wpmcp_write_failed', 'Could not write file.'); }

            $reverted = false; $perr = null;
            if (strtolower(pathinfo($r['rel'], PATHINFO_EXTENSION)) === 'php') {
                $chk = wpmcp_php_parse_ok($content);
                if ($chk !== true) {
                    $perr = $chk;
                    if ($existed) { file_put_contents($r['abs'], $prior); } else { @unlink($r['abs']); }
                    $reverted = true;
                }
            }

            // HASHED FROM THE DISK, not from the string that was about to be written.
            // "the bytes match the stored version" is a claim about the file, and hashing
            // the variable would make it a claim about this function's own arithmetic.
            $written = $reverted ? null : @file_get_contents($r['abs']);
            $sha     = is_string($written) ? hash('sha256', $written) : '';

            $out = array(
                'path'       => $r['rel'],
                'bytes'      => $reverted ? 0 : (int) $bytes,
                'sha256'     => $sha,
                'matched'    => ($sha !== '' && $sha === $row->sha256),
                'created'    => ($existed ? false : !$reverted),
                'reverted'   => $reverted,
                'version_id' => $versionId,
            );
            if ($perr !== null) { $out['error'] = 'PHP parse error (reverted): ' . $perr; }
            return $out;
        },
    ),

    );
}

/**
 * The name to show against a stored version: a login, never an email address.
 *
 * Two values are not logins and say so plainly. `system` is saved_by 0, which only the
 * upgrade sweep writes - nobody did that, the upgrade did. `(deleted user 7)` is a row
 * whose author has since been removed from the site, which is worth seeing rather than
 * silently blank: it is the difference between "nobody" and "somebody who is gone".
 */
function wpmcp_version_author_login($userId) {
    if ($userId <= 0) { return 'system'; }

    $user = get_userdata($userId);

    return $user ? $user->user_login : '(deleted user ' . $userId . ')';
}

/* ============================================================
 * sql-select. A read-only SQL window onto this site's database.
 *
 * ONE TOOL, ONE SWITCH, AND NO PARSER. The tool hands the caller's statement to the
 * database inside a wrapper that makes anything other than a single SELECT a SERVER
 * syntax error, and runs it inside a READ ONLY transaction that makes a write the
 * server refuses even when the wrapper lets the syntax through. Both walls are the
 * server's own; nothing here inspects the SQL to decide whether it is safe, because a
 * SQL parser written in PHP is a second, worse implementation of MySQL's grammar and
 * every one of them has been walked around.
 *
 * WHAT WAS MEASURED, on MySQL 8.4.0 (jaygroup, WordPress 7.1, PHP 8.2.29), wrapping
 * each statement as `SELECT * FROM (<sql>) AS wpmcp_q LIMIT 201`:
 *
 *   INTO OUTFILE / INTO DUMPFILE / INTO @var   1064, syntax error.
 *   `SELECT 1; DROP TABLE x`                   1064 - the semicolon cannot appear there,
 *                                              and WordPress talks to mysqli through
 *                                              mysqli_query(), which carries one
 *                                              statement at a time in the first place.
 *   `UPDATE ...`, `DELETE ...`, `SHOW TABLES`  1064 - a derived table must be a query
 *                                              expression, and none of those is one.
 *   `SELECT ... FOR UPDATE`                    ACCEPTED BY THE WRAPPER. This is the one
 *                                              the design expected to be a syntax error
 *                                              and it is not: MySQL 8.4 parses a locking
 *                                              read inside a derived table. It is
 *                                              refused by the OTHER wall - 1792, "Cannot
 *                                              execute statement in a READ ONLY
 *                                              transaction" - which is exactly why there
 *                                              are two walls and not one.
 *   `SELECT ... LOCK IN SHARE MODE`            accepted by both walls. It takes shared
 *                                              locks and writes nothing; the session
 *                                              timeout below bounds how long it can hold
 *                                              them, and ROLLBACK releases them.
 *   `WITH c AS (...) SELECT * FROM c`          works inside the wrapper.
 *   an inner `ORDER BY`                        honoured, with derived_merge on AND off.
 *                                              The merge-drops-ORDER-BY behaviour this
 *                                              was expected to need `derived_merge=off`
 *                                              for did NOT reproduce on 8.4.0. The
 *                                              switch is set anyway: it is documented
 *                                              server behaviour on earlier 8.0.x, it
 *                                              costs one round trip, and an ordering a
 *                                              caller asked for and silently did not get
 *                                              is the kind of wrong answer nobody checks.
 *   duplicate column names                     1060. `SELECT p.ID, m.post_id AS ID ...`
 *                                              is legal on its own and illegal as a
 *                                              derived table, because a derived table's
 *                                              columns must be uniquely named. A real
 *                                              limitation of the wrapper; the caller
 *                                              aliases one of them and moves on.
 *
 * THE DATABASE USER CAN READ EVERYTHING, and that is the point to be honest about: this
 * tool reads whatever the WordPress database user can read, `wp_users` and its password
 * hashes included. It is off by default, admin-scope only, and the switch is in
 * Settings > WP MCP next to code editing. See SECURITY.md.
 * ========================================================== */

/** Rows returned at most. One more than this is FETCHED, and that one sets `truncated`. */
define('WPMCP_SQL_ROW_CAP', 200);

/** Bytes of JSON-encoded rows returned at most. */
define('WPMCP_SQL_BYTE_CAP', 262144);

/** Bytes of one cell returned at most; past this it is cut and suffixed with an ellipsis. */
define('WPMCP_SQL_CELL_CAP', 8192);

/** How long the server may spend on the statement. */
define('WPMCP_SQL_TIMEOUT_MS', 5000);

function wpmcp_sql_enabled() {
    return (bool) get_option('wpmcp_sql_enabled', false);
}

/**
 * May the current user read the database? Null when yes, a WP_Error when no.
 *
 * NOT IN SPRINT 9'S BRIEF, AND ADDED ANYWAY, because this plugin has already paid for
 * the lesson once. The brief's gates are the switch plus admin scope, and admin SCOPE is
 * not an administrator: wpmcp_mint() takes an owner, so an administrator can mint an
 * admin-scope token that "Runs as" an Editor, and that is exactly the shape sprint 8
 * found in front of the code tools - they were gated on scope and the option alone, so
 * an Editor-bound admin token could read and rewrite the active theme. See
 * wpmcp_code_forbidden(), whose docblock is the same paragraph.
 *
 * The capability is `manage_options`, which is the one wp-admin requires to reach the
 * settings page this tool's own switch lives on. A caller who may not look at the
 * plugin's settings has no business reading every row of every table it can see -
 * wp_users and its password hashes included.
 *
 * PER-CALLER, AND SO IT IS NOT A LISTING CONDITION. wpmcp_tools() lists the tool on the
 * site-wide switch and this refuses per token, the same split the code tools make: the
 * registry is built once per request but the switch is the same answer for everybody,
 * while `manage_options` is not.
 */
function wpmcp_sql_forbidden() {
    if (!current_user_can('manage_options')) {
        return wpmcp_cannot('read this site\'s database');
    }

    return null;
}

/**
 * What the server calls itself. Asked once per request - it is a property of the
 * connection, it cannot change under us, and on some drivers it costs a round trip.
 */
function wpmcp_sql_server_info() {
    static $info = null;

    if ($info === null) {
        global $wpdb;
        $info = is_object($wpdb) && method_exists($wpdb, 'db_server_info')
            ? (string) $wpdb->db_server_info()
            : '';
    }

    return $info;
}

/**
 * The session statement-timeout statement for a server that describes itself this way.
 *
 * THE TWO FLAVOURS DO NOT KNOW EACH OTHER'S NAME FOR IT, and that is measured rather
 * than assumed: `SET SESSION max_statement_time = 5` is 1193 "Unknown system variable"
 * on MySQL 8.4, and MySQL's MAX_EXECUTION_TIME does not exist on MariaDB. One spelling
 * would therefore leave one of the two flavours with no statement timeout at all - and
 * silently, because a failed SET only leaves a string in last_error. MySQL counts
 * MILLISECONDS, MariaDB counts SECONDS. mysqli reports MariaDB as something like
 * `5.5.5-10.6.12-MariaDB`, so the name is in the string either way.
 *
 * PURE, AND TAKING THE DESCRIPTION AS AN ARGUMENT, so both branches can be tested from
 * a machine with only one of the two servers on it. Nobody on this project has reached
 * a MariaDB; the MySQL branch is measured on 8.4.0 and the MariaDB branch is this
 * function plus its test.
 */
function wpmcp_sql_timeout_statement($serverInfo) {
    if (stripos((string) $serverInfo, 'mariadb') !== false) {
        return 'SET SESSION max_statement_time = ' . (WPMCP_SQL_TIMEOUT_MS / 1000);
    }

    return 'SET SESSION MAX_EXECUTION_TIME = ' . (int) WPMCP_SQL_TIMEOUT_MS;
}

/**
 * The name of that variable on this flavour, so its prior value can be read back and
 * restored. Same branch, same pure shape, one source of truth for the spelling.
 */
function wpmcp_sql_timeout_variable($serverInfo) {
    return stripos((string) $serverInfo, 'mariadb') !== false
        ? 'max_statement_time'
        : 'MAX_EXECUTION_TIME';
}

/**
 * Put the two session variables back the way they were found.
 *
 * WHY IT IS WORTH THE TWO ROUND TRIPS. $wpdb is the connection WordPress uses for the rest
 * of the request - every option write, every `WP_Query`, every other plugin's query. Left
 * as this tool sets them, a 5-second server-side cap applies to every later SELECT and
 * every later plan is built with `derived_merge` off. Neither is likely to break a page,
 * and "the request ends in milliseconds anyway" was the first version's reasoning; but a
 * tool that silently changes how unrelated queries are planned and timed is a tool that
 * will one day be the answer to a bug nobody can reproduce.
 *
 * BEST EFFORT, AND SILENT ON FAILURE. A null prior value means the read did not work -
 * an unexpected flavour, a proxy - and there is nothing honest to restore. The values are
 * re-validated on the way back in even though they came from the server: they are being
 * concatenated into SQL, and "it came from the database" is the sentence in front of most
 * second-order injections. The timeout is a number or nothing; the switch goes through
 * prepare().
 */
function wpmcp_sql_restore_session($variable, $priorTimeout, $priorSwitch) {
    global $wpdb;

    if ($priorTimeout !== null && preg_match('/^[0-9]+(\.[0-9]+)?$/', (string) $priorTimeout) === 1) {
        $wpdb->query('SET SESSION ' . $variable . ' = ' . (string) $priorTimeout);
    }

    if ($priorSwitch !== null && preg_match('/^[A-Za-z0-9_=,]+$/', (string) $priorSwitch) === 1) {
        $wpdb->query($wpdb->prepare('SET SESSION optimizer_switch = %s', (string) $priorSwitch));
    }
}

/**
 * The identifiers this tool refuses to see, ANYWHERE in the statement, case-insensitively.
 *
 * THIS AND wpmcp_sql_denied_functions() ARE THE WHOLE OF THE STRING INSPECTION IN THIS
 * TOOL - two table names and one function name - AND THEY EXIST BECAUSE THE SERVER CANNOT
 * MAKE THESE DECISIONS. Everything else the tool refuses is refused by MySQL itself - the
 * wrapper's grammar, the READ ONLY transaction, the statement timeout. But the WordPress
 * database user owns the token table and the file-version table: it created them and it
 * can read them, and there is no GRANT this plugin can issue on its own connection to
 * take that away. So the one thing the server will happily do and must not is read the
 * table of token hashes and the table of theme-file bytes, and the only place that can be
 * stopped is here, before the statement is sent. `LOAD_FILE()` is the same shape of
 * problem with a different subject and lives in the other function.
 *
 * NOTHING IS STRIPPED FIRST. No comments removed, no strings skipped, no tokenising: a
 * mention of either name inside a comment or inside a string literal refuses the whole
 * statement. That over-refuses - `SELECT 'wpmcp_tokens' AS label` is harmless and is
 * refused - and over-refusal is the safe direction, because the alternative is a
 * comment-stripper that has to be exactly as correct as MySQL's lexer to be worth
 * anything. It is documented in README.md and in the tool's own refusal message.
 *
 * The bare constants are matched as well as the prefixed names. A prefix is a prefix, so
 * `wp_wpmcp_tokens` contains `wpmcp_tokens` and the bare form already subsumes it - the
 * prefixed names are listed too so that the rule reads as what it is rather than as a
 * substring trick, and so it keeps holding if a constant is ever renamed.
 *
 * @return array
 */
function wpmcp_sql_denied_identifiers() {
    return array_values(array_unique(array(
        wpmcp_table(),
        wpmcp_versions_table(),
        WPMCP_TABLE,
        WPMCP_VERSIONS_TABLE,
    )));
}

/**
 * SQL functions refused by name for the same reason the two tables are: the server will
 * run them and the server cannot be told not to.
 *
 * `LOAD_FILE()` READS A FILE OFF THE SERVER'S DISK AND BOTH WALLS LET IT THROUGH. It is a
 * valid query expression, so the derived-table wrapper accepts it, and it is a read, so
 * `START TRANSACTION READ ONLY` accepts it too - measured through the exact wrapper on
 * MySQL 8.4.0, errno 0 from both. Whether bytes actually come back is then decided by
 * `secure_file_priv` and the database user's `FILE` privilege, neither of which this
 * plugin controls and both of which are the operator's to get right. On a host where
 * `secure_file_priv` is empty or points somewhere useful, `SELECT LOAD_FILE('.../wp-config.php')`
 * hands over the database password and every salt, and the tool's own docs would have
 * promised the blast radius was "the database".
 *
 * `INTO OUTFILE` and `INTO DUMPFILE` - the WRITE side of the same privilege - are already
 * 1064 inside the wrapper, because they are not part of a query expression. `LOAD_FILE` is
 * the read side and it is the one file-touching function reachable inside a SELECT
 * expression, which is why one entry closes it rather than a list.
 *
 * THIS IS NOT A COMPLETE FILE-READ BOUNDARY AND MUST NOT BE DOCUMENTED AS ONE. It is one
 * name, refused bluntly, on a surface whose real fence is the server; an operator who
 * cares should still pin `secure_file_priv` or deny the database user `FILE`. SECURITY.md
 * says exactly that.
 *
 * Matched by the same rule as the table names: anywhere in the statement, case-insensitive,
 * with nothing stripped first. So a column called `payload_load_file` is refused too. Same
 * deliberate over-refusal, same reason - the alternative is a lexer.
 *
 * @return array
 */
function wpmcp_sql_denied_functions() {
    return array('load_file');
}

/**
 * One cell on its way to the wire: null stays null, invalid UTF-8 becomes hex, long is cut.
 *
 * WHY HEX AND NOT THE BYTES. wp_json_encode() does not fail on a value that is not valid
 * UTF-8 and it does not return null for it either - measured on this stack, the four
 * bytes `61 80 62 63` come back as `"a?bc"`, the bad byte silently replaced by a question
 * mark by WordPress's own _wp_json_convert_string() sanity pass. A caller reading a
 * `longblob`, a serialised option written by a plugin in latin1, or a hash column would
 * therefore be handed something that looks like text and is not the data. `0x`-prefixed
 * uppercase hex is unambiguous, round-trips, and is what every database client shows for
 * a binary value.
 *
 * THE CUT IS IN BYTES AND THEN REPAIRED. WPMCP_SQL_CELL_CAP is a byte budget - one
 * `longtext` column must not be able to become the whole response - and a byte cut lands
 * in the middle of a multibyte character often enough to matter, which would hand
 * wp_json_encode() exactly the invalid string this function exists to prevent. So the
 * trailing partial sequence is dropped (at most three bytes) before the ellipsis is added.
 */
function wpmcp_sql_cell($value) {
    if ($value === null) { return null; }

    $text = (string) $value;

    if ($text !== '' && preg_match('//u', $text) !== 1) {
        $text = '0x' . strtoupper(bin2hex($text));
    }

    if (strlen($text) > WPMCP_SQL_CELL_CAP) {
        $text = substr($text, 0, WPMCP_SQL_CELL_CAP);

        while ($text !== '' && preg_match('//u', $text) !== 1) {
            $text = substr($text, 0, -1);
        }

        $text .= "\xE2\x80\xA6";
    }

    return $text;
}

/**
 * The mysqli error number of the last statement, or 0 when it cannot be reached.
 *
 * THE NUMBER IS THE ONE THING THE CLIENT GETS. The server's message is not fit for the
 * wire - 1054 names a column, 1064 quotes the statement back, and either can carry a
 * table name, a path or a value out of somebody's database - but the NUMBER is a closed,
 * public vocabulary and it is what an agent needs in order to do something other than
 * retry: 1064 means rewrite the SQL, 1054 means the column is not there, 1060 means alias
 * the duplicate, 1792 means the statement tried to write, 3024 means it was too slow.
 *
 * $wpdb does not expose it. `last_error` is a string and `dbh` is the mysqli handle, so
 * the number is read from there, defensively: a site on a $wpdb replacement (HyperDB,
 * LudicrousDB, SQLite) may have no mysqli object at all, in which case the client gets
 * the trace id alone and the operator finds the rest in the log.
 */
function wpmcp_sql_errno() {
    global $wpdb;

    if (is_object($wpdb) && isset($wpdb->dbh) && class_exists('mysqli') && $wpdb->dbh instanceof mysqli) {
        return (int) $wpdb->dbh->errno;
    }

    return 0;
}

/**
 * Run one statement and shape the answer. A WP_Error is a refusal the caller can act on.
 *
 * THE ORDER IS THE DESIGN. Session caps, then READ ONLY, then the wrapped statement, then
 * ROLLBACK in a `finally` - and the ROLLBACK is the part that is not optional. $wpdb is
 * reused for the rest of the request: every option write, every post save, everything
 * WordPress does after this tool returns runs on the same connection, and a connection
 * left inside a READ ONLY transaction fails all of it with 1792. The `finally` is what
 * makes that true after a throw as well as after an error.
 *
 * AND THE TWO SESSION VARIABLES GO BACK WITH IT. Their prior values are read - two
 * `get_var`s - before either is changed, and wpmcp_sql_restore_session() puts them back in
 * the same `finally`, AFTER the ROLLBACK: a restore issued while still inside the
 * transaction would make the clean-up depend on the thing it is cleaning up after. The
 * first version of this left them set and argued that both are session-scoped and the
 * request ends in milliseconds anyway. True, and beside the point: for those milliseconds
 * every remaining WordPress SELECT ran under a 5-second server cap and every remaining
 * plan was built with derived_merge off, which is this tool changing how somebody else's
 * query behaves. Found by review. Two round trips is the right price.
 *
 * A FAILED `SET` IS NOT THE CALLER'S ERROR. An exotic server that does not know one of
 * these variables leaves its complaint in $wpdb->last_error, which the code below would
 * otherwise read as the statement's own failure; last_error is therefore cleared after the
 * preamble. The caps are best-effort - the wrapper and the transaction are the gate.
 *
 * @return array|WP_Error
 */
function wpmcp_sql_select_run($sql) {
    global $wpdb;

    $denied = wpmcp_sql_forbidden();
    if ($denied) { return $denied; }

    // A human types `SELECT 1;`. One trailing semicolon and the whitespace around it, and
    // nothing else - no comment stripping, no normalisation. Anything further would be
    // this file deciding what the statement means, which is the job it refuses to take.
    $statement = preg_replace('/;\s*$/', '', trim((string) $sql));
    $statement = $statement === null ? '' : trim($statement);

    if ($statement === '') {
        return new WP_Error('wpmcp_sql_empty', 'The sql argument is empty.');
    }

    foreach (wpmcp_sql_denied_identifiers() as $identifier) {
        if (stripos($statement, $identifier) !== false) {
            return new WP_Error(
                'wpmcp_sql_denied',
                "The plugin's own tables cannot be read: the statement mentions "
                . $identifier . '. That rule matches the name anywhere in the statement,'
                . ' including inside a comment or a string literal.'
            );
        }
    }

    foreach (wpmcp_sql_denied_functions() as $function) {
        if (stripos($statement, $function) !== false) {
            return new WP_Error(
                'wpmcp_sql_denied',
                'SQL functions that read the server filesystem cannot be used: the'
                . ' statement mentions ' . strtoupper($function) . '(). That rule matches'
                . ' the name anywhere in the statement, including inside a comment or a'
                . ' string literal.'
            );
        }
    }

    $wrapped ='SELECT * FROM (' . $statement . ') AS wpmcp_q LIMIT ' . (int) (WPMCP_SQL_ROW_CAP + 1);

    $started       = microtime(true);
    $suppressed    = $wpdb->suppress_errors(true);
    $inTransaction = false;
    $rows          = array();
    $columns       = array();
    $error         = '';
    $errno         = 0;
    $thrown        = null;

    $timeoutVariable = wpmcp_sql_timeout_variable(wpmcp_sql_server_info());
    $priorTimeout    = null;
    $priorSwitch     = null;

    try {
        // READ BOTH BEFORE CHANGING EITHER, so the `finally` can put them back. $wpdb is
        // shared with the rest of the request: without this, every later WordPress SELECT
        // ran under a 5-second server cap and with derived_merge off, which is this tool
        // quietly changing how somebody else's query is planned.
        $priorTimeout = $wpdb->get_var('SELECT @@SESSION.' . $timeoutVariable);
        $priorSwitch  = $wpdb->get_var('SELECT @@SESSION.optimizer_switch');

        $wpdb->query(wpmcp_sql_timeout_statement(wpmcp_sql_server_info()));
        $wpdb->query("SET SESSION optimizer_switch = 'derived_merge=off'");
        $wpdb->last_error = '';

        $wpdb->query('START TRANSACTION READ ONLY');
        $inTransaction    = true;
        $wpdb->last_error = '';

        // THE CAPS BOUND THE RESPONSE, NOT THE FETCH. get_results() materialises every
        // returned cell at full width before wpmcp_sql_cell() trims one of them, so the
        // 8 KiB and 256 KiB budgets shape what goes on the wire and not what goes into PHP
        // memory. The wrapper's LIMIT holds the row count to 201; what bounds the width is
        // the server's own `max_allowed_packet` per value, and what bounds the time spent
        // building it is the statement timeout set above. Streaming with an unbuffered
        // query is the real fix and is in analysis/BACKLOG.md; the caller is already an
        // administrator spending their own request, which is why it is parked there.
        $rows  = (array) $wpdb->get_results($wrapped, ARRAY_N);
        $error = (string) $wpdb->last_error;
        $errno = wpmcp_sql_errno();

        if ($error === '') {
            // POSITIONAL, not associative. $wpdb->get_results(..., ARRAY_A) would collapse
            // two columns of the same name into one and say nothing; ARRAY_N plus the
            // column list keeps the shape the server actually returned.
            $columns = array_map('strval', (array) $wpdb->get_col_info('name'));
        }
    } catch (Throwable $e) {
        $thrown = $e;
    } finally {
        // WHATEVER HAPPENED. See the docblock: the rest of the request shares this handle.
        if ($inTransaction) { $wpdb->query('ROLLBACK'); }

        wpmcp_sql_restore_session($timeoutVariable, $priorTimeout, $priorSwitch);

        // The ROLLBACK and the two restores are this function's own statements. Whatever
        // they left in last_error is not an answer to anybody's question - $error was
        // captured inside the try, before any of them ran - and leaving it set would hand
        // the next reader of last_error a failure that is not theirs.
        $wpdb->last_error = '';
        $wpdb->suppress_errors($suppressed);
    }

    if ($thrown !== null) {
        return wpmcp_sql_failure(wpmcp_trace($thrown, 'tools/call', 'sql-select'), 0);
    }

    if ($error !== '') {
        // The server's sentence and the statement go to the private log and nowhere else.
        $detail = new WP_Error('wpmcp_sql_server', $error, array(
            'errno'     => $errno,
            'statement' => $statement,
        ));

        return wpmcp_sql_failure(
            wpmcp_trace_wp_error($detail, 'tools/call', 'sql-select'),
            $errno
        );
    }

    $out         = array();
    $bytes       = 0;
    $truncated   = false;
    $truncatedBy = '';
    $total       = count($rows);

    foreach (array_values($rows) as $index => $row) {
        if (count($out) >= WPMCP_SQL_ROW_CAP) {
            // The 201st row was fetched for exactly this: it is the evidence that there
            // was more, without a second COUNT(*) over the caller's statement.
            $truncated   = true;
            $truncatedBy = 'rows';
            break;
        }

        $cells = array();

        foreach ((array) $row as $value) { $cells[] = wpmcp_sql_cell($value); }

        $out[]  = $cells;
        $bytes += strlen((string) wp_json_encode($cells));

        // Only when something was actually left behind. A last row that tips the budget
        // with nothing after it has truncated nothing, and saying otherwise would send an
        // agent looking for a page that does not exist.
        if ($bytes > WPMCP_SQL_BYTE_CAP && $index + 1 < $total) {
            $truncated   = true;
            $truncatedBy = 'bytes';
            break;
        }
    }

    $result = array(
        'columns'   => $columns,
        'rows'      => $out,
        'row_count' => count($out),
        'truncated' => $truncated,
    );

    if ($truncated) { $result['truncated_by'] = $truncatedBy; }

    $session = isset($GLOBALS['wpmcp_session']) ? $GLOBALS['wpmcp_session'] : null;

    wpmcp_auth_event('sql_select', array(
        'token_id'   => $session ? (int) $session->id : 0,
        'user_id'    => $session ? (int) $session->user_id : 0,
        'row_count'  => count($out),
        'truncated'  => $truncated,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        // THE FIRST 200 CHARACTERS AND NO MORE. An operator reading the log needs to
        // recognise the query; the whole of it can carry a value out of the database into
        // a log that is not the trace log, and the full statement is already in the trace
        // log on the only path where it is worth having.
        'sql'        => function_exists('mb_substr') ? mb_substr($statement, 0, 200) : substr($statement, 0, 200),
    ));

    return $result;
}

/** The one sentence a failed statement puts on the wire. See wpmcp_sql_errno(). */
function wpmcp_sql_failure($traceId, $errno) {
    $number = $errno > 0 ? ' Database error number ' . (int) $errno . '.' : '';

    return new WP_Error(
        'wpmcp_sql_failed',
        'The database refused the statement.' . $number
        . ' Trace id: ' . $traceId . '.'
    );
}

/**
 * The group. Listed only when the switch in Settings > WP MCP is on - endpoint.php's
 * wpmcp_tools() asks wpmcp_sql_enabled() before it merges this in, so with the switch off
 * the tool is absent from tools/list AND tools/call answers the same "Unknown tool"
 * -32602 it answers for a name that was never registered. There is no third answer that
 * would tell a caller the tool exists but is switched off, because that is a fact about
 * this site's configuration and a caller who may not use it has no business learning it.
 */
function wpmcp_sql_tools() {
    return array(

    'sql-select' => array(
        'write' => true,
        'annotations' => array(
            // FALSE, AND IT IS THE HOUSE RULE RATHER THAN A CLAIM ABOUT THE TOOL.
            // readOnlyHint is the inverse of `write` throughout this plugin - `write` IS
            // the admin-scope gate, the two are one fact, and code-list, code-read and
            // code-history already report false for the same reason despite only
            // reading. Sprint 9's brief asked for true here; that would have made this
            // the one tool whose two declarations disagree, gone red on
            // ToolContractTest::testReadOnlyHintIsDerivedFromTheWriteFlag, and
            // contradicted the paragraph in README.md that states the rule. The
            // description says in words that the tool only reads, which is where a model
            // actually reads it, and destructiveHint: false carries the safety claim.
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Run one read-only SQL SELECT. Args: sql (required). The statement is wrapped as a derived table inside a READ ONLY transaction, so anything but a single SELECT is a server syntax error. CTEs, joins, UNION and ORDER BY work; SHOW, stacked statements, INTO OUTFILE and FOR UPDATE do not, and a derived table needs unique column names. Returns JSON: columns, rows, row_count, truncated, truncated_by. At most 200 rows, 256KB of rows and 8KB per cell (cut with an ellipsis). The plugin\'s own tables and LOAD_FILE are refused, even when named only in a comment. NULL is null; a non-UTF-8 value comes back as 0x-prefixed hex.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('sql' => array('type' => 'string')), 'required' => array('sql')),
        'run' => function ($a) {
            return wpmcp_sql_select_run(isset($a['sql']) ? (string) $a['sql'] : '');
        },
    ),

    );
}
