<?php
/**
 * WP MCP - tool catalog beyond the core reads.
 *
 * wpmcp_extra_tools(): content / taxonomy / media / comment tools.
 * wpmcp_code_tools():  the four jailed code-edit tools (active theme only).
 * Each tool = array('write'=>bool, 'description'=>str, 'inputSchema'=>array, 'run'=>callable).
 * Merged into the registry by endpoint.php's wpmcp_tools().
 */
if (!defined('ABSPATH')) { exit; }

/* ============================================================
 * Code-editing config + jail helpers
 * ========================================================== */
function wpmcp_code_enabled() {
    return (bool) get_option('wpmcp_code_enabled', false);
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
 * A .bak path is safe to copy/rename onto only if it is not itself a symlink
 * (copy() follows the symlink target) and, if it exists, resolves inside the
 * theme root. Same jail guarantee as the main target.
 */
function wpmcp_bak_ok($bak) {
    if (is_link($bak)) { return false; }
    if (file_exists($bak)) {
        $real = realpath($bak);
        if ($real === false || !wpmcp_path_within($real, wpmcp_code_root())) { return false; }
    }
    return true;
}

/** Syntax-check PHP source. Returns true, or an error string. */
function wpmcp_php_parse_ok($code) {
    if (!defined('TOKEN_PARSE')) { return true; } // can't check on this runtime
    try {
        token_get_all($code, TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return $e->getMessage();
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

/** Apply {taxonomy:[id|name,...]} to a post, creating missing terms by name. */
function wpmcp_apply_terms($post_id, $terms) {
    foreach ((array) $terms as $tax => $vals) {
        $tax = sanitize_key($tax);
        if (!taxonomy_exists($tax)) { continue; }
        $ids = array();
        foreach ((array) $vals as $v) {
            if (is_numeric($v)) {
                $ids[] = (int) $v;
            } else {
                $t = get_term_by('name', (string) $v, $tax);
                if (!$t) {
                    $new = wp_insert_term((string) $v, $tax);
                    if (!is_wp_error($new)) { $ids[] = (int) $new['term_id']; }
                } else {
                    $ids[] = (int) $t->term_id;
                }
            }
        }
        if ($ids) { wp_set_object_terms($post_id, $ids, $tax, false); }
    }
}

/* ============================================================
 * Content / taxonomy / media / comment tools
 * ========================================================== */
function wpmcp_extra_tools() {
    return array(

    'create-post' => array(
        'write' => true,
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
                return new WP_Error('bad_type', 'Unsupported post_type (use a public content type; attachments use the media tools).');
            }
            if (isset($a['excerpt'])) { $postarr['post_excerpt'] = (string) $a['excerpt']; }
            if (isset($a['slug']))    { $postarr['post_name'] = sanitize_title((string) $a['slug']); }
            $id = wp_insert_post($postarr, true);
            if (is_wp_error($id)) { return $id; }
            if (!empty($a['terms']) && is_array($a['terms'])) { wpmcp_apply_terms($id, $a['terms']); }
            $p = get_post($id);
            return array('id' => (int) $id, 'link' => get_permalink($id), 'status' => $p ? $p->post_status : null);
        },
    ),

    'update-post' => array(
        'write' => true,
        'description' => 'Update a post/page. Args: id (required) plus any of title, content, status, excerpt, slug, terms. Set status=publish to publish.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'title' => array('type' => 'string'),
            'content' => array('type' => 'string'), 'status' => array('type' => 'string'),
            'excerpt' => array('type' => 'string'), 'slug' => array('type' => 'string'),
            'terms' => array('type' => 'object'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = $id ? get_post($id) : null;
            if (!$p0) { return new WP_Error('not_found', 'No post with that ID.'); }
            if (!wpmcp_post_type_ok($p0->post_type)) { return new WP_Error('bad_type', 'That item is not an editable content type.'); }
            $upd = array('ID' => $id); $changed = array();
            if (isset($a['title']))   { $upd['post_title'] = wp_strip_all_tags((string) $a['title']); $changed[] = 'title'; }
            if (isset($a['content'])) { $upd['post_content'] = (string) $a['content']; $changed[] = 'content'; }
            if (isset($a['status']))  { $upd['post_status'] = sanitize_key($a['status']); $changed[] = 'status'; }
            if (isset($a['excerpt'])) { $upd['post_excerpt'] = (string) $a['excerpt']; $changed[] = 'excerpt'; }
            if (isset($a['slug']))    { $upd['post_name'] = sanitize_title((string) $a['slug']); $changed[] = 'slug'; }
            $r = wp_update_post($upd, true);
            if (is_wp_error($r)) { return $r; }
            if (!empty($a['terms']) && is_array($a['terms'])) { wpmcp_apply_terms($id, $a['terms']); $changed[] = 'terms'; }
            $p = get_post($id);
            return array('id' => $id, 'link' => get_permalink($id), 'status' => $p->post_status, 'changed' => $changed);
        },
    ),

    'delete-post' => array(
        'write' => true,
        'description' => 'Delete a post/page. Args: id (required), force (default false). force=false trashes; force=true permanently deletes.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'force' => array('type' => 'boolean'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p0 = $id ? get_post($id) : null;
            if (!$p0) { return new WP_Error('not_found', 'No post with that ID.'); }
            if (!wpmcp_post_type_ok($p0->post_type)) { return new WP_Error('bad_type', 'That item is not a deletable content type (attachments use delete-media).'); }
            $force = !empty($a['force']);
            $r = wp_delete_post($id, $force);
            if (!$r) { return new WP_Error('delete_failed', 'Could not delete.'); }
            return array('id' => $id, 'deleted' => $force, 'trashed' => !$force);
        },
    ),

    'list-terms' => array(
        'write' => false,
        'description' => 'List taxonomy terms. Args: taxonomy (default category), search, hide_empty (default false).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'search' => array('type' => 'string'),
            'hide_empty' => array('type' => 'boolean'),
        )),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : 'category';
            if (!taxonomy_exists($tax)) { return new WP_Error('bad_taxonomy', 'Unknown taxonomy.'); }
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
        'description' => 'Create a taxonomy term. Args: taxonomy (required), name (required), slug, parent, description.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'name' => array('type' => 'string'),
            'slug' => array('type' => 'string'), 'parent' => array('type' => 'integer'),
            'description' => array('type' => 'string'),
        ), 'required' => array('taxonomy', 'name')),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : '';
            if (!taxonomy_exists($tax)) { return new WP_Error('bad_taxonomy', 'Unknown taxonomy.'); }
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
        'description' => 'Delete a taxonomy term. Args: taxonomy (required), id (required).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'taxonomy' => array('type' => 'string'), 'id' => array('type' => 'integer'),
        ), 'required' => array('taxonomy', 'id')),
        'run' => function ($a) {
            $tax = isset($a['taxonomy']) ? sanitize_key($a['taxonomy']) : '';
            if (!taxonomy_exists($tax)) { return new WP_Error('bad_taxonomy', 'Unknown taxonomy.'); }
            $r = wp_delete_term((int) $a['id'], $tax);
            if (is_wp_error($r)) { return $r; }
            if (!$r) { return new WP_Error('not_found', 'Term not found.'); }
            return array('id' => (int) $a['id'], 'deleted' => true);
        },
    ),

    'list-media' => array(
        'write' => false,
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
                $out[] = array('id' => $p->ID, 'title' => get_the_title($p), 'mime' => $p->post_mime_type,
                    'url' => wp_get_attachment_url($p->ID), 'date' => $p->post_date_gmt);
            }
            return array('count' => count($out), 'items' => $out);
        },
    ),

    'get-media' => array(
        'write' => false,
        'description' => 'Get one media item. Args: id (required).',
        'inputSchema' => array('type' => 'object',
            'properties' => array('id' => array('type' => 'integer')), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = $id ? get_post($id) : null;
            if (!$p || $p->post_type !== 'attachment') { return new WP_Error('not_found', 'No attachment with that ID.'); }
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
        'description' => 'Upload media by sideloading a URL. Args: source_url (required, http/https), filename, title, alt, post (attach to post id).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'source_url' => array('type' => 'string'), 'filename' => array('type' => 'string'),
            'title' => array('type' => 'string'), 'alt' => array('type' => 'string'),
            'post' => array('type' => 'integer'),
        ), 'required' => array('source_url')),
        'run' => function ($a) {
            $url = isset($a['source_url']) ? esc_url_raw((string) $a['source_url']) : '';
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true)) {
                return new WP_Error('bad_url', 'source_url must be http or https.');
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
                return new WP_Error('too_big', 'Downloaded file exceeds the upload size limit.');
            }
            $name = isset($a['filename']) ? sanitize_file_name((string) $a['filename'])
                : sanitize_file_name(basename((string) wp_parse_url($url, PHP_URL_PATH)));
            if ($name === '') { $name = 'upload'; }
            $file = array('name' => $name, 'tmp_name' => $tmp);
            $post = isset($a['post']) ? (int) $a['post'] : 0;
            $id = media_handle_sideload($file, $post, isset($a['title']) ? (string) $a['title'] : null);
            if (is_wp_error($id)) { @unlink($tmp); return $id; }
            if (isset($a['alt'])) { update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $a['alt'])); }
            return array('id' => (int) $id, 'url' => wp_get_attachment_url($id), 'mime' => get_post_mime_type($id));
        },
    ),

    'delete-media' => array(
        'write' => true,
        'description' => 'Delete a media attachment. Args: id (required), force (default false).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'force' => array('type' => 'boolean'),
        ), 'required' => array('id')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            $p = $id ? get_post($id) : null;
            if (!$p || $p->post_type !== 'attachment') { return new WP_Error('not_found', 'No attachment with that ID.'); }
            $r = wp_delete_attachment($id, !empty($a['force']));
            if (!$r) { return new WP_Error('delete_failed', 'Could not delete.'); }
            return array('id' => $id, 'deleted' => true);
        },
    ),

    'list-comments' => array(
        'write' => false,
        'description' => 'List comments (emails omitted). Args: post (id), status (default all: approve|hold|spam|trash), search, page, per_page.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'post' => array('type' => 'integer'), 'status' => array('type' => 'string'),
            'search' => array('type' => 'string'), 'page' => array('type' => 'integer'),
            'per_page' => array('type' => 'integer'),
        )),
        'run' => function ($a) {
            $args = array(
                'status' => isset($a['status']) ? sanitize_key($a['status']) : 'all',
                'search' => isset($a['search']) ? (string) $a['search'] : '',
                'number' => isset($a['per_page']) ? min(100, max(1, (int) $a['per_page'])) : 20,
                'paged'  => isset($a['page']) ? max(1, (int) $a['page']) : 1,
            );
            if (isset($a['post'])) { $args['post_id'] = (int) $a['post']; }
            $cs = get_comments($args);
            $out = array();
            foreach ($cs as $c) {
                $out[] = array('id' => (int) $c->comment_ID, 'post' => (int) $c->comment_post_ID,
                    'author_name' => $c->comment_author, 'content' => $c->comment_content,
                    'status' => wp_get_comment_status($c->comment_ID), 'date' => $c->comment_date_gmt);
            }
            return array('count' => count($out), 'items' => $out);
        },
    ),

    'moderate-comment' => array(
        'write' => true,
        'description' => 'Moderate a comment. Args: id (required), action (approve|unapprove|spam|trash|untrash).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'action' => array('type' => 'string'),
        ), 'required' => array('id', 'action')),
        'run' => function ($a) {
            $id = isset($a['id']) ? (int) $a['id'] : 0;
            if (!$id || !get_comment($id)) { return new WP_Error('not_found', 'No comment with that ID.'); }
            $action = isset($a['action']) ? sanitize_key($a['action']) : '';
            $valid = array('approve', 'unapprove', 'spam', 'trash', 'untrash');
            if (!in_array($action, $valid, true)) { return new WP_Error('bad_action', 'Unknown action.'); }
            if ($action === 'trash')        { $ok = wp_trash_comment($id); }
            elseif ($action === 'untrash')  { $ok = wp_untrash_comment($id); }
            elseif ($action === 'spam')     { $ok = wp_spam_comment($id); }
            elseif ($action === 'unapprove'){ $ok = wp_set_comment_status($id, 'hold'); }
            else                            { $ok = wp_set_comment_status($id, 'approve'); }
            if (!$ok) { return new WP_Error('failed', 'Action failed.'); }
            return array('id' => $id, 'status' => wp_get_comment_status($id));
        },
    ),

    'reply-comment' => array(
        'write' => true,
        'description' => 'Reply to a comment. Args: id (required, parent comment), content (required).',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'content' => array('type' => 'string'),
        ), 'required' => array('id', 'content')),
        'run' => function ($a) {
            $parent = isset($a['id']) ? (int) $a['id'] : 0;
            $pc = $parent ? get_comment($parent) : null;
            if (!$pc) { return new WP_Error('not_found', 'No parent comment.'); }
            $u = wp_get_current_user();
            $cid = wp_insert_comment(array(
                'comment_post_ID' => (int) $pc->comment_post_ID,
                'comment_parent'  => $parent,
                'comment_content' => (string) $a['content'],
                'user_id'         => $u ? $u->ID : 0,
                'comment_author'  => $u ? $u->display_name : '',
                'comment_author_email' => $u ? $u->user_email : '',
                'comment_approved' => 1,
            ));
            if (!$cid) { return new WP_Error('failed', 'Could not create reply.'); }
            return array('id' => (int) $cid);
        },
    ),

    );
}

/* ============================================================
 * Code-edit tools (admin scope; only listed when wpmcp_code_enabled())
 * ========================================================== */
function wpmcp_code_tools() {
    return array(

    'code-list' => array(
        'write' => true,
        'description' => 'List files/dirs in the active theme. Args: path (relative, default ""). Denylisted entries show blocked=true.',
        'inputSchema' => array('type' => 'object', 'properties' => array('path' => array('type' => 'string'))),
        'run' => function ($a) {
            $root = wpmcp_code_root();
            if ($root === '') { return new WP_Error('no_theme', 'Active theme directory not found.'); }
            $rel = isset($a['path']) ? ltrim(str_replace('\\', '/', (string) $a['path']), '/') : '';
            if (strpos($rel, '..') !== false) { return new WP_Error('illegal', 'Illegal path.'); }
            $dir = $root . ($rel !== '' ? '/' . $rel : '');
            $abs = realpath($dir);
            if ($abs === false || !wpmcp_path_within($abs, $root) || !is_dir($abs)) {
                return new WP_Error('not_found', 'No such directory.');
            }
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
        'description' => 'Read a text file in the active theme. Args: path (required). Denylisted/binary/oversized files are refused.',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $r = wpmcp_code_resolve(isset($a['path']) ? $a['path'] : '', true);
            if (!$r['ok']) { return new WP_Error('path', $r['error']); }
            if (wpmcp_code_denied($r['rel'])) { return new WP_Error('denied', 'That file is on the denylist.'); }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('ext', 'Only text files may be read.'); }
            if (!is_file($r['abs'])) { return new WP_Error('not_found', 'Not a file.'); }
            if (filesize($r['abs']) > 524288) { return new WP_Error('too_big', 'File exceeds 512KB.'); }
            return array('path' => $r['rel'], 'content' => file_get_contents($r['abs']));
        },
    ),

    'code-write' => array(
        'write' => true,
        'description' => 'Create or overwrite a text file in the active theme. Args: path (required), content (required). Backs up to .bak; PHP is parse-checked and auto-reverted on a syntax error.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'path' => array('type' => 'string'), 'content' => array('type' => 'string'),
        ), 'required' => array('path', 'content')),
        'run' => function ($a) {
            $r = wpmcp_code_resolve(isset($a['path']) ? $a['path'] : '', false);
            if (!$r['ok']) { return new WP_Error('path', $r['error']); }
            if (wpmcp_code_denied($r['rel'])) { return new WP_Error('denied', 'That file is on the denylist.'); }
            if (!wpmcp_code_ext_ok($r['rel'])) { return new WP_Error('ext', 'Only text files may be written.'); }
            $content = isset($a['content']) ? (string) $a['content'] : '';
            if (strlen($content) > 524288) { return new WP_Error('too_big', 'Content exceeds 512KB.'); }

            $existed = is_file($r['abs']);
            $bak = $r['abs'] . '.bak';
            if (!wpmcp_bak_ok($bak)) { return new WP_Error('bak_unsafe', 'Backup path is unsafe (symlink or outside theme).'); }
            if ($existed) { @copy($r['abs'], $bak); }
            $bytes = file_put_contents($r['abs'], $content);
            if ($bytes === false) { return new WP_Error('write_failed', 'Could not write file.'); }

            $reverted = false; $perr = null;
            if (strtolower(pathinfo($r['rel'], PATHINFO_EXTENSION)) === 'php') {
                $chk = wpmcp_php_parse_ok($content);
                if ($chk !== true) {
                    $perr = $chk;
                    if ($existed) { @copy($bak, $r['abs']); } else { @unlink($r['abs']); }
                    $reverted = true;
                }
            }
            $out = array(
                'path' => $r['rel'], 'bytes' => $reverted ? 0 : (int) $bytes,
                'created' => ($existed ? false : !$reverted), 'reverted' => $reverted,
            );
            if ($perr !== null) { $out['error'] = 'PHP parse error (reverted): ' . $perr; }
            return $out;
        },
    ),

    'code-delete' => array(
        'write' => true,
        'description' => 'Delete a file in the active theme (moved to .bak, not unlinked). Args: path (required).',
        'inputSchema' => array('type' => 'object',
            'properties' => array('path' => array('type' => 'string')), 'required' => array('path')),
        'run' => function ($a) {
            $r = wpmcp_code_resolve(isset($a['path']) ? $a['path'] : '', true);
            if (!$r['ok']) { return new WP_Error('path', $r['error']); }
            if (wpmcp_code_denied($r['rel'])) { return new WP_Error('denied', 'That file is on the denylist.'); }
            if (!is_file($r['abs'])) { return new WP_Error('not_found', 'Not a file.'); }
            $bak = $r['abs'] . '.bak';
            if (!wpmcp_bak_ok($bak)) { return new WP_Error('bak_unsafe', 'Backup path is unsafe (symlink or outside theme).'); }
            if (!@rename($r['abs'], $bak)) { return new WP_Error('delete_failed', 'Could not move file to .bak.'); }
            return array('path' => $r['rel'], 'deleted' => true, 'backup' => basename($bak));
        },
    ),

    );
}
