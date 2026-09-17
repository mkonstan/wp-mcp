<?php
/**
 * Keep the local MCP servers' tokens usable - on a LOCAL development site only.
 *
 *   DEVTOKENS_CMD=status DEVTOKENS_MCP_JSON=/path/to/.mcp.json wp eval-file bin/dev-tokens.php
 *   bin/dev-tokens.sh status          # both Local sites, jaygroup then sample
 *
 * Commands (DEVTOKENS_CMD):
 *   status   each matching server's token: active, dormant or dead, minutes left in its
 *            active window and days left in its lifetime.
 *   label    set the label `claude-code dev (local)` on each matching token row, so
 *            nobody revokes a dev token by mistake.
 *   mint     mint a new admin token per matching server - bound to the user of the row
 *            its current token matches, else user 1 - labelled `claude-code dev (local)`,
 *            with a 30-day window and a 365-day lifetime. Writes a timestamped backup of
 *            the .mcp.json, replaces ONLY that server's Authorization header, and says
 *            Claude must be restarted. The OLD TOKEN IS NOT REVOKED.
 *
 * WHICH SERVERS. Only `.mcp.json` entries whose URL host equals this site's home host.
 * A server for any other site is never read past its URL, whatever its token.
 *
 * WHICH ROWS. A server's row is found by sha256 of its bearer value, exactly as the
 * plugin stores it (wpmcp_hash()). Never by label, never by position.
 *
 * WHAT IT NEVER PRINTS. A token, or a token's hash. It prints server names, row ids,
 * states and times.
 *
 * WHERE IT RUNS. Refused unless the site reports environment type exactly 'local' - the
 * same test that widens the active-window cap (wpmcp_is_local_environment()). It runs
 * through `wp eval-file`, so it can only reach a site whose files are on this machine;
 * the environment check is the second fence, not the first.
 *
 * Not shipped: release.yml copies no file from bin/.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "dev-tokens: run me with `wp eval-file`, inside a WordPress site.\n");
    exit(2);
}

if (!function_exists('wpmcp_devtokens_main')) {
    /** The label `label` and `mint` write. */
    define('WPMCP_DEVTOKENS_LABEL', 'claude-code dev (local)');

    /**
     * @return int exit code: 0 done, 1 refused or failed
     */
    function wpmcp_devtokens_main() {
        $cmd  = strtolower(trim((string) getenv('DEVTOKENS_CMD')));
        $path = trim((string) getenv('DEVTOKENS_MCP_JSON'));

        if (!function_exists('wpmcp_is_local_environment') || !function_exists('wpmcp_mint')) {
            return wpmcp_devtokens_refuse('the wp-mcp plugin is not active on this site.');
        }

        $type = (string) wp_get_environment_type();

        if ($type !== 'local' || !wpmcp_is_local_environment()) {
            return wpmcp_devtokens_refuse(
                'this site reports environment type "' . $type . '" and is not treated as a local'
                . ' environment. dev-tokens runs only on a site whose environment type is "local".'
            );
        }

        if (!in_array($cmd, array('status', 'label', 'mint'), true)) {
            return wpmcp_devtokens_refuse('set DEVTOKENS_CMD to status, label or mint.');
        }

        if ($path === '') {
            return wpmcp_devtokens_refuse('set DEVTOKENS_MCP_JSON to the path of the .mcp.json to read.');
        }

        if (!is_file($path) || !is_readable($path)) {
            return wpmcp_devtokens_refuse('cannot read ' . $path . '.');
        }

        $text   = (string) file_get_contents($path);
        $config = json_decode($text, true);

        if (!is_array($config) || !isset($config['mcpServers']) || !is_array($config['mcpServers'])) {
            return wpmcp_devtokens_refuse($path . ' is not a .mcp.json with an mcpServers object.');
        }

        $host    = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $servers = wpmcp_devtokens_servers($config['mcpServers'], $host);

        echo 'dev-tokens: ', $cmd, ' on ', $host, ' (environment type local), ', count($servers),
            ' server(s) in .mcp.json for this host.', "\n";

        if ($cmd === 'status') {
            foreach ($servers as $name => $server) {
                echo wpmcp_devtokens_status_line($name, $server), "\n";
            }

            return 0;
        }

        if ($cmd === 'label') {
            return wpmcp_devtokens_label($servers);
        }

        return wpmcp_devtokens_mint($servers, $path, $text);
    }

    function wpmcp_devtokens_refuse($why) {
        fwrite(STDERR, 'dev-tokens: refused - ' . $why . "\n");

        return 1;
    }

    /**
     * The servers whose URL host is this site's home host, each with its bearer token
     * ('' when it has none) and its row (null when no row carries that token's hash).
     *
     * @return array<string, array{token:string, row:object|null}>
     */
    function wpmcp_devtokens_servers(array $all, $host) {
        global $wpdb;

        $found = array();

        foreach ($all as $name => $server) {
            if (!is_array($server) || !isset($server['url']) || !is_string($server['url'])) {
                continue;
            }

            if (strtolower((string) wp_parse_url($server['url'], PHP_URL_HOST)) !== $host || $host === '') {
                continue;
            }

            $token = '';

            foreach ((array) ($server['headers'] ?? array()) as $header => $value) {
                if (strtolower((string) $header) === 'authorization'
                    && is_string($value)
                    && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $value, $m)) {
                    $token = $m[1];
                }
            }

            $row = null;

            if ($token !== '') {
                $row = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . wpmcp_table() . ' WHERE token_hash = %s',
                    wpmcp_hash($token)
                ));
            }

            $found[(string) $name] = array('token' => $token, 'row' => $row ?: null);
        }

        return $found;
    }

    function wpmcp_devtokens_status_line($name, array $server) {
        if ($server['token'] === '') {
            return $name . ': no Authorization: Bearer header.';
        }

        $row = $server['row'];

        if (!$row) {
            return $name . ': no token row on this site matches this server\'s token.';
        }

        $now     = time();
        $minutes = max(0, (int) floor((strtotime($row->active_until . ' UTC') - $now) / 60));
        $days    = max(0, (int) floor((strtotime($row->expires_at . ' UTC') - $now) / DAY_IN_SECONDS));

        return sprintf(
            '%s: %s - %d min left in the active window, %d d left in the lifetime (row %d, %s scope, user %d, label "%s").',
            $name,
            wpmcp_token_status($row),
            $minutes,
            $days,
            (int) $row->id,
            (string) $row->scope,
            (int) $row->user_id,
            (string) $row->label
        );
    }

    function wpmcp_devtokens_label(array $servers) {
        global $wpdb;

        foreach ($servers as $name => $server) {
            if (!$server['row']) {
                echo wpmcp_devtokens_status_line($name, $server), ' Nothing labelled.', "\n";
                continue;
            }

            $wpdb->update(
                wpmcp_table(),
                array('label' => WPMCP_DEVTOKENS_LABEL),
                array('id' => (int) $server['row']->id),
                array('%s'),
                array('%d')
            );

            echo $name, ': row ', (int) $server['row']->id, ' labelled "', WPMCP_DEVTOKENS_LABEL, '".', "\n";
        }

        return 0;
    }

    /**
     * Mint, then rewrite the file by replacing each old bearer value in the TEXT, so every
     * other byte of a hand-edited file stays as it was. A token that appears in the file
     * more than once cannot be replaced for one server alone, so that server is skipped.
     */
    function wpmcp_devtokens_mint(array $servers, $path, $text) {
        $replacements = array();
        $minted_ids   = array();
        $status       = 0;

        foreach ($servers as $name => $server) {
            if ($server['token'] === '') {
                echo $name, ': no Authorization: Bearer header to replace. Skipped.', "\n";
                continue;
            }

            if (substr_count($text, $server['token']) !== 1) {
                echo $name, ': its token appears more than once in the file, so it cannot be replaced for this server alone. Skipped.', "\n";
                $status = 1;
                continue;
            }

            $user_id = $server['row'] ? (int) $server['row']->user_id : 1;

            if (!get_userdata($user_id)) {
                echo $name, ': user ', $user_id, ' does not exist. Skipped.', "\n";
                $status = 1;
                continue;
            }

            // Mint AS the bound user: wp-cli has no current user, and minting for somebody
            // else needs edit_user over them.
            wp_set_current_user($user_id);

            $minted = wpmcp_mint('admin', WPMCP_DEVTOKENS_LABEL, 30 * DAY_IN_SECONDS, 365 * DAY_IN_SECONDS, $user_id);

            if (is_wp_error($minted)) {
                echo $name, ': mint failed - ', $minted->get_error_message(), "\n";
                $status = 1;
                continue;
            }

            $replacements[$server['token']] = $minted['raw'];
            $minted_ids[$name]              = (int) $minted['id'];

            echo $name, ': minted row ', (int) $minted['id'], ' (admin scope, user ', $user_id,
                ', 30-day window, 365-day lifetime)', ($server['row'] ? ', replacing row ' . (int) $server['row']->id : ''), '.', "\n";
        }

        if (!$replacements) {
            echo 'Nothing minted; .mcp.json not touched.', "\n";

            return $status;
        }

        $backup = $path . '.backup-' . gmdate('Ymd-His');

        for ($n = 2; file_exists($backup); $n++) {
            $backup = $path . '.backup-' . gmdate('Ymd-His') . '-' . $n;
        }

        $new = strtr($text, $replacements);

        // BACKUP, THEN A WHOLE FILE AT ONCE. copy() first, so nothing is written unless a
        // byte-identical copy already exists; then the new text goes to a temporary file
        // beside the original and is RENAMED over it, because a truncating write that
        // fails midway - a full disk, an editor holding the file open on Windows - would
        // leave a half-written .mcp.json and no client able to read it (review round 1,
        // S1). rename() within one directory replaces the file in one step.
        $tmp    = $path . '.tmp-' . getmypid();
        $failed = '';

        if (!copy($path, $backup)) {
            $failed = 'could not write the backup ' . $backup . '; the .mcp.json was not touched';
        } elseif (file_put_contents($tmp, $new, LOCK_EX) !== strlen($new)) {
            @unlink($tmp);
            $failed = 'could not write ' . $tmp . '; the .mcp.json is unchanged and a backup is at ' . $backup;
        } elseif (!rename($tmp, $path)) {
            @unlink($tmp);
            $failed = 'could not replace ' . $path . ' with the rewritten file; the previous file is unchanged and also copied to ' . $backup;
        }

        if ($failed !== '') {
            // Never leave a token live that nobody holds.
            foreach ($minted_ids as $id) {
                wpmcp_revoke($id);
            }

            fwrite(STDERR, 'dev-tokens: ' . $failed . '. The newly minted token(s) were revoked, so the old ones still work.' . "\n");

            return 1;
        }

        echo 'Backup of the previous file: ', $backup, "\n";
        echo 'Rewrote only the Authorization header of: ', implode(', ', array_keys($minted_ids)), '.', "\n";
        echo 'Restart Claude Code so it picks up the new token(s).', "\n";
        echo 'The old token(s) were NOT revoked. Revoke them in Settings > WP MCP when nothing uses them.', "\n";

        return $status;
    }
}

$wpmcp_devtokens_exit = wpmcp_devtokens_main();

if ($wpmcp_devtokens_exit !== 0) {
    exit($wpmcp_devtokens_exit);
}
