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

/** The endpoint URL, forced to https - the only scheme the endpoint answers on. */
function wpmcp_endpoint_url($path = '') {
    return set_url_scheme(rest_url('wpmcp/mcp' . $path), 'https');
}

function wpmcp_render_admin() {
    if (!current_user_can('manage_options')) { wp_die('Insufficient permissions.'); }

    $minted = null; // shown once after mint
    $notice = '';

    // Handle mint
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'mint') {
        check_admin_referer('wpmcp_mint');
        $scope = (isset($_POST['scope']) && $_POST['scope'] === 'admin') ? 'admin' : 'read';
        $label = isset($_POST['label']) ? wp_unslash($_POST['label']) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 12;
        $ttl   = (int) round(min(12, max(0.0167, $hours)) * HOUR_IN_SECONDS); // up to 12h
        // 0 -> the minting admin. wpmcp_mint() rejects an ID with no user behind it.
        $owner = isset($_POST['wpmcp_user_id']) ? (int) $_POST['wpmcp_user_id'] : 0;
        $res   = wpmcp_mint($scope, $label, $ttl, $owner);
        if (is_wp_error($res)) {
            $notice = 'Error: ' . esc_html($res->get_error_message());
        } else {
            $owner_user = get_userdata($owner ? $owner : get_current_user_id());
            $minted = array(
                'url'     => wpmcp_endpoint_url('/' . $res['raw']),
                'base'    => wpmcp_endpoint_url(),
                'raw'     => $res['raw'],
                'scope'   => $scope,
                'hours'   => $hours,
                'owner'   => $owner_user ? $owner_user->user_login : '',
            );
        }
    }

    // Handle revoke
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'revoke' && isset($_POST['id'])) {
        check_admin_referer('wpmcp_revoke');
        wpmcp_revoke((int) $_POST['id']);
        $notice = 'Token revoked.';
    }

    // Handle code-editing settings save
    if (isset($_POST['wpmcp_action']) && $_POST['wpmcp_action'] === 'code_settings') {
        check_admin_referer('wpmcp_code');
        update_option('wpmcp_code_enabled', !empty($_POST['code_enabled']) ? 1 : 0);
        $lines = isset($_POST['denylist']) ? (string) wp_unslash($_POST['denylist']) : '';
        $list  = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $lines))));
        update_option('wpmcp_code_denylist', $list);
        $notice = 'Code-editing settings saved.';
    }

    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . wpmcp_table() . ' ORDER BY created_at DESC');
    ?>
    <div class="wrap">
      <h1>WP MCP</h1>
      <p>Short-lived, IP-pinned tokens for the MCP endpoint. Read-only by default.</p>

      <?php if (wpmcp_site_is_https()): ?>
        <p>Endpoint base: <code><?php echo esc_html(wpmcp_endpoint_url('/')); ?>{token}</code></p>
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
              <p class="description">Read tokens are refused any write tool.</p>
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
            <th scope="row"><label for="wpmcp-hours">Expires in (hours)</label></th>
            <td><input name="hours" id="wpmcp-hours" type="number" min="0.5" max="12" step="0.5" value="12">
              <p class="description">Hard cap 12h.</p></td>
          </tr>
        </table>
        <?php submit_button('Generate token'); ?>
      </form>

      <?php if ($minted): ?>
        <div class="notice notice-success">
          <p><strong>Token created - copy it now, it won't be shown again:</strong></p>
          <p style="display:flex;gap:8px;align-items:center">
            <?php // The URL form on an https site; the bare token on one that has no
                  // working endpoint, because a copyable http:// URL is a trap. ?>
            <input type="text" id="wpmcp-newtok" readonly style="flex:1;font-family:monospace" value="<?php echo esc_attr(wpmcp_site_is_https() ? $minted['url'] : $minted['raw']); ?>" onclick="this.select()">
            <button type="button" class="button button-primary" onclick="var i=document.getElementById('wpmcp-newtok');i.focus();i.select();var ok=false;try{ok=document.execCommand('copy');}catch(e){}if(navigator.clipboard){navigator.clipboard.writeText(i.value).catch(function(){});}var b=this,t=b.textContent;b.textContent=ok?'Copied':'Select + Ctrl C';setTimeout(function(){b.textContent=t;},1500);">Copy</button>
          </p>
          <?php if (!wpmcp_site_is_https()): ?>
            <p><strong>That is the token itself, not a URL.</strong> This site is not on
               HTTPS, so the endpoint refuses every request and there is no address
               worth copying yet.</p>
          <?php endif; ?>
          <p>Runs as: <strong><?php echo esc_html($minted['owner']); ?></strong> &middot;
             Scope: <strong><?php echo esc_html($minted['scope']); ?></strong> &middot;
             Expires in <strong><?php echo esc_html((string) $minted['hours']); ?>h</strong> &middot;
             It binds to the IP of the first tool call; once bound, every request must match.</p>
          <p style="margin-top:10px"><strong>Header style</strong> (recommended; keeps the token out of server logs):</p>
          <p><?php if (wpmcp_site_is_https()): ?>URL: <code><?php echo esc_html($minted['base']); ?></code><br><?php endif; ?>
             Header: <code>Authorization: Bearer <?php echo esc_html($minted['raw']); ?></code></p>
        </div>
      <?php endif; ?>

      <h2>Active &amp; recent tokens</h2>
      <table class="widefat striped">
        <thead><tr>
          <th>Label</th><th>Owner</th><th>Scope</th><th>Created (UTC)</th><th>Expires (UTC)</th>
          <th>Bound IP</th><th>Last used</th><th>Uses</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9"><em>No tokens. The endpoint is dormant until one is minted.</em></td></tr>
        <?php else: foreach ($rows as $r):
            $expired = strtotime($r->expires_at . ' UTC') <= time();
            // A deleted user leaves the id behind; say so rather than printing a bare
            // number, because such a token is dead and the admin needs to know why.
            $owner = get_userdata((int) $r->user_id); ?>
          <tr<?php echo $expired ? ' style="opacity:.5"' : ''; ?>>
            <td><?php echo esc_html($r->label); ?></td>
            <td><?php echo $owner
                ? esc_html($owner->user_login)
                : '<em>' . esc_html('deleted user #' . (int) $r->user_id) . '</em>'; ?></td>
            <td><?php echo esc_html($r->scope); ?></td>
            <td><?php echo esc_html($r->created_at); ?></td>
            <td><?php echo esc_html($r->expires_at) . ($expired ? ' (expired)' : ''); ?></td>
            <td><?php echo esc_html($r->bound_ip ? $r->bound_ip : '- unbound'); ?></td>
            <td><?php echo esc_html($r->last_used_at ? $r->last_used_at : '-'); ?></td>
            <td><?php echo (int) $r->use_count; ?></td>
            <td>
              <form method="post" style="margin:0">
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

      <h2>Code editing</h2>
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
        </table>
        <?php submit_button('Save code settings'); ?>
      </form>
      <p style="margin-top:24px;color:#666;font-size:12px">
        ☕ Like WP MCP? <a href="https://github.com/sponsors/mkonstan" target="_blank" rel="noopener">Sponsor the project</a> &mdash; it stays free either way.
      </p>
    </div>
    <?php
}
