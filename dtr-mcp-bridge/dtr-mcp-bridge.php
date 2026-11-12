<?php
/**
 * Plugin Name: DTR MCP Bridge
 * Description: Mantenimiento REST con solicitudes de un solo uso y aprobacion humana local.
 * Version: 1.3.7
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: DTR
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/dtr-mcp-content.php';
require_once __DIR__ . '/dtr-mcp-elementor.php';
require_once __DIR__ . '/dtr-mcp-extended.php';
require_once __DIR__ . '/dtr-mcp-woo.php';

final class DTR_MCP_Bridge {
    const VERSION = '1.3.7';
    private static $application_user = 0;
    private static $upgrading = false;

    public static function boot() {
        add_action('application_password_did_authenticate', function ($user) { self::$application_user = (int) $user->ID; }, 10, 1);
        add_filter('authenticate', function ($user) {
            if ($user instanceof WP_User && in_array('dtr_mcp_operator', $user->roles, true) && (self::$application_user !== (int) $user->ID || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST))) {
                return new WP_Error('dtr_no_interactive_login', 'Cuenta reservada para Application Passwords.');
            }
            return $user;
        }, 100, 1);
        add_filter('rest_pre_dispatch', [__CLASS__, 'restrict_service_user'], 5, 3);
        add_filter('http_request_args', function ($args) {
            if (self::$upgrading) { $args['redirection'] = 0; $args['sslverify'] = true; }
            return $args;
        }, PHP_INT_MAX, 1);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', function () { add_management_page('DTR MCP', 'DTR MCP', 'manage_options', 'dtr-mcp', [__CLASS__, 'admin_page']); });
        add_action('admin_post_dtr_mcp_decision', [__CLASS__, 'decide']);
    }

    public static function install() {
        if (is_multisite()) { wp_die('DTR MCP Bridge 1.0 no admite WordPress Multisite.'); }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            plan_id char(32) NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            operation varchar(32) NOT NULL,
            target varchar(191) NOT NULL,
            value varchar(80) NOT NULL,
            before_hash char(64) NOT NULL,
            offer_hash char(64) NOT NULL,
            status varchar(20) NOT NULL,
            created_at bigint(20) NOT NULL,
            expires_at bigint(20) NOT NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            backup_ref varchar(191) NOT NULL DEFAULT '',
            result_code varchar(80) NOT NULL DEFAULT '',
            PRIMARY KEY  (plan_id),
            KEY owner_status (owner_id,status)
        ) $charset;");
        DTR_MCP_Woo::install();
        add_role('dtr_mcp_operator', 'DTR MCP operador', [
            'read' => true, 'dtr_mcp_access' => true,
            'activate_plugins' => true, 'switch_themes' => true,
            'edit_theme_options' => true, 'list_users' => true,
        ]);
    }

    private static function table() { global $wpdb; return $wpdb->prefix . 'dtr_mcp_plans'; }
    private static function error($code, $status = 400) { return new WP_Error($code, $code, ['status' => $status]); }
    private static function writes_enabled() { return defined('DTR_MCP_ALLOW_WRITES') && DTR_MCP_ALLOW_WRITES === true; }
    private static function cache_supported() { return defined('DTR_MCP_ALLOW_CACHE_FLUSH') && DTR_MCP_ALLOW_CACHE_FLUSH === true && wp_using_ext_object_cache(); }
    private static function is_service_user() { return in_array('dtr_mcp_operator', wp_get_current_user()->roles, true); }

    public static function restrict_service_user($result, $server, $request) {
        if (!self::is_service_user()) { return $result; }
        if (self::$application_user !== get_current_user_id() || (!is_ssl() && $request->get_route() !== '/dtr-mcp/v1/diagnostics')) { return self::error('DTR_APPLICATION_PASSWORD_REQUIRED', 403); }
        $route = $request->get_route();
        $method = $request->get_method();
        $bridge_read = preg_match('#^/dtr-mcp/v1/(status|updates|users|diagnostics|plans/[a-f0-9]{32})$#D', $route);
        $bridge_write = in_array($route, ['/dtr-mcp/v1/plans', '/dtr-mcp/v1/execute'], true);
        $native_read = in_array($route, ['/', '/wp/v2/users/me', '/wp/v2/plugins', '/wp/v2/themes', '/wp/v2/users', '/wp/v2/posts', '/wp/v2/pages'], true);
        if ($method === 'GET' && $native_read) {
            $fields = ['/wp/v2/users' => 'id,roles', '/wp/v2/plugins' => 'plugin,name,version,status,requires_wp,requires_php', '/wp/v2/themes' => 'stylesheet,name,version,status,requires_wp,requires_php', '/wp/v2/posts' => 'id,status,link', '/wp/v2/pages' => 'id,status,link'];
            if ($route === '/wp/v2/users/me') { $request->set_param('_fields', 'id'); $request->set_param('context', 'view'); }
            if (isset($fields[$route])) { $request->set_param('_fields', $fields[$route]); $request->set_param('_embed', false); }
            if (in_array($route, ['/wp/v2/posts', '/wp/v2/pages'], true)) { $request->set_param('status', 'publish'); $request->set_param('context', 'view'); }
        }
        if (($method === 'GET' && ($native_read || $bridge_read)) || ($method === 'POST' && $bridge_write) || DTR_MCP_Content::allowed_route($route, $method) || DTR_MCP_Extended::allowed_route($route, $method)) { return $result; }
        return self::error('DTR_ROUTE_NOT_ALLOWED', 403);
    }

    public static function permission($write = false) {
        if (!is_ssl() || self::$application_user !== get_current_user_id() || !current_user_can('dtr_mcp_access')) { return self::error('DTR_ACCESS_DENIED', 403); }
        if (is_multisite()) { return self::error('DTR_MULTISITE_NOT_SUPPORTED', 403); }
        if ($write && !self::writes_enabled()) { return self::error('DTR_WRITES_DISABLED', 403); }
        return true;
    }

    public static function routes() {
        $read = function () { return self::permission(); };
        $write = function () { return self::permission(true); };
        register_rest_route('dtr-mcp/v1', '/status', ['methods' => 'GET', 'permission_callback' => $read, 'callback' => function () {
            return ['wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'multisite' => is_multisite(), 'bridge_version' => self::VERSION, 'writes_enabled' => self::writes_enabled(), 'cache_supported' => self::cache_supported(), 'content_writes_enabled' => DTR_MCP_Content::enabled(), 'public_content_requires_approval' => true];
        }]);
        register_rest_route('dtr-mcp/v1', '/diagnostics', [
            'methods' => 'GET',
            'permission_callback' => function () {
                return (get_current_user_id() > 0 && self::$application_user === get_current_user_id())
                    ? true : self::error('DTR_APPLICATION_PASSWORD_REQUIRED', 403);
            },
            'callback' => function () {
                return [
                    'https_recognized' => is_ssl(),
                    'application_password_authenticated' => self::$application_user === get_current_user_id(),
                    'operator_capability' => current_user_can('dtr_mcp_access'),
                    'operator_role' => self::is_service_user(),
                ];
            },
        ]);
        register_rest_route('dtr-mcp/v1', '/updates', ['methods' => 'GET', 'permission_callback' => $read, 'callback' => [__CLASS__, 'updates']]);
        register_rest_route('dtr-mcp/v1', '/users', [
            'methods' => 'GET', 'permission_callback' => [__CLASS__, 'users_permission'],
            'callback' => [__CLASS__, 'users'],
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 10000],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
        register_rest_route('dtr-mcp/v1', '/plans', ['methods' => 'POST', 'permission_callback' => $write, 'callback' => [__CLASS__, 'prepare']]);
        register_rest_route('dtr-mcp/v1', '/plans/(?P<plan_id>[a-f0-9]{32})', ['methods' => 'GET', 'permission_callback' => $read, 'callback' => [__CLASS__, 'status']]);
        register_rest_route('dtr-mcp/v1', '/execute', ['methods' => 'POST', 'permission_callback' => $write, 'callback' => [__CLASS__, 'execute']]);
    }

    public static function users_permission() {
        $allowed = self::permission();
        if (is_wp_error($allowed)) { return $allowed; }
        return current_user_can('list_users') ? true : self::error('DTR_USERS_DENIED', 403);
    }

    public static function users($request) {
        $allowed = self::users_permission();
        if (is_wp_error($allowed)) { return $allowed; }
        $page = $request['page'] ?? 1; $per_page = $request['per_page'] ?? 50;
        foreach (['page' => [$page, 10000], 'per_page' => [$per_page, 100]] as $limits) {
            [$value, $max] = $limits;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value) || (int) $value > $max) { return self::error('DTR_INVALID_PAGINATION'); }
        }
        $query = new WP_User_Query([
            'blog_id' => get_current_blog_id(), 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC',
            'number' => (int) $per_page, 'offset' => ((int) $page - 1) * (int) $per_page, 'count_total' => true,
        ]);
        $data = [];
        foreach ($query->get_results() as $id) {
            $user = get_userdata((int) $id);
            if (!$user) { continue; }
            $data[] = ['id' => (int) $id, 'roles' => array_values(array_filter((array) $user->roles, 'is_string'))];
        }
        $response = new WP_REST_Response($data);
        $response->header('X-WP-Total', (int) $query->get_total());
        $response->header('X-WP-TotalPages', (int) ceil($query->get_total() / (int) $per_page));
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    private static function includes() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    public static function updates() {
        self::includes();
        $plugins = get_plugins(); $themes = wp_get_themes();
        $pu = get_site_transient('update_plugins'); $tu = get_site_transient('update_themes'); $cu = get_site_transient('update_core');
        $times = [(int) ($pu->last_checked ?? 0), (int) ($tu->last_checked ?? 0), (int) ($cu->last_checked ?? 0)];
        $out = ['checked_at' => min($times) ? gmdate('c', min($times)) : null, 'stale' => min($times) < time() - 43200, 'plugins' => [], 'themes' => [], 'core' => []];
        foreach (($pu->response ?? []) as $file => $offer) { if (isset($plugins[$file])) { $out['plugins'][] = ['target' => $file, 'current' => $plugins[$file]['Version'], 'available' => $offer->new_version ?? '']; } }
        foreach (($tu->response ?? []) as $slug => $offer) { if (isset($themes[$slug])) { $out['themes'][] = ['target' => $slug, 'current' => $themes[$slug]->get('Version'), 'available' => $offer['new_version'] ?? '']; } }
        foreach (($cu->updates ?? []) as $offer) { if (($offer->response ?? '') === 'upgrade') { $out['core'][] = ['target' => 'core', 'current' => get_bloginfo('version'), 'available' => $offer->current]; } }
        return $out;
    }

    private static function describe($op, $target, $value, $refresh = false) {
        self::includes();
        $plugins = get_plugins(); $state = null; $offer = null;
        if (in_array($op, ['plugin_state', 'update_plugin'], true)) {
            if (!isset($plugins[$target]) || $target === plugin_basename(__FILE__) || in_array($target, (array) (defined('DTR_MCP_PROTECTED_PLUGINS') ? DTR_MCP_PROTECTED_PLUGINS : []), true)) { return self::error('DTR_PROTECTED_OR_UNKNOWN_PLUGIN'); }
            $state = [$plugins[$target]['Version'], is_plugin_active($target)];
            if ($op === 'plugin_state') {
                if (!in_array($value, ['active', 'inactive'], true)) { return self::error('DTR_INVALID_STATE'); }
                if (($value === 'active') === is_plugin_active($target)) { return self::error('DTR_ALREADY_IN_STATE', 409); }
            } else {
                if ($refresh) { wp_update_plugins(); }
                $u = get_site_transient('update_plugins'); $offer = $u->response[$target] ?? null;
            }
        } elseif ($op === 'update_theme') {
            $theme = wp_get_theme($target);
            if (!$theme->exists()) { return self::error('DTR_UNKNOWN_THEME'); }
            $state = [$theme->get('Version'), get_stylesheet() === $target];
            if ($refresh) { wp_update_themes(); }
            $u = get_site_transient('update_themes'); $offer = isset($u->response[$target]) ? (object) $u->response[$target] : null;
        } elseif ($op === 'update_core') {
            if ($target !== 'core') { return self::error('DTR_INVALID_TARGET'); }
            $state = [get_bloginfo('version')];
            if ($refresh) { wp_version_check(); }
            foreach (get_core_updates() ?: [] as $candidate) { if ($candidate->response === 'upgrade' && $candidate->current === $value) { $offer = $candidate; break; } }
        } elseif ($op === 'cache_purge') {
            if ($target !== 'object' || $value !== 'flush' || !self::cache_supported()) { return self::error('DTR_CACHE_UNSUPPORTED'); }
            $state = ['persistent_object_cache'];
        } else { return self::error('DTR_OPERATION_NOT_ALLOWED'); }
        if (str_starts_with($op, 'update_')) {
            if (!wp_is_file_mod_allowed('dtr_mcp_update')) { return self::error('DTR_FILE_MODIFICATIONS_DISABLED', 403); }
            if (!preg_match('/^[0-9][A-Za-z0-9.+-]{0,39}$/D', $value)) { return self::error('DTR_INVALID_VERSION'); }
            $available = $op === 'update_core' ? ($offer->current ?? '') : ($offer->new_version ?? '');
            if (!$offer || $available !== $value || !version_compare($value, $state[0], '>')) { return self::error('DTR_VERSION_NOT_AVAILABLE', 409); }
            $packages = $op === 'update_core' ? array_filter((array) ($offer->packages ?? []), 'is_string') : [$offer->package ?? ''];
            $hosts = defined('DTR_MCP_PACKAGE_HOSTS') ? (array) DTR_MCP_PACKAGE_HOSTS : ['downloads.wordpress.org'];
            if (!$packages) { return self::error('DTR_PACKAGE_SOURCE_NOT_ALLOWED', 403); }
            foreach ($packages as $package) {
                if (!$package) { continue; }
                if (wp_parse_url($package, PHP_URL_SCHEME) !== 'https' || !in_array(wp_parse_url($package, PHP_URL_HOST), $hosts, true) || wp_parse_url($package, PHP_URL_USER) || wp_parse_url($package, PHP_URL_PASS)) { return self::error('DTR_PACKAGE_SOURCE_NOT_ALLOWED', 403); }
            }
        }
        return ['before_hash' => hash('sha256', wp_json_encode($state)), 'offer_hash' => hash('sha256', wp_json_encode($offer)), 'offer' => $offer];
    }

    public static function prepare($request) {
        global $wpdb;
        $a = $request->get_json_params();
        if (!is_array($a) || array_diff(array_keys($a), ['operation', 'target', 'value']) || count($a) !== 3) { return self::error('DTR_INVALID_ARGUMENTS'); }
        foreach ($a as $v) { if (!is_string($v) || strlen($v) > 191 || preg_match('/[\x00-\x1F]/', $v)) { return self::error('DTR_INVALID_ARGUMENTS'); } }
        $table = self::table();
        $recent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND created_at>%d", get_current_user_id(), time() - 3600));
        if ($recent >= 30) { return self::error('DTR_RATE_LIMIT', 429); }
        $d = self::describe($a['operation'], $a['target'], $a['value'], true);
        if (is_wp_error($d)) { return $d; }
        $id = bin2hex(random_bytes(16));
        $row = ['plan_id' => $id, 'owner_id' => get_current_user_id(), 'operation' => $a['operation'], 'target' => $a['target'], 'value' => $a['value'], 'before_hash' => $d['before_hash'], 'offer_hash' => $d['offer_hash'], 'status' => 'pending', 'created_at' => time(), 'expires_at' => time() + 900];
        if (!$wpdb->insert($table, $row)) { return self::error('DTR_STORAGE_FAILED', 500); }
        return self::public_plan($row);
    }

    private static function public_plan($row) {
        return ['plan_id' => $row['plan_id'], 'status' => (int) $row['expires_at'] < time() && in_array($row['status'], ['pending', 'approved'], true) ? 'expired' : $row['status'], 'operation' => $row['operation'], 'target' => $row['target'], 'value' => $row['value'], 'expires_at' => gmdate('c', (int) $row['expires_at']), 'approval_required' => true, 'result' => $row['result_code'] ?? ''];
    }

    public static function status($request) {
        global $wpdb; $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE plan_id=%s AND owner_id=%d", $request['plan_id'], get_current_user_id()), ARRAY_A);
        return $row ? self::public_plan($row) : self::error('DTR_PLAN_NOT_FOUND', 404);
    }

    public static function execute($request) {
        global $wpdb; $table = self::table();
        $a = $request->get_json_params();
        if (!is_array($a) || array_keys($a) !== ['plan_id'] || !is_string($a['plan_id']) || !preg_match('/^[a-f0-9]{32}$/D', $a['plan_id'])) { return self::error('DTR_INVALID_ARGUMENTS'); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE plan_id=%s AND owner_id=%d", $a['plan_id'], get_current_user_id()), ARRAY_A);
        if (!$row) { return self::error('DTR_PLAN_NOT_FOUND', 404); }
        if ($row['operation'] === 'content_change') { return self::error('DTR_USE_CONTENT_EXECUTE'); }
        if ($row['operation'] === 'extended_change') { return self::error('DTR_USE_EXTENDED_EXECUTE'); }
        if ($row['status'] !== 'approved' || (int) $row['expires_at'] < time()) { return self::error('DTR_NOT_APPROVED_OR_EXPIRED', 409); }
        // Atomic lock, intentionally no automatic expiry: a crashed update needs human inspection.
        $lock = bin2hex(random_bytes(16));
        if (!add_option('dtr_mcp_write_lock', $lock, '', false)) { return self::error('DTR_SITE_LOCKED', 409); }
        try {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='running' WHERE plan_id=%s AND owner_id=%d AND status='approved' AND expires_at>=%d", $row['plan_id'], get_current_user_id(), time()));
            if ($claimed !== 1) { return self::error('DTR_ALREADY_CLAIMED', 409); }
            $d = self::describe($row['operation'], $row['target'], $row['value'], true);
            if (is_wp_error($d) || !hash_equals($row['before_hash'], $d['before_hash']) || !hash_equals($row['offer_hash'], $d['offer_hash'])) {
                $wpdb->update($table, ['status' => 'failed', 'result_code' => 'DTR_STATE_CHANGED'], ['plan_id' => $row['plan_id']]);
                return self::error('DTR_STATE_CHANGED', 409);
            }
            $result = self::perform($row, $d);
            $success = !is_wp_error($result) && $result === true;
            $wpdb->update($table, ['status' => $success ? 'completed' : 'failed', 'result_code' => $success ? 'OK' : 'DTR_EXECUTION_FAILED'], ['plan_id' => $row['plan_id']]);
            $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE plan_id=%s", $row['plan_id']), ARRAY_A);
            return self::public_plan($latest);
        } catch (Throwable $e) {
            $wpdb->update($table, ['status' => 'failed', 'result_code' => 'DTR_EXECUTION_EXCEPTION'], ['plan_id' => $row['plan_id']]);
            return self::error('DTR_EXECUTION_FAILED', 500);
        } finally {
            if (get_option('dtr_mcp_write_lock') === $lock) { delete_option('dtr_mcp_write_lock'); }
        }
    }

    private static function perform($row, $d) {
        $op = $row['operation']; $target = $row['target'];
        if ($op === 'plugin_state') {
            if ($row['value'] === 'active') { $r = activate_plugin($target, '', false, false); if (is_wp_error($r)) { return $r; } }
            else { deactivate_plugins($target, false, false); }
            return is_plugin_active($target) === ($row['value'] === 'active');
        }
        if ($op === 'cache_purge') { return wp_cache_flush() === true; }
        if (!str_starts_with($op, 'update_') || !$row['backup_ref'] || get_filesystem_method() !== 'direct') { return self::error('DTR_UPDATE_PREREQUISITES'); }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $skin = new Automatic_Upgrader_Skin();
        ob_start(); self::$upgrading = true;
        try {
            if ($op === 'update_plugin') {
                $r = (new Plugin_Upgrader($skin))->upgrade($target);
                wp_clean_plugins_cache(false); $plugins = get_plugins();
                return !is_wp_error($r) && $r === true && ($plugins[$target]['Version'] ?? '') === $row['value'];
            }
            if ($op === 'update_theme') {
                $r = (new Theme_Upgrader($skin))->upgrade($target);
                wp_clean_themes_cache(false);
                return !is_wp_error($r) && $r === true && wp_get_theme($target)->get('Version') === $row['value'];
            }
            $r = (new Core_Upgrader($skin))->upgrade($d['offer']);
            if (is_wp_error($r) || !$r) { return self::error('DTR_CORE_UPDATE_FAILED'); }
            // Core_Upgrader invokes the database upgrade in a fresh HTTP request. Verify both.
            $new = (static function () { include ABSPATH . WPINC . '/version.php'; return [$wp_version, $wp_db_version]; })();
            if ($new[0] !== $row['value']) { return self::error('DTR_CORE_VERSION_MISMATCH'); }
            wp_cache_delete('db_version', 'options');
            return (int) get_option('db_version') === (int) $new[1];
        } finally { self::$upgrading = false; ob_end_clean(); }
    }

    public static function admin_page() {
        if (!current_user_can('manage_options') || self::is_service_user()) { wp_die('Acceso denegado.'); }
        global $wpdb; $table = self::table();
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100", ARRAY_A);
        echo '<div class="wrap"><h1>DTR MCP</h1><p>Revise sitio, operacion, destino y version. Cada solicitud caduca en 15 minutos. La aprobacion no ejecuta el cambio.</p>';
        DTR_MCP_Content::settings_form();
        echo '<p>Escrituras: ' . (self::writes_enabled() ? 'habilitadas' : 'bloqueadas: definir DTR_MCP_ALLOW_WRITES=true tras validar la instalacion') . '</p>';
        echo '<p>El campo de backup es una declaracion del administrador, no una copia creada ni verificada por este plugin.</p>';
        if (get_option('dtr_mcp_write_lock')) { echo '<p><strong>Existe un bloqueo de mantenimiento. No eliminarlo hasta comprobar que no hay una actualizacion en curso.</strong></p>'; }
        echo '<table class="widefat"><thead><tr><th>Solicitud</th><th>Cambio exacto</th><th>Estado</th><th>Decision</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $p = self::public_plan($row);
            echo '<tr><td>' . esc_html($row['plan_id']) . '<br>' . esc_html(home_url()) . '<br>' . esc_html($p['expires_at']) . '</td><td>' . esc_html($row['operation']) . '<br>' . esc_html($row['target']) . '<br>' . esc_html($row['value']) . '</td><td>' . esc_html($p['status']) . '<br>' . esc_html($row['result_code']) . '</td><td>';
            DTR_MCP_Content::review($row);
            DTR_MCP_Extended::review($row);
            if ($p['status'] === 'pending') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dtr_mcp_decision"><input type="hidden" name="plan_id" value="' . esc_attr($row['plan_id']) . '">';
                wp_nonce_field('dtr_mcp_' . $row['plan_id']);
                if ($row['operation'] === 'extended_change' && $row['value'] === 'delete') { echo '<label>Confirmar borrado definitivo: escriba DELETE ' . esc_html($row['target']) . '<br><input name="delete_confirmation" required></label><br>'; }
                if (str_starts_with($row['operation'], 'update_')) { echo '<label>Referencia de backup reciente de archivos y BD, con restauracion disponible<br><input name="backup_ref" maxlength="191" required></label><br>'; }
                echo '<button class="button button-primary" name="decision" value="approve">Aprobar este cambio</button> <button class="button" name="decision" value="reject" formnovalidate>Rechazar</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function decide() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_user_logged_in() || !current_user_can('manage_options') || self::is_service_user() || self::$application_user) { wp_die('Acceso denegado.', '', ['response' => 403]); }
        $id = isset($_POST['plan_id']) && is_string($_POST['plan_id']) ? sanitize_text_field(wp_unslash($_POST['plan_id'])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { wp_die('Solicitud no valida.'); }
        check_admin_referer('dtr_mcp_' . $id);
        global $wpdb; $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE plan_id=%s", $id), ARRAY_A);
        if (!$row || $row['status'] !== 'pending' || (int) $row['expires_at'] < time()) { wp_die('Solicitud no aprobable.'); }
        // Owner approval is allowed only in this cookie-authenticated, nonce-protected admin form.
        // Application Passwords and the service role are rejected above.
        if ((int) $row['owner_id'] === get_current_user_id() && !in_array($row['operation'], ['content_change', 'extended_change'], true)) { wp_die('Esta operacion requiere otro administrador.'); }
        $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? wp_unslash($_POST['decision']) : '';
        if (!in_array($decision, ['approve', 'reject'], true)) { wp_die('Decision no valida.'); }
        if ($decision === 'approve' && $row['operation'] === 'extended_change' && $row['value'] === 'delete' && (!isset($_POST['delete_confirmation']) || wp_unslash($_POST['delete_confirmation']) !== 'DELETE ' . $row['target'])) { wp_die('Confirmacion de borrado definitivo incorrecta.'); }
        $backup = isset($_POST['backup_ref']) && is_string($_POST['backup_ref']) ? sanitize_text_field(wp_unslash($_POST['backup_ref'])) : '';
        if ($decision === 'approve' && str_starts_with($row['operation'], 'update_') && (strlen($backup) < 5 || strlen($backup) > 191)) { wp_die('Indique una referencia de backup valida.'); }
        $wpdb->query($wpdb->prepare("UPDATE $table SET status=%s, approved_by=%d, backup_ref=%s WHERE plan_id=%s AND status='pending' AND expires_at>=%d", $decision === 'approve' ? 'approved' : 'rejected', get_current_user_id(), $backup, $id, time()));
        wp_safe_redirect(admin_url('tools.php?page=dtr-mcp')); exit;
    }
}
register_activation_hook(__FILE__, ['DTR_MCP_Bridge', 'install']);
DTR_MCP_Bridge::boot();
DTR_MCP_Content::boot();
DTR_MCP_Extended::boot();
