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
        'list-posts' => array(
            'write' => false,
            'annotations' => array(
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ),
            'description' => 'List recent content the caller is allowed to see. Args: post_type (default "post"), status (default: every status the caller may see), limit (default 20, max 100).',
            'inputSchema' => array('type' => 'object', 'properties' => array(
                'post_type' => array('type' => 'string'),
                'status'    => array('type' => 'string'),
                'limit'     => array('type' => 'integer'),
            )),
            'run' => function ($args) {
                $type = isset($args['post_type']) ? sanitize_key($args['post_type']) : 'post';
                // Same allow-list the write tools use: no revisions, no nav_menu_item,
                // no wp_template, no attachments (media has its own tools).
                if (!wpmcp_post_type_ok($type)) {
                    return new WP_Error('wpmcp_bad_type', 'Not a listable post type: ' . $type);
                }
                $limit = isset($args['limit']) ? min(100, max(1, (int) $args['limit'])) : 20;

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

                $posts = array();
                if ($permitted) {
                    $q = new WP_Query(array(
                        'post_type'      => $type,
                        'post_status'    => $permitted,
                        'posts_per_page' => $limit,
                        'no_found_rows'  => true,
                        // Belt and braces over the list above: on an explicit status
                        // list this also scopes `private` to the user's own posts when
                        // they lack read_private_posts.
                        'perm'           => 'readable',
                    ));
                    $posts = $q->posts;
                }
                // Own unpublished work, which the first query cannot reach - see
                // wpmcp_own_listable_statuses(). Disjoint status sets, so no duplicates.
                if ($own) {
                    $q2 = new WP_Query(array(
                        'post_type'      => $type,
                        'post_status'    => $own,
                        'author'         => get_current_user_id(),
                        'posts_per_page' => $limit,
                        'no_found_rows'  => true,
                    ));
                    $posts = array_merge($posts, $q2->posts);
                    // Re-impose WP_Query's own ordering across the merge, then the
                    // limit, so `limit` still means what it says.
                    //
                    // post_date, NOT post_date_gmt. Every status registered with
                    // date_floating - draft, pending, auto-draft - is stored by
                    // wp_insert_post with post_date_gmt AND post_modified_gmt set to
                    // '0000-00-00 00:00:00' (measured on WP 7.1; only the non-GMT
                    // columns are populated). Sorting on either GMT column therefore
                    // puts every own draft behind every dated post, and the slice
                    // below drops them first - so on any site with `limit` published
                    // posts or more, the own-draft case this merge exists for failed.
                    // post_date is also the column WP_Query's own `orderby => date`
                    // uses, so both halves stay in the order they arrived in.
                    usort($posts, function ($a, $b) {
                        $cmp = strcmp((string) $b->post_date, (string) $a->post_date);
                        return $cmp !== 0 ? $cmp : ((int) $b->ID - (int) $a->ID);
                    });
                    $posts = array_slice($posts, 0, $limit);
                }

                $items = array();
                foreach ($posts as $p) {
                    $items[] = array(
                        'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                        'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink($p),
                    );
                }
                return array('count' => count($items), 'items' => $items);
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
            'description' => 'Get title/status/raw content for a post or page. Args: id (integer, required).',
            'inputSchema' => array('type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
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
                return array(
                    'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                    'status' => $p->post_status, 'slug' => $p->post_name, 'content' => $p->post_content,
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
        'description' => 'Create a post or page. Args: title, content, post_type (default post), status (default draft), excerpt, slug, terms {taxonomy:[id or name]}.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'title' => array('type' => 'string'), 'content' => array('type' => 'string'),
            'post_type' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
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
            if (isset($a['excerpt'])) { $postarr['post_excerpt'] = (string) $a['excerpt']; }
            if (isset($a['slug']))    { $postarr['post_name'] = sanitize_title((string) $a['slug']); }
            $id = wp_insert_post($postarr, true);
            if (is_wp_error($id)) { return $id; }
            $out = array('id' => (int) $id, 'link' => get_permalink($id));
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                // Reported, not swallowed: a caller that asked for three categories
                // and got two has to be able to see which one did not happen.
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }
            $p = get_post($id);
            $out['status'] = $p ? $p->post_status : null;
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
        'description' => 'Update a post/page. Args: id (required) plus any of title, content, status, excerpt, slug, terms. Set status=publish to publish.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'title' => array('type' => 'string'),
            'content' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
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
            if (isset($a['excerpt'])) { $upd['post_excerpt'] = (string) $a['excerpt']; $changed[] = 'excerpt'; }
            if (isset($a['slug']))    { $upd['post_name'] = sanitize_title((string) $a['slug']); $changed[] = 'slug'; }
            $r = wp_update_post($upd, true);
            if (is_wp_error($r)) { return $r; }
            $out = array('id' => $id, 'link' => get_permalink($id));
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                $changed[] = 'terms';
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }
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
 * The identifiers this tool refuses to see, ANYWHERE in the statement, case-insensitively.
 *
 * THIS IS THE ONLY STRING INSPECTION IN THE TOOL AND IT EXISTS BECAUSE THE SERVER CANNOT
 * MAKE THIS DECISION. Everything else the tool refuses is refused by MySQL itself - the
 * wrapper's grammar, the READ ONLY transaction, the statement timeout. But the WordPress
 * database user owns the token table and the file-version table: it created them and it
 * can read them, and there is no GRANT this plugin can issue on its own connection to
 * take that away. So the one thing the server will happily do and must not is read the
 * table of token hashes and the table of theme-file bytes, and the only place that can be
 * stopped is here, before the statement is sent.
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
 * NOTHING ELSE IS RESTORED. MAX_EXECUTION_TIME applies to read-only SELECTs and nothing
 * else, optimizer_switch only changes a plan and not a result, both are session-scoped,
 * and the request ends within milliseconds of this returning - so putting them back would
 * be two more round trips buying nothing. It is a deliberate choice, not an oversight.
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

    $wrapped = 'SELECT * FROM (' . $statement . ') AS wpmcp_q LIMIT ' . (int) (WPMCP_SQL_ROW_CAP + 1);

    $started       = microtime(true);
    $suppressed    = $wpdb->suppress_errors(true);
    $inTransaction = false;
    $rows          = array();
    $columns       = array();
    $error         = '';
    $errno         = 0;
    $thrown        = null;

    try {
        $wpdb->query(wpmcp_sql_timeout_statement(wpmcp_sql_server_info()));
        $wpdb->query("SET SESSION optimizer_switch = 'derived_merge=off'");
        $wpdb->last_error = '';

        $wpdb->query('START TRANSACTION READ ONLY');
        $inTransaction    = true;
        $wpdb->last_error = '';

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
        'description' => 'Run one read-only SQL SELECT. Args: sql (required). The statement is wrapped as a derived table inside a READ ONLY transaction, so anything but a single SELECT is a server syntax error. CTEs, joins, UNION and ORDER BY work; SHOW, stacked statements, INTO OUTFILE and FOR UPDATE do not, and a derived table needs unique column names. Returns JSON: columns, rows, row_count, truncated, truncated_by. At most 200 rows, 256KB of rows and 8KB per cell (cut with an ellipsis). The plugin\'s own tables are refused, even when named only in a comment. NULL is null; a non-UTF-8 value comes back as 0x-prefixed hex.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('sql' => array('type' => 'string')), 'required' => array('sql')),
        'run' => function ($a) {
            return wpmcp_sql_select_run(isset($a['sql']) ? (string) $a['sql'] : '');
        },
    ),

    );
}
