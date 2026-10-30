<?php
/** Content API for DTR MCP Bridge. No standalone activation. */
if (!defined('ABSPATH')) { exit; }
final class DTR_MCP_Content {
    public static function boot() {
        add_action('init', [__CLASS__, 'upgrade_role']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_post_dtr_mcp_content_setting', [__CLASS__, 'save_setting']);
    }
    public static function upgrade_role() {
        if (get_option('dtr_mcp_content_role_version') === '1.2.0') { return; }
        $role = get_role('dtr_mcp_operator');
        if (!$role) { return; }
        foreach (['dtr_mcp_content', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'publish_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'read_private_pages', 'publish_pages'] as $cap) { $role->add_cap($cap); }
        foreach (['dtr_mcp_read_templates', 'edit_private_posts', 'edit_private_pages', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages'] as $cap) { $role->add_cap($cap); }
        update_option('dtr_mcp_content_role_version', '1.2.0', false);
    }
    public static function enabled() { return get_option('dtr_mcp_content_writes') === 'yes'; }
    private static function error($code, $status = 400) { return new WP_Error($code, $code, ['status' => $status]); }
    public static function permission($write = false) {
        $ok = DTR_MCP_Bridge::permission();
        if (is_wp_error($ok)) { return $ok; }
        if (!current_user_can('dtr_mcp_content')) { return self::error('DTR_CONTENT_DENIED', 403); }
        return $write && !self::enabled() ? self::error('DTR_CONTENT_WRITES_DISABLED', 403) : true;
    }
    public static function allowed_route($route, $method) {
        return ($method === 'GET' && preg_match('#^/dtr-mcp/v1/content/(items|items/[1-9][0-9]*|taxonomies|terms|requests/[a-f0-9]{32})$#D', $route))
            || ($method === 'POST' && in_array($route, ['/dtr-mcp/v1/content/drafts', '/dtr-mcp/v1/content/drafts/update', '/dtr-mcp/v1/content/plans', '/dtr-mcp/v1/content/execute'], true));
    }
    public static function routes() {
        foreach (['items' => 'listing', 'items/(?P<id>[1-9][0-9]*)' => 'item', 'taxonomies' => 'taxonomies', 'terms' => 'terms', 'requests/(?P<request_id>[a-f0-9]{32})' => 'request_status'] as $path => $callback) {
            register_rest_route('dtr-mcp/v1', '/content/' . $path, ['methods' => 'GET', 'permission_callback' => function () { return self::permission(); }, 'callback' => [__CLASS__, $callback]]);
        }
        foreach (['drafts' => 'create', 'drafts/update' => 'update', 'plans' => 'prepare', 'execute' => 'execute'] as $path => $callback) {
            register_rest_route('dtr-mcp/v1', '/content/' . $path, ['methods' => 'POST', 'permission_callback' => function () { return self::permission(true); }, 'callback' => [__CLASS__, $callback]]);
        }
    }
    private static function number($v, $max) { return (is_int($v) || is_string($v)) && preg_match('/^[1-9][0-9]*$/D', (string) $v) && (string) (int) $v === (string) $v && (int) $v <= $max; }
    public static function type($v) {
        if (!is_string($v) || in_array($v, ['revision', 'attachment', 'nav_menu_item', 'wp_global_styles', 'wp_navigation'], true)) { return false; }
        $o = get_post_type_object($v);
        return $o && ($o->public || $o->show_in_rest || in_array($v, ['elementor_library', 'wp_template', 'wp_template_part'], true));
    }
    private static function object($a, $keys) { return is_array($a) && !array_diff(array_keys($a), $keys); }
    public static function template_read($p) { return in_array($p->post_type, ['wp_template', 'wp_template_part'], true) && (current_user_can('dtr_mcp_read_templates') || current_user_can('manage_options')); }
    private static function text($v, $max) { return is_string($v) && strlen($v) <= $max && !str_contains($v, "\0"); }
    private static function post($id, $for_write = true) {
        if (!self::number($id, PHP_INT_MAX)) { return self::error('DTR_INVALID_ID'); }
        $p = get_post((int) $id);
        if (!$p || !self::type($p->post_type) || !get_post_status_object($p->post_status)) { return self::error('DTR_CONTENT_NOT_FOUND', 404); }
        $cap = $for_write || in_array($p->post_status, ['trash', 'auto-draft'], true) ? 'edit_post' : 'read_post';
        if (!(!$for_write && self::template_read($p)) && !current_user_can($cap, $p->ID)) { return self::error('DTR_CONTENT_DENIED', 403); }
        return $p;
    }
    private static function providers() { return ['yoast' => class_exists('WPSEO_Meta'), 'rankmath' => defined('RANK_MATH_VERSION')]; }
    private static function seo($id) {
        $out = [];
        foreach (self::providers() as $provider => $active) {
            if (!$active) { continue; }
            $out[$provider] = $provider === 'yoast'
                ? ['title' => (string) WPSEO_Meta::get_value('title', $id), 'description' => (string) WPSEO_Meta::get_value('metadesc', $id)]
                : ['title' => (string) get_post_meta($id, 'rank_math_title', true), 'description' => (string) get_post_meta($id, 'rank_math_description', true)];
        }
        return $out;
    }
    private static function tax_objects($type) {
        return array_filter(get_object_taxonomies($type, 'objects'), function ($t) { return $t->show_in_rest && $t->public; });
    }
    private static function snapshot($p) {
        $terms = [];
        foreach (self::tax_objects($p->post_type) as $name => $tax) {
            $ids = wp_get_object_terms($p->ID, $name, ['fields' => 'ids']);
            if (is_wp_error($ids)) { return self::error('DTR_TAXONOMY_READ_FAILED', 500); }
            $ids = array_map('intval', $ids); sort($ids); $terms[$name] = $ids;
        }
        ksort($terms);
        return ['id' => (int) $p->ID, 'type' => $p->post_type, 'status' => $p->post_status, 'slug' => $p->post_name, 'title' => $p->post_title, 'content' => $p->post_content, 'excerpt' => $p->post_excerpt, 'modified_gmt' => $p->post_modified_gmt, 'terms' => $terms, 'seo' => self::seo($p->ID), 'builder' => get_post_meta($p->ID, '_elementor_edit_mode', true) === 'builder' ? 'elementor' : null];
    }
    private static function hash($snapshot) { return hash('sha256', wp_json_encode($snapshot)); }
    private static function public_item($p, $full = true) {
        $s = self::snapshot($p); if (is_wp_error($s)) { return $s; }
        $s['expected_hash'] = self::hash($s); $s['url'] = get_permalink($p->ID);
        $s['seo_resolved'] = null;
        if (function_exists('YoastSEO')) {
            try { $meta = YoastSEO()->meta->for_post($p->ID); if ($meta) { $s['seo_resolved'] = ['provider' => 'yoast', 'title' => (string) $meta->title, 'description' => (string) $meta->description, 'canonical' => (string) $meta->canonical]; } } catch (Throwable $e) { /* No invented fallback for computed SEO. */ }
        }
        if (!$full) { unset($s['content'], $s['excerpt']); }
        return $s;
    }
    private static function response($data) { $r = new WP_REST_Response($data); $r->header('Cache-Control', 'private, no-store'); return $r; }
    public static function listing($r) {
        $ok = self::permission(); if (is_wp_error($ok)) { return $ok; }
        $type = $r['type'] ?? 'post'; $status = $r['status'] ?? 'publish'; $page = $r['page'] ?? 1; $size = $r['per_page'] ?? 20; $search = $r['search'] ?? '';
        if (!self::type($type) || ($status !== 'all' && !get_post_status_object($status)) || !self::number($page, 10000) || !self::number($size, 100) || !self::text($search, 200)) { return self::error('DTR_INVALID_QUERY'); }
        $pt = get_post_type_object($type);
        $template_read = self::template_read((object) ['post_type' => $type]);
        if (!$template_read && (!current_user_can($pt->cap->edit_others_posts) || ($status === 'private' && !current_user_can($pt->cap->read_private_posts)))) { return self::error('DTR_CONTENT_DENIED', 403); }
        $q = new WP_Query(['post_type' => $type, 'post_status' => $status === 'all' ? array_values(get_post_stati()) : $status, 'posts_per_page' => (int) $size, 'paged' => (int) $page, 's' => $search, 'orderby' => 'ID', 'order' => 'ASC', 'ignore_sticky_posts' => true]);
        $items = []; $omitted = 0; foreach ($q->posts as $p) { $p = self::post($p->ID, false); if (is_wp_error($p)) { $omitted++; continue; } $item = self::public_item($p, false); if (is_wp_error($item)) { return $item; } $items[] = $item; }
        return self::response(['items' => $items, 'total' => (int) $q->found_posts, 'total_pages' => (int) $q->max_num_pages, 'omitted_for_permissions' => $omitted]);
    }
    public static function item($r) {
        $ok = self::permission(); if (is_wp_error($ok)) { return $ok; }
        $p = self::post($r['id'], false); if (is_wp_error($p)) { return $p; }
        $item = self::public_item($p); return is_wp_error($item) ? $item : self::response($item);
    }
    public static function taxonomies($r) {
        $ok = self::permission(); if (is_wp_error($ok)) { return $ok; }
        $type = $r['type'] ?? 'post'; if (!self::type($type)) { return self::error('DTR_INVALID_TYPE'); }
        $items = []; foreach (self::tax_objects($type) as $name => $t) { $items[] = ['taxonomy' => $name, 'name' => $t->label, 'hierarchical' => (bool) $t->hierarchical]; }
        return self::response(['items' => $items]);
    }
    public static function terms($r) {
        $ok = self::permission(); if (is_wp_error($ok)) { return $ok; }
        $tax = $r['taxonomy']; $size = $r['per_page'] ?? 50; $page = $r['page'] ?? 1; $search = $r['search'] ?? '';
        $allowed = array_merge(self::tax_objects('post'), self::tax_objects('page'));
        if (!is_string($tax) || !isset($allowed[$tax]) || !self::number($page, 10000) || !self::number($size, 100) || !self::text($search, 200)) { return self::error('DTR_INVALID_QUERY'); }
        $query = ['taxonomy' => $tax, 'hide_empty' => false, 'search' => $search];
        $terms = get_terms($query + ['number' => (int) $size, 'offset' => ((int) $page - 1) * (int) $size, 'orderby' => 'term_id', 'order' => 'ASC']); $total = wp_count_terms($query);
        if (is_wp_error($terms) || is_wp_error($total)) { return self::error('DTR_TAXONOMY_READ_FAILED', 500); }
        return self::response(['items' => array_map(function ($t) { return ['id' => (int) $t->term_id, 'taxonomy' => $t->taxonomy, 'name' => $t->name, 'slug' => $t->slug, 'description' => $t->description, 'parent' => (int) $t->parent, 'count' => (int) $t->count]; }, $terms), 'total' => (int) $total, 'total_pages' => (int) ceil($total / $size)]);
    }
    private static function changes($a, $type, $p = null, $public = false) {
        if (!self::object($a, ['title', 'content', 'excerpt', 'slug', 'terms', 'seo', 'status']) || !$a) { return self::error('DTR_INVALID_CHANGES'); }
        if (array_key_exists('status', $a) && (!$public || $a['status'] !== 'publish')) { return self::error('DTR_STATUS_NOT_ALLOWED'); }
        $out = [];
        foreach (['title' => 1000, 'content' => 200000, 'excerpt' => 10000, 'slug' => 200] as $key => $max) {
            if (!array_key_exists($key, $a)) { continue; }
            if (!self::text($a[$key], $max)) { return self::error('DTR_INVALID_CONTENT'); }
            if ($key === 'content' && $p && get_post_meta($p->ID, '_elementor_edit_mode', true) === 'builder') { return self::error('DTR_BUILDER_CONTENT_PROTECTED'); }
            $out[$key] = in_array($key, ['content', 'excerpt'], true) ? wp_kses_post($a[$key]) : ($key === 'slug' ? sanitize_title($a[$key]) : sanitize_text_field($a[$key]));
        }
        if (array_key_exists('terms', $a)) {
            if (!is_array($a['terms']) || count($a['terms']) > 20) { return self::error('DTR_INVALID_TERMS'); }
            $allowed = self::tax_objects($type); $out['terms'] = [];
            foreach ($a['terms'] as $tax => $ids) {
                if (!isset($allowed[$tax]) || !current_user_can($allowed[$tax]->cap->assign_terms) || !is_array($ids) || count($ids) > 100) { return self::error('DTR_INVALID_TERMS'); }
                foreach ($ids as $id) { if (!is_int($id) || $id < 1 || !term_exists($id, $tax)) { return self::error('DTR_UNKNOWN_TERM'); } }
                $out['terms'][$tax] = array_values(array_unique($ids)); sort($out['terms'][$tax]);
            }
            ksort($out['terms']);
        }
        if (array_key_exists('seo', $a)) {
            $s = $a['seo']; $providers = self::providers();
            if (!self::object($s, ['provider', 'title', 'description']) || !isset($s['provider']) || !is_string($s['provider']) || empty($providers[$s['provider']]) || count($s) < 2) { return self::error('DTR_SEO_PROVIDER_UNAVAILABLE'); }
            $out['seo'] = ['provider' => $s['provider']];
            foreach (['title', 'description'] as $k) { if (array_key_exists($k, $s)) { if (!self::text($s[$k], 2000)) { return self::error('DTR_INVALID_SEO'); } $out['seo'][$k] = sanitize_text_field($s[$k]); } }
        }
        if (isset($a['status'])) { $out['status'] = 'publish'; }
        return $out;
    }
    private static function body($r, $keys) {
        $a = $r->get_json_params();
        return !self::object($a, $keys) || strlen(wp_json_encode($a)) > 300000 ? self::error('DTR_INVALID_ARGUMENTS') : $a;
    }
    private static function table() { global $wpdb; return $wpdb->prefix . 'dtr_mcp_plans'; }
    private static function req_key($id) { return 'dtr_mcp_content_req_' . get_current_user_id() . '_' . $id; }
    public static function request_status($r) {
        $ok = self::permission(); if (is_wp_error($ok)) { return $ok; }
        $id = $r['request_id']; if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { return self::error('DTR_INVALID_REQUEST_ID'); }
        $record = get_option(self::req_key($id));
        return $record ? self::response(['request_id' => $id, 'status' => $record['status'], 'post_id' => $record['post_id'] ?? null, 'result' => $record['result'] ?? null]) : self::error('DTR_REQUEST_NOT_FOUND', 404);
    }
    private static function claim($a) {
        $id = $a['request_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { return self::error('DTR_INVALID_REQUEST_ID'); }
        $key = self::req_key($id); $hash = self::hash($a); $existing = get_option($key);
        if ($existing) { return self::error($existing['hash'] === $hash ? 'DTR_REQUEST_ALREADY_RECORDED' : 'DTR_REQUEST_ID_CONFLICT', 409); }
        // All draft writes hold the same site lock, so this counter cannot race between MCP drafts.
        $rate = get_option('dtr_mcp_content_rate', ['hour' => '', 'count' => 0]); $hour = gmdate('Y-m-d-H');
        if ($rate['hour'] !== $hour) { $rate = ['hour' => $hour, 'count' => 0]; }
        if ($rate['count'] >= 120) { return self::error('DTR_RATE_LIMIT', 429); }
        $rate['count']++; update_option('dtr_mcp_content_rate', $rate, false);
        return add_option($key, ['hash' => $hash, 'status' => 'running', 'created_at' => time()], '', false) ? $key : self::error('DTR_REQUEST_ID_CONFLICT', 409);
    }
    private static function record($key, $values) { update_option($key, array_merge(get_option($key), $values), false); }
    private static function editor_locked($id) {
        require_once ABSPATH . 'wp-admin/includes/post.php';
        return wp_check_post_lock($id) ? self::error('DTR_EDITOR_LOCKED', 409) : true;
    }
    private static function apply($id, $a, $record_key = null) {
        $map = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name']; $post = ['ID' => $id];
        foreach ($map as $key => $field) { if (array_key_exists($key, $a)) { $post[$field] = $a[$key]; } }
        if (count($post) > 1) { $r = wp_update_post(wp_slash($post), true); if (is_wp_error($r)) { return self::error('DTR_CONTENT_SAVE_FAILED', 500); } }
        foreach (($a['terms'] ?? []) as $tax => $ids) { $r = wp_set_object_terms($id, $ids, $tax, false); if (is_wp_error($r)) { return self::error('DTR_TERMS_SAVE_FAILED', 500); } }
        if (isset($a['seo'])) {
            $s = $a['seo']; foreach (['title', 'description'] as $field) {
                if (!array_key_exists($field, $s)) { continue; }
                if ($s['provider'] === 'yoast') { WPSEO_Meta::set_value($field === 'title' ? 'title' : 'metadesc', $s[$field], $id); }
                else { update_post_meta($id, 'rank_math_' . $field, wp_slash($s[$field])); }
            }
        }
        // Publish last: earlier errors leave a new draft unpublished. No transactional claim across hooks.
        if (isset($a['status'])) { $r = wp_update_post(['ID' => $id, 'post_status' => 'publish'], true); if (is_wp_error($r)) { return self::error('DTR_PUBLICATION_FAILED', 500); } }
        clean_post_cache($id); $result = self::public_item(get_post($id));
        if (is_wp_error($result)) { return $result; }
        foreach ($map as $key => $field) { if (isset($a[$key]) && $key !== 'slug' && $result[$key] !== $a[$key]) { return self::error('DTR_CONTENT_VERIFY_FAILED', 500); } }
        foreach (($a['terms'] ?? []) as $tax => $ids) { if (($result['terms'][$tax] ?? []) !== $ids) { return self::error('DTR_CONTENT_VERIFY_FAILED', 500); } }
        if (isset($a['seo'])) { foreach (['title', 'description'] as $field) { if (isset($a['seo'][$field]) && ($result['seo'][$a['seo']['provider']][$field] ?? null) !== $a['seo'][$field]) { return self::error('DTR_SEO_VERIFY_FAILED', 500); } } }
        if (isset($a['status']) && $result['status'] !== 'publish') { return self::error('DTR_PUBLICATION_VERIFY_FAILED', 500); }
        // Computed SEO may be cached by the SEO plugin; stored fields are verified, rendered head is not.
        return ['post_id' => $id, 'status' => $result['status'], 'url' => $result['url'], 'expected_hash' => $result['expected_hash'], 'seo_rendered_verified' => false];
    }
    public static function create($r) { return self::draft($r, true); }
    public static function update($r) { return self::draft($r, false); }
    private static function draft($r, $create) {
        $ok = self::permission(true); if (is_wp_error($ok)) { return $ok; }
        $a = self::body($r, $create ? ['type', 'changes', 'request_id'] : ['id', 'expected_hash', 'changes', 'request_id']); if (is_wp_error($a)) { return $a; }
        if (!add_option('dtr_mcp_write_lock', 'content', '', false)) { return self::error('DTR_SITE_LOCKED', 409); }
        $key = null;
        try {
            $p = $create ? null : self::post($a['id'] ?? null); if (is_wp_error($p)) { return $p; }
            $type = $create ? ($a['type'] ?? '') : $p->post_type;
            if (!self::type($type) || !current_user_can(get_post_type_object($type)->cap->edit_posts)) { return self::error('DTR_CONTENT_DENIED', 403); }
            if (!$create) {
                $lock = self::editor_locked($p->ID); if (is_wp_error($lock)) { return $lock; }
                $snap = self::snapshot($p); if (is_wp_error($snap)) { return $snap; }
                if ($p->post_status !== 'draft') { return self::error('DTR_PUBLIC_CHANGE_REQUIRES_APPROVAL', 409); }
                if (!is_string($a['expected_hash'] ?? null) || !hash_equals(self::hash($snap), $a['expected_hash'])) { return self::error('DTR_CONTENT_CHANGED', 409); }
            }
            $changes = self::changes($a['changes'] ?? null, $type, $p); if (is_wp_error($changes)) { return $changes; }
            if ($create && empty($changes['title'])) { return self::error('DTR_TITLE_REQUIRED'); }
            $key = self::claim($a); if (is_wp_error($key)) { return $key; }
            $id = $create ? wp_insert_post(wp_slash(['post_type' => $type, 'post_status' => 'draft', 'post_title' => $changes['title'], 'post_author' => get_current_user_id()]), true) : $p->ID;
            if (is_wp_error($id) || !$id) { self::record($key, ['status' => 'failed']); return self::error('DTR_CONTENT_CREATE_FAILED', 500); }
            self::record($key, ['post_id' => (int) $id]);
            $result = self::apply((int) $id, $changes);
            self::record($key, ['status' => is_wp_error($result) ? 'failed' : 'completed', 'result' => is_wp_error($result) ? null : $result]);
            return is_wp_error($result) ? $result : self::response($result + ['request_id' => $a['request_id']]);
        } catch (Throwable $e) { if (is_string($key)) { self::record($key, ['status' => 'failed']); } return self::error('DTR_CONTENT_SAVE_FAILED', 500); }
        finally { delete_option('dtr_mcp_write_lock'); }
    }
    public static function prepare($r) {
        $ok = self::permission(true); if (is_wp_error($ok)) { return $ok; }
        $a = self::body($r, ['id', 'expected_hash', 'changes']); if (is_wp_error($a)) { return $a; }
        $p = self::post($a['id'] ?? null); if (is_wp_error($p)) { return $p; }
        if (!in_array($p->post_status, ['draft', 'publish'], true) || !current_user_can(get_post_type_object($p->post_type)->cap->publish_posts)) { return self::error('DTR_PUBLICATION_DENIED', 403); }
        $before = self::snapshot($p); if (is_wp_error($before)) { return $before; }
        if (!is_string($a['expected_hash'] ?? null) || !hash_equals(self::hash($before), $a['expected_hash'])) { return self::error('DTR_CONTENT_CHANGED', 409); }
        $changes = self::changes($a['changes'] ?? null, $p->post_type, $p, true); if (is_wp_error($changes)) { return $changes; }
        if ($p->post_status === 'draft' && ($changes['status'] ?? '') !== 'publish') { return self::error('DTR_USE_DRAFT_UPDATE'); }
        global $wpdb; $table = self::table();
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND created_at>%d", get_current_user_id(), time() - 3600)) >= 30) { return self::error('DTR_RATE_LIMIT', 429); }
        $id = bin2hex(random_bytes(16)); $payload = ['before' => $before, 'changes' => $changes];
        if (!add_option('dtr_mcp_content_plan_' . $id, $payload, '', false)) { return self::error('DTR_STORAGE_FAILED', 500); }
        $row = ['plan_id' => $id, 'owner_id' => get_current_user_id(), 'operation' => 'content_change', 'target' => (string) $p->ID, 'value' => $changes['status'] ?? 'edit_published', 'before_hash' => self::hash($before), 'offer_hash' => self::hash($payload), 'status' => 'pending', 'created_at' => time(), 'expires_at' => time() + 900];
        if (!$wpdb->insert($table, $row)) { delete_option('dtr_mcp_content_plan_' . $id); return self::error('DTR_STORAGE_FAILED', 500); }
        return self::response(['plan_id' => $id, 'status' => 'pending', 'post_id' => (int) $p->ID, 'approval_required' => true, 'expires_at' => gmdate('c', $row['expires_at'])]);
    }
    public static function execute($r) {
        $ok = self::permission(true); if (is_wp_error($ok)) { return $ok; }
        $a = self::body($r, ['plan_id']); if (is_wp_error($a)) { return $a; }
        $id = $a['plan_id'] ?? ''; if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { return self::error('DTR_INVALID_PLAN'); }
        global $wpdb; $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE plan_id=%s AND owner_id=%d", $id, get_current_user_id()), ARRAY_A);
        if (!$row || $row['operation'] !== 'content_change') { return self::error('DTR_PLAN_NOT_FOUND', 404); }
        if ($row['status'] !== 'approved' || (int) $row['expires_at'] < time() || empty($row['approved_by'])) { return self::error('DTR_NOT_APPROVED_OR_EXPIRED', 409); }
        if (!add_option('dtr_mcp_write_lock', 'content', '', false)) { return self::error('DTR_SITE_LOCKED', 409); }
        $claimed = false;
        try {
            $p = self::post($row['target']); if (is_wp_error($p)) { return $p; }
            $lock = self::editor_locked($p->ID); if (is_wp_error($lock)) { return $lock; }
            if (!current_user_can(get_post_type_object($p->post_type)->cap->publish_posts)) { return self::error('DTR_PUBLICATION_DENIED', 403); }
            $snap = self::snapshot($p); if (is_wp_error($snap)) { return $snap; }
            $payload = get_option('dtr_mcp_content_plan_' . $id);
            if (!$payload || !hash_equals($row['offer_hash'], self::hash($payload)) || !hash_equals($row['before_hash'], self::hash($snap))) { return self::error('DTR_CONTENT_CHANGED', 409); }
            $changes = self::changes($payload['changes'], $p->post_type, $p, true); if (is_wp_error($changes)) { return $changes; }
            if (!hash_equals(self::hash($payload['changes']), self::hash($changes))) { return self::error('DTR_CONTENT_CHANGED', 409); }
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET status='running' WHERE plan_id=%s AND owner_id=%d AND status='approved' AND expires_at>=%d", $id, get_current_user_id(), time())) !== 1) { return self::error('DTR_ALREADY_CLAIMED', 409); }
            $claimed = true;
            $result = self::apply($p->ID, $changes);
            $wpdb->update($table, ['status' => is_wp_error($result) ? 'failed' : 'completed', 'result_code' => is_wp_error($result) ? 'DTR_CONTENT_SAVE_FAILED' : 'OK'], ['plan_id' => $id]);
            return is_wp_error($result) ? $result : self::response($result + ['plan_id' => $id, 'plan_status' => 'completed']);
        } catch (Throwable $e) { if ($claimed) { $wpdb->update($table, ['status' => 'failed', 'result_code' => 'DTR_CONTENT_EXCEPTION'], ['plan_id' => $id]); } return self::error('DTR_CONTENT_SAVE_FAILED', 500); }
        finally { delete_option('dtr_mcp_write_lock'); }
    }
    public static function review($row) {
        if ($row['operation'] !== 'content_change') { return; }
        $payload = get_option('dtr_mcp_content_plan_' . $row['plan_id']);
        echo '<details><summary>Revisar contenido anterior y cambios exactos</summary><pre style="white-space:pre-wrap;max-width:900px;max-height:600px;overflow:auto">' . esc_html(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
    }
    public static function settings_form() {
        echo '<h2>Escritura de contenidos desde MCP</h2><p>Borradores directos. Publicar o editar contenido publicado requiere aprobar cada solicitud aqui. Las actualizaciones de software mantienen su bloqueo independiente.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dtr_mcp_content_setting">';
        wp_nonce_field('dtr_mcp_content_setting');
        echo '<label><input type="checkbox" name="enabled" value="yes" ' . (self::enabled() ? 'checked' : '') . '>Permitir escritura de contenido a clientes MCP autorizados</label> <button class="button">Guardar</button></form>';
    }
    public static function save_setting() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user_can('manage_options') || in_array('dtr_mcp_operator', wp_get_current_user()->roles, true)) { wp_die('Acceso denegado.', '', ['response' => 403]); }
        check_admin_referer('dtr_mcp_content_setting');
        update_option('dtr_mcp_content_writes', isset($_POST['enabled']) && $_POST['enabled'] === 'yes' ? 'yes' : 'no', false);
        wp_safe_redirect(admin_url('tools.php?page=dtr-mcp')); exit;
    }
}