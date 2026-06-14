<?php
/**
 * WP MCP - admin settings page (mint / list / revoke tokens, code-editing config). Admin-only.
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
    add_options_page('WP MCP', 'WP MCP', 'manage_options', 'wp-mcp', 'wpmcp_render_admin');
});

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
        $res   = wpmcp_mint($scope, $label, $ttl);
        if (is_wp_error($res)) {
            $notice = 'Error: ' . esc_html($res->get_error_message());
        } else {
            $minted = array(
                'url'     => rest_url('wpmcp/mcp/' . $res['raw']),
                'base'    => rest_url('wpmcp/mcp'),
                'raw'     => $res['raw'],
                'scope'   => $scope,
                'hours'   => $hours,
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
      <p>Short-lived, IP-pinned tokens for the MCP endpoint. Read-only by default.
         Endpoint base: <code><?php echo esc_html(rest_url('wpmcp/mcp/')); ?>{token}</code></p>

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
            <input type="text" id="wpmcp-newtok" readonly style="flex:1;font-family:monospace" value="<?php echo esc_attr($minted['url']); ?>" onclick="this.select()">
            <button type="button" class="button button-primary" onclick="var i=document.getElementById('wpmcp-newtok');i.focus();i.select();var ok=false;try{ok=document.execCommand('copy');}catch(e){}if(navigator.clipboard){navigator.clipboard.writeText(i.value).catch(function(){});}var b=this,t=b.textContent;b.textContent=ok?'Copied':'Select + Ctrl C';setTimeout(function(){b.textContent=t;},1500);">Copy</button>
          </p>
          <p>Scope: <strong><?php echo esc_html($minted['scope']); ?></strong> &middot;
             Expires in <strong><?php echo esc_html((string) $minted['hours']); ?>h</strong> &middot;
             It binds to the IP of the first tool call; once bound, every request must match.</p>
          <p style="margin-top:10px"><strong>Header style</strong> (recommended; keeps the token out of server logs):</p>
          <p>URL: <code><?php echo esc_html($minted['base']); ?></code><br>
             Header: <code>Authorization: Bearer <?php echo esc_html($minted['raw']); ?></code></p>
        </div>
      <?php endif; ?>

      <h2>Active &amp; recent tokens</h2>
      <table class="widefat striped">
        <thead><tr>
          <th>Label</th><th>Scope</th><th>Created (UTC)</th><th>Expires (UTC)</th>
          <th>Bound IP</th><th>Last used</th><th>Uses</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><em>No tokens. The endpoint is dormant until one is minted.</em></td></tr>
        <?php else: foreach ($rows as $r):
            $expired = strtotime($r->expires_at . ' UTC') <= time(); ?>
          <tr<?php echo $expired ? ' style="opacity:.5"' : ''; ?>>
            <td><?php echo esc_html($r->label); ?></td>
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
    </div>
    <?php
}
