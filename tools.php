<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - tool catalog.
 *
 * wpmcp_core_tools():     site-info / list-posts / get-post.
 * wpmcp_content_tools():  create-post / update-post / delete-post.
 * wpmcp_revision_tools():  list-revisions / get-revision / restore-revision.
 * wpmcp_taxonomy_tools(): list-terms / create-term / delete-term.
 * wpmcp_media_tools():    list-media / get-media / upload-media / delete-media.
 * wpmcp_comment_tools():  list-comments / moderate-comment / reply-comment.
 * wpmcp_code_tools():     the six jailed code-edit tools (active theme only).
 * wpmcp_sql_tools():      sql-select, one read-only SQL statement (opt-in, off by default).
 * wpmcp_inventory_tools(): list-users / get-user / get-option (read scope) and
 *                         list-plugins / list-themes (admin scope). All five only read; the
 *                         two admin ones run no filter a plugin could make a request from.
 * Each tool = array('write'=>bool, 'annotations'=>array, 'description'=>str,
 *                   'inputSchema'=>array, 'run'=>callable).
 * Merged into the registry by endpoint.php's wpmcp_tools(), which REFUSES an entry
 * missing any of those - annotations included, all four hints, each a real boolean.
 *
 * THE MENU TOOLS LEFT THIS FILE IN 1.1.2 and now live in modules/menus.php, behind the seam
 * described in modules.php; the content-type discovery tool arrived there rather than here for
 * the same reason. This file is the CORE catalog. A new feature goes in its own module file,
 * and the five helpers below that a module may call - wpmcp_cannot(),
 * wpmcp_decode_specialchars(), wpmcp_listable_statuses(), wpmcp_post_type_ok() and
 * wpmcp_raw_title() - are the only traffic in that direction, which
 * tests/unit/ModuleBoundaryTest.php holds them to; see ARCHITECTURE.md's "The module seam".
 *
 * THE ANNOTATIONS ARE AUTHORED HERE, ONE ENTRY AT A TIME, and that is the point of them:
 * `readOnlyHint` is !write (one fact, one declaration), but `destructiveHint`,
 * `idempotentHint` and `openWorldHint` are judgements about what the tool does that no
 * flag already carries. See wpmcp_annotation_hints() in endpoint.php for what each one
 * means and why an unstated `destructiveHint` defaults to true. The judgements made here:
 *
 *   destructiveHint true   update-post, delete-post (force=true permanently deletes),
 *                          restore-revision (replaces the text it restores over),
 *                          delete-term, delete-media, moderate-comment (spam and trash
 *                          destroy the comment's place in the thread), code-write
 *                          (overwrites a theme file) and code-delete.
 *                  false   every read tool, and create-post / create-term /
 *                          upload-media / reply-comment, which only ADD: each call brings
 *                          a new post, term, attachment or comment into being and
 *                          replaces nothing that was there.
 *
 *                  THE MENU TOOLS ARE NOT IN EITHER LIST ANY MORE, and that is the point
 *                  of the seam rather than an omission: they are declared in
 *                  modules/menus.php, so their judgements are recorded in THAT file's
 *                  header. A roll-call here that named tools this file does not define
 *                  would be the first thing to drift the next time a module moves.
 *
 *                  THE TEST IS MCP'S OWN AND IT IS NARROW: `false` promises the update
 *                  is ADDITIVE. update-post was false, and that was wrong - it replaces
 *                  every field it touches, and its `terms` path replaces the post's
 *                  terms in that taxonomy rather than adding to them. Found by review
 *                  2026-09-12. "Only the fields the caller named" is scope, not
 *                  additivity, and a client honouring the hint would have let an agent
 *                  overwrite a published body without asking. When in doubt, true.
 *   idempotentHint  false  the four tools HERE that CREATE a new object per call
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
 * of the three it was, and so it holds if that mapping ever moves.) The MODS half goes
 * through `wp_is_file_mod_allowed()`, so a hardening plugin's `file_mod_allowed` filter
 * counts as well - see wpmcp_code_constants_forbid().
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
 * THE ROLE HALF STAYS PER-REQUEST and out of here, because it is a property of the token's
 * user's ROLE rather than of the site: an Administrator holds `edit_themes` and a
 * Subscriber does not, so a tool this token's user may not use is still listed, because
 * another token's user may. Listing on the site's own switches and refusing on the role is
 * the same split endpoint.php already makes between the scope gate and the capability
 * checks inside each tool.
 *
 * THE ONE EXCEPTION IS MULTISITE, AND IT IS CORE'S THIRD DENY BRANCH (sprint CORE-FIX).
 * `map_meta_cap`'s `edit_themes` case has THREE deny branches, not two
 * (wp-includes/capabilities.php:607-618): DISALLOW_FILE_EDIT, then
 * `wp_is_file_mod_allowed('capability_edit_themes')`, then
 * `is_multisite() && ! is_super_admin( $user_id )`. This function had the first two, so on
 * a network install every non-super-admin - including a Site Administrator, who holds
 * `edit_themes` in their role - was SHOWN all six code tools and refused every call. That is
 * the exact "advertised and refused" state the docblock above says this split fixed, and it
 * is D32's argument in one function: we copied core's check, got two branches of three, and
 * the copy drifted where core's cannot drift from itself.
 *
 * SO THE THIRD BRANCH READS THE CALLER AND STILL BELONGS HERE. It is not a ROLE fact - no
 * role on a network grants theme file editing to a site administrator, and no token minted
 * for one can ever pass it - so "another token's user may" is false for every token except a
 * network administrator's. The registry is built inside the request, after the token's user
 * is the current user, so asking is well defined; and the split the paragraph above describes
 * is unchanged, because what stays out of here is the ROLE capability itself.
 */
function wpmcp_code_constants_forbid() {
    // THE FILE-MOD HALF IS THE PLATFORM'S ANSWER, NOT A CONSTANT READ (1.1.1).
    // `wp_is_file_mod_allowed('capability_edit_themes')` (wp-includes/load.php:1829, since
    // 4.8) is `! DISALLOW_FILE_MODS` passed through the `file_mod_allowed` filter, and it is
    // the exact call `map_meta_cap` makes for `edit_themes` (capabilities.php:607-611) - so a
    // hardening plugin that switches file editing off through that filter now switches the
    // LISTING off too. Before this, such a site advertised all six code tools and refused
    // every call, which is the same "advertised and refused" defect the constants split fixed
    // for DISALLOW_FILE_EDIT, measured on seosemia.net (see the docblock above).
    //
    // The context string is core's own for this capability, so a filter that answers
    // per-context - which is why the parameter exists - gets asked the same question core
    // asks it.
    if (!wp_is_file_mod_allowed('capability_edit_themes')) {
        return new WP_Error(
            'wpmcp_forbidden',
            defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS
                ? 'File modification is disabled on this site (DISALLOW_FILE_MODS).'
                : 'File modification is disabled on this site (the file_mod_allowed filter).'
        );
    }
    // DISALLOW_FILE_EDIT STAYS A DIRECT CONSTANT READ, because core's is too: map_meta_cap
    // tests the constant itself and has no filter in front of it, so there is nothing to
    // delegate to and a filter of our own would disagree with `current_user_can`.
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        return new WP_Error('wpmcp_forbidden', 'Theme file editing is disabled on this site (DISALLOW_FILE_EDIT).');
    }
    // CORE'S THIRD DENY BRANCH (capabilities.php:613), and it is LAST here because it is last
    // there: when more than one applies, the operator is told about the one core would have
    // stopped at. `is_super_admin()` with no argument asks about the current user, which is the
    // same user `current_user_can('edit_themes')` resolves $user_id to - so the listing and the
    // run closures cannot answer differently.
    if (is_multisite() && !is_super_admin()) {
        return new WP_Error(
            'wpmcp_forbidden',
            'Theme file editing on a network is limited to network administrators (multisite).'
        );
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
 * Tell the opcode cache that a PHP file on disk is not what it has compiled.
 *
 * A WRITE IS NOT FINISHED UNTIL THE OPCODE CACHE IS TOLD. The measurable condition is
 * `opcache.validate_timestamps=0`, where PHP never stats a file it has already compiled,
 * or ANY `opcache.revalidate_freq`, where it stats it no more often than that - at the
 * default of 2 the old bytes run for up to two seconds and at 60 for up to a minute, which
 * is the same defect with a clock on it. code-write answered `bytes: 4096` and the site went on executing the OLD
 * functions.php until the pool was restarted. Every tool here that changes a compiled file therefore
 * calls this, and core's own theme editor is the pattern: it invalidates after the write
 * (`wp-admin/includes/file.php:525`, after the `fwrite`) AND AGAIN after the rollback
 * (`:638`, after the `file_put_contents` that puts the previous contents back), so a write
 * that is undone does not leave the undone bytes compiled.
 *
 * `wp_opcache_invalidate()` IS `@since 5.5.0` - exactly this plugin's floor (`Requires at
 * least: 5.5`) - and lives in `wp-admin/includes/file.php`, which a REST request does not
 * load. That require is the whole reason this is a function rather than five call sites:
 * without it the call is a fatal on the one request shape this plugin ever runs in.
 *
 * NOTHING HERE DEPENDS ON AN OPCODE CACHE BEING PRESENT. Core's function is already
 * guarded: it returns false, silently, when `opcache_invalidate()` is not defined, when
 * `opcache.restrict_api` excludes the calling script, when the cache is off, and when the
 * path is not a `.php` file (`file.php:2725-2777`).
 *
 * BUT FALSE IS TWO DIFFERENT ANSWERS, and the difference matters to an operator. For "no
 * opcode cache", "the cache is off" and "not a .php file" it means THERE WAS NOTHING TO
 * TELL, and nothing is wrong. For `opcache.restrict_api` excluding the REST front
 * controller, and for a site whose `wp_opcache_invalidate_file` filter returns false, THERE
 * IS A CACHE AND IT WAS NOT TOLD: the bytes are on disk and the old code can keep running,
 * which is the pre-fix symptom exactly. A third case answers TRUE and is still not enough -
 * a PHP pool spread over more than one node on a shared filesystem, where the write lands
 * everywhere and the invalidation lands only on the node that served the request.
 *
 * NONE OF THAT GOES IN THE RESULT, AND NOT REPORTING IS A CHOICE RATHER THAN A LIMIT. For
 * the first two cases this plugin could tell the difference - `function_exists`
 * `('opcache_invalidate')`, `ini_get('opcache.enable')` and `ini_get('opcache.restrict_api')`
 * are all readable in the same request, and TestRecorder reads exactly those to assert
 * against core's answer. It chooses not to: false is not a failure, so no caller treats it
 * as one, and a field saying `opcache: false` would be read by an agent as "the change is
 * not live" - which it would say on every host without a cache, which is most of them. Only
 * the multi-node case is genuinely invisible here, and it answers true. The tool's claim
 * stays what it has always been: what it did to the FILE. The three cases are written up in
 * the README and the CHANGELOG, where the reader is an operator who can go and look at a
 * setting; an agent cannot act on any of them and must not be handed them as a boolean.
 *
 * THE EXTENSION TEST IS CORE'S, spelled here so five call sites do not each have to make
 * it. `.php` is the only member of wpmcp_code_allowed_ext() that PHP compiles.
 *
 * WHAT IT ANSWERS, MEASURED on both Local sites (opcache loaded, `opcache.enable=1`,
 * `opcache.restrict_api` empty): TRUE for a `.php` path that exists, FALSE for a `.php`
 * path that does not. PHP resolves the path on the filesystem before it looks in the cache
 * (`zend_accel_invalidate()`), so a file that has just been unlinked cannot be invalidated
 * at all - which is why code-delete calls this BEFORE its unlink and not after it, and why
 * the second call of a revert that unlinks a file it created answers false and is left
 * where core puts it. FALSE is never an error here; see above.
 *
 * The action fires AFTER the attempt, carrying what core answered, and is the only
 * observable this leaves: see tests/integration/OpcacheInvalidationTest.php, which counts
 * it per file-changing act and reads the file from disk at the moment it fires, so the
 * ordering (invalidate after the write, not before it) is measured rather than read.
 *
 * @param string $abs Absolute path of the file that changed - the file need not still exist.
 * @return bool What wp_opcache_invalidate() answered: true when the cache was told,
 *              false when there was nothing or nobody to tell.
 */
function wpmcp_opcache_invalidate($abs) {
    $abs = (string) $abs;

    // Core's own test (file.php:2762-2765). A path this returns on is not compiled by
    // PHP, so there is nothing to invalidate and no witness to fire.
    if (strtolower(substr($abs, -4)) !== '.php') { return false; }

    if (!function_exists('wp_opcache_invalidate')
        && defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/file.php')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $invalidated = function_exists('wp_opcache_invalidate')
        ? (bool) wp_opcache_invalidate($abs, true)
        : false;

    /**
     * A compiled file changed on disk and the opcode cache has just been told.
     *
     * @since 1.1.0
     *
     * @param string $abs         Absolute path of the file that changed.
     * @param bool   $invalidated What wp_opcache_invalidate() answered.
     */
    do_action('wpmcp_compiled_file_changed', $abs, $invalidated);

    return $invalidated;
}

/**
 * download_url()'s failure, as something the caller can act on - or the error untouched.
 *
 * MEASURED 2026-09-21. upload-media on a Wikimedia thumbnail URL answered a bare
 * `Internal error`, and the trace log held the whole story:
 * `class=WP_Error:http_404 message=Bad Request data={"code":400,...}` - the CDN had refused
 * WordPress's user agent. Core's download_url() turns EVERY non-2xx into
 * `WP_Error('http_404', <reason phrase>, array('code' => <status>, 'body' => <1 KB sample>))`
 * (wp-admin/includes/file.php:1193-1219), whatever the status actually was, and `http_404` is
 * not on wpmcp_relayable_core_error_codes() - so the boundary correctly hid it, and the caller
 * was left with nothing to act on for a failure that was not this site's fault at all.
 *
 * THE STATUS AND THE REASON, AND NOT THE BODY. The status is the one fact that tells an agent
 * what to do next: 401/403 means this site is not allowed to fetch that URL and a different
 * one is needed, 404 means the URL is wrong, 429/5xx means wait. The `body` core attaches is a
 * kilobyte of whatever the remote server sent - in the measured case a full HTML error page,
 * in another case somebody else's session-bearing redirect - and it is not ours to forward.
 * The reason phrase comes from the remote server too, so it is bounded and stripped of
 * control characters before it goes anywhere near a client.
 *
 * ANY OTHER CODE IS RETURNED UNTOUCHED, which means `http_request_failed` (no connection at
 * all) stays generic: its message is the transport's, and cURL's names the host it could not
 * reach, which on a site with WP_PROXY_HOST set is the operator's internal proxy. That one
 * comes back as `Internal error (trace <id>)` and the sentence is in the private log.
 */
function wpmcp_fetch_error($error) {
    if ((string) $error->get_error_code() !== 'http_404') { return $error; }

    $data   = $error->get_error_data();
    $status = (is_array($data) && isset($data['code'])) ? (int) $data['code'] : 0;

    // No status means this is not the shape this function was written for - a filter, or a
    // transport that answered without a code. Nothing to relay, so nothing is.
    if ($status < 100 || $status > 599) { return $error; }

    // The remote server chose this string. Control characters out, 80 characters at most.
    $reason = preg_replace('/[^\x20-\x7E]/', ' ', (string) $error->get_error_message());
    $reason = trim((string) $reason);
    if (strlen($reason) > 80) { $reason = substr($reason, 0, 80) . '...'; }

    // "THE FETCH OF source_url", NEVER "THE SERVER AT source_url" (round 2). download_url()
    // goes through wp_safe_remote_get(), which follows up to five redirects by default
    // (class-wp-http.php:191, the http_request_redirection_count filter), so the status can
    // have come from a URL the caller never named - a CDN, a login page, a country redirect.
    // Naming source_url as the answerer would then be a false sentence in shipped text, which
    // is a defect by this project's own triage rule. This wording is true either way, and says
    // the redirect is possible rather than pretending it is not.
    return new WP_Error(
        'wpmcp_fetch_failed',
        'The fetch of source_url ended in HTTP ' . $status
        . ($reason !== '' ? ' ' . $reason : '')
        . ' instead of a file. This site fetched the URL itself and followed any redirects, so'
        . ' whichever server finally answered has to be willing to serve the file to this site -'
        . ' a URL that works in your browser may still be refused here, and the status may come'
        . ' from a redirect target rather than from source_url. The response body is not'
        . ' relayed.'
    );
}

/**
 * ONE DECLARATION, TWO OUTPUTS: a tool's `outputSchema` AND the result it returns.
 *
 * THE PILOT, AND WHY IT IS SHAPED THIS WAY (1.1.1, decision D19). `outputSchema` and
 * `structuredContent` are normative in every revision this server speaks, and a client cannot
 * tell us whether it wants the structured half - so a tool that declares one sends its data
 * TWICE, because the specification says to keep the text block for compatibility. That cost
 * is paid on four small-payload read tools only: site-info, get-post, get-media and get-user.
 *
 * THE POINT IS NOT THE WIRE FORMAT, IT IS THAT THERE IS ONE SOURCE. Sprint 14d existed
 * largely because results had drifted: three list tools with three envelopes, dates in three
 * formats, site-info gluing a name and a version together. A schema written out beside a
 * result builder would be a fourth thing to keep in step - so it is not written out. A tool
 * declares its fields ONCE, each with its JSON type and the closure that produces it, and
 * wpmcp_result_schema() and wpmcp_result_build() read the same declaration. A field that is
 * added, removed, renamed or re-typed moves in both at once, and a field with no `get` is a
 * PHP error rather than a schema that promises something nobody sends.
 *
 * A DECLARATION IS AN ORDERED MAP of field name => {
 *   type         string|list<string>  the JSON type(s). Required.
 *   nullable     bool                 adds 'null' to the type and nothing else.
 *   description  string               goes into the schema; this is where the field list that
 *                                     used to eat the tool's 1,000 description characters
 *                                     belongs (D19: the budget is the scarce thing).
 *   get          callable($ctx)       the value. Required.
 *   fields       array                a NESTED declaration: `get` then returns the inner
 *                                     context (or null for a nullable object), and the
 *                                     builder recurses. That is what keeps an object's
 *                                     insides single-source too.
 *   items        array                a JSON Schema node for an array's elements.
 *   when         callable($ctx)       false = this field is absent from the result AND
 *                                     optional in the schema. get-user's capability-gated
 *                                     half is the case.
 * }
 *
 * EVERY FIELD IS `required` UNLESS `when` SAYS OTHERWISE, because a read tool that sometimes
 * omits a key is the drift this is meant to prevent; nullable says "present and empty".
 *
 * @param array $shape the declaration
 * @return array a JSON Schema object node
 */
function wpmcp_result_schema($shape) {
    $properties = array();
    $required   = array();

    foreach ($shape as $name => $field) {
        $types = (array) $field['type'];
        if (!empty($field['nullable'])) { $types[] = 'null'; }

        $node = array('type' => count($types) === 1 ? reset($types) : array_values($types));

        if (isset($field['description'])) { $node['description'] = (string) $field['description']; }

        if (isset($field['fields'])) {
            $inner               = wpmcp_result_schema($field['fields']);
            $node['properties']  = $inner['properties'];
            $node['required']    = $inner['required'];
        }

        if (isset($field['items'])) { $node['items'] = $field['items']; }

        $properties[(string) $name] = $node;

        if (!isset($field['when'])) { $required[] = (string) $name; }
    }

    return array('type' => 'object', 'properties' => $properties, 'required' => $required);
}

/**
 * The result the declaration above describes, in the order it declares.
 *
 * @param array $shape the same declaration wpmcp_result_schema() was given
 * @param mixed $ctx   whatever the tool's `get` closures need - a WP_Post, an id, anything
 * @return array
 */
function wpmcp_result_build($shape, $ctx = null) {
    $out = array();

    foreach ($shape as $name => $field) {
        if (isset($field['when']) && !call_user_func($field['when'], $ctx)) { continue; }

        $value = call_user_func($field['get'], $ctx);

        // A nested declaration: `get` handed back the inner context, not the inner value.
        if (isset($field['fields'])) {
            $value = $value === null ? null : wpmcp_result_build($field['fields'], $value);
        }

        $out[(string) $name] = $value;
    }

    return $out;
}

/**
 * site-info's fields. The context is wpmcp_theme_header()'s reading of the active theme's
 * style.css, or null when there is no theme - which is why the two theme fields are nullable.
 */
function wpmcp_site_info_shape() {
    return array(
        'name' => array(
            'type' => 'string',
            'description' => 'The site title, as stored (the blogname option, not a rendering of it).',
            'get'  => function ($header) { return get_bloginfo('name'); },
        ),
        'url' => array(
            'type' => 'string',
            'description' => 'The site home URL. https whenever the request reached WordPress over TLS.',
            'get'  => function ($header) { return home_url(); },
        ),
        'wp_version' => array(
            'type' => 'string',
            'description' => 'The WordPress version this site runs.',
            'get'  => function ($header) { return get_bloginfo('version'); },
        ),
        'active_theme' => array(
            'type'        => 'string',
            'nullable'    => true,
            'description' => 'The active theme\'s name, exactly as list-themes gives it. Never glued to its version.',
            'get'         => function ($header) { return $header ? $header['name'] : null; },
        ),
        'active_theme_version' => array(
            'type'        => 'string',
            'nullable'    => true,
            'description' => 'The active theme\'s Version header, or an empty string when it has none.',
            'get'         => function ($header) { return $header ? $header['version'] : null; },
        ),
        'active_plugins' => array(
            'type'        => 'integer',
            'description' => 'How many plugins are active. A count, never the list: a plugin inventory is not a read tool\'s to hand out.',
            'get'         => function ($header) { return count((array) get_option('active_plugins', array())); },
        ),
        // NESTED, and not `wpmcp_version` beside `wp_version`. Two keys one character apart,
        // one meaning WordPress and the other meaning this plugin, is a misreading waiting to
        // happen in exactly the situation this field exists for: somebody trying to work out
        // which of two builds a site is running.
        'wp_mcp' => array(
            'type'        => 'object',
            'description' => 'This plugin, not WordPress.',
            'get'         => function ($header) { return true; },
            'fields'      => array(
                'version' => array(
                    'type' => 'string',
                    'description' => 'The plugin version from its header.',
                    'get'  => function ($ctx) { return WPMCP_VER; },
                ),
                'build' => array(
                    'type' => 'string',
                    'description' => 'The commit a zip was built from, or "source" when the site runs it from a checkout.',
                    'get'  => function ($ctx) { return wpmcp_build_label(); },
                ),
            ),
        ),
    );
}

/**
 * get-post's fields. The context is {post, author, revisions} - the author userdata and the
 * revision count are read by the tool, because both are capability decisions rather than
 * columns and belong where the refusals are.
 */
function wpmcp_get_post_shape() {
    return array(
        'id' => array(
            'type' => 'integer',
            'description' => 'Post ID.',
            'get'  => function ($c) { return (int) $c['post']->ID; },
        ),
        'title' => array(
            'type' => 'string',
            'description' => 'The stored post_title column, not the display rendering.',
            'get'  => function ($c) { return wpmcp_raw_title($c['post']); },
        ),
        'type' => array(
            'type' => 'string',
            'description' => 'Post type slug.',
            'get'  => function ($c) { return (string) $c['post']->post_type; },
        ),
        'status' => array(
            'type' => 'string',
            'description' => 'Post status slug, a plugin\'s custom status included.',
            'get'  => function ($c) { return (string) $c['post']->post_status; },
        ),
        'slug' => array(
            'type' => 'string',
            'description' => 'The post_name column. Empty for a draft that has never had one.',
            'get'  => function ($c) { return (string) $c['post']->post_name; },
        ),
        'link' => array(
            'type'        => 'string',
            'description' => 'The RENDERED permalink - the plain ?p=ID form while the post is draft, pending, future or trashed, whatever the permalink structure. There is no column to write it back to.',
            'get'         => function ($c) { return get_permalink($c['post']); },
        ),
        'content' => array(
            'type' => 'string',
            'description' => 'The stored post_content column, unfiltered: no shortcodes run, no blocks rendered, no wpautop.',
            'get'  => function ($c) { return (string) $c['post']->post_content; },
        ),
        'excerpt' => array(
            'type' => 'string',
            'description' => 'The stored post_excerpt column. Empty when the post has none; never generated from the content.',
            'get'  => function ($c) { return (string) $c['post']->post_excerpt; },
        ),
        'author' => array(
            'type'        => 'object',
            'description' => 'Who wrote it. An id and a display name and nothing else - not the login, which is half of a credential.',
            'get'         => function ($c) { return $c; },
            'fields'      => array(
                'id' => array(
                    'type' => 'integer',
                    'description' => 'The author\'s user id.',
                    'get'  => function ($c) { return (int) $c['post']->post_author; },
                ),
                'name' => array(
                    'type'     => 'string',
                    'nullable' => true,
                    'description' => 'The author\'s display name, or null when the user has been deleted.',
                    'get'      => function ($c) { return $c['author'] ? (string) $c['author']->display_name : null; },
                ),
            ),
        ),
        'date' => array(
            'type' => 'string', 'nullable' => true,
            'description' => 'ISO 8601, site-local, no offset - what post_date holds. Null when the column holds no date.',
            'get'  => function ($c) { return wpmcp_iso_date($c['post']->post_date); },
        ),
        'date_gmt' => array(
            'type' => 'string', 'nullable' => true,
            'description' => 'The same instant in UTC. Null for a draft nobody dated: WordPress stores 0000-00-00 there for a date-floating status.',
            'get'  => function ($c) { return wpmcp_iso_date($c['post']->post_date_gmt); },
        ),
        'modified' => array(
            'type' => 'string', 'nullable' => true,
            'description' => 'ISO 8601, site-local, no offset.',
            'get'  => function ($c) { return wpmcp_iso_date($c['post']->post_modified); },
        ),
        'modified_gmt' => array(
            'type' => 'string', 'nullable' => true,
            'description' => 'The same instant in UTC, or null - see date_gmt.',
            'get'  => function ($c) { return wpmcp_iso_date($c['post']->post_modified_gmt); },
        ),
        'featured_image' => array(
            'type'        => 'object',
            'nullable'    => true,
            'description' => 'The featured image, or null when the post has none.',
            'get'         => function ($c) {
                $id = (int) get_post_thumbnail_id($c['post']);

                return $id ? $id : null;
            },
            'fields' => array(
                'id' => array(
                    'type' => 'integer',
                    'description' => 'The attachment id. upload-media and update-post both take it.',
                    'get'  => function ($id) { return (int) $id; },
                ),
                'url' => array(
                    'type'     => 'string',
                    'nullable' => true,
                    'description' => 'The rendered file URL, or null when the file is gone.',
                    'get'      => function ($id) {
                        $url = wp_get_attachment_url((int) $id);

                        return $url === false ? null : $url;
                    },
                ),
            ),
        ),
        'terms' => array(
            'type'        => 'object',
            'description' => 'Terms keyed by taxonomy, for every viewable taxonomy on this post type. Each entry is {id, name, slug}, the name as a person would type it. update-post accepts exactly these objects back.',
            'get'         => function ($c) { return wpmcp_post_terms($c['post']); },
        ),
        'revisions' => array(
            'type'        => 'integer',
            'nullable'    => true,
            'description' => 'How many revisions are stored, or null when the caller cannot edit the post - revisions are editorial data and follow the editorial capability, as wp-admin does. Null rather than 0, because 0 would be a claim.',
            'get'         => function ($c) { return $c['revisions']; },
        ),
    );
}

/**
 * get-media's fields. The context is the attachment WP_Post.
 *
 * `url` IS NULLABLE AND THAT IS A FIX (1.1.1). It used to be whatever
 * `wp_get_attachment_url()` returned, which is `false` when the attachment has no file - so
 * the field was a string on every row but one, and a client reading it as text got the JSON
 * literal `false`. Declaring the type is what found it; get-post's featured_image.url had
 * already been normalised to null in sprint 14d and this is the same shape.
 */
function wpmcp_get_media_shape() {
    return array(
        'id' => array(
            'type' => 'integer',
            'description' => 'Attachment ID.',
            'get'  => function ($p) { return (int) $p->ID; },
        ),
        'title' => array(
            'type' => 'string',
            'description' => 'The stored post_title column, not the display rendering.',
            'get'  => function ($p) { return wpmcp_raw_title($p); },
        ),
        'mime' => array(
            'type' => 'string',
            'description' => 'The stored MIME type, e.g. image/jpeg.',
            'get'  => function ($p) { return (string) $p->post_mime_type; },
        ),
        'url' => array(
            'type'        => 'string',
            'nullable'    => true,
            'description' => 'The rendered file URL, or null when the attachment has no file. There is no column to write it back to.',
            'get'         => function ($p) {
                $url = wp_get_attachment_url((int) $p->ID);

                return $url === false ? null : $url;
            },
        ),
        'alt' => array(
            'type'        => 'string',
            'description' => 'The alt text, from _wp_attachment_image_alt. Plain text: upload-media strips tags and encodes < on the way in. Empty when unset.',
            'get'         => function ($p) { return (string) get_post_meta((int) $p->ID, '_wp_attachment_image_alt', true); },
        ),
        'caption' => array(
            'type'        => 'string',
            'description' => 'The caption - WordPress stores it in post_excerpt. Empty when unset.',
            'get'         => function ($p) { return (string) $p->post_excerpt; },
        ),
        'filesize' => array(
            'type'        => 'integer',
            'nullable'    => true,
            'description' => 'Size in bytes, or null when the file is missing from disk.',
            'get'         => function ($p) {
                $file = get_attached_file((int) $p->ID);

                return ($file && file_exists($file)) ? filesize($file) : null;
            },
        ),
        'width' => array(
            'type'        => 'integer',
            'nullable'    => true,
            'description' => 'Pixels, or null for anything that is not an image.',
            'get'         => function ($p) {
                $meta = wp_get_attachment_metadata((int) $p->ID);

                return isset($meta['width']) ? (int) $meta['width'] : null;
            },
        ),
        'height' => array(
            'type'        => 'integer',
            'nullable'    => true,
            'description' => 'Pixels, or null for anything that is not an image.',
            'get'         => function ($p) {
                $meta = wp_get_attachment_metadata((int) $p->ID);

                return isset($meta['height']) ? (int) $meta['height'] : null;
            },
        ),
    );
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
 * CUSTOM STATUSES ARE INCLUDED, AND THE PLATFORM DECIDES WHICH (1.1.1). The five core
 * statuses used to be written out here, so a workflow plugin's `archived` or `expired` was
 * invisible to list-posts however public it was. `get_post_stati(array('internal' => false),
 * 'objects')` is the registry (since 3.0) and each status object's `public` / `private` /
 * `protected` flags are the SAME flags WP_Query itself consults to decide who may see a
 * status (class-wp-query.php:3538-3553: protected -> the edit capability, private -> the read
 * capability, public -> everybody, and a status with none of the three -> nobody). Reading
 * the flags instead of a list means a status registered after this code was written is
 * scoped by the rule its own author declared.
 *
 * THE MAPPING, AND IT IS THE ONE THIS FUNCTION ALREADY HAD:
 *
 *   public     -> everyone. It is on the front end already.
 *   protected  -> $pto->cap->edit_others_posts. This is where draft, pending and future are;
 *                 seeing other people's unpublished work is an editorial capability.
 *   private    -> $pto->cap->read_private_posts. This is where `private` is.
 *   none of the three -> NOBODY, not even the author, because that is core's own answer
 *                 (class-wp-query.php:3557 empties the result) and a status with no flag has
 *                 declared no audience.
 *
 * `internal => false` keeps `trash` and `auto-draft` out, exactly as the old list did by
 * omission - and `inherit`, which attachments use, since it is not internal but also not
 * public, protected or private, so no flag admits it.
 *
 * WIDER THAN BEFORE, AND THE CAPABILITY IS WHAT BOUNDS IT. A plugin that registers an
 * internal workflow state as `public => true` makes it listable here - but it has also put it
 * on the front end, which is what that flag means.
 */
function wpmcp_listable_statuses($post_type) {
    $pto = get_post_type_object($post_type);
    if (!$pto) { return array('publish'); }

    $statuses = array();

    foreach (get_post_stati(array('internal' => false), 'objects') as $name => $status) {
        if (!empty($status->public)
            || (!empty($status->protected) && current_user_can($pto->cap->edit_others_posts))
            || (!empty($status->private) && current_user_can($pto->cap->read_private_posts))) {
            $statuses[] = (string) $name;
        }
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
 *
 * THE COMPLEMENT IS TAKEN OVER THE SAME REGISTRY (1.1.1), not over the five core statuses,
 * so an author sees their OWN posts in a plugin's custom status on the same rule: a status
 * whose flags say somebody else's copy is withheld is still the author's own to see. A
 * status with NO flag at all is in neither list, because core shows it to nobody.
 */
function wpmcp_own_listable_statuses($post_type) {
    $pto = get_post_type_object($post_type);
    if (!$pto || !current_user_can($pto->cap->edit_posts)) { return array(); }

    $scoped = array();

    foreach (get_post_stati(array('internal' => false), 'objects') as $name => $status) {
        if (empty($status->public) && (!empty($status->protected) || !empty($status->private))) {
            $scoped[] = (string) $name;
        }
    }

    return array_values(array_diff($scoped, wpmcp_listable_statuses($post_type)));
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
        // SLASHED, because WP_Query::parse_search() opens with
        // `$query_vars['s'] = stripslashes( $query_vars['s'] )` (class-wp-query.php:1439).
        // Now that the write tools store a backslash correctly, an unslashed search for
        // `C:\Users` is stripped to `C:Users` and can no longer find the row it names.
        if ($search !== '') { $query['s'] = wp_slash($search); }
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
            // The name as typed, not as core escaped it - see wpmcp_decode_specialchars().
            // Writing this entry back through update-post's `terms` finds the same term:
            // it takes the {id, name, slug} object itself, or the name.
            $found[$taxonomy][] = array(
                'id'   => (int) $term->term_id,
                'name' => wpmcp_decode_specialchars($term->name),
                'slug' => $term->slug,
            );
        }
    }

    return $found === array() ? new stdClass() : $found;
}

/**
 * A post's title AS THE COLUMN HOLDS IT - never get_the_title().
 *
 * get_the_title() runs the `the_title` filter chain, and core hangs wptexturize,
 * convert_chars and trim on it by default (default-filters.php:197-199), so a title stored
 * with a straight quote comes back as `&#8220;...&#8221;`; it also prepends "Private: " or
 * "Protected: " for those statuses (post-template.php:131-151). Content and excerpt were
 * always the raw columns, so the title was the odd field out - and a client that read a
 * title and wrote it back corrupted the post, which apostrophes and ampersands make routine.
 * MEASURED on a live site, 2026-09-16: stored `Migration test A\B "quoted"`, read back
 * `Migration test A\B &#8220;quoted&#8221;`, the row's own bytes correct.
 *
 * THE RULE THIS SETTLES, for every read tool: a field a caller may write back is the stored
 * column, unfiltered. A field that is a rendering - `link` (get_permalink), a media `url` -
 * stays filtered, because there is no column to write it back to.
 */
function wpmcp_raw_title($post) {
    return isset($post->post_title) ? (string) $post->post_title : '';
}

/**
 * THE TITLE CONTRACT - what this plugin does to a title, and why it is now nothing.
 *
 * Documentation only: there is no code left to put in a function, which is the point. The
 * rule is ARCHITECTURE's "the tool layer is the browser and the form" (decision D5), and
 * for a title it comes out as: HAND CORE THE TITLE AS TYPED AND LET title_save_pre RUN.
 *
 * WHAT WAS THERE BEFORE. create-post and update-post ran `wp_strip_all_tags()` on the title,
 * so a title typed `x<y z` was stored as `x` - text destroyed, silently, on a write the
 * caller thought it understood. Neither wp-admin nor WP_REST_Posts_Controller does that.
 *
 * WHAT CORE DOES INSTEAD, read on WP 7.1.1 and measured on sample.local 2026-09-21:
 *
 *   - `title_save_pre` carries only `trim` by default (default-filters.php:328).
 *   - `wp_filter_kses` is added to it ONLY for a user without `unfiltered_html`
 *     (kses.php:2548, through kses_init/kses_init_filters), and `DISALLOW_UNFILTERED_HTML`
 *     forces that path for everyone, administrators included.
 *   - So the SAME wire value stores different bytes depending on the token user: an
 *     administrator's `x<y z` is stored `x<y z`, and an author's is stored `x&lt;y z`.
 *     That is the accepted cost of the contract, and it is what wp-admin does.
 *   - kses is a FIXED POINT: `wp_filter_kses('x<y z')` is `x&lt;y z`, and a second and third
 *     pass change nothing. `esc_attr()` does not double-encode, so the stored `x&lt;y z`
 *     reaches the browser as `x&lt;y z` and wp-admin's field shows the user `x<y z`. The two
 *     are inverses, which is why wp-admin never compounds an encoding.
 *
 * THE SLASHING IS THE TRAP, AND IT IS ALREADY HANDLED - by one call, on purpose.
 * `wp_filter_kses` is `addslashes(wp_kses(stripslashes($data)))`, so it expects SLASHED input
 * exactly as `wp_insert_post()` does (it unslashes at post.php:4981, after the save_pre
 * filters have run at :4632). Both write tools slash the whole postarr once, at the boundary
 * (`wp_insert_post(wp_slash($postarr))`, `wp_update_post(wp_slash($upd))`), so kses's
 * stripslashes/addslashes pair cancels out and `Tom's "quoted" A\B` survives byte for byte for
 * an administrator and as `Tom's "quoted" A\B` for an author too - kses touches none of those
 * three characters. Hand kses an UNSLASHED title and the backslash is eaten instead
 * (`A\B` -> `AB`), which is the failure this note exists to stop a future field from
 * repeating: slash the array, never the field.
 *
 * THE READ SIDE IS NOT PART OF THIS CHANGE, and the asymmetry is named rather than hidden:
 * get-post returns the stored column (wpmcp_raw_title()), so an author's `x<y z` reads back
 * as `x&lt;y z` while wp-admin's field would show `x<y z`. Writing what was read back is
 * still exact, because an unchanged field is not written at all (see the no-op rule above).
 * Decoding a title on read is a separate decision - it would change what five read tools
 * return and would let an administrator's write-back rewrite an author's stored bytes - and
 * it is recorded as an open item in ARCHITECTURE.
 *
 * @see wpmcp_decode_specialchars() for the fields where the read half IS applied.
 */

/**
 * A stored name with the HTML escaping WordPress put on it taken off again - the text a
 * person typed, and the text a client should send to name the same thing.
 *
 * THE ROUND-TRIP RULE, SECOND HALF (sprint 14d; the first half is wpmcp_raw_title). A field
 * a caller may write back must come back as the caller would type it. For a post title that
 * is the raw column, because core stores a title as typed. For a TERM NAME it is not: core's
 * `pre_term_name` filter runs `_wp_specialchars()` (default-filters.php, the pre_term_name
 * loop), so `Arts & Crafts` is stored as `Arts &amp; Crafts` and `x < y` as `x &lt; y` -
 * measured on both sites, every taxonomy. A client that read `&amp;` and showed it, compared
 * it, or built a new name from it was working with text nobody typed. The same is true of a
 * MENU LABEL wp-admin saved: jaygroup holds eight labels with `&#038;` for `&` - the form
 * `convert_chars` gives it, believed to come from the label field being filled from the
 * displayed title (the cause is inferred; the stored bytes are measured).
 *
 * ENT_NOQUOTES, and nothing wider, because it is the exact inverse of what core applies:
 * `_wp_specialchars()` as a filter runs with ENT_NOQUOTES, so it encodes `&`, `<` and `>`
 * and never a quote. This decodes `&amp;`, `&lt;`, `&gt;` and their numeric forms
 * (`&#038;`, `&#060;`, `&#062;`) and leaves `&quot;`, `&#8217;` and `&nbsp;` as stored: a
 * wider decode would turn stored bytes into text core would NOT turn back into them.
 *
 * WRITING THE DECODED TEXT BACK STORES THE SAME BYTES. For a term, core's own pre_term_name
 * encodes it again, and does not double-encode an `&amp;` that is already there (measured:
 * `A & B` and `A &amp; B` both store `A &amp; B`, and both find the existing term by name).
 * For a menu label, update-menu-item keeps the stored bytes when the label it is sent equals
 * this function's reading of them (see there).
 */
function wpmcp_decode_specialchars($text) {
    return wp_specialchars_decode((string) $text, ENT_NOQUOTES);
}

/**
 * Where every paged list tool stops, and what it says when it gets there.
 *
 * THE PAGING-END RULE (sprint 14d). Every paged tool clamped `page` to 100 and then computed
 * has_more from the rows beyond it - so page 101, 102, ... all answered with page 100's rows
 * and `has_more: true`, and an agent paging "until has_more is false" looped for ever on a
 * site with more than 100 pages of anything. Found on list-users, true of all of them.
 *
 * `has_more` is FALSE at the cap, whatever lies beyond it, and every description says where
 * the cap is and that the way past it is a narrower filter. That is a deliberate
 * under-statement at exactly one page, and the description carries the rest of it: a
 * `has_more` a loop can trust is worth more than one that is literally complete and never
 * ends. Deeper paging would also cost more: list-posts fetches page x limit + 1 rows per
 * query to merge its two queries, so the cap bounds that at 10,001.
 */
define('WPMCP_PAGE_CAP', 100);

/**
 * limit and page for a paged list tool: limit 1-100 (default 20), page 1-WPMCP_PAGE_CAP.
 *
 * `per_page` IS ACCEPTED AS limit's OLD NAME. list-media and list-comments took `per_page`
 * before every list tool shared one envelope; `limit` wins when both are sent.
 *
 * @return array{0: int, 1: int} limit, page
 */
function wpmcp_page_args($a) {
    $raw   = isset($a['limit']) ? $a['limit'] : (isset($a['per_page']) ? $a['per_page'] : null);
    $limit = $raw === null ? 20 : min(100, max(1, (int) $raw));
    $page  = isset($a['page']) ? min(WPMCP_PAGE_CAP, max(1, (int) $a['page'])) : 1;

    return array($limit, $page);
}

/**
 * The one paging envelope every paged list tool returns: count, page, limit, has_more,
 * items. $more is "the query found a row past this page"; at the cap it is not reported.
 */
function wpmcp_page_envelope($items, $page, $limit, $more) {
    return array(
        'count'    => count($items),
        'page'     => (int) $page,
        'limit'    => (int) $limit,
        'has_more' => (bool) $more && $page < WPMCP_PAGE_CAP,
        'items'    => array_values($items),
    );
}

/**
 * A UTC datetime column as the list tools' one date format: ISO 8601, site-local, no offset
 * - the form wpmcp_iso_date() gives post_date, so a date means the same thing in every tool.
 * user_registered and the file-version saved_at are stored in UTC; post and comment dates
 * have a local column of their own and do not come through here.
 */
function wpmcp_iso_date_from_gmt($gmt) {
    $gmt = trim((string) $gmt);
    if ($gmt === '' || str_starts_with($gmt, '0000-00-00')) { return null; }

    return wpmcp_iso_date(get_date_from_gmt($gmt));
}

/**
 * The active theme's name and version from its style.css header, shaped exactly as
 * list-themes shapes every theme's - the header read without a filter, tags stripped,
 * trimmed. site-info used to build "Name Version" from WP_Theme and a theme with no Version
 * header came back as `"JDA "` beside list-themes' `"JDA"` (seosemia.net, 2026-09-18): one
 * value, two answers. Both tools now take it from here.
 *
 * @return array{name: string, version: string}
 */
function wpmcp_theme_header($styleFile) {
    $headers = is_readable($styleFile)
        ? get_file_data($styleFile, array('Name' => 'Theme Name', 'Version' => 'Version', 'Template' => 'Template'))
        : array('Name' => '', 'Version' => '', 'Template' => '');

    return array(
        'name'     => trim(strip_tags((string) $headers['Name'])),
        'version'  => trim(strip_tags((string) $headers['Version'])),
        'template' => (string) $headers['Template'],
    );
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

        // AN EMPTY LIST CLEARS THE TAXONOMY (sprint 14d round 2): `terms` REPLACES, and
        // `{post_tag: []}` used to reach no wp_set_object_terms() call at all, so the tags
        // stayed and the caller had no way to remove the last one. Only a list SENT empty
        // clears - a list whose every entry was refused is not a request to clear.
        if (is_array($vals) && $vals === array()) {
            $set = wp_set_object_terms($post_id, array(), $tax, false);
            if (is_wp_error($set)) { $failed[$tax] = $set->get_error_message(); } else { $assigned[$tax] = array(); }
            continue;
        }

        $ids = array();
        foreach ((array) $vals as $v) {
            // GET-POST'S OWN SHAPE IS ACCEPTED, because a caller that reads a post's terms
            // and writes them back sends what it read: `{id, name, slug}` objects, not ids.
            // Before sprint 14d an object here fell through to the name branch as the string
            // "Array" - measured on both sites, update-post then created a category named
            // `Array`, assigned it, and reported `changed: ["terms"]`. The id wins when both
            // are present; an object with neither, or any other non-scalar, is refused by
            // name rather than guessed at.
            if (is_array($v)) {
                if (isset($v['id']) && is_scalar($v['id'])) {
                    $v = $v['id'];
                } elseif (isset($v['name']) && is_scalar($v['name'])) {
                    $v = (string) $v['name'];
                } else {
                    $refused[$tax][] = (string) wp_json_encode($v);
                    continue;
                }
            } elseif (!is_scalar($v)) {
                $refused[$tax][] = (string) wp_json_encode($v);
                continue;
            }
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
            // THE READ SIDE OF THE SAME CONTRACT, and it has to agree with the write below.
            // get_term_by('name') goes through WP_Term_Query, which runs
            // `stripslashes( sanitize_term_field( 'name', ... 'db' ) )`
            // (class-wp-term-query.php:548-549) - a lookup that expects SLASHED input.
            // Before round 3 the lookup and the insert were both unslashed and agreed by
            // accident; once the insert was slashed, `A\B` was stored correctly and then
            // searched for as `AB`, missed, and every later post naming that term created a
            // duplicate (`ab-2`, `ab-3`). Slash both, or neither works.
            $t = get_term_by('name', wp_slash((string) $v), $tax);
            if ($t) {
                $ids[] = (int) $t->term_id;
                continue;
            }
            if (!$may_create) {
                $refused[$tax][] = (string) $v;
                continue;
            }
            // SLASHED. wp_insert_term() unslashes the name it is given
            // (taxonomy.php:2509-2511, `// expected_slashed ($name)`), so a category
            // created from `terms: {category: ["A\\B"]}` was stored as `AB`. Core's own
            // terms controller slashes here too
            // (class-wp-rest-terms-controller.php:550).
            $new = wp_insert_term(wp_slash((string) $v), $tax);
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
 * THE CONVERSION IS CORE'S, AND ONLY THE GRAMMAR IS OURS. `rest_get_date_with_gmt()`
 * (wp-includes/rest-api.php:1401, since 4.4) returns exactly this pair and is what
 * WP_REST_Posts_Controller pairs with `edit_date => true` - the same pattern
 * wpmcp_post_fields() already uses - so the local/GMT conversion is a platform API we do not
 * maintain. What is left here is a GRAMMAR SHIM and a GUARD, and both earn their lines:
 *
 * THE SHIM, because core's grammar is narrower than the one this tool documents.
 * `rest_parse_date()` (:1358) demands `YYYY-MM-DDTHH:MM:SS` with a colon in any offset, so
 * `2026-03-04`, `2026-03-04T09:30` and `2026-03-04T09:30:00-0500` - all three documented by
 * create-post and update-post since 1.0 - would simply be refused. The regex matches our
 * shape, then the parts are padded into core's.
 *
 * THE GUARD, because core's parser ends in `strtotime()` (:1364), which does not validate:
 * `2026-02-30T00:00:00` matches core's regex and rolls silently to 2 March, and `+25:00`
 * matches and means nothing. A caller whose date is impossible is told so, which is the whole
 * reason this project has its own date parsing at all - see wpmcp_parse_iso_datetime(), the
 * filter-side parser, whose docblock refuses the same failure for the same reason.
 *
 * WHY NOT strtotime(), AND WHY NOT wpmcp_parse_iso_datetime(). strtotime() accepts 'next
 * tuesday', '@1700000000' and '2026-13-45' (which it rolls into 2027). wpmcp_parse_iso_datetime()
 * is the refusal of exactly that, but it rejects an offset outright, which is right for a date
 * FILTER (a window on stored local columns) and wrong for a date a caller is SETTING. Two
 * shapes, two parsers, and neither loosened.
 *
 * BOTH OF CORE'S BRANCHES ARE THE ONES WE WANT. With no offset it runs `strtotime()` under
 * WordPress's UTC default timezone and formats straight back, so the wall-clock string is
 * unchanged and `get_gmt_from_date()` does the local->UTC step; with an offset the instant is
 * fixed by the caller and `get_date_from_gmt()` writes it down locally. The DST objection the
 * previous implementation was built around applies only to a round trip through a local
 * string, which neither branch makes.
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

    // ALWAYS +HH:MM OR ABSENT, because core's regex accepts neither `z` nor `-0500`.
    $offset = isset($m[7]) ? $m[7] : '';

    if ($offset === 'Z' || $offset === 'z') {
        $offset = '+00:00';
    } elseif (strlen($offset) === 5) {
        $offset = substr($offset, 0, 3) . ':' . substr($offset, 3);
    }
    // +25:00 parses in PHP and means nothing. The real range is -12:00..+14:00.
    if ($offset !== ''
        && ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59)) {
        return null;
    }

    $pair = rest_get_date_with_gmt(sprintf(
        '%s-%s-%sT%02d:%02d:%02d%s',
        $m[1], $m[2], $m[3], $hour, $minute, $second, $offset
    ));

    return is_array($pair) ? array('local' => $pair[0], 'gmt' => $pair[1]) : null;
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
 * THE CURRENT ROW, ON UPDATE (sprint 14d). update-post passes the post as it stands, and a
 * field whose sent value EQUALS what is stored is left out of the write - not re-shaped, not
 * re-gated. That is the round-trip rule: a caller that reads a post and writes a field back
 * unchanged must store the same bytes, and re-shaping is exactly what broke that. Measured
 * on both sites: a title stored by wp-admin as `x<y z` read back as `x<y z`, and writing it
 * back ran wp_strip_all_tags() and stored `x` (dropped in 1.1.1, see
 * wpmcp_title_contract()); a floating draft's `date` written back fixed
 * its GMT column, so a draft nobody dated became a dated one. An unchanged `author` or
 * `featured_image` is not a change of author or image, so it does not need the capability a
 * change needs - an Author writing back their own post's author id was refused. A `date`
 * equal to the stored one goes into `keep`: it is not a change, but if anything else is
 * written, sending it back holds it in place (edit_date) so core does not re-date the draft under a
 * caller who said which date it has.
 *
 * @param array        $a        the tool's arguments
 * @param string       $postType the type the row will have
 * @param WP_Post|null $current  the row as stored, on update; null on create
 * @return array{insert: array, keep: array, after: array, changed: list<string>}|WP_Error
 */
function wpmcp_post_fields($a, $postType, $current = null) {
    $pto     = get_post_type_object($postType);
    $insert  = array();
    $keep    = array();
    $after   = array();
    $changed = array();

    if (isset($a['excerpt'])
        && !($current && (string) $a['excerpt'] === (string) $current->post_excerpt)) {
        $insert['post_excerpt'] = (string) $a['excerpt'];
        $changed[] = 'excerpt';
    }

    if (isset($a['slug'])
        && !($current && (string) $a['slug'] === (string) $current->post_name)) {
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

        // UNCHANGED: the same local time, and either the same GMT or none stored yet (a
        // draft nobody dated reads back its local date with date_gmt null).
        $same = $current
            && $date['local'] === (string) $current->post_date
            && (str_starts_with((string) $current->post_date_gmt, '0000-00-00')
                || $date['gmt'] === (string) $current->post_date_gmt);

        if ($same) {
            // Only the local column, and edit_date: wp_update_post() merges the stored GMT
            // back in, so a floating draft stays floating and keeps this exact date.
            $keep['post_date'] = (string) $current->post_date;
            $keep['edit_date'] = true;
        } else {
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
    }

    if (isset($a['author'])) {
        // THE SHAPE, CHECKED HERE BECAUSE THE SCHEMA CANNOT SAY IT. This argument
        // declares no `type` - the dialect SchemaValidator enforces has no way to say
        // "integer or string" - so the validator lets a boolean or a float through, and
        // `wpmcp_list_author_id(true)` resolved `(string) true === '1'` to user 1. A
        // silent cast to whoever installed the site is not an answer to `author: true`.
        // Before the capability since sprint 14d, because an unchanged author needs none.
        if (!is_int($a['author']) && !(is_string($a['author']) && trim($a['author']) !== '')) {
            return new WP_Error(
                'wpmcp_bad_arg',
                'author must be a user id (integer) or a user login (non-empty string).'
            );
        }

        $authorId = wpmcp_list_author_id($a['author']);
    }

    if (isset($a['author']) && !($current && $authorId === (int) $current->post_author)) {
        if (!$pto || !current_user_can($pto->cap->edit_others_posts)) {
            return wpmcp_cannot('set the author of ' . $postType . ' content');
        }

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

    if (isset($a['featured_image'])
        && !($current && (int) $a['featured_image'] === (int) get_post_thumbnail_id($current))) {
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

    return array('insert' => $insert, 'keep' => $keep, 'after' => $after, 'changed' => $changed);
}

/**
 * The fields of a post update-post reports on, as the row holds them now - for `changed`.
 *
 * `changed` IS A DIFF OF THIS, TAKEN BEFORE AND AFTER THE WRITE (sprint 14d). It used to be
 * the list of fields the call SENT, plus the two core moves (date, slug) found by comparing
 * those columns - so sending back an identical title reported `changed: ["title"]` (cold
 * client #4), and a status change that core turned into a re-slug was reported only because
 * somebody had thought of slug. Diffing every reported field means a field is named exactly
 * when its stored value differs, whoever changed it: the caller, core's re-dating, core's
 * re-slugging, core's default category, a `future` that core stored as `publish`.
 *
 * `date` is the pair post_date / post_date_gmt: a draft nobody dated getting its first GMT
 * value is a change of date even when the local time is the same. `terms` is every
 * taxonomy of the post type, because core can assign a default category on an update that
 * sent no terms. post_modified is not reported: every write moves it, so it would say
 * nothing.
 *
 * @return array<string, string>
 */
function wpmcp_post_state($postId) {
    $p = get_post($postId);
    if (!$p) { return array(); }

    $terms = array();
    foreach (get_object_taxonomies($p->post_type) as $taxonomy) {
        $ids = wp_get_object_terms($p->ID, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($ids)) { continue; }
        $ids = array_map('intval', (array) $ids);
        sort($ids);
        $terms[$taxonomy] = $ids;
    }
    ksort($terms);

    return array(
        'title'          => (string) $p->post_title,
        'content'        => (string) $p->post_content,
        'status'         => (string) $p->post_status,
        'excerpt'        => (string) $p->post_excerpt,
        'slug'           => (string) $p->post_name,
        'date'           => $p->post_date . '|' . $p->post_date_gmt,
        'author'         => (string) (int) $p->post_author,
        'featured_image' => (string) (int) get_post_thumbnail_id($p),
        'terms'          => (string) wp_json_encode($terms),
    );
}

/** The names of the fields that differ between two wpmcp_post_state() snapshots, in order. */
function wpmcp_post_state_diff($before, $after) {
    $changed = array();

    foreach ($before as $field => $value) {
        if (!array_key_exists($field, $after) || $after[$field] !== $value) { $changed[] = $field; }
    }

    return $changed;
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
            // The first sentence still ends by character 50, which is all a client shows
            // until a tool is loaded. WHAT IT RETURNS IS NO LONGER SPELLED OUT HERE: this
            // tool declares an outputSchema, and the field list lives in it with a
            // description per field (the pilot, D19 - see wpmcp_site_info_shape()).
            'description' => 'Get name, URL, WP version, theme, plugin count. Also wp_mcp:'
                . ' this plugin\'s own version and build, because "which build is this site'
                . ' running" is a question an agent has to be able to answer without leaving'
                . ' the tool surface. Every returned field is described in outputSchema.',
            // array() and not new stdClass(): endpoint.php's wpmcp_objectify_schema()
            // makes an empty `properties` serialize as `{}` wherever it appears, at any
            // depth, so the inline cast this used to carry is no longer the thing
            // keeping the listing valid - and a second way of saying it would drift.
            'inputSchema'  => array('type' => 'object', 'properties' => array()),
            'outputSchema' => wpmcp_result_schema(wpmcp_site_info_shape()),
            'run' => function ($args) {
                $theme = wp_get_theme();

                return wpmcp_result_build(
                    wpmcp_site_info_shape(),
                    // THE NAME ALONE, read and shaped by list-themes' own function: "Name
                    // Version" glued together was `"JDA "` for a theme with no Version
                    // header, while list-themes said `"JDA"` (sprint 14d). The version is
                    // its own key.
                    $theme ? wpmcp_theme_header($theme->get_stylesheet_directory() . '/style.css') : null
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
                . ' author (id or login), after and before (ISO 8601,'
                . ' inclusive), orderby ("date", "modified" or "title"; default "date"),'
                . ' order ("asc" or "desc"; default "desc"), limit (default 20, max 100)'
                . ' and page (default 1, max 100; has_more is false at page 100 - narrow the'
                . ' filter to reach further). A filter naming something that does'
                . ' not exist, or that the caller may not see, returns an empty'
                . ' list, not an error. Returns count, page, limit, has_more and items;'
                . ' each item is id, title (the stored column, as get-post gives it), type,'
                . ' status, slug, link, date and modified - ISO 8601 site-local, or null where'
                . ' the column holds no date. link is ?p=ID while a post is draft, pending,'
                . ' future or trashed. There is no total.',
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
                'page'      => array('type' => 'integer', 'description' => 'Page number. Clamped to 1-100; has_more is false at 100. Default 1.'),
            )),
            'run' => function ($args) {
                $type = isset($args['post_type']) ? sanitize_key($args['post_type']) : 'post';
                // Same allow-list the write tools use: no revisions, no nav_menu_item,
                // no wp_template, no attachments (media has its own tools).
                if (!wpmcp_post_type_ok($type)) {
                    return new WP_Error('wpmcp_bad_type', 'Not a listable post type: ' . $type);
                }
                // `limit` ONLY, not the per_page alias the media and comment tools accept:
                // this tool never took per_page, and an alias nobody used is a second name
                // for an agent to wonder about.
                list($limit, $page) = wpmcp_page_args(array_intersect_key($args, array('limit' => 1, 'page' => 1)));

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
                        'id' => $p->ID, 'title' => wpmcp_raw_title($p), 'type' => $p->post_type,
                        'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink($p),
                        // orderby: date is offered, so the date is part of the row (B-DATE).
                        'date' => wpmcp_iso_date($p->post_date),
                        'modified' => wpmcp_iso_date($p->post_modified),
                    );
                }
                // The shared envelope, which is where has_more stops at the page cap.
                return wpmcp_page_envelope($items, $page, $limit, $hasMore);
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
            // THE FIELD LIST MOVED INTO outputSchema (1.1.1, the pilot - D19). What is left
            // here is what a schema cannot say: the rules, the refusals and the warnings.
            'description' => 'Read one post or page in full. Args: id (required).'
                . ' title, content and excerpt are the stored columns, not the display'
                . ' rendering: quotes, apostrophes, ampersands and backslashes are as'
                . ' stored. Writing one back unchanged stores the same bytes: an equal field is'
                . ' not written at all. A post the caller may not read, one that is not there and an'
                . ' id of the wrong kind all answer identically. Every returned field is'
                . ' described in outputSchema.',
            'inputSchema' => array('type' => 'object',
                'properties' => array('id' => array('type' => 'integer', 'description' => 'Post ID.')),
                'required' => array('id')),
            'outputSchema' => wpmcp_result_schema(wpmcp_get_post_shape()),
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

                return wpmcp_result_build(wpmcp_get_post_shape(), array(
                    'post'      => $p,
                    'author'    => $author,
                    'revisions' => $revisions,
                ));
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
            . ' none. Title and content are stored as typed. Returns id, link, status, changed (the'
            . ' fields this call set), date and date_gmt when date was sent, and terms_refused'
            . ' or terms_failed when a term could not be assigned. link is the plain ?p=ID'
            . ' form while the post is draft, pending, future or trashed, whatever the'
            . ' permalink structure.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'title' => array('type' => 'string'), 'content' => array('type' => 'string'),
            'post_type' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object', 'description' => '{taxonomy: [term id, term name, or get-post\'s {id, name, slug} entry]}. An unknown name creates the term when you may create terms, and is listed in terms_refused when you may not.'),
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
                // THE TITLE AS TYPED - no stripping of ours (1.1.1). See
                // wpmcp_title_contract() for the whole rule and its measurements.
                'post_title'   => isset($a['title']) ? (string) $a['title'] : '',
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

            // SLASHED AT THE BOUNDARY, as one array rather than field by field.
            // wp_insert_post() unslashes the whole row it is about to write
            // (post.php:4981), and the kses filters on `content_save_pre` for a user
            // without unfiltered_html are `addslashes(wp_kses(stripslashes(...)))` - both
            // conventions expect slashed input, and raw JSON is not. Core's own posts
            // controller does exactly this (class-wp-rest-posts-controller.php:776).
            $id = wp_insert_post(wp_slash($postarr), true);
            if (is_wp_error($id)) { return $id; }
            // `link` is read at the END, after terms and the featured image (sprint 14d round
            // 3): under a %category% permalink structure the terms are part of the link.
            $out = array('id' => (int) $id);
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
            $out['link']    = get_permalink($id);
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
        // Every sentence here is a measurement (sprint 14d, both sites): the re-slug rule
        // is the status-transition table in that sprint's report, and the revision
        // sentence is what a revision-less post and a revisioned one each do.
        'description' => 'Update a post or page. Args: id (required) plus any of title,'
            . ' content, status, excerpt, slug, terms, date, author and'
            . ' featured_image. A field sent REPLACES what was there;'
            . ' one equal to what is stored is not written, and an update that changes'
            . ' nothing writes nothing. A changed title is stored as typed. Returns id,'
            . ' link, status and changed: every field whose'
            . ' stored value now differs, core\'s moves included - it re-dates an undated'
            . ' draft, and on a status change re-slugs: leaving draft or pending, a slug-less'
            . ' post gets one from its title and a taken slug a -N suffix; trashing appends'
            . ' __trashed. link is ?p=ID for a draft, pending, future or trashed post.'
            . ' date and date_gmt come back when date moved or was sent.'
            . ' Refused, naming who, while another user edits it. After a TEXT change'
            . ' the NEWEST revision holds what you sent, the one below the pre-edit text'
            . ' restore-revision undoes to; otherwise none is added, except by the'
            . ' first write that CHANGES something to a post with none.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'title' => array('type' => 'string'),
            'content' => array('type' => 'string'),
            'status' => array('type' => 'string', 'description' => 'A post status: draft, pending, private, future, publish or trash. Core stores "future" for a future date and publishes a past one, whatever status you send, so read status and date in the result.'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object', 'description' => '{taxonomy: [term id, term name, or get-post\'s {id, name, slug} entry]}. Replaces the post\'s terms in each taxonomy named; an empty list clears that taxonomy, except that WordPress gives a post left with no category its default category. An unknown name creates the term when you may create terms, and is listed in terms_refused when you may not.'),
            'date' => array('type' => 'string', 'description' => 'ISO 8601 date or datetime: 2026-03-04, 2026-03-04T09:30:00, or 2026-03-04T09:30:00+02:00. Without an offset it is this site\'s local time. Kept on a draft, which WordPress would otherwise re-date. To SCHEDULE, send a future date with status "future".'),
            // See create-post: no `type` because the dialect cannot say "integer or
            // string", and this argument is honestly both.
            'author' => array('description' => 'User id (integer) or user login (string). Changing it needs the capability to edit other people\'s posts of this type, and the target must be able to write them; sending the current author needs nothing.'),
            'featured_image' => array('type' => 'integer', 'description' => 'Attachment id of an image you are allowed to edit, or 0 to remove the featured image. Sending the current one changes nothing.'),
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
            // NOT OVER A COLLEAGUE'S OPEN EDITOR - the same refusal restore-revision makes, from
            // the same helper. After edit_post, so only a caller who may edit the post learns
            // that somebody else is; before every other gate and every write, the baseline
            // revision included, so a refused call leaves nothing behind.
            $locked = wpmcp_post_lock_refusal($id);
            if ($locked) { return $locked; }
            // A FIELD SENT WITH THE VALUE IT ALREADY HAS IS NOT WRITTEN (sprint 14d) - the
            // round-trip rule; see wpmcp_post_fields(). The comparison is with what the
            // caller SENT and the column as it stands, which since 1.1.1 is the whole of it:
            // nothing of ours shapes a title on the way in any more, so "sent equals stored"
            // means exactly "nothing to do". See wpmcp_title_contract().
            $upd = array('ID' => $id);
            if (isset($a['title']) && (string) $a['title'] !== (string) $p0->post_title) {
                $upd['post_title'] = (string) $a['title'];
            }
            if (isset($a['content']) && (string) $a['content'] !== (string) $p0->post_content) {
                $upd['post_content'] = (string) $a['content'];
            }
            if (isset($a['status'])) {
                $status = sanitize_key($a['status']);
                // THE GATES RUN ON WHAT WAS SENT, unchanged or not: a Contributor sending
                // `publish` is refused whether or not the post is already published, which
                // it cannot edit anyway. Only the write is skipped for an unchanged value.
                //
                // Publishing somebody else's draft is a capability of its own, and
                // edit_post does not imply it (a Contributor may edit, never publish).
                // `private` is in that set too - see wpmcp_publishing_statuses().
                if (in_array($status, wpmcp_publishing_statuses(), true)) {
                    $pto = get_post_type_object($p0->post_type);
                    if (!current_user_can($pto->cap->publish_posts)) {
                        return wpmcp_cannot('publish ' . $p0->post_type . ' content');
                    }
                }
                // Trashing through update-post is a delete by another name, so it
                // answers to delete_post, not edit_post - otherwise delete-post's
                // gate is one argument away from being bypassed.
                if ($status === 'trash' && !current_user_can('delete_post', $id)) {
                    return wpmcp_cannot('trash post ' . $id);
                }
                if ($status !== (string) $p0->post_status) { $upd['post_status'] = $status; }
            }
            // THE SHARED STEP - the same one create-post calls, which is what keeps the
            // two tools' idea of excerpt, slug, date, author and featured_image identical.
            // It runs AFTER the edit_post gate above, so a caller who may not touch this
            // post at all is never handed a verdict about an attachment or a user. Handed
            // the stored row, so an unchanged value is left alone rather than re-written.
            $fields = wpmcp_post_fields($a, $p0->post_type, $p0);
            if (is_wp_error($fields)) { return $fields; }

            $upd = array_merge($upd, $fields['insert']);

            // NOTHING TO WRITE, NOTHING WRITTEN. Every sent post column equals the stored
            // one, so wp_update_post() is not called at all: no baseline revision, no
            // re-dating of a floating draft, no modified date moved. Measured before this
            // (both sites): an update sending back an identical title created a revision on
            // a post that had none, and moved a floating draft's date to now.
            $writes = count($upd) > 1;
            if ($writes) {
                // A date sent back unchanged is held rather than dropped - see `keep` in
                // wpmcp_post_fields(): without edit_date core would re-date a floating draft
                // as part of this very write.
                $upd = array_merge($fields['keep'], $upd);
            }

            // THE BEFORE HALF OF `changed`. See wpmcp_post_state().
            $before = wpmcp_post_state($id);

            // SLASHED, and here it is not only wp_insert_post's unslash at the far end:
            // wp_update_post() reads the existing row and calls `wp_slash($post)` on it
            // (post.php:5345, "Escape data pulled from DB") before merging our array over
            // it. An unslashed array merged into a slashed row is two conventions in one
            // structure, and every field we sent comes out one backslash short.
            // class-wp-rest-posts-controller.php:980 does the same thing.
            // THE UNDO BASELINE, BEFORE THE WRITE. Core saves a revision AFTER an update,
            // of the NEW state (post_updated -> wp_save_post_revision, default-filters.php:
            // 446), and saves none on create (wp_save_post_revision_on_insert returns when
            // !$update, revision.php:108). So a post with no revisions - everything
            // create-post makes, everything imported - lost its original title, content
            // and excerpt on its first update, with nothing for restore-revision to put
            // back. Saving the CURRENT state here closes that. It costs nothing on a post
            // that is already revisioned: core compares with the latest revision and saves
            // only when a revisioned field differs (revision.php:159-212) - MEASURED on both
            // sites, a post with an up-to-date revision keeps its count. After every refusal
            // above, so a refused call writes no revision either, and only when there is a
            // write: an update that changes nothing leaves the revisions as they were.
            // No slashing question: it is handed an id and reads the row itself.
            //
            // WHAT A CLIENT SEES, measured on both sites (sprint 14d): the FIRST write to a
            // post with no revisions adds one revision whatever it changes - this baseline,
            // holding the text as it was - and core saves nothing more unless the text
            // changed. So "no text change, no revision" holds only once a post has one;
            // core's own post_updated handler does the same without this call (measured:
            // a status-only wp_update_post on a revision-less post leaves one revision).
            //
            // THE COLUMNS FIRST, THEN TERMS AND THE IMAGE, THEN - ONLY IF THOSE CHANGED
            // SOMETHING - ONE MORE SAVE (sprint 14d round 3). Two constraints, each measured
            // or read against core, and each broken by one of the earlier orders:
            //
            //   A failed save must leave nothing half-written. Terms and the thumbnail touch
            //   no posts column, so if they went first and wp_update_post() then refused
            //   (empty content, a wp_insert_post_data filter, a database error), the caller
            //   got an error with its terms already changed (review round 2, should-fix 1).
            //   So a column write goes first, and a refusal returns before anything else.
            //
            //   Any change must be a real save. A terms-only or image-only update that never
            //   called wp_update_post() left `modified` alone and fired no save_post, so cache
            //   and search plugins never heard of it (round 1, should-fix 1). So when terms or
            //   the image changed the row, a second save follows, carrying the date as it now
            //   stands with edit_date - the `keep` shape - so a floating draft is not re-dated.
            //   That save also applies core's default-category rule to cleared categories
            //   ("'post' requires at least one category"), every time, whichever path ran.
            if ($writes) {
                wp_save_post_revision($id);

                $r = wp_update_post(wp_slash($upd), true);
                if (is_wp_error($r)) { return $r; }
            }

            $out = array('id' => $id);
            $mid = wpmcp_post_state($id);
            if (!empty($a['terms']) && is_array($a['terms'])) {
                $t = wpmcp_apply_terms($id, $a['terms']);
                if ($t['refused']) { $out['terms_refused'] = $t['refused']; }
                if ($t['failed'])  { $out['terms_failed']  = $t['failed']; }
            }

            wpmcp_apply_post_fields($id, $fields['after'], array());

            if (wpmcp_post_state($id) !== $mid) {
                $now = get_post($id);
                if (!$writes) { wp_save_post_revision($id); }

                $r = wp_update_post(wp_slash(array(
                    'ID'        => $id,
                    'post_date' => (string) $now->post_date,
                    'edit_date' => true,
                )), true);
                if (is_wp_error($r)) { return $r; }
            }

            // EVERY FIELD OF THE RESULT IS READ AFTER THE LAST WRITE (round 3, the blocker):
            // `link` was read before the save in round 2, so publishing a draft answered its
            // `?p=N` link. `changed`, `link`, `status`, `author`, `featured_image` and the
            // date all come from the row as it now stands.
            $changed = wpmcp_post_state_diff($before, wpmcp_post_state($id));
            $applied = wpmcp_apply_post_fields($id, $fields['after'], array());

            // The new date beside `changed`, whenever the date moved or was sent: a caller
            // that set one, or whose draft core re-dated, gets the stored value without a
            // second call. Same fragment create-post returns.
            if (in_array('date', $changed, true) || array_key_exists('date', $a)) {
                $applied = array_merge($applied, wpmcp_apply_post_fields($id, array(), array('date')));
            }

            $out = array_merge($out, $applied);
            $p = get_post($id);
            $out['link']    = get_permalink($id);
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
        'description' => 'Delete a post or page. Args: id (required), force (default false).'
            . ' force=false moves it to the trash - a post already there stays there, and on a'
            . ' site with the trash switched off (EMPTY_TRASH_DAYS 0) WordPress deletes it'
            . ' permanently instead. force=true deletes it permanently, with its revisions.'
            . ' Trashing appends __trashed to the slug. Returns id, deleted (gone for good) and'
            . ' trashed (in the trash now), read back after the call. Needs permission to'
            . ' delete the post.',
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
            // force=false IS THE TRASH, FOR EVERY TYPE, and a post already in it stays
            // there (sprint 14d). wp_delete_post($id, false) trashes only a `post` or a
            // `page` that is not already trashed (post.php, the EMPTY_TRASH_DAYS branch) -
            // measured on the bare site: a custom post type, and a second force=false on a
            // trashed post, were both deleted PERMANENTLY while this tool answered
            // `trashed: true`. wp_trash_post() is what wp-admin's Trash link calls for any
            // type; it deletes permanently only where the site has no trash at all.
            if ($force) {
                $r = wp_delete_post($id, true);
            } elseif ($p0->post_status === 'trash') {
                $r = $p0;
            } else {
                $r = wp_trash_post($id);
            }
            if (!$r) { return new WP_Error('wpmcp_delete_failed', 'Could not delete.'); }
            // READ BACK, NOT ASSERTED: what the row says now is the only true answer.
            $left = get_post($id);
            return array(
                'id'      => $id,
                'deleted' => !$left,
                'trashed' => $left instanceof WP_Post && $left->post_status === 'trash',
            );
        },
    ),

    );
}

/* ============================================================
 * Revision tools (list-revisions / get-revision / restore-revision)
 *
 * UNDO, AS WORDPRESS ALREADY KEEPS IT. Every revisioned post carries its history as rows
 * of type `revision` whose post_parent is the post; these tools read that history and put
 * one entry of it back. Nothing is stored that core does not store.
 *
 * ONE CLASS OF ACCESS, AND IT IS WORDPRESS'S. A revision is editorial data, so the gate
 * is edit_post ON THE PARENT - wp-admin/revision.php:42 and the REST revisions controller
 * (class-wp-rest-revisions-controller.php:186) both ask exactly that, and get-post already
 * answers `revisions: null` to a caller who may read a post but not edit it. Every tool
 * here resolves through wpmcp_revision_parent() and every way of failing it answers the
 * SAME not_found, so a caller cannot learn by probing ids that a revision, or a post, is
 * there.
 * ========================================================== */

/**
 * The refusal for a post another user has open in the editor right now, or null.
 *
 * wp-admin refuses to restore over a colleague's open editor (wp-admin/revision.php:58), and
 * update-post - the far more common write - must refuse too, or the protection covers the
 * rare path only (review of sprint 12, S9). ONE HELPER, so the two tools cannot disagree.
 *
 * wp_check_post_lock() lives in wp-admin/includes/post.php, which a REST request does NOT
 * load; without the require the check is a fatal error, not a refusal. wp-cli does load it,
 * which is why only a test over HTTP can show the difference. It answers false for a lock the
 * CURRENT user holds (wp-admin/includes/post.php:1739), so the caller's own open editor is
 * never a refusal, and false once the lock is older than its 150-second window.
 *
 * NAMED, not not_found: every caller reaches this after passing edit_post on this very post,
 * so saying who is editing leaks nothing a wp-admin user would not see - and the display name,
 * never the login, is get-post's ceiling.
 *
 * @return WP_Error|null
 */
function wpmcp_post_lock_refusal($postId) {
    if (!function_exists('wp_check_post_lock')) {
        require_once ABSPATH . 'wp-admin/includes/post.php';
    }

    $lockedBy = wp_check_post_lock((int) $postId);
    if (!$lockedBy) { return null; }

    $holder = get_userdata((int) $lockedBy);

    return new WP_Error(
        'wpmcp_post_locked',
        'Post ' . (int) $postId . ' is being edited by '
        . ($holder ? $holder->display_name : 'another user')
        . ' right now. Try again when they have finished.'
    );
}

/**
 * The post a revision tool may act on, or null - THE CLASS OF REVISION ACCESS.
 *
 * Null for every one of: no such id, a post of a type the post tools refuse (attachment,
 * revision, nav_menu_item, wp_block, anything not viewable), and a post the caller may not
 * edit. The callers turn null into one wpmcp_not_found and never say which it was.
 *
 * @return WP_Post|null
 */
function wpmcp_revision_parent($postId) {
    $postId = (int) $postId;
    $post   = $postId > 0 ? get_post($postId) : null;

    if (!$post || !wpmcp_post_type_ok($post->post_type)) { return null; }
    if (!current_user_can('edit_post', $post->ID)) { return null; }

    return $post;
}

/**
 * A revision, with the post it belongs to, that the caller may read and restore - or the
 * one not_found every failure along the chain answers.
 *
 * THE CHAIN: the id is a post of type `revision`; its post_parent exists and passes
 * wpmcp_post_type_ok(); the caller holds edit_post on that parent. A normal post id, an
 * attachment id, a revision whose parent is gone or of a refused type, and a revision of a
 * post the caller may not edit all come back as the same sentence as an id nobody ever
 * used.
 *
 * @return array{revision: WP_Post, parent: WP_Post}|WP_Error
 */
function wpmcp_revision_for_edit($revisionId) {
    $notFound   = new WP_Error('wpmcp_not_found', 'No revision with that ID.');
    $revisionId = (int) $revisionId;

    if ($revisionId <= 0) { return $notFound; }

    // By reference in core's signature, hence the variable.
    $revision = wp_get_post_revision($revisionId);
    if (!$revision) { return $notFound; }

    $parent = wpmcp_revision_parent($revision->post_parent);
    if (!$parent) { return $notFound; }

    return array('revision' => $revision, 'parent' => $parent);
}

/**
 * The fields of one revision every revision tool reports, in get-post's own conventions:
 * the title as the stored column through wpmcp_raw_title() - never get_the_title() - the
 * date through wpmcp_iso_date(), and the author as an id and a DISPLAY NAME - never the
 * login, for get-post's reason.
 */
function wpmcp_revision_summary($revision) {
    $author = get_userdata((int) $revision->post_author);

    return array(
        'id'       => (int) $revision->ID,
        'date'     => wpmcp_iso_date($revision->post_date),
        'author'   => array(
            'id'   => (int) $revision->post_author,
            'name' => $author ? $author->display_name : null,
        ),
        'title'    => wpmcp_raw_title($revision),
        'autosave' => (bool) wp_is_post_autosave($revision),
    );
}

/**
 * The names of the fields wp_restore_post_revision() will copy from this revision.
 *
 * CORE'S OWN RULE, READ RATHER THAN RESTATED: the keys of _wp_post_revision_fields() that
 * are also columns of the revision row (revision.php:485-492). That is post_title,
 * post_content and post_excerpt on a bare site; the filter may add more, and it also
 * carries names that are NOT columns - core's `footnotes` on both sites, and field names
 * from ACF on the stress site (measured) - which core's array_intersect drops, so this
 * drops them too. The three core columns are reported by the names get-post uses.
 *
 * @return list<string>
 */
function wpmcp_revision_restored_fields($revision) {
    $row    = get_post($revision->ID, ARRAY_A);
    $fields = array_keys(_wp_post_revision_fields($row));
    $names  = array('post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt');
    $out    = array();

    foreach ($fields as $field) {
        if (!is_array($row) || !array_key_exists($field, $row)) { continue; }
        $out[] = isset($names[$field]) ? $names[$field] : $field;
    }

    return $out;
}

function wpmcp_revision_tools() {
    return array(

    'list-revisions' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        // A READ TOOL, so a read-scope token sees this description and never sees
        // update-post's or restore-revision's (sprint 14d, seosemia read-only cold client):
        // which revision is which has to be said HERE, and restoring must not be offered as
        // something this token can do.
        'description' => 'List a post\'s revisions, newest first. Args: id (integer,'
            . ' required), limit (1-100, default 20) and page (1-100, default 1; has_more is'
            . ' false at page 100). Returns count, page, limit, has_more and items; each'
            . ' item is id, date (ISO 8601, site-local: when the text it holds was saved to the'
            . ' post, not when the revision was made - trust the order, not the date),'
            . ' author {id, name}, title (the stored column) and autosave - true for an'
            . ' autosave, which is listed with the rest. WordPress saves a revision after each'
            . ' text change, so the newest non-autosave revision normally holds the post\'s'
            . ' CURRENT text and the one below it the text before the last change. No'
            . ' content here: get-revision reads one; an admin-scope token can restore one'
            . ' with restore-revision. Needs permission to edit the post; a post you may not'
            . ' edit, a post that is not there and an id of the wrong kind all answer'
            . ' identically. Where revisions are off for the post, the list is empty.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id'    => array('type' => 'integer', 'description' => 'Post ID.'),
            'limit' => array('type' => 'integer', 'description' => 'Items per page, 1-100. Default 20.'),
            'page'  => array('type' => 'integer', 'description' => 'Page number, 1-100. Default 1.'),
        ), 'required' => array('id')),
        'run' => function ($args) {
            $post = wpmcp_revision_parent(isset($args['id']) ? $args['id'] : 0);
            // get-post's sentence, because it is get-post's question: is there a post
            // with that id that this caller may see - here, may edit.
            if (!$post) { return new WP_Error('wpmcp_not_found', 'No post with that ID.'); }

            list($limit, $page) = wpmcp_page_args(array_intersect_key($args, array('limit' => 1, 'page' => 1)));

            // CORE'S LISTING, NOT A QUERY OF OUR OWN. wp_get_post_revisions() is what
            // wp-admin lists from: newest first on `date ID` (so two revisions saved in
            // one second still come back in the order they were made - measured), and
            // EMPTY when revisions are off for the post. That last is not worked around:
            // it is what wp-admin shows, and the description says so. One row past the
            // page is the has_more probe, as in list-posts.
            $revisions = array_values(wp_get_post_revisions($post->ID, array(
                'posts_per_page' => $limit + 1,
                'offset'         => ($page - 1) * $limit,
            )));

            $hasMore = count($revisions) > $limit;
            $items   = array_map('wpmcp_revision_summary', array_slice($revisions, 0, $limit));

            return wpmcp_page_envelope($items, $page, $limit, $hasMore);
        },
    ),

    'get-revision' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Read one revision of a post in full. Args: revision_id (integer,'
            . ' required), from list-revisions. Returns id, parent (the post id), title,'
            . ' content and excerpt - raw, exactly as get-post returns a post, so the two'
            . ' can be compared field by field; no diff is computed - plus date (ISO 8601,'
            . ' site-local), author {id, name} and autosave. Needs permission to edit the'
            . ' post the revision belongs to; a revision of a post you may not edit, an id'
            . ' that is not a revision and an id that is not there all answer identically.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'revision_id' => array('type' => 'integer', 'description' => 'Revision ID, from list-revisions.'),
        ), 'required' => array('revision_id')),
        'run' => function ($args) {
            $r = wpmcp_revision_for_edit(isset($args['revision_id']) ? $args['revision_id'] : 0);
            if (is_wp_error($r)) { return $r; }

            $revision = $r['revision'];
            $summary  = wpmcp_revision_summary($revision);

            return array(
                'id'       => $summary['id'],
                'parent'   => (int) $revision->post_parent,
                'title'    => $summary['title'],
                'content'  => $revision->post_content,
                'excerpt'  => $revision->post_excerpt,
                'date'     => $summary['date'],
                'author'   => $summary['author'],
                'autosave' => $summary['autosave'],
            );
        },
    ),

    'restore-revision' => array(
        'write' => true,
        // destructiveHint TRUE: the post's current title, content and excerpt are
        // REPLACED. That they are saved as a revision first makes it undoable, not
        // additive - update-post saves one too and carries the same judgement.
        // idempotentHint TRUE: a second restore of the same revision finds the post
        // already holding it, and core saves no revision for an unchanged post.
        'annotations' => array(
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Restore a post to one of its revisions. Args: revision_id'
            . ' (integer, required). Copies back title, content and excerpt, plus revisioned'
            . ' meta - footnotes and ACF fields. Author, slug and terms stay; status is'
            . ' re-derived as on any update, so a scheduled post whose date has passed is'
            . ' published. The current text is kept as a revision first, so it can be put'
            . ' back; that copy holds no ACF values, so an ACF rewind is not undoable here.'
            . ' Refused, saying why, while another user is editing the post, or when revisions'
            . ' are off for it and this is not an autosave. Returns id, restored_from, fields'
            . ' (post columns only), autosave, new_revision_id (the RESTORED text, null if'
            . ' the post held it) and pre_restore_revision_id (the text BEFORE). That is'
            . ' usually null: after any edit here or in wp-admin, the newest non-autosave'
            . ' revision list-revisions showed before the call holds it and is the undo'
            . ' copy. Both are null with revisions off.'
            . ' Needs permission to edit the post; anything else answers like a missing id.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'revision_id' => array('type' => 'integer', 'description' => 'Revision ID, from list-revisions.'),
        ), 'required' => array('revision_id')),
        'run' => function ($args) {
            $r = wpmcp_revision_for_edit(isset($args['revision_id']) ? $args['revision_id'] : 0);
            if (is_wp_error($r)) { return $r; }

            $revision = $r['revision'];
            $post     = $r['parent'];

            // NAMED REFUSALS FROM HERE ON. The caller has passed the edit_post gate on this
            // very post, so saying why leaks nothing - and an agent needs "locked, retry
            // later" and "never, on this post" to be different answers.
            //
            // wp-admin/revision.php:52 - with revisions off, only an autosave comes back.
            if (!wp_revisions_enabled($post) && !wp_is_post_autosave($revision)) {
                return new WP_Error(
                    'wpmcp_revisions_disabled',
                    'Revisions are turned off for post ' . $post->ID . ', so only an'
                    . ' autosave of it can be restored.'
                );
            }

            // wp-admin/revision.php:58 - not over somebody who is editing it now. See
            // wpmcp_post_lock_refusal() for the admin include this needs over REST.
            $locked = wpmcp_post_lock_refusal($post->ID);
            if ($locked) { return $locked; }

            // BOTH REVISIONS THIS CALL CAN STORE, caught as core stores them rather than
            // guessed at afterwards: _wp_put_post_revision fires once per revision saved,
            // with the parent's id, and not at all when the text already matched.
            //
            //   the BASELINE, from wp_save_post_revision() below, holds what the post said
            //   BEFORE this restore - but it is stored only when no revision holds that text
            //   yet, which is rare: after any edit through these tools or wp-admin the newest
            //   non-autosave revision already does, is the undo copy, and $baseline is null;
            //   the one core stores inside wp_restore_post_revision() holds the RESTORED
            //   text, and that is what `new_revision_id` has always reported.
            //
            // The two were read as one on a live site (2026-09-16): restoring 966 answered
            // new_revision_id 968, the restored text, while the pre-restore text sat in 967.
            // Both ids are returned now, each under its own name.
            $saved = array();
            $catch = static function ($revisionId, $parentId = 0) use (&$saved, $post) {
                if ((int) $parentId === (int) $post->ID) { $saved[] = (int) $revisionId; }
            };
            add_action('_wp_put_post_revision', $catch, 10, 2);

            $baseline = null;
            $created  = null;

            try {
                // THE UNDO BASELINE, before the write - see update-post. Normally a no-op:
                // the latest revision already matches the post (revision.php:159-212).
                wp_save_post_revision($post->ID);

                $baseline = $saved ? (int) end($saved) : null;
                $before   = count($saved);
                $fields   = wpmcp_revision_restored_fields($revision);

                // NOT SLASHED BY US. wp_restore_post_revision() reads the revision from the
                // database and slashes it itself (revision.php:498, "Since data is from
                // DB") before wp_update_post() unslashes it. Every other write in this file
                // is slashed at the boundary (KB 0.27); this one receives no caller string
                // at all, only an id, and slashing here would add a backslash to every
                // escape it restores.
                $restored = wp_restore_post_revision($revision->ID);
                $created  = count($saved) > $before ? (int) end($saved) : null;
            } finally {
                remove_action('_wp_put_post_revision', $catch, 10);
            }

            if (is_wp_error($restored)) { return $restored; }
            if (!$restored) {
                return new WP_Error('wpmcp_restore_failed', 'The revision could not be restored.');
            }

            return array(
                'id'                      => (int) $post->ID,
                'restored_from'           => (int) $revision->ID,
                'fields'                  => $fields,
                'autosave'                => (bool) wp_is_post_autosave($revision),
                'pre_restore_revision_id' => $baseline,
                'new_revision_id'         => $created,
            );
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
            . ' (optional). Returns id and `meta`, an object of key to value for every meta key'
            . ' this site allows MCP to touch that has a value on the post - one value when'
            . ' the key holds ONE row, a list of N values when it holds N rows (one row holding'
            . ' a serialised array also comes back as a list or object) - or just'
            . ' the one key you name. Values are strings as stored; a nested value is null.'
            . ' An administrator sets which keys those are in Settings > WP MCP;'
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
            . ' single=true - is not writable this way: a list sent to a key holding one such'
            . ' array is refused. An object, a list holding one, and'
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
                // A LIST WRITTEN BACK ONTO ONE SERIALISED ARRAY IS REFUSED (sprint 14d).
                // get-post-meta returns a key holding ONE row that is a serialised array (an
                // ACF repeater, a `single` key) as that array - the same JSON a key of N rows
                // gives - and writing it back here would store N rows where there was one
                // array: the round trip changes the shape of the data. The description has
                // always said such a field is not writable this way; now the tool refuses
                // rather than corrupting it. A scalar or null still replaces it, deliberately.
                $existing = get_post_meta($id, $key, false);
                if (is_array($existing) && count($existing) === 1 && is_array($existing[0])) {
                    return new WP_Error(
                        'wpmcp_meta_shape',
                        'The key ' . $key . ' holds one serialised array, and a list here would'
                        . ' replace it with one row per element. This tool cannot write that'
                        . ' shape; send a scalar, or null to delete the key.'
                    );
                }
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

/**
 * create-term's refusal for a name the taxonomy already has, naming the term that has it -
 * the id is what a caller that meant "make sure this term exists" needs next, and it is a
 * term the caller could list anyway.
 */
function wpmcp_term_exists_error($taxonomy, $termId) {
    return new WP_Error(
        'wpmcp_term_exists',
        'A term with that name already exists in ' . $taxonomy
        . ($termId > 0 ? ': term ' . (int) $termId : '') . '. Use it, or choose another name.'
    );
}

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
        'description' => 'List taxonomy terms, by name. Args: taxonomy (default "category"),'
            . ' search (in name and slug), hide_empty (default false), limit (default 20, max'
            . ' 100) and page (default 1, max 100; has_more is false at page 100 - narrow the'
            . ' search to reach further). Returns count, page, limit, has_more and items; each'
            . ' item is id, name, slug, taxonomy, count and parent (0 at the'
            . ' top). count is how many PUBLISHED posts are in the term: a term used only on'
            . ' drafts, pending or scheduled posts reads 0, and hide_empty leaves it out. No'
            . ' item carries a date - terms have no date column.'
            . ' name is as typed: WordPress stores & < > as &amp; &lt; &gt;, and they come'
            . ' back decoded, so a name read here can be sent to create-term or in update-post\'s'
            . ' terms and names the same term.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'search' => array('type' => 'string'),
            'hide_empty' => array('type' => 'boolean'),
            'limit' => array('type' => 'integer', 'description' => 'Terms per page. Clamped to 1-100. Default 20.'),
            'page'  => array('type' => 'integer', 'description' => 'Page number. Clamped to 1-100; has_more is false at 100. Default 1.'),
        )),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : 'category';
            if (!taxonomy_exists($tax)) { return new WP_Error('wpmcp_bad_taxonomy', 'Unknown taxonomy.'); }
            // PAGED SINCE SPRINT 14d, in the envelope every other list tool returns. It used
            // to return every term of the taxonomy in one answer, which on a site with
            // thousands of tags is an answer no client should be handed unasked, and it was
            // the one list tool whose result had a different shape (`terms`, no page).
            list($limit, $page) = wpmcp_page_args(array_intersect_key($a, array('limit' => 1, 'page' => 1)));
            $terms = get_terms(array(
                'taxonomy'   => $tax,
                'hide_empty' => !empty($a['hide_empty']),
                'search'     => isset($a['search']) ? (string) $a['search'] : '',
                // One past the page: the has_more probe, as in every other list tool.
                'number'     => $limit + 1,
                'offset'     => ($page - 1) * $limit,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ));
            if (is_wp_error($terms)) { return $terms; }
            $more = count($terms) > $limit;
            $out  = array();
            foreach (array_slice($terms, 0, $limit) as $t) {
                $out[] = array('id' => (int) $t->term_id, 'name' => wpmcp_decode_specialchars($t->name),
                    'slug' => $t->slug, 'taxonomy' => $t->taxonomy, 'count' => (int) $t->count,
                    'parent' => (int) $t->parent);
            }
            return wpmcp_page_envelope($out, $page, $limit, $more);
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
        'description' => 'Create a taxonomy term. Args: taxonomy (required), name (required), slug,'
            . ' parent (a term id, hierarchical taxonomies only) and description. Tags are'
            . ' stripped from the name, and the result does not say so. Returns id,'
            . ' name (as typed - see list-terms) and slug; a slug already taken gets a -N suffix.'
            . ' A name the taxonomy already has (under the same parent) is refused, naming the'
            . ' existing term\'s id. Needs permission to edit terms in the taxonomy.',
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
            // BOTH SLASHED, name and args, exactly as
            // class-wp-rest-terms-controller.php:550 does: wp_insert_term() unslashes
            // `name` AND `description` (taxonomy.php:2509-2511). `slug` went through
            // sanitize_title, which strips a backslash, and `parent` is an int, so
            // slashing the whole array is a no-op on those two and the rule stays one
            // rule rather than a list of exceptions.
            //
            // A NAME THE TAXONOMY ALREADY HAS IS REFUSED BY NAME, twice over (sprint 14d).
            // Core refuses it with its own `term_exists` code, which is not a wpmcp_ code, so
            // the error boundary turned "that category already exists" into an opaque -32603
            // with a trace id - a client re-creating a term it had just read was left to
            // guess. And core does not refuse it at all when the name holds a BACKSLASH: its
            // duplicate check looks the name up through WP_Term_Query, which stripslashes()
            // the name first (measured on both sites: `A\B` created twice, the second as
            // slug `ab-2`). So the lookup is made here too, slashed, the way
            // wpmcp_apply_terms() makes it - the same term by the same rule in both tools -
            // and core's own answer is translated rather than passed through.
            $name     = (string) $a['name'];
            $existing = get_term_by('name', wp_slash($name), $tax);
            if ($existing && !isset($args['slug'])
                && (!is_taxonomy_hierarchical($tax) || (int) $existing->parent === (int) ($args['parent'] ?? 0))) {
                return wpmcp_term_exists_error($tax, (int) $existing->term_id);
            }
            $r = wp_insert_term(wp_slash($name), $tax, wp_slash($args));
            if (is_wp_error($r) && $r->get_error_code() === 'term_exists') {
                return wpmcp_term_exists_error($tax, (int) $r->get_error_data());
            }
            if (is_wp_error($r)) { return $r; }
            $t = get_term($r['term_id'], $tax);
            return array('id' => (int) $r['term_id'], 'name' => wpmcp_decode_specialchars($t->name), 'slug' => $t->slug);
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
        'description' => 'Delete a taxonomy term. It goes permanently - terms have no trash. Args: taxonomy'
            . ' (required), id (required). Posts keep their other terms; a post left with no'
            . ' category gets the default one, and the taxonomy\'s default term cannot be'
            . ' deleted (refused, saying so). Returns id and deleted (true). Needs permission to'
            . ' delete terms in the taxonomy.',
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
            // 0 IS NOT "NOT FOUND". wp_delete_term() answers 0 for the taxonomy's DEFAULT
            // term (default_category, or a registered default_term) and false for a term
            // that is not there; both used to come back as "Term not found." - measured
            // (sprint 14d) on the default category, which plainly exists.
            if ($r === 0) {
                return new WP_Error(
                    'wpmcp_default_term',
                    'Term ' . (int) $a['id'] . ' is the default term of ' . $tax
                    . ', which WordPress does not delete. Make another term the default first.'
                );
            }
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
        'description' => 'List media attachments, newest first. Args: search (title, caption'
            . ' and description), mime_type ("image" or "image/png"), limit (default 20, max'
            . ' 100; per_page is accepted as its old name) and page (default 1, max 100; has_more'
            . ' is false at page 100 - narrow the search to reach further). Returns count, page,'
            . ' limit, has_more and items; each item is id, title (the stored column), mime, url'
            . ' (the rendered file link), and date and modified - ISO 8601 site-local, as'
            . ' list-posts gives them. Media attached to a post you may not read is left out,'
            . ' so count can be below limit while has_more is true.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'search' => array('type' => 'string'), 'mime_type' => array('type' => 'string'),
            'limit' => array('type' => 'integer', 'description' => 'Items per page. Clamped to 1-100. Default 20.'),
            'page' => array('type' => 'integer', 'description' => 'Page number. Clamped to 1-100; has_more is false at 100. Default 1.'),
            'per_page' => array('type' => 'integer', 'description' => 'The old name of limit; limit wins when both are sent.'),
        )),
        'run' => function ($a) {
            // THE SHARED ENVELOPE (sprint 14d): limit + 1 rows from an offset, rather than
            // core's `paged`, so has_more can be answered without a total, and an ID
            // tie-break so two uploads in one second cannot swap between pages.
            list($limit, $page) = wpmcp_page_args($a);
            $q = new WP_Query(array(
                'post_type' => 'attachment', 'post_status' => 'inherit',
                // Slashed for the same reason as list-posts' search: parse_search()
                // stripslashes `s` (class-wp-query.php:1439).
                's' => isset($a['search']) ? wp_slash((string) $a['search']) : '',
                'post_mime_type' => isset($a['mime_type']) ? (string) $a['mime_type'] : '',
                'posts_per_page' => $limit + 1,
                'offset' => ($page - 1) * $limit,
                'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'update_post_meta_cache' => false,
            ));
            $more = count($q->posts) > $limit;
            $out = array();
            foreach (array_slice($q->posts, 0, $limit) as $p) {
                // An attachment's read_post resolves through its parent post's status,
                // so this is what keeps the media of a private or draft post - titles
                // and, worse, direct file URLs - out of a token that cannot read the
                // post it belongs to. WP_Query has no perm handling for
                // post_status=inherit, so the filter has to be here.
                if (!current_user_can('read_post', (int) $p->ID)) { continue; }

                // date and modified are the LOCAL columns through wpmcp_iso_date(), as every
                // other list tool gives them. Before sprint 14d `date` was post_date_gmt as
                // MySQL stores it, `2026-08-27 02:17:03` - another format and another zone
                // from the tool beside it.
                $out[] = array('id' => $p->ID, 'title' => wpmcp_raw_title($p), 'mime' => $p->post_mime_type,
                    'url' => wp_get_attachment_url($p->ID),
                    'date' => wpmcp_iso_date($p->post_date),
                    'modified' => wpmcp_iso_date($p->post_modified));
            }
            return wpmcp_page_envelope($out, $page, $limit, $more);
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
        'description' => 'Get one media item. Args: id (required). title, alt and caption are'
            . ' stored columns or meta, as written; url is the rendered link. Media attached to'
            . ' a post you may not read answers like a missing id. Every returned field is'
            . ' described in outputSchema.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('id' => array('type' => 'integer')), 'required' => array('id')),
        'outputSchema' => wpmcp_result_schema(wpmcp_get_media_shape()),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = wpmcp_get_attachment($id);
            if (is_wp_error($p)) { return $p; }
            // Same non-disclosing refusal get-post uses: identical to the message a
            // missing id returns, so ids cannot be probed for existence.
            if (!current_user_can('read_post', $id)) {
                return new WP_Error('wpmcp_not_found', 'No attachment with that ID.');
            }
            return wpmcp_result_build(wpmcp_get_media_shape(), $p);
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
        'description' => 'Upload media by sideloading a URL. This site downloads the file - the only'
            . ' way in; there is no way to send the file\'s bytes, so the remote server must'
            . ' serve it to THIS site, which a URL that opens in your browser may not be.'
            . ' Args: source_url (required, http or'
            . ' https, fetched within 20 seconds), filename (default: the URL\'s own), title'
            . ' (default: from the file name), alt, and post (an id to attach it to, which you'
            . ' must be able to edit). The file must be a type WordPress lets you upload (for most'
            . ' roles: images, audio, video, PDF and office documents) and within the site\'s'
            . ' upload size limit. alt is stored as plain text: tags stripped, < as &lt;.'
            . ' A refusal by the remote server is reported with its HTTP status; a fetch that never'
            . ' connected answers "Internal error (trace <id>)" - quote that id to the operator,'
            . ' who can look the event up. Returns id, url and mime. Needs permission to upload files.',
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
            // The STATUS, never the body - see wpmcp_fetch_error(). A code it does not
            // recognise comes back untouched and stays generic with a trace id.
            if (is_wp_error($tmp)) { return wpmcp_fetch_error($tmp); }
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
            // THE TITLE IS SLASHED. media_handle_sideload() puts it straight into the
            // attachment array it hands to wp_insert_attachment()
            // (wp-admin/includes/media.php:518, :528), which is wp_insert_post() and
            // unslashes. `$file['name']` needs nothing: sanitize_file_name() lists the
            // backslash among the characters it removes.
            $id = media_handle_sideload(
                $file,
                $post,
                isset($a['title']) ? wp_slash((string) $a['title']) : null
            );
            if (is_wp_error($id)) { @unlink($tmp); return $id; }
            // SANITISE FIRST, THEN SLASH - sanitize_text_field() is a sanitiser for
            // unslashed text, and update_post_meta() unslashes what it is given. The KEY
            // is a literal with no backslash in it, so it is left alone, which is what
            // class-wp-rest-attachments-controller.php:1323 does with this same key.
            if (isset($a['alt'])) {
                update_post_meta(
                    $id,
                    '_wp_attachment_image_alt',
                    wp_slash(sanitize_text_field((string) $a['alt']))
                );
            }
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
        'description' => 'Delete a media attachment. Args: id (required), force (default false).'
            . ' Unless the site turns media trash on (MEDIA_TRASH, off by default), WordPress'
            . ' deletes an attachment permanently whatever force says - the row, its files and'
            . ' every generated size; with MEDIA_TRASH on, force=false moves it to the trash.'
            . ' Returns id, deleted (gone for good) and trashed (in the trash now), read back'
            . ' after the call. Needs permission to delete the attachment.',
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
            // force=false IS HONOURED ONLY WHERE CORE HAS A MEDIA TRASH (sprint 14d):
            // wp_delete_attachment() trashes only when MEDIA_TRASH is true, and it is false
            // unless wp-config sets it - measured on both sites, force=false deleted the
            // attachment outright. The description says so, and the answer is read back.
            $r = wp_delete_attachment($id, !empty($a['force']));
            if (!$r) { return new WP_Error('wpmcp_delete_failed', 'Could not delete.'); }
            $left = get_post($id);
            return array(
                'id'      => $id,
                'deleted' => !$left,
                'trashed' => $left instanceof WP_Post && $left->post_status === 'trash',
            );
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
        'description' => 'List comments the caller may read, newest first. Emails and IPs are'
            . ' never returned. Args: post (id), status (default "approve"; hold, spam, trash and'
            . ' all need moderate_comments and are otherwise treated as "approve"; the returned'
            . ' words approved and unapproved are accepted too), search (comment text and author'
            . ' name), limit (default 20, max 100; per_page is its old name) and page (default 1,'
            . ' max 100; has_more is false at page 100 - narrow the filter to reach further).'
            . ' Returns count, page, limit, has_more and items; each item is id, post, author_name,'
            . ' content (stored text), status (approved, unapproved, spam or trash) and date (ISO'
            . ' 8601 site-local). Comments on posts you may not read are left out, so count can be'
            . ' below limit while has_more is true.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'post' => array('type' => 'integer'), 'status' => array('type' => 'string'),
            'search' => array('type' => 'string'),
            'limit' => array('type' => 'integer', 'description' => 'Comments per page. Clamped to 1-100. Default 20.'),
            'page' => array('type' => 'integer', 'description' => 'Page number. Clamped to 1-100; has_more is false at 100. Default 1.'),
            'per_page' => array('type' => 'integer', 'description' => 'The old name of limit; limit wins when both are sent.'),
        )),
        'run' => function ($a) {
            // WP_Comment_Query performs no capability checks of any kind (zero
            // current_user_can calls in the class), so every restriction here is this
            // tool's own. wp-admin gates unapproved comments on moderate_comments and
            // core's REST controller checks read_post per comment; match both.
            $status = isset($a['status']) ? sanitize_key($a['status']) : 'approve';
            // The words this tool RETURNS are accepted as filters too (sprint 14d), so a
            // status read off one comment can be used to list its siblings.
            $aliases = array('approved' => 'approve', 'unapproved' => 'hold');
            if (isset($aliases[$status])) { $status = $aliases[$status]; }
            if ($status !== 'approve' && !current_user_can('moderate_comments')) {
                // Fall back rather than refuse: an error would confirm that held or
                // spam comments exist and are being withheld. Same stance as
                // list-posts on an unpermitted status, and get-post on read_post.
                $status = 'approve';
            }

            // THE SHARED ENVELOPE (sprint 14d): limit + 1 from an offset, for has_more.
            // WP_Comment_Query appends comment_ID to a date ordering itself, so pages do
            // not overlap on comments posted in the same second.
            list($limit, $page) = wpmcp_page_args($a);
            $args = array(
                // Approved only unless asked otherwise. The old default of 'all' handed
                // spam and held-for-moderation text - unreviewed, attacker-supplied
                // content - to every read token without anyone asking for it.
                'status' => $status,
                'number' => $limit + 1,
                'offset' => ($page - 1) * $limit,
                'no_found_rows' => true,
            );
            if (isset($a['post'])) { $args['post_id'] = (int) $a['post']; }

            // `search` is NOT passed to WP_Comment_Query: its `search` var hard-codes the
            // columns comment_author, comment_author_email, comment_author_url,
            // comment_author_IP and comment_content, and there is no FILTER on that
            // choice. A tool that says "emails omitted" while letting a caller
            // prefix-probe them by search does not omit them. The clause is built here
            // over the two safe columns instead, which keeps the filtering - and
            // therefore the pagination - in SQL.
            //
            // AND THE COLUMN LIST *IS* REACHABLE, WHICH THIS COMMENT USED TO DENY (sprint
            // CORE-FIX). It said the columns "cannot be narrowed", full stop, and that is
            // false: `WP_Comment_Query::get_search_sql( $search, $columns )` takes the
            // column list as a parameter and the class's `__call()` proxy forwards exactly
            // that one name, so it is publicly callable
            // (class-wp-comment-query.php:132-134, :1169). What core gives no hook for is
            // the list `$query_vars['search']` uses; the SQL builder itself is ours to call.
            // Calling it would replace the two `$wpdb->prepare` lines below with one core
            // call - which is a refactor, deliberately NOT done here, and it is recorded so
            // the next author finds a true claim rather than a closed door.
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

            $more = count($cs) > $limit;
            $out = array();
            foreach (array_slice($cs, 0, $limit) as $c) {
                // A comment on a post the caller cannot read is a read of that post:
                // it leaks the post's existence plus author names, text and dates.
                // This is the same leak get-post closes, one indirection away.
                if (!current_user_can('read_post', (int) $c->comment_post_ID)) { continue; }

                // date is the LOCAL column in the list tools' one format (sprint 14d); it
                // was comment_date_gmt as MySQL stores it.
                $out[] = array('id' => (int) $c->comment_ID, 'post' => (int) $c->comment_post_ID,
                    'author_name' => $c->comment_author, 'content' => $c->comment_content,
                    'status' => wp_get_comment_status((int) $c->comment_ID),
                    'date' => wpmcp_iso_date($c->comment_date));
            }
            // count is what the caller may see, so it is smaller than limit when the page
            // held comments on unreadable posts. Paging is still by limit.
            return wpmcp_page_envelope($out, $page, $limit, $more);
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
        'description' => 'Moderate a comment. Args: id (required), action (required): approve,'
            . ' unapprove, spam, trash or untrash - list-comments\' status words approved and'
            . ' unapproved are accepted too. Returns id and status (approved, unapproved, spam or'
            . ' trash). Needs permission to moderate comments, or to edit the comment.',
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
            // THE STATUS A COMMENT WAS READ WITH IS AN ACTION TOO (sprint 14d). list-comments
            // reports `approved` / `unapproved`, and sending that word back was "Unknown
            // action." - measured on the bare site.
            $aliases = array('approved' => 'approve', 'unapproved' => 'unapprove', 'hold' => 'unapprove');
            if (isset($aliases[$action])) { $action = $aliases[$action]; }
            $valid = array('approve', 'unapprove', 'spam', 'trash', 'untrash');
            if (!in_array($action, $valid, true)) { return new WP_Error('wpmcp_bad_action', 'Unknown action.'); }
            // ALREADY THERE IS NOT A FAILURE (sprint 14d). wp_set_comment_status() answers
            // false when its UPDATE touches no row, so approving an approved comment - which
            // is what sending back the status list-comments read does - came back "Action
            // failed." (measured on the bare site). The tool is declared idempotent; now it is.
            $already = array('approve' => 'approved', 'unapprove' => 'unapproved', 'spam' => 'spam', 'trash' => 'trash');
            $current = wp_get_comment_status($id);
            if ((isset($already[$action]) && $current === $already[$action])
                || ($action === 'untrash' && $current !== 'trash')) {
                return array('id' => $id, 'status' => $current);
            }
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
        'description' => 'Reply to a comment, as the token\'s user. Args: id (required, the comment'
            . ' replied to) and content (required). The reply goes through WordPress\'s own'
            . ' comment checks - moderation, blocklist, duplicate and flood - and a comment'
            . ' plugin such as Akismet, so it may be held rather than approved. Returns id and'
            . ' status (approved, unapproved or spam). Needs permission to edit the post; on a'
            . ' post with comments closed, only a moderator may reply.',
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
            //
            // SLASHED AS ONE ARRAY. wp_new_comment() runs wp_filter_comment() - whose
            // `pre_comment_content` filter is kses for a user without unfiltered_html,
            // and kses is addslashes(wp_kses(stripslashes(...))) - and then
            // wp_insert_comment(), which opens with `wp_unslash($commentdata)`
            // (comment.php:2159). Core's comments controller writes it as
            // `wp_insert_comment( wp_filter_comment( wp_slash( $prepared ) ) )`
            // (class-wp-rest-comments-controller.php:793), which is this, one layer up.
            $cid = wp_new_comment(wp_slash($comment), true);

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
 * Code-edit tools. Listed only when the switch in Settings > WP MCP is on AND nothing core
 * denies `edit_themes` for outright answers yes: DISALLOW_FILE_EDIT, the `file_mod_allowed`
 * filter, and - since sprint CORE-FIX - core's third branch, a network install and a caller
 * who is not a super admin. All three are wpmcp_code_constants_forbid(), which endpoint.php's
 * wpmcp_tools() asks before it merges these in. The remaining gate is the `edit_themes`
 * capability ITSELF, which is a property of the token's user's ROLE and is checked by each run
 * closure through wpmcp_code_forbidden(); a tool that this token's role may not use is still
 * LISTED, because another token's user may hold the role.
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
        'description' => 'List files and folders in the active theme. Args: path (a folder relative to the theme root, default "" for the root). Returns path (canonical) and entries: name, type (file or dir), size (bytes, 0 for a folder) and blocked (true when the denylist refuses it to every code tool).',
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
        'description' => 'Read a text file in the active theme. Args: path (required, relative to the theme root). Returns path (canonical) and content, the file\'s bytes exactly - code-write stores them back unchanged. Only php, css, js, html, json, txt, md and svg files up to 512KB; denylisted files are refused.',
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
        'description' => 'Create or overwrite a text file in the theme. Active theme only. Args: path (required), content (required, up to 512KB, stored byte for byte). The previous contents are stored as a version first (see code-history); PHP is parse-checked and auto-reverted on a syntax error. Returns path, bytes (written; 0 when reverted), created, reverted, version_id (the stored previous version, null for a new file) and, after a revert, error naming the line.',
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

            // AFTER THE WRITE, as core's editor does (wp-admin/includes/file.php:525).
            // A no-op on anything but a .php path; see wpmcp_opcache_invalidate().
            wpmcp_opcache_invalidate($r['abs']);

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
                    // AND AGAIN AFTER THE ROLLBACK (file.php:638). The bytes the cache was
                    // told about two lines up are no longer on disk, and a revert that
                    // leaves them compiled is the defect running backwards.
                    wpmcp_opcache_invalidate($r['abs']);
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
        'description' => 'Delete a file in the theme. Active theme only; its contents are stored as a version first, so code-history and code-restore can bring it back. Args: path (required). Returns path, deleted (true) and version_id (the stored copy).',
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

            // A DELETE IS A CHANGE TO A COMPILED FILE TOO - and it is the one place this
            // plugin invalidates BEFORE the change rather than after it. MEASURED, not
            // preferred: `opcache_invalidate()` resolves the path on the filesystem first
            // (`zend_accel_invalidate()`), so once the file is gone it answers false and
            // drops nothing - the entry for the deleted path survives. Called here, while
            // the path still resolves, it actually removes it. Core has no delete in its
            // editor to mirror, so this is core's rule ("the cache must not hold bytes
            // that are not on disk") applied at the only moment the API can act.
            //
            // If the unlink below then fails, the cache has been told about a file that
            // did not change - which costs one recompile and nothing else.
            //
            // THERE IS A RACE HERE THAT THIS API CANNOT CLOSE, AND IT IS NOT A REASON TO
            // MOVE THE CALL BACK DOWN. Between this line and the unlink, a CONCURRENT
            // request that includes this file recompiles it - the file is still on disk -
            // into a fresh, valid entry. The unlink then removes the file, and on a
            // `validate_timestamps=0` host that entry serves a file that is not there
            // until the pool restarts, because no `opcache_invalidate()` can reach a path
            // that no longer resolves. The window is two adjacent statements wide and
            // needs a concurrent include of that exact file inside it.
            //
            // Invalidating AFTER the unlink instead does not close it - it makes it
            // certain, for every delete, concurrent or not. Only `opcache_reset()` or a
            // pool restart clears the entry once the file is gone, and neither is a thing
            // a tool call may do to a site. So: before, knowingly.
            wpmcp_opcache_invalidate($r['abs']);

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
        'description' => 'List stored versions of a theme file. Args: path (required). Returns path and versions, newest first, each with id, saved_at (ISO 8601 site-local, as every date these tools return), size (bytes), sha256, reason (write, delete, restore or sweep) and saved_by (a login, or system). The site keeps a bounded number per file (20 by default), so there is no paging. A path with no stored versions returns an empty list, which is not an error. Pass an id to code-restore to put that version back.',
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
                    // STORED IN UTC, RETURNED SITE-LOCAL ISO 8601 (sprint 14d): the one date
                    // format of every list tool. It was the raw `Y-m-d H:i:s` UTC column.
                    'saved_at' => wpmcp_iso_date_from_gmt($row->saved_at),
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
        'description' => 'Restore a stored version of a theme file. Args: version_id (required), from code-history. Writes the stored bytes back to the path the version was taken from; the current contents are stored as a version first. PHP is parse-checked and auto-reverted on a syntax error. Returns path, bytes, sha256, matched (the bytes on disk match the stored hash), created, reverted, version_id (the copy of what it replaced) and, after a revert, error.',
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

            // The same two calls code-write makes, for the same reason: a restore is a
            // write, and a restore that fails its parse check is a rollback.
            wpmcp_opcache_invalidate($r['abs']);

            $reverted = false; $perr = null;
            if (strtolower(pathinfo($r['rel'], PATHINFO_EXTENSION)) === 'php') {
                $chk = wpmcp_php_parse_ok($content);
                if ($chk !== true) {
                    $perr = $chk;
                    if ($existed) { file_put_contents($r['abs'], $prior); } else { @unlink($r['abs']); }
                    $reverted = true;
                    wpmcp_opcache_invalidate($r['abs']);
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
 * TOOL - three table names and one function name - AND THEY EXIST BECAUSE THE SERVER CANNOT
 * MAKE THESE DECISIONS. Everything else the tool refuses is refused by MySQL itself - the
 * wrapper's grammar, the READ ONLY transaction, the statement timeout. But the WordPress
 * database user owns all three of this plugin's tables: it created them and it can read
 * them, and there is no GRANT this plugin can issue on its own connection to take that
 * away. So the things the server will happily do and must not are read the table of token
 * hashes, the table of theme-file bytes and the table of TRACES, and the only place that
 * can be stopped is here, before the statement is sent. `LOAD_FILE()` is the same shape of
 * problem with a different subject and lives in the other function.
 *
 * THE TRACES TABLE IS THE THIRD SINCE 1.1.2, AND IT IS THE ONE THIS TOOL MOST HAS TO REFUSE.
 * Every other denial here protects a credential or a file; this one protects the error
 * BOUNDARY. A trace row holds exactly the detail the boundary exists to keep from a caller -
 * the class, the message, the absolute file:line, the WP_Error data (which is where wpdb puts
 * the failing query) and the whole stack. The plugin hands a caller eight hex digits and
 * nothing else; leaving the table readable would let an admin-scope token that has just
 * caused a failure select the stack trace for it and undo the boundary through the back door.
 * The log file was never readable by a token at all, so this denial is what replaces the
 * filesystem as the wall. See analysis/53 D25's second cost.
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
        wpmcp_traces_table(),
        WPMCP_TABLE,
        WPMCP_VERSIONS_TABLE,
        WPMCP_TRACES_TABLE,
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

    // truncated_by IS ALWAYS PRESENT, null when nothing was cut (sprint 14d). The
    // description promised the key and the result carried it only on a truncated answer,
    // so a client that read it unconditionally met a missing key on every ordinary query
    // (seosemia.net, 2026-09-18). One shape, every time.
    $result = array(
        'columns'      => $columns,
        'rows'         => $out,
        'row_count'    => count($out),
        'truncated'    => $truncated,
        'truncated_by' => $truncated ? $truncatedBy : null,
    );

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
        'description' => 'Run one read-only SQL SELECT. Args: sql (required). The statement is wrapped as a derived table inside a READ ONLY transaction, so anything but a single SELECT is a server syntax error. CTEs, joins, UNION and ORDER BY work; SHOW, stacked statements, INTO OUTFILE and FOR UPDATE do not, and a derived table needs unique column names. Returns columns (names), rows (each a list in column order), row_count, truncated, and truncated_by - "rows" or "bytes" when truncated, null when not. At most 200 rows, 256KB of rows and 8KB per cell (cut with an ellipsis). Every value is a string as MySQL sends it - COUNT(*) comes back as "3", not 3 - NULL is null, and a non-UTF-8 value comes back as 0x-prefixed hex. The plugin\'s own tables and LOAD_FILE are refused, even when named only in a comment.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('sql' => array('type' => 'string')), 'required' => array('sql')),
        'run' => function ($a) {
            return wpmcp_sql_select_run(isset($a['sql']) ? (string) $a['sql'] : '');
        },
    ),

    );
}

/* ============================================================
 * Admin inventory: users, options, plugins, themes (sprint 14)
 * ========================================================== */

/**
 * THE CLASS OF INVENTORY ACCESS: core's REST gate is the ceiling, field by field. Every
 * field these five tools return is one core's own REST API, or the wp-admin screen the
 * caller can open, already shows that caller - and nothing else is read into a result:
 * no user meta, no password hash, no activation key, no session. Each tool builds its
 * result from named fields, never from a whole row. The sweep table is in the commit
 * that added them.
 *
 * AND list-plugins AND list-themes RUN NO HOOK A PLUGIN COULD MAKE A REQUEST FROM. The first
 * version read update data through get_site_transient() and fired auto_update_plugin, and
 * built the theme list through wp_get_themes() and WP_Theme; Gravity Forms, LiteSpeed Cache
 * and Rank Math each make requests from filters on that path once their own caches are cold
 * (sprint 14 review, round 1), and a test fixture hooked on those filters counted 174
 * requests from one list-plugins call on the stress site. So neither tool calls a WordPress
 * read that runs a filter or an action: stored settings come straight from their rows
 * (wpmcp_raw_option), plugin and theme headers from get_file_data() without a context, and
 * WP_Theme's rules for which folders are themes are restated in wpmcp_scan_themes(). The hooks
 * left are current_user_can()'s map_meta_cap and user_has_cap, which every tool's gate runs,
 * and wpdb's own `query` filter, which the token lookup has already run. The sweep table is in
 * the round-2 commit. list-users, get-user and get-option are not in that class: they run
 * core's user query and option read, as the REST API does, and no update code.
 */

/** The post types core counts a published author in: every type shown in REST. */
function wpmcp_user_rest_types() {
    return array_values(get_post_types(array('show_in_rest' => true), 'names'));
}

/**
 * May this caller use the user tools at all?
 *
 * Core's rule for a caller asking about authors (class-wp-rest-users-controller.php:
 * 237-251): list_users, or edit_posts on a REST post type that supports authors. Core's
 * plain collection is wider - it answers anybody, with published authors - but a token
 * whose user can edit nothing has no use for a list of authors, and this is the line
 * core itself draws the moment the question is "who writes here". A Subscriber is refused.
 */
function wpmcp_users_can_read() {
    if (current_user_can('list_users')) { return true; }

    foreach (get_post_types(array('show_in_rest' => true), 'objects') as $type) {
        if (post_type_supports($type->name, 'author') && current_user_can($type->cap->edit_posts)) {
            return true;
        }
    }

    return false;
}

/**
 * One user, as this caller may see them.
 *
 * id and name (the display name) for everybody who may see the user at all - get-post's
 * author shape. login, email, roles and the registered date only when $full: core shows
 * them in the `edit` context, which needs list_users on the collection (:220) and
 * edit_user on one user (:487), and roles to list_users or edit_user (:1096).
 * Named fields only: nothing from the row or its meta rides along.
 */
function wpmcp_user_out($user, $full) {
    return wpmcp_result_build(wpmcp_get_user_shape(), array('user' => $user, 'full' => (bool) $full));
}

/**
 * get-user's fields. THE FOUR PRIVILEGED ONES ARE ABSENT, NOT NULL, for a reader who may not
 * have them - which is why they carry `when` and are therefore not `required` in the schema.
 * "Absent" is the honest shape: a null `email` would say this user has no email address.
 *
 * list-users returns the same two public fields through the same declaration, so the two
 * tools cannot disagree about what a user looks like.
 */
function wpmcp_get_user_shape() {
    $full = function ($ctx) { return !empty($ctx['full']); };

    return array(
        'id' => array(
            'type' => 'integer',
            'description' => 'WordPress user ID.',
            'get'  => function ($ctx) { return (int) $ctx['user']->ID; },
        ),
        'name' => array(
            'type' => 'string',
            'description' => 'The display name the user chose - often their login, or an email address.',
            'get'  => function ($ctx) { return (string) $ctx['user']->display_name; },
        ),
        'login' => array(
            'type' => 'string',
            'description' => 'The username. Only for a caller with list_users, edit_user on this user, or themselves.',
            'when' => $full,
            'get'  => function ($ctx) { return (string) $ctx['user']->user_login; },
        ),
        'email' => array(
            'type' => 'string',
            'description' => 'The registered email address. Same condition as login.',
            'when' => $full,
            'get'  => function ($ctx) { return (string) $ctx['user']->user_email; },
        ),
        'roles' => array(
            'type'  => 'array',
            'items' => array('type' => 'string'),
            'description' => 'Role slugs on this site. Same condition as login.',
            'when'  => $full,
            'get'   => function ($ctx) { return array_values(array_map('strval', (array) $ctx['user']->roles)); },
        ),
        'registered' => array(
            'type'        => 'string',
            'nullable'    => true,
            // user_registered is stored in UTC. Core's REST field is `c`, UTC with +00:00
            // (:1102); this is the list tools' ONE format instead (sprint 14d) - ISO 8601,
            // site-local, no offset, as every post, revision, media and comment date here.
            'description' => 'When the account was created: ISO 8601, site-local, no offset. Same condition as login.',
            'when'        => $full,
            'get'         => function ($ctx) { return wpmcp_iso_date_from_gmt($ctx['user']->user_registered); },
        ),
    );
}

/**
 * The options get-option reads, and the JSON type each is returned as.
 *
 * A FIXED LIST IN CODE, NOT A SETTING. Wider than core's REST settings endpoint, which
 * needs manage_options (class-wp-rest-settings-controller.php:68), on purpose: every value
 * here is already visible on the public site or in its URLs, and an Editor scheduling a
 * post needs the timezone and the date formats. admin_email, this plugin's own wpmcp_*
 * options, the salts, active_plugins and every other option stay off it. The types are
 * core's register_setting() types where core registers one (start_of_week: integer,
 * option.php:2841); gmt_offset, which core does not register, is a number.
 */
function wpmcp_option_allow_list() {
    return array(
        'blogname'            => 'string',
        'blogdescription'     => 'string',
        'timezone_string'     => 'string',
        'gmt_offset'          => 'number',
        'date_format'         => 'string',
        'time_format'         => 'string',
        'start_of_week'       => 'integer',
        'permalink_structure' => 'string',
        'siteurl'             => 'string',
        'home'                => 'string',
    );
}

/**
 * A stored option, read straight from its row.
 *
 * NOT get_option(): that runs pre_option_{$name}, pre_option, alloptions, default_option_*
 * and option_{$name} (option.php:132, :150, :199, :256), and any plugin can hook those. This
 * runs none of them. $wpdb still applies its own `query` filter (class-wpdb.php:2230), which
 * every database read of this request - the token lookup included - has already run before
 * a tool is reached. Null when there is no row.
 */
function wpmcp_raw_option($name) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    return $row ? maybe_unserialize($row->option_value) : null;
}

/**
 * A network option, read the same way: from sitemeta on a network (option.php:2095), from
 * the options table on a single site (:2089) - without get_site_option()'s pre_site_option_*,
 * site_option_* and default filters (:2038, :2057, :2135).
 */
function wpmcp_raw_network_option($name) {
    global $wpdb;
    if (!is_multisite()) { return wpmcp_raw_option($name); }
    $row = $wpdb->get_row($wpdb->prepare("SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", $name, (int) $wpdb->siteid));
    return $row ? maybe_unserialize($row->meta_value) : null;
}

/**
 * The installed plugins, found the way get_plugins() finds them - .php files in the plugins
 * directory and one level of folders below it, readable, with a Plugin Name header
 * (wp-admin/includes/plugin.php:297-346) - but with none of get_plugins()'s hooks: headers
 * are read by get_file_data() with NO context, which is what skips the extra_plugin_headers
 * filter (functions.php:7057), and no plugins cache is read or written.
 *
 * AND KEYED BY plugin_basename(), BECAUSE get_plugins() IS (:346, sprint CORE-FIX). The keys
 * of this array are compared with `active_plugins`, whose values activate_plugin() wrote
 * through that same function - so a key built any other way is a key set that CAN diverge from
 * the one it is matched against, and `active` reads false for a plugin that is genuinely
 * active. It normalises separators, resolves a path registered by
 * wp_register_plugin_realpath() for a symlinked plugin directory, and strips the plugins-dir
 * prefix. On an ordinary install it changes nothing, which is the point: the two key sets are
 * now identical BY CONSTRUCTION rather than by both happening to be a raw readdir() path.
 *
 * @return array<string, array{Name: string, Version: string}> file => headers, by name
 */
function wpmcp_scan_plugins() {
    $root  = WP_PLUGIN_DIR;
    $files = array();
    $dir   = @opendir($root);

    if ($dir) {
        while (($entry = readdir($dir)) !== false) {
            if (str_starts_with($entry, '.')) { continue; }

            if (is_dir($root . '/' . $entry)) {
                $sub = @opendir($root . '/' . $entry);
                if (!$sub) { continue; }
                while (($subEntry = readdir($sub)) !== false) {
                    if (!str_starts_with($subEntry, '.') && str_ends_with($subEntry, '.php')) {
                        $files[] = $entry . '/' . $subEntry;
                    }
                }
                closedir($sub);
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $entry;
            }
        }
        closedir($dir);
    }

    $plugins = array();
    foreach ($files as $file) {
        if (!is_readable($root . '/' . $file)) { continue; }
        $headers = get_file_data($root . '/' . $file, array('Name' => 'Plugin Name', 'Version' => 'Version'));
        if ($headers['Name'] === '') { continue; }
        $plugins[plugin_basename($file)] = $headers;
    }

    uasort($plugins, static function ($a, $b) { return strnatcasecmp($a['Name'], $b['Name']); });

    return $plugins;
}

/**
 * Whether a theme is a block theme, WP_Theme::is_block_theme()'s rule (class-wp-theme.php:
 * 1595-1615, path choice :1628-1643) without the theme_file_path filter: a readable
 * templates/index.html or block-templates/index.html, in the theme's own folder when it has
 * one there and a parent, otherwise in the template's folder.
 */
function wpmcp_theme_is_block($styleDir, $templateDir) {
    foreach (array('templates/index.html', 'block-templates/index.html') as $file) {
        $path = ($styleDir !== $templateDir && file_exists($styleDir . '/' . $file))
            ? $styleDir . '/' . $file
            : $templateDir . '/' . $file;
        if (is_file($path) && is_readable($path)) { return true; }
    }
    return false;
}

/**
 * The installed themes, found the way search_theme_directories() finds them
 * (wp-includes/theme.php:515-565), kept or dropped the way WP_Theme's constructor and
 * wp_get_themes() keep them (class-wp-theme.php:299-340, :363, :393-405, :421-465, :471-514;
 * theme.php:92-97) - and with none of the hooks on that path: no wp_cache_themes_persistently
 * filter (theme.php:487), no theme_roots site transient, which search_theme_directories()
 * also WRITES (:580-581), no extra_theme_headers (get_file_data() without a context), no
 * theme_file_path, no kses on the name.
 *
 * Not reproduced: a theme paused by recovery mode (class-wp-theme.php:521) is listed, and a
 * copy of a default theme in another folder keeps its header name where wp-admin appends the
 * folder (:352-356).
 *
 * @return array<string, array{name: string, version: string, parent: ?string, block: bool}>
 */
function wpmcp_scan_themes() {
    $roots = isset($GLOBALS['wp_theme_directories']) ? (array) $GLOBALS['wp_theme_directories'] : array();
    $found = array();

    foreach ($roots as $root) {
        $dirs = @scandir($root);
        if (!$dirs) { continue; }

        foreach ($dirs as $dir) {
            if ('.' === $dir[0] || 'CVS' === $dir || !is_dir($root . '/' . $dir)) { continue; }

            if (file_exists($root . '/' . $dir . '/style.css')) {
                $found[$dir] = $root;
                continue;
            }

            $any = false;
            foreach ((array) @scandir($root . '/' . $dir) as $subDir) {
                if (!is_dir($root . '/' . $dir . '/' . $subDir) || !file_exists($root . '/' . $dir . '/' . $subDir . '/style.css')) { continue; }
                $found[$dir . '/' . $subDir] = $root;
                $any = true;
            }
            // A folder with no style.css anywhere: core records it and WP_Theme errors it
            // (theme_no_stylesheet); it is dropped below.
            if (!$any) { $found[$dir] = $root; }
        }
    }

    ksort($found);
    $themes = array();

    foreach ($found as $stylesheet => $root) {
        $stylesheet = (string) $stylesheet;
        $styleFile  = $root . '/' . $stylesheet . '/style.css';
        if (!file_exists($styleFile) || !is_readable($styleFile)) { continue; }

        // wpmcp_theme_header(), which site-info reads the active theme's name through too.
        $headers = wpmcp_theme_header($styleFile);
        if ($headers['template'] === $stylesheet) { continue; }

        $template     = $headers['template'] !== '' ? $headers['template'] : $stylesheet;
        $templateRoot = $root;

        if ($template === $stylesheet) {
            if (!wpmcp_theme_is_block($root . '/' . $stylesheet, $root . '/' . $stylesheet)
                && !file_exists($root . '/' . $stylesheet . '/index.php')) {
                continue;
            }
        } else {
            if (!file_exists($root . '/' . $template . '/index.php')) {
                $parentDir = dirname($stylesheet);
                if ('.' !== $parentDir && file_exists($root . '/' . $parentDir . '/' . $template . '/index.php')) {
                    $template = $parentDir . '/' . $template;
                } elseif (isset($found[$template])) {
                    $templateRoot = $found[$template];
                } else {
                    continue;
                }
            }

            // Only two generations: a parent that names a parent of its own is invalid.
            $parentFile = $templateRoot . '/' . $template . '/style.css';
            $parent     = is_readable($parentFile) ? get_file_data($parentFile, array('Template' => 'Template')) : array('Template' => '');
            if ($parent['Template'] !== '') { continue; }
        }

        $themes[$stylesheet] = array(
            'name'    => $headers['name'],
            'version' => $headers['version'],
            'parent'  => $template !== $stylesheet ? $template : null,
            'block'   => wpmcp_theme_is_block($root . '/' . $stylesheet, $templateRoot . '/' . $template),
        );
    }

    return $themes;
}

function wpmcp_inventory_tools() {
    $readHints = array(
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    );
    // Admin-scope readers carry readOnlyHint FALSE, like code-list and sql-select: the hint
    // is !write, and `write` is the flag the scope gate reads. destructiveHint says they
    // destroy nothing.
    $adminReadHints = array(
        'readOnlyHint' => false,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
    );

    return array(

    'list-users' => array(
        'write' => false,
        'annotations' => $readHints,
        'description' => 'List the site\'s users you are allowed to see. With the list_users'
            . ' capability (Administrators): every user, each with id, name, login, email, roles'
            . ' and registered (ISO 8601 site-local, as every date these tools return), and the'
            . ' role and search filters (the search argument says where it looks). Without list_users - an Editor, Author or Contributor -'
            . ' only users who have published posts, as id and name, as in the WordPress REST API;'
            . ' role and search are refused. name is the display name the user chose, often their'
            . ' login or an email address. Args: role, search, limit (default 20, max 100), page'
            . ' (default 1, max 100; has_more is false at page 100 - narrow role or search to'
            . ' reach further). Returns count, page, limit, has_more and items; no total.'
            . ' Never returns passwords, keys, sessions or user meta. Needs list_users or'
            . ' permission to edit posts: Subscribers are refused.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'role'   => array('type' => 'string', 'description' => 'A role such as "editor". Needs list_users.'),
            'search' => array('type' => 'string', 'description' => 'Needs list_users. With @: email. A number: login and ID. http:// or https://: URL. Otherwise login, URL, email, nicename and display name.'),
            'limit'  => array('type' => 'integer', 'description' => 'Users per page. Clamped to 1-100. Default 20.'),
            'page'   => array('type' => 'integer', 'description' => 'Page number. Clamped to 1-100; has_more is false at 100. Default 1.'),
        )),
        'run' => function ($a) {
            if (!wpmcp_users_can_read()) { return wpmcp_cannot('list users'); }

            $full   = current_user_can('list_users');
            $role   = isset($a['role']) ? trim((string) $a['role']) : '';
            $search = isset($a['search']) ? trim((string) $a['search']) : '';

            // Core refuses a role filter without list_users (:203), and without it would
            // search only some columns of only the published authors (:332). One rule here
            // for both: a caller who may not list users does not filter them, and hears why.
            // The same sentence whatever the value, so it says nothing about what exists.
            if (!$full && $role !== '') {
                return new WP_Error('wpmcp_forbidden', 'The role argument needs the list_users capability.'
                    . ' Without it list-users shows only users with published posts, unfiltered.');
            }
            if (!$full && $search !== '') {
                return new WP_Error('wpmcp_forbidden', 'The search argument needs the list_users capability.'
                    . ' Without it list-users shows only users with published posts, unfiltered.');
            }

            // The page cap is where list-users looped: page 101 answered page 100's rows
            // with has_more true, for ever (sprint 14d). See WPMCP_PAGE_CAP.
            list($limit, $page) = wpmcp_page_args(array_intersect_key($a, array('limit' => 1, 'page' => 1)));

            // limit + 1 rows: the extra one only answers has_more (KB 5.6). ID order, so a
            // page does not shift under two users with one display name.
            $query = array(
                'number'      => $limit + 1,
                'offset'      => ($page - 1) * $limit,
                'orderby'     => 'ID',
                'order'       => 'ASC',
                'count_total' => false,
            );

            if ($full) {
                // An unknown role matches nobody: an empty list, not an error (KB 4.6).
                if ($role !== '')   { $query['role__in'] = array($role); }
                // WP_User_Query escapes the term for LIKE itself; the stars ask for a substring.
                if ($search !== '') { $query['search'] = '*' . $search . '*'; }
            } else {
                // Core's own rule for a caller without list_users (:321-322).
                $query['has_published_posts'] = wpmcp_user_rest_types();
            }

            $users   = get_users($query);
            $hasMore = count($users) > $limit;
            $items   = array();

            foreach (array_slice($users, 0, $limit) as $user) {
                $items[] = wpmcp_user_out($user, $full);
            }

            return wpmcp_page_envelope($items, $page, $limit, $hasMore);
        },
    ),

    'get-user' => array(
        'write' => false,
        'annotations' => $readHints,
        'description' => 'Read one user you are allowed to see. Args: id (integer, required).'
            . ' login, email, roles and registered come back only'
            . ' when you have the'
            . ' list_users capability, may edit that user, or it is you. When you can neither list'
            . ' nor edit users, you see a user only if they have posts you may read in a post type'
            . ' the REST API shows: published posts, and private posts when you can read those -'
            . ' so an Editor also sees a user whose only posts are private, which list-users does'
            . ' not show. This is the WordPress REST API\'s rule. Any other user answers exactly'
            . ' like an id that does not exist. Never returns passwords, keys, sessions or user'
            . ' meta. Needs list_users or permission to edit posts. Every returned field is'
            . ' described in outputSchema.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer', 'description' => 'User ID, from list-users or a post\'s author.'),
        ), 'required' => array('id')),
        'outputSchema' => wpmcp_result_schema(wpmcp_get_user_shape()),
        'run' => function ($a) {
            if (!wpmcp_users_can_read()) { return wpmcp_cannot('read users'); }

            $missing = new WP_Error('wpmcp_not_found', 'No user with that ID.');
            $id      = isset($a['id']) ? (int) $a['id'] : 0;
            $user    = $id > 0 ? get_userdata($id) : false;

            if (!$user) { return $missing; }
            // Core's get_user(): on a network, a user of another site is not found here.
            if (is_multisite() && !is_user_member_of_blog((int) $user->ID)) { return $missing; }

            $full = current_user_can('list_users') || current_user_can('edit_user', $user->ID);

            // Core's rule (:484, :495): yourself; or edit_user or list_users; or a user with
            // posts in a REST type (count_user_posts counts published posts, and private
            // ones the caller may read). Anything else is the missing-id answer, word for word.
            if (get_current_user_id() !== (int) $user->ID
                && !$full
                && !count_user_posts($user->ID, wpmcp_user_rest_types())) {
                return $missing;
            }

            return wpmcp_user_out($user, $full);
        },
    ),

    'get-option' => array(
        'write' => false,
        'annotations' => $readHints,
        'description' => 'Read one site setting from a fixed list. Args: name (required), one of'
            . ' blogname, blogdescription, timezone_string, gmt_offset, date_format, time_format,'
            . ' start_of_week, permalink_structure, siteurl and home - values the public site'
            . ' already shows. Every other name gets one identical refusal, whether or not such'
            . ' an option exists. Returns name and value (start_of_week an integer, gmt_offset a'
            . ' number of hours, the rest strings). Needs permission to edit posts.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'name' => array('type' => 'string', 'description' => 'One of the ten option names the description lists.'),
        ), 'required' => array('name')),
        'run' => function ($a) {
            if (!current_user_can('edit_posts')) { return wpmcp_cannot('read site options'); }

            $allowed = wpmcp_option_allow_list();
            $name    = isset($a['name']) ? (string) $a['name'] : '';

            // array_key_exists is exact: "BlogName" is not on the list, although MySQL's
            // collation would find the row. The refusal never echoes the name and never
            // looks the option up, so it is the same bytes for every name off the list.
            if (!array_key_exists($name, $allowed)) {
                return new WP_Error('wpmcp_option_not_listed', 'get-option reads only these options: '
                    . implode(', ', array_keys($allowed)) . '. Any other name gets this answer.');
            }

            $value = get_option($name);

            if ($value === false) {
                $value = null;
            } elseif ($allowed[$name] === 'integer') {
                $value = (int) $value;
            } elseif ($allowed[$name] === 'number') {
                $value = (float) $value;
            } else {
                $value = (string) $value;
            }

            return array('name' => $name, 'value' => $value);
        },
    ),

    'list-plugins' => array(
        // ADMIN SCOPE. `write` is the flag the scope gate reads, so an admin-scope reader
        // carries it, as code-list and sql-select do; it writes nothing.
        'write' => true,
        'annotations' => $adminReadHints,
        'description' => 'List installed plugins and which are active. Returns count and plugins: file (the'
            . ' plugin\'s id, such as "akismet/akismet.php"), name and version from its header, active,'
            . ' network_active (multisite only), and auto_update - true when the plugin is in the'
            . ' site\'s stored auto-update list, false when not, null when you cannot update plugins'
            . ' (your role lacks update_plugins, wp-config sets DISALLOW_FILE_MODS, or a network'
            . ' limits it to network admins). auto_update'
            . ' does not reflect auto-updates switched off site-wide, a plugin forcing its own answer,'
            . ' or whether an update source exists. It reads plugin files and stored settings'
            . ' directly: no update, auto-update, plugin-header or per-option filter runs, so no'
            . ' update check is triggered. Hooks every tool call runs still run - capability, database'
            . ' query and option filters, and wp-mcp\'s tools filter - and a plugin that goes remote'
            . ' from those does so here too. Needs an admin-scope token and the activate_plugins'
            . ' capability (Administrators).',
        'inputSchema' => array('type' => 'object', 'properties' => array()),
        'run' => function ($a) {
            // Core's REST gate (class-wp-rest-plugins-controller.php:113). The capability checks
            // are the only filters this tool runs; see the section docblock.
            if (!current_user_can('activate_plugins')) { return wpmcp_cannot('list plugins'); }

            // STORED values, from their rows: is_plugin_active() and get_site_option() run
            // pre_option_* / option_* and pre_site_option_* / site_option_* filters.
            $active   = (array) wpmcp_raw_option('active_plugins');
            $network  = is_multisite() ? (array) wpmcp_raw_network_option('active_sitewide_plugins') : array();
            // The Plugins screen shows auto-update state only to a caller who can update plugins
            // (class-wp-plugins-list-table.php:60-61). The stored list, and nothing that has to
            // run a filter to be known: wp_is_auto_update_enabled_for_type() and the
            // auto_update_plugin filter are exactly where plugins go remote.
            //
            // NOT current_user_can('update_plugins'): map_meta_cap resolves that capability
            // through wp_is_file_mod_allowed(), whose file_mod_allowed filter is a hook
            // (capabilities.php, the update_plugins case; load.php:1838) - the test's fixture
            // made its request from there. The same rule without the filter: wp-config does not
            // set DISALLOW_FILE_MODS, and the caller's role holds update_plugins. The user object
            // was loaded when the token was checked, so reading its caps runs no hook.
            //
            // AND CORE'S SECOND DENY BRANCH, WHICH THIS COPY OMITTED (sprint CORE-FIX).
            // map_meta_cap's update_plugins case is THREE branches, not one
            // (capabilities.php:619-641): the file-mod gate, then
            // `is_multisite() && ! is_super_admin( $user_id )`, then the capability. Without the
            // middle one, a Site Administrator on a network - who holds update_plugins in their
            // role and cannot update a single plugin - was told true or false where core says
            // "you cannot update plugins", which is what `auto_update: null` is for. Same class
            // as wpmcp_code_constants_forbid()'s missing third branch, same file, one copy of
            // core's decision each. is_multisite() reads a constant and is_super_admin() reads
            // the user object already loaded, so the tool's no-remote-work contract holds.
            $fileMods = !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)
                && !(is_multisite() && !is_super_admin());
            $canAuto  = $fileMods && !empty(wp_get_current_user()->allcaps['update_plugins']);
            $autoList = $canAuto ? (array) wpmcp_raw_network_option('auto_update_plugins') : null;
            $items    = array();

            foreach (wpmcp_scan_plugins() as $file => $headers) {
                $file = (string) $file;
                $item = array(
                    'file'    => $file,
                    'name'    => (string) $headers['Name'],
                    'version' => (string) $headers['Version'],
                    'active'  => in_array($file, $active, true) || isset($network[$file]),
                );
                if (is_multisite()) { $item['network_active'] = isset($network[$file]); }
                $item['auto_update'] = $autoList === null ? null : in_array($file, $autoList, true);

                $items[] = $item;
            }

            return array('count' => count($items), 'plugins' => $items);
        },
    ),

    'list-themes' => array(
        'write' => true,
        'annotations' => $adminReadHints,
        'description' => 'List installed themes and which one is active. Returns active (the stylesheet stored'
            . ' as the site\'s theme), count and themes: stylesheet, name, version, active, parent (the'
            . ' parent theme\'s stylesheet, or null), block_theme (true when the theme or its parent'
            . ' has a templates/index.html or block-templates/index.html), and menu_locations - for'
            . ' the active theme, the classic menu locations registered (location and description);'
            . ' null for other themes or when you cannot edit theme options. It reads theme files and'
            . ' stored settings directly: no theme, update or per-option filter runs, so no update'
            . ' check is triggered. Hooks every tool call runs still run - capability, database query'
            . ' and option filters, and wp-mcp\'s tools filter - and a plugin that goes remote from'
            . ' those does so here too. Needs an admin-scope token and the switch_themes capability'
            . ' (Administrators).',
        'inputSchema' => array('type' => 'object', 'properties' => array()),
        'run' => function ($a) {
            // Core's REST gate (class-wp-rest-themes-controller.php:99), on a single site.
            if (!current_user_can('switch_themes')) { return wpmcp_cannot('list themes'); }

            // The STORED theme: get_stylesheet() runs pre_option_stylesheet, option_stylesheet
            // and stylesheet (theme.php:189).
            $active = (string) wpmcp_raw_option('stylesheet');
            // Core's menu-locations endpoint shows the registered locations to
            // edit_theme_options alone (class-wp-rest-menu-locations-controller.php:152-168),
            // which switch_themes does not imply: without it, menu_locations is null.
            $canMenus = current_user_can('edit_theme_options');
            $items    = array();

            foreach (wpmcp_scan_themes() as $stylesheet => $theme) {
                $stylesheet = (string) $stylesheet;
                $isActive   = $stylesheet === $active;
                $locations  = null;

                // Only the active theme's code has run, so only its locations are known.
                // get_registered_nav_menus() reads a global and runs no hook (nav-menu.php:149-152).
                if ($isActive && $canMenus) {
                    $locations = array();
                    foreach (get_registered_nav_menus() as $location => $description) {
                        $locations[] = array('location' => (string) $location, 'description' => (string) $description);
                    }
                }

                $items[] = array(
                    'stylesheet'     => $stylesheet,
                    'name'           => $theme['name'],
                    'version'        => $theme['version'],
                    'active'         => $isActive,
                    'parent'         => $theme['parent'],
                    'block_theme'    => $theme['block'],
                    'menu_locations' => $locations,
                );
            }

            return array('active' => $active, 'count' => count($items), 'themes' => $items);
        },
    ),

    );
}
