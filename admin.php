<?php
/**
 * WP MCP - admin settings page (mint / list / revoke tokens, code-editing config). Admin-only.
 *
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
    add_options_page('WP MCP', 'WP MCP', 'manage_options', 'wp-mcp', 'wpmcp_render_admin');
});

/**
 * The build, on the Plugins screen, right beside the version WordPress prints there.
 *
 * WHY HERE AND NOT IN THE `Version:` HEADER. The Plugins screen is where an operator
 * looks first after uploading a zip - it is where the hour was lost on 2026-09-16 - so
 * the build has to be visible there. It cannot be visible there by being part of the
 * version: WordPress shows the header verbatim, the release tooling and the version
 * consistency test read it, and a header reading `1.1.0-dev+3b5d129` would make the
 * checkout's own header a lie too (the substitution happens in `git archive`, not in the
 * tree). `plugin_row_meta` puts the fact next to the version without corrupting it.
 *
 * @param array  $links the row's meta links
 * @param string $file  the plugin file this row is for, relative to the plugins dir
 * @return array
 */
function wpmcp_plugin_row_meta($links, $file) {
    if ($file !== plugin_basename(WPMCP_PLUGIN_FILE)) { return (array) $links; }

    $links   = (array) $links;
    $links[] = esc_html('Build: ' . wpmcp_build_label());

    return $links;
}
add_filter('plugin_row_meta', 'wpmcp_plugin_row_meta', 10, 2);

/**
 * The one line that answers "which build is this site running", as HTML.
 *
 * A FUNCTION AND NOT INLINE TEMPLATE, for two reasons. The suite can read exactly what
 * the page shows without scraping a page that also lists this site's tokens; and the
 * settings page and the Plugins screen row are then provably the same fact, because
 * both end at wpmcp_build_label().
 *
 * THE `source` CASE IS SPELLED OUT rather than left as a bare word. An operator who has
 * just uploaded a zip and reads `build: source` needs to know that means "this is not a
 * zip" and not "the build id is missing".
 */
function wpmcp_admin_build_line() {
    $version = '<strong>Version</strong> <code>' . esc_html(WPMCP_VER) . '</code>';
    $build   = ' &middot; <strong>build</strong> <code>' . esc_html(wpmcp_build_label()) . '</code>';

    if (wpmcp_build_id() === '') {
        return $version . $build
            . ' &mdash; this copy is running from a source checkout, not from a built'
            . ' zip, so there is no build to report.';
    }

    $date = wpmcp_build_date();

    return $version . $build
        . ($date === '' ? '' : ' (committed ' . esc_html($date) . ')')
        . ' &mdash; the commit this zip was built from.';
}

/**
 * Can this site serve the endpoint at all?
 *
 * wpmcp_authorize() refuses every request that is not over HTTPS, so a site that
 * cannot do HTTPS has a dormant endpoint and the admin page must say so instead of
 * handing out a URL that will always be refused.
 *
 * Two ways to be sure, and either is enough: the `home` option says https, or this
 * very page arrived over TLS. The second matters because the option can lag behind
 * reality - on the site this was developed against, `home` is http:// while the site is
 * reachable, and is_ssl() true, over https (verified). A site that is only reachable
 * over http answers false to both.
 */
function wpmcp_site_is_https() {
    if (is_ssl()) { return true; }
    return strtolower((string) wp_parse_url(home_url(), PHP_URL_SCHEME)) === 'https';
}

/**
 * The endpoint URL, forced to https - the only scheme the endpoint answers on.
 *
 * NO ARGUMENT, and that is the point. It used to take a path so the mint page could
 * append a token to it; there is no such URL any more. This address is CONSTANT for the
 * life of the site, so a client that has it never needs to be told a new one.
 */
function wpmcp_endpoint_url() {
    return set_url_scheme(rest_url('wpmcp/mcp'), 'https');
}

/**
 * The mint form's "Active window (hours)" field, in seconds.
 *
 * SEPARATE FROM wpmcp_mint()'s OWN CLAMP, and not a duplicate of it. This one speaks the
 * form's units (hours, halves) and has to answer for a field that is empty, absent or
 * nonsense, which is a question the API has no view on. wpmcp_mint() clamps again;
 * defence in depth costs one max() and means a caller that never touches the form cannot
 * write an out-of-range window either.
 *
 * WPMCP_DEFAULT_WINDOW - six hours - because that is the normal case: a token on
 * somebody's laptop that should stop answering by the end of the working day. Twelve is
 * the ceiling, and the way to keep a connector alive past it is Renew, not a longer
 * window.
 *
 * THE CEILING IS wpmcp_max_window(), in hours (sprint 14b): 12, or 720 on a site whose
 * environment type is 'local'. The default stays six hours on both.
 */
function wpmcp_form_window_secs($hours) {
    if ($hours === null || $hours === '' || !is_numeric($hours)) {
        return WPMCP_DEFAULT_WINDOW;
    }

    $hours = min(wpmcp_max_window() / HOUR_IN_SECONDS, max(0.5, (float) $hours));

    return (int) round($hours * HOUR_IN_SECONDS);
}

/**
 * The mint form's "Lifetime (days)" field, in seconds.
 *
 * WPMCP_DEFAULT_LIFETIME - thirty days - because the field exists for connectors, and a
 * connector that has to be deleted and re-added more often than monthly is a connector
 * nobody keeps. A year is the ceiling: past that a credential nobody has looked at is not
 * a credential anybody is managing. The v2 -> v3 migration reads the same constant, so a
 * token that predates the upgrade gets the lifetime a fresh one would.
 */
function wpmcp_form_lifetime_secs($days) {
    if ($days === null || $days === '' || !is_numeric($days)) {
        return WPMCP_DEFAULT_LIFETIME;
    }

    $days = min(365, max(1, (int) $days));

    return $days * DAY_IN_SECONDS;
}

/**
 * A duration as something a human reads in a table cell: "6 h", "30 d", "45 m".
 *
 * Whole units only, largest that fits. The table is a glance, not an audit; the exact
 * timestamps are in the two datetime columns beside it.
 */
function wpmcp_format_duration($seconds) {
    $seconds = (int) $seconds;

    if ($seconds >= DAY_IN_SECONDS && $seconds % DAY_IN_SECONDS === 0) {
        return (int) ($seconds / DAY_IN_SECONDS) . ' d';
    }

    if ($seconds >= HOUR_IN_SECONDS) {
        return rtrim(rtrim(number_format($seconds / HOUR_IN_SECONDS, 1, '.', ''), '0'), '.') . ' h';
    }

    return max(1, (int) round($seconds / MINUTE_IN_SECONDS)) . ' m';
}

/**
 * Write the one settings form's four values. $post is the UNSLASHED $_POST.
 *
 * SPLIT OUT OF THE PAGE, and not for tidiness: the page body is 200 lines of HTML that
 * cannot be run outside an admin request, so while the save lived inside it the only way
 * to test that the textarea round-trips was to test the normaliser and hope. This is the
 * code the form actually posts to, and a test can call it.
 *
 * THE NONCE IS NOT IN HERE. check_admin_referer() is a statement about the REQUEST, and
 * it stays at the call site where a request exists; this function is the write.
 */
function wpmcp_save_settings($post) {
    update_option('wpmcp_code_enabled', !empty($post['code_enabled']) ? 1 : 0);
    update_option('wpmcp_sql_enabled', !empty($post['sql_enabled']) ? 1 : 0);

    $lines = isset($post['denylist']) ? (string) $post['denylist'] : '';
    $list  = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $lines))));
    update_option('wpmcp_code_denylist', $list);

    // NORMALISED HERE AND AGAIN ON EVERY READ. wpmcp_meta_keys_normalise() drops blanks,
    // duplicates and any key WordPress calls protected - so a `_secret` typed into the
    // box is gone the moment Save is pressed, and wpmcp_meta_key_allowed() refuses it at
    // call time as well, because this option is an ordinary row that wp-cli or a restored
    // backup can write without ever passing through this form.
    $keys = isset($post['meta_keys']) ? (string) $post['meta_keys'] : '';
    update_option('wpmcp_meta_keys', wpmcp_meta_keys_normalise($keys));
}

function wpmcp_render_admin() {
    if (!current_user_can('manage_options')) { wp_die('Insufficient permissions.'); }

    $minted = null; // shown once after mint
    $notice = '';

    // Handle mint
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'mint') {
        check_admin_referer('wpmcp_mint');
        $scope = (isset($_POST['scope']) && $_POST['scope'] === 'admin') ? 'admin' : 'read';
        $label    = isset($_POST['label']) ? wp_unslash($_POST['label']) : '';
        $window   = wpmcp_form_window_secs($_POST['window_hours'] ?? null);
        $lifetime = wpmcp_form_lifetime_secs($_POST['lifetime_days'] ?? null);
        // 0 -> the minting admin. wpmcp_mint() rejects an ID with no user behind it.
        $owner = isset($_POST['wpmcp_user_id']) ? (int) $_POST['wpmcp_user_id'] : 0;
        $res   = wpmcp_mint($scope, $label, $window, $lifetime, $owner);
        if (is_wp_error($res)) {
            $notice = 'Error: ' . esc_html($res->get_error_message());
        } else {
            $owner_user = get_userdata($owner ? $owner : get_current_user_id());
            $minted = array(
                'url'      => wpmcp_endpoint_url(),
                'raw'      => $res['raw'],
                'scope'    => $scope,
                'window'   => wpmcp_format_duration($window),
                'lifetime' => wpmcp_format_duration($lifetime),
                'owner'    => $owner_user ? $owner_user->user_login : '',
            );
        }
    }

    // Handle revoke
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'revoke' && isset($_POST['id'])) {
        check_admin_referer('wpmcp_revoke');
        wpmcp_revoke((int) $_POST['id']);
        $notice = 'Token revoked.';
    }

    // Handle renew. Its own nonce, so a revoke form cannot be replayed as a renew.
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'renew' && isset($_POST['id'])) {
        check_admin_referer('wpmcp_renew');
        $renewed = wpmcp_renew((int) $_POST['id']);
        $notice  = is_wp_error($renewed)
            ? 'Could not renew: ' . $renewed->get_error_message()
            : 'Token renewed - its active window runs again until ' . $renewed . ' UTC.'
              . ' The token itself did not change, so the client needs no edit.';
    }

    // Handle the code-editing + SQL + meta settings save.
    //
    // ONE FORM, ONE NONCE, and the SQL switch and the meta allow-list ride in it rather
    // than getting forms of their own. Every one of them turns on a surface that an
    // admin-scope token can reach and none is on by default, so an operator decides about
    // them in one place and one submit; a second form would be a second nonce and a
    // second way for a checkbox to be silently left at its old value because the other
    // form was the one that posted.
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'code_settings') {
        check_admin_referer('wpmcp_code');
        wpmcp_save_settings(wp_unslash($_POST));
        $notice = 'Code-editing, SQL and post-meta settings saved.';
    }

    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . wpmcp_table() . ' ORDER BY created_at DESC');

    // The window field's ceiling, in hours: 12, or 720 on a local site (sprint 14b).
    $local        = wpmcp_is_local_environment();
    $window_hours = (int) (wpmcp_max_window() / HOUR_IN_SECONDS);
    ?>
    <div class="wrap">
      <h1>WP MCP</h1>
      <p><?php echo wpmcp_admin_build_line(); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in wpmcp_admin_build_line() ?></p>
      <p>Short-lived, admin-minted tokens for the MCP endpoint. Read-only by default.</p>

      <?php if (wpmcp_site_is_https()): ?>
        <p>Endpoint URL (constant - it never changes, and never contains the token):
           <code><?php echo esc_html(wpmcp_endpoint_url()); ?></code></p>
        <div class="notice notice-warning">
          <p><strong>HTTPS enforcement relies on your proxy overwriting &mdash; not
             forwarding &mdash; <code>X-Forwarded-Proto</code>.</strong></p>
          <p>WordPress decides whether a request arrived encrypted from what the web
             server told PHP, and a reverse proxy, CDN or local dev stack that passes the
             <em>client's</em> <code>X-Forwarded-Proto</code> through lets a client claim
             HTTPS over a plaintext connection &mdash; token in cleartext, request
             accepted. Your proxy must set that header from its own view of the
             connection. In nginx:
             <code>proxy_set_header X-Forwarded-Proto $scheme;</code></p>
        </div>
      <?php else: ?>
        <?php // No URL at all: every request to it would be refused, and printing one
              // that cannot work is worse than printing none. ?>
        <div class="notice notice-error">
          <p><strong>This site is not served over HTTPS, so the MCP endpoint is closed.</strong></p>
          <p>Every request is refused with <code>403 HTTPS required</code> before the
             token is even read - a token in a URL or an <code>Authorization</code>
             header over plaintext is a token given away. Put the site on HTTPS and
             the endpoint address appears here.</p>
          <p>For a local development site with no certificate, and nowhere else, add
             <code>define('WPMCP_ALLOW_INSECURE', true);</code> to
             <code>wp-config.php</code>.</p>
        </div>
      <?php endif; ?>

      <?php
      // The two trace-log warnings are NOT rendered here. They are on `admin_notices`
      // site-wide (trace.php): an operator who never opens this page is exactly the
      // operator who needs to be told the log is public.
      ?>

      <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

      <h2>Generate a token</h2>
      <form method="post">
        <?php wp_nonce_field('wpmcp_mint'); ?>
        <input type="hidden" name="wpmcp_action" value="mint">
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="wpmcp-scope">Scope</label></th>
            <td>
              <select name="scope" id="wpmcp-scope">
                <option value="read" selected>read (safe default)</option>
                <option value="admin">admin (full)</option>
              </select>
              <p class="description">Read tokens are refused any write tool.
                 Read scope is not privacy: a read-scope token can read everything its user
                 can, including every user's email address when that user is an
                 administrator. Scope gates writing only.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="wpmcp-user-id">Runs as</label></th>
            <td>
              <?php wp_dropdown_users(array(
                  'name'     => 'wpmcp_user_id',
                  'id'       => 'wpmcp-user-id',
                  'selected' => get_current_user_id(),
              )); ?>
              <p class="description">The token authenticates as this WordPress user. Its
                 capabilities are the ceiling on what the token can see or do - scope only
                 narrows further. Defaults to you.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="wpmcp-label">Label</label></th>
            <td><input name="label" id="wpmcp-label" type="text" class="regular-text" placeholder="e.g. claude code"></td>
          </tr>
          <tr>
            <th scope="row"><label for="wpmcp-window">Active window (hours)</label></th>
            <td><input name="window_hours" id="wpmcp-window" type="number" min="0.5" max="<?php echo (int) $window_hours; ?>" step="0.5" value="6">
              <p class="description">How long the token answers before it goes
                 <strong>dormant</strong>. A dormant token is refused like any other bad
                 credential, but its row stays here and <strong>Renew</strong> restarts
                 the window &mdash; the token itself never changes, so the client does not
                 have to be touched. Hard cap <?php echo esc_html(wpmcp_format_duration(wpmcp_max_window())); ?>.</p>
              <?php if ($local): ?>
                <p class="description"><?php echo esc_html('This site reports environment type "local", so tokens may stay active up to 30 days.'); ?></p>
              <?php endif; ?></td>
          </tr>
          <tr>
            <th scope="row"><label for="wpmcp-lifetime">Lifetime (days)</label></th>
            <td><input name="lifetime_days" id="wpmcp-lifetime" type="number" min="1" max="365" step="1" value="30">
              <p class="description">The hard end. Past it the token is <strong>dead</strong>:
                 Renew is not offered and the row is cleaned up within the hour. Hard cap
                 365 days.</p></td>
          </tr>
        </table>
        <?php submit_button('Generate token'); ?>
      </form>

      <?php if ($minted): ?>
        <div class="notice notice-success">
          <p><strong>Token created - copy it now, it won't be shown again:</strong></p>
          <p style="display:flex;gap:8px;align-items:center">
            <?php // THE TOKEN ITSELF, not a URL: the header is the only place a token
                  // goes now, so the one string worth a copy button is the token. ?>
            <input type="text" id="wpmcp-newtok" readonly style="flex:1;font-family:monospace" value="<?php echo esc_attr($minted['raw']); ?>" onclick="this.select()">
            <button type="button" class="button button-primary" onclick="var i=document.getElementById('wpmcp-newtok');i.focus();i.select();var ok=false;try{ok=document.execCommand('copy');}catch(e){}if(navigator.clipboard){navigator.clipboard.writeText(i.value).catch(function(){});}var b=this,t=b.textContent;b.textContent=ok?'Copied':'Select + Ctrl C';setTimeout(function(){b.textContent=t;},1500);">Copy</button>
          </p>
          <p>Runs as: <strong><?php echo esc_html($minted['owner']); ?></strong> &middot;
             Scope: <strong><?php echo esc_html($minted['scope']); ?></strong> &middot;
             Active window: <strong><?php echo esc_html($minted['window']); ?></strong> &middot;
             Lifetime: <strong><?php echo esc_html($minted['lifetime']); ?></strong></p>
          <p class="description">When a client starts getting <code>401</code>, look at
             the Status column below before assuming anything is broken:
             <strong>dormant</strong> needs Renew and nothing else,
             <strong>dead</strong> needs a new token and one edit of the client.</p>

          <?php if (wpmcp_site_is_https()): ?>
            <p style="margin-top:14px"><strong>The two things a client needs</strong> &mdash;
               the URL never changes and never contains the token:</p>
            <p>URL: <code><?php echo esc_html($minted['url']); ?></code><br>
               Header: <code>Authorization: Bearer <?php echo esc_html($minted['raw']); ?></code></p>

            <p style="margin-top:14px"><strong>claude.ai and Claude Desktop</strong>
               (Settings &rarr; Connectors &rarr; Add custom connector):</p>
            <ol style="margin:0 0 0 22px">
              <li>URL: <code><?php echo esc_html($minted['url']); ?></code></li>
              <li>Authentication: <strong>No sign-in</strong> (OAuth is not offered here).</li>
              <li>Request header &mdash; name <code>authorization</code>, value
                  <code>Bearer <?php echo esc_html($minted['raw']); ?></code></li>
            </ol>
            <p class="description">Claude cannot edit that header after the connector is
               added, so give a connector a long lifetime and use <strong>Renew</strong>
               below when it goes dormant. Changing the <em>token</em> means deleting the
               connector and adding it again.</p>

            <p style="margin-top:14px"><strong>Claude Code</strong>:</p>
            <p><code>claude mcp add --transport http wpmcp <?php echo esc_html($minted['url']); ?> --header "Authorization: Bearer <?php echo esc_html($minted['raw']); ?>"</code></p>
          <?php else: ?>
            <p><strong>That is the token itself.</strong> This site is not on HTTPS, so
               the endpoint refuses every request and there is no address worth copying
               yet.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h2>Active &amp; recent tokens</h2>
      <table class="widefat striped">
        <thead><tr>
          <th>Label</th><th>Owner</th><th>Scope</th><th>Status</th>
          <th>Active until (UTC)</th><th>Lifetime ends (UTC)</th>
          <th>Last used</th><th>Uses</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9"><em>No tokens. The endpoint is dormant until one is minted.</em></td></tr>
        <?php else: foreach ($rows as $r):
            $status = wpmcp_token_status($r);
            // A deleted user leaves the id behind; say so rather than printing a bare
            // number, because such a token is dead and the admin needs to know why.
            $owner = get_userdata((int) $r->user_id); ?>
          <tr<?php echo $status === 'dead' ? ' style="opacity:.5"' : ''; ?>>
            <td><?php echo esc_html($r->label); ?></td>
            <td><?php echo $owner
                ? esc_html($owner->user_login)
                : '<em>' . esc_html('deleted user #' . (int) $r->user_id) . '</em>'; ?></td>
            <td><?php echo esc_html($r->scope); ?></td>
            <td><?php echo esc_html(str_replace('_', ' ', $status)); ?></td>
            <?php
            // THE WINDOW THIS SITE HONOURS, not the one the column happens to hold
            // (sprint 14b round 2). A row copied in from a site with a wider ceiling - a
            // local development database moved to staging - stops answering earlier here
            // than its own column says, and an operator reading this table has to see the
            // moment the endpoint will actually use, or "dormant" looks like a bug.
            $honoured = min((int) $r->window_secs, wpmcp_max_window());
            $ends     = gmdate('Y-m-d H:i:s', wpmcp_effective_active_until($r));
            ?>
            <td><?php echo esc_html($ends); ?>
                <span class="description">(<?php echo esc_html(wpmcp_format_duration($honoured)); ?><?php
                    if ($honoured !== (int) $r->window_secs) {
                        echo esc_html(', capped from ' . wpmcp_format_duration((int) $r->window_secs) . ' by this site');
                    }
                ?>)</span></td>
            <td><?php echo esc_html($r->expires_at); ?></td>
            <td><?php echo esc_html($r->last_used_at ? $r->last_used_at : '-'); ?></td>
            <td><?php echo (int) $r->use_count; ?></td>
            <td style="white-space:nowrap">
              <?php // RENEW IS OFFERED ONLY WHERE IT CAN WORK. A dead row has nothing
                    // left to extend, and a row whose owner was deleted is refused on
                    // every request whatever its timers say - wpmcp_renew() refuses both
                    // - so showing the button would be offering an action that answers
                    // with an error, or worse, a green notice for a token that does not
                    // work. ?>
              <?php if ($status === 'active' || $status === 'dormant'): ?>
                <form method="post" style="display:inline;margin:0">
                  <?php wp_nonce_field('wpmcp_renew'); ?>
                  <input type="hidden" name="wpmcp_action" value="renew">
                  <input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
                  <button class="button button-small button-primary">Renew</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline;margin:0">
                <?php wp_nonce_field('wpmcp_revoke'); ?>
                <input type="hidden" name="wpmcp_action" value="revoke">
                <input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
                <button class="button button-small">Revoke</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <h2>Code editing, SQL reads and post meta</h2>
      <form method="post">
        <?php wp_nonce_field('wpmcp_code'); ?>
        <input type="hidden" name="wpmcp_action" value="code_settings">
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Enable code-edit tools</th>
            <td>
              <label><input type="checkbox" name="code_enabled" value="1" <?php checked(wpmcp_code_enabled()); ?>>
                Allow admin-scope tokens to read/write files in the active theme</label>
              <p class="description">Off by default. While off, the code tools are not exposed at all, even to admin tokens.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Editable root</th>
            <td><code><?php echo esc_html(get_stylesheet_directory()); ?></code>
              <p class="description">Active theme only. Cannot be changed here.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="wpmcp-denylist">Denylist</label></th>
            <td>
              <textarea name="denylist" id="wpmcp-denylist" rows="6" class="large-text code"><?php echo esc_textarea(implode("\n", wpmcp_code_denylist())); ?></textarea>
              <p class="description">One per line, never read or written. Bare name (functions.php) blocks that file anywhere; trailing slash (inc/) blocks a folder.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Allow SQL reads (sql-select)</th>
            <td>
              <label><input type="checkbox" name="sql_enabled" value="1" <?php checked(wpmcp_sql_enabled()); ?>>
                Allow admin-scope tokens to run one read-only SQL SELECT at a time</label>
              <p class="description">Off by default. It reads every table the WordPress
                database user can read &mdash; including <code><?php echo esc_html($wpdb->users); ?></code>
                and its password hashes. Writes are refused by the database itself, not by
                a filter: the statement runs inside a READ ONLY transaction, wrapped so
                that anything but a single SELECT is a syntax error. At most 200 rows and
                256&nbsp;KB per call. While off, the tool is not exposed at all, even to
                admin tokens.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="wpmcp-meta-keys">Post meta keys</label></th>
            <td>
              <textarea name="meta_keys" id="wpmcp-meta-keys" rows="6" class="large-text code"><?php echo esc_textarea(implode("\n", wpmcp_meta_keys())); ?></textarea>
              <p class="description">Post meta keys the tools may read and write, one per
                line. Empty by default, and while it is empty <code>get-post-meta</code>
                and <code>set-post-meta</code> are not exposed at all. Exact key names, not
                patterns. Keys WordPress treats as protected &mdash; anything starting with
                an underscore, such as <code>_thumbnail_id</code> or <code>_edit_lock</code>
                &mdash; are dropped when you save and refused if called anyway. An
                <a href="https://www.advancedcustomfields.com/" target="_blank" rel="noopener">ACF</a>
                field is stored under its field name, so naming the field here is what lets
                a token read and write it.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save code, SQL and meta settings'); ?>
      </form>
      <p style="margin-top:24px;color:#666;font-size:12px">
        ☕ Like WP MCP? <a href="https://github.com/sponsors/mkonstan" target="_blank" rel="noopener">Sponsor the project</a> &mdash; it stays free either way.
      </p>
    </div>
    <?php
}
