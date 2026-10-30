<?php
/** Extended editorial, Elementor, SEO and audit API. Loaded only by DTR MCP Bridge. */
if (!defined('ABSPATH')) { exit; }
final class DTR_MCP_Extended {
    const READS = ['capabilities', 'types', 'record', 'elementor', 'elementor_search', 'templates', 'redirects', 'redirect_test', 'seo', 'sitemap', 'audit'];
    const WRITES = ['trash', 'restore', 'delete', 'elementor_patch', 'elementor_insert_post_content', 'elementor_account_finish', 'redirect_create', 'cache_purge'];
    public static function boot() { add_action('rest_api_init', [__CLASS__, 'routes']); }
    public static function allowed_route($route, $method) {
        return ($method === 'GET' && $route === '/dtr-mcp/v1/extended/read') || ($method === 'POST' && in_array($route, ['/dtr-mcp/v1/extended/plan', '/dtr-mcp/v1/extended/execute'], true));
    }
    public static function routes() {
        register_rest_route('dtr-mcp/v1', '/extended/read', ['methods' => 'GET', 'permission_callback' => function () { return DTR_MCP_Content::permission(); }, 'callback' => [__CLASS__, 'read']]);
        foreach (['plan', 'execute'] as $path) { register_rest_route('dtr-mcp/v1', '/extended/' . $path, ['methods' => 'POST', 'permission_callback' => function () { return DTR_MCP_Content::permission(true); }, 'callback' => [__CLASS__, $path]]); }
    }
    private static function err($code, $status = 400) { return new WP_Error($code, $code, ['status' => $status]); }
    private static function out($a) { $r = new WP_REST_Response($a); $r->header('Cache-Control', 'private, no-store'); return $r; }
    private static function hash($a) { return hash('sha256', wp_json_encode($a)); }
    private static function exact($a, $keys) { return is_array($a) && !array_diff(array_keys($a), $keys); }
    private static function integer($n, $max = PHP_INT_MAX) { return is_int($n) && $n > 0 && $n <= $max; }
    private static function table() { global $wpdb; return $wpdb->prefix . 'dtr_mcp_plans'; }
    private static function post($id, $write = false) {
        if (!self::integer($id)) { return self::err('DTR_INVALID_ID'); }
        $p = get_post($id);
        if (!$p || !DTR_MCP_Content::type($p->post_type)) { return self::err('DTR_CONTENT_NOT_FOUND', 404); }
        if (!(!$write && DTR_MCP_Content::template_read($p)) && !current_user_can($write || in_array($p->post_status, ['trash', 'auto-draft'], true) ? 'edit_post' : 'read_post', $id)) { return self::err('DTR_CONTENT_DENIED', 403); }
        return $p;
    }
    private static function state($p) { return ['id' => (int) $p->ID, 'type' => $p->post_type, 'status' => $p->post_status, 'title' => $p->post_title, 'content' => $p->post_content, 'modified' => $p->post_modified_gmt, 'trash_status' => get_post_meta($p->ID, '_wp_trash_meta_status', true), 'elementor' => get_post_meta($p->ID, '_elementor_data', true)]; }
    public static function read($r) {
        $ok = DTR_MCP_Content::permission(); if (is_wp_error($ok)) { return $ok; }
        $op = $r['operation']; $a = json_decode($r['query_json'] ?? '{}', true);
        if (!in_array($op, self::READS, true) || !is_array($a) || strlen($r['query_json'] ?? '') > 10000) { return self::err('DTR_INVALID_ARGUMENTS'); }
        try {
            switch ($op) {
                case 'capabilities': return self::out(['version' => DTR_MCP_Bridge::VERSION, 'read_operations' => self::READS, 'plan_operations' => self::WRITES, 'approval' => 'owner_wordpress_session', 'cache' => self::cache(), 'cache_auto_policy' => 'exactly_one_available_page_cache_provider', 'elementor' => defined('ELEMENTOR_VERSION'), 'redirection' => class_exists('Red_Item'), 'yoast' => function_exists('YoastSEO'), 'seo_providers' => self::seo_providers()]);
                case 'types':
                    $items = []; foreach (get_post_types([], 'objects') as $o) { if (DTR_MCP_Content::type($o->name)) { $items[] = ['type' => $o->name, 'label' => $o->label, 'can_edit' => current_user_can($o->cap->edit_posts), 'can_read_private' => current_user_can($o->cap->read_private_posts)]; } }
                    return self::out(['items' => $items, 'statuses' => array_values(get_post_stati())]);
                case 'elementor': return self::elementor($a);
                case 'elementor_search': return self::elementor_search($a);
                case 'record':
                    if (!self::exact($a, ['id'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
                    $p = self::post($a['id'] ?? null); if (is_wp_error($p)) { return $p; }
                    return self::out(['id' => (int) $p->ID, 'type' => $p->post_type, 'status' => $p->post_status, 'title' => $p->post_title, 'content' => $p->post_content, 'content_format' => 'wordpress_post_content', 'expected_hash' => self::hash(self::state($p)), 'hash_scope' => 'extended_plan', 'url' => get_permalink($p->ID)]);
                case 'templates': return self::templates($a);
                case 'redirects': return self::redirects($a);
                case 'redirect_test': return self::redirect_test($a);
                case 'seo': return self::seo($a);
                case 'sitemap': return self::sitemap($a);
                case 'audit': return self::audit($a);
            }
        } catch (Throwable $e) { return self::err('DTR_INTEGRATION_FAILED', 502); }
    }
    private static function elements($id) {
        if (!class_exists('Elementor\\Plugin')) { return self::err('DTR_ELEMENTOR_UNAVAILABLE', 409); }
        $raw = get_post_meta($id, '_elementor_data', true);
        if (is_string($raw) && strlen($raw) > 2000000) { return self::err('DTR_DOCUMENT_TOO_LARGE', 413); }
        $data = is_array($raw) ? $raw : json_decode($raw ?: '[]', true);
        return is_array($data) && array_is_list($data) ? $data : self::err('DTR_INVALID_ELEMENTOR_DATA');
    }
    private static function elementor($a) {
        if (!self::exact($a, ['id'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $p = self::post($a['id'] ?? null); if (is_wp_error($p)) { return $p; }
        $data = self::elements($p->ID); if (is_wp_error($data)) { return $data; }
        return self::out(['id' => (int) $p->ID, 'status' => $p->post_status, 'elements' => self::redact($data), 'expected_hash' => self::hash(self::state($p)), 'content_format' => 'elementor_editor_json', 'template_type' => get_post_meta($p->ID, '_elementor_template_type', true), 'conditions' => get_post_meta($p->ID, '_elementor_conditions', true)]);
    }
    private static function redact($data) {
        foreach ($data as $k => &$v) { if (is_string($k) && preg_match('/(?:password|secret|token|api.?key|credential|authorization)/i', $k)) { $v = '[REDACTED]'; } elseif (is_array($v)) { $v = self::redact($v); } }
        unset($v); return $data;
    }
    private static function elementor_search($a) {
        if (!self::exact($a, ['id', 'search']) || !is_string($a['search'] ?? null) || $a['search'] === '' || strlen($a['search']) > 500) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $p = self::post($a['id'] ?? null); if (is_wp_error($p)) { return $p; } $data = self::elements($p->ID); if (is_wp_error($data)) { return $data; }
        $matches = []; self::search_nodes(self::redact($data), $a['search'], $matches);
        return self::out(['id' => (int) $p->ID, 'expected_hash' => self::hash(self::state($p)), 'matches' => array_slice($matches, 0, 200), 'truncated' => count($matches) > 200, 'search_mode' => 'literal_case_sensitive']);
    }
    private static function search_nodes($nodes, $needle, &$out) {
        foreach ($nodes as $n) { if (!empty($n['settings']) && is_array($n['settings'])) { self::search_settings($n['settings'], [], $n['id'] ?? '', $needle, $out); } if (!empty($n['elements']) && is_array($n['elements'])) { self::search_nodes($n['elements'], $needle, $out); } }
    }
    private static function search_settings($settings, $path, $id, $needle, &$out) {
        if (count($path) > 8 || count($out) > 200) { return; }
        foreach ($settings as $k => $v) {
            $p = array_merge($path, [$k]); if (is_array($v)) { self::search_settings($v, $p, $id, $needle, $out); }
            elseif (is_string($v) && $v !== '[REDACTED]' && str_contains($v, $needle)) { $out[] = ['element_id' => $id, 'path' => $p, 'before' => $v]; }
        }
    }
    private static function cache() {
        return ['wp_fastest_cache' => function_exists('wpfc_clear_all_cache'), 'wp_rocket' => function_exists('rocket_clean_domain'), 'litespeed' => defined('LSCWP_V') && has_action('litespeed_purge_all') !== false, 'object_cache' => wp_using_ext_object_cache() && defined('DTR_MCP_ALLOW_CACHE_FLUSH') && DTR_MCP_ALLOW_CACHE_FLUSH === true];
    }
    private static function seo_providers() { return ['yoast' => function_exists('YoastSEO'), 'rankmath' => defined('RANK_MATH_VERSION')]; }
    private static function cache_provider($requested) {
        $available = self::cache();
        if ($requested === 'auto') {
            $active = array_keys(array_filter(array_intersect_key($available, array_flip(['wp_fastest_cache', 'wp_rocket', 'litespeed']))));
            if (count($active) !== 1) { return self::err(count($active) > 1 ? 'DTR_CACHE_PROVIDER_AMBIGUOUS' : 'DTR_CACHE_UNAVAILABLE', 409); }
            return $active[0];
        }
        return is_string($requested) && !empty($available[$requested]) ? $requested : self::err('DTR_CACHE_UNAVAILABLE', 409);
    }
    // Public frontend requests never carry a session, an Application Password or MCP credentials.
    private static function url($u) {
        if (!is_string($u) || strlen($u) > 2048 || preg_match('/[\x00-\x20\\\\]/', $u)) { return self::err('DTR_INVALID_URL'); }
        if (str_starts_with($u, '/') && !str_starts_with($u, '//')) { $u = rtrim(home_url(), '/') . $u; }
        $p = wp_parse_url($u); $home = wp_parse_url(home_url());
        if (!$p || ($p['scheme'] ?? '') !== 'https' || strtolower($p['host'] ?? '') !== strtolower($home['host'] ?? '') || ($p['port'] ?? 443) !== ($home['port'] ?? 443) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query'])) { return self::err('DTR_URL_NOT_ALLOWED'); }
        $path = rawurldecode($p['path'] ?? '/');
        if (preg_match('#(?:^|/)(?:wp-admin|wp-json|wp-login\.php|xmlrpc\.php)(?:/|$)|(?:^|/)\.\.(?:/|$)#i', $path)) { return self::err('DTR_URL_NOT_ALLOWED'); }
        return $u;
    }
    private static function fetch($u) {
        $url = self::url($u); if (is_wp_error($url)) { return $url; }
        $r = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => 2000000, 'cookies' => [], 'headers' => ['User-Agent' => 'DTR-MCP-Audit/1.2', 'Accept' => 'text/html,application/xml,text/xml']]);
        if (is_wp_error($r)) { return self::err('DTR_FETCH_FAILED', 502); }
        return ['url' => $url, 'status' => wp_remote_retrieve_response_code($r), 'body' => wp_remote_retrieve_body($r), 'location' => wp_remote_retrieve_header($r, 'location'), 'x_robots_tag' => wp_remote_retrieve_header($r, 'x-robots-tag')];
    }
    private static function templates($a) {
        if (!self::exact($a, ['id', 'url'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $u = isset($a['url']) ? self::url($a['url']) : null; if (is_wp_error($u)) { return $u; }
        $id = $a['id'] ?? ($u ? url_to_postid($u) : null); $p = self::post($id); if (is_wp_error($p)) { return $p; }
        $q = new WP_Query(['post_type' => 'elementor_library', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 101, 'orderby' => 'ID', 'order' => 'ASC']);
        $items = []; foreach (array_slice($q->posts, 0, 100) as $t) {
            if (!current_user_can('read_post', $t->ID)) { continue; }
            $items[] = ['id' => (int) $t->ID, 'title' => $t->post_title, 'status' => $t->post_status, 'type' => get_post_meta($t->ID, '_elementor_template_type', true), 'conditions' => get_post_meta($t->ID, '_elementor_conditions', true)];
        }
        // Real frontend observation is needed for custom conditions and third-party Theme Builders.
        $observed = []; $observed_status = null;
        if ($p->post_status === 'publish') { $html = self::fetch(get_permalink($id)); if (!is_wp_error($html)) { $observed_status = $html['status']; preg_match_all('/data-elementor-id=["\x27]([0-9]+)["\x27]/', $html['body'], $m); $observed = array_values(array_unique(array_map('intval', $m[1]))); } }
        return self::out(['id' => $id, 'wp_page_template' => get_page_template_slug($id), 'document_type' => get_post_meta($id, '_elementor_template_type', true), 'templates' => $items, 'templates_truncated' => count($q->posts) > 100, 'rendered_document_ids' => $observed, 'rendered_http_status' => $observed_status, 'resolution' => 'stored_conditions_and_frontend_observation', 'conditions_evaluated' => false]);
    }
    private static function redirects($a) {
        if (!self::exact($a, ['page', 'per_page']) || !self::integer($a['page'] ?? 1, 10000) || !self::integer($a['per_page'] ?? 50, 100)) { return self::err('DTR_INVALID_ARGUMENTS'); }
        if (!is_callable(['Red_Item', 'get_filtered']) || !is_callable(['Red_Group', 'get_all'])) { return self::err('DTR_REDIRECTION_UNAVAILABLE', 409); }
        $data = Red_Item::get_filtered(['page' => ($a['page'] ?? 1) - 1, 'per_page' => max(5, $a['per_page'] ?? 50), 'orderby' => 'position', 'direction' => 'asc']);
        $items = []; foreach ($data['items'] as $row) { $items[] = array_intersect_key($row, array_flip(['id', 'url', 'action_code', 'action_type', 'action_data', 'match_type', 'regex', 'group_id', 'enabled'])); }
        return self::out(['items' => $items, 'total' => (int) $data['total'], 'per_page' => max(5, $a['per_page'] ?? 50), 'total_pages' => (int) ceil($data['total'] / max(5, $a['per_page'] ?? 50)), 'groups' => array_map(function ($g) { return array_intersect_key($g, array_flip(['id', 'name', 'module_id', 'enabled'])); }, Red_Group::get_all())]);
    }
    private static function redirect_test($a) {
        if (!self::exact($a, ['url', 'expected_target'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $r = self::fetch($a['url'] ?? null); if (is_wp_error($r)) { return $r; }
        unset($r['body']); $r['is_301'] = $r['status'] === 301;
        $r['target_matches'] = isset($a['expected_target']) ? $r['location'] === $a['expected_target'] : null;
        $r['redirect_followed'] = false; return self::out($r);
    }
    private static function seo($a) {
        if (!self::exact($a, ['url'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $url = self::url($a['url'] ?? null); if (is_wp_error($url)) { return $url; }
        $id = url_to_postid($url); $providers = self::seo_providers(); $active = array_keys(array_filter($providers));
        $is_home = untrailingslashit($url) === untrailingslashit(home_url('/'));
        if ($is_home && get_option('show_on_front') === 'page') { $id = (int) get_option('page_on_front'); }
        if ($id && !current_user_can('read_post', $id)) { return self::err('DTR_CONTENT_DENIED', 403); }
        $data = ['url' => $url, 'post_id' => $id ?: null, 'seo_providers' => $providers, 'seo_provider' => count($active) === 1 ? $active[0] : (count($active) > 1 ? 'multiple' : null), 'yoast_available' => $providers['yoast'], 'rankmath_available' => $providers['rankmath'], 'yoast' => null, 'rankmath' => null];
        if (function_exists('YoastSEO')) {
            // Yoast resolves a static homepage with a dedicated context. URL lookup
            // can match an obsolete home-page indexable sharing the same permalink.
            $is_posts_page = $id && get_option('show_on_front') === 'page' && $id === (int) get_option('page_for_posts');
            $m = $is_home ? YoastSEO()->meta->for_home_page() : ($is_posts_page ? YoastSEO()->meta->for_posts_page() : ($id ? YoastSEO()->meta->for_post($id) : YoastSEO()->meta->for_url($url)));
            if ($m) { $data['yoast'] = ['title' => $m->title, 'description' => $m->description, 'canonical' => $m->canonical, 'robots' => $m->robots]; }
        }
        if ($id && class_exists('WPSEO_Meta')) { $data['stored_yoast_robots'] = ['noindex' => WPSEO_Meta::get_value('meta-robots-noindex', $id), 'nofollow' => WPSEO_Meta::get_value('meta-robots-nofollow', $id)]; }
        $r = self::fetch($url); if (is_wp_error($r)) { return $r; }
        $data['rendered'] = self::html($r);
        $resolved = ['title' => $data['rendered']['title'] ?? null, 'description' => $data['rendered']['description'] ?? null, 'canonical' => $data['rendered']['canonical'] ?? [], 'robots' => $data['rendered']['robots'] ?? [], 'x_robots_tag' => $data['rendered']['x_robots_tag'] ?? '', 'source' => 'public_rendered_html', 'http_status' => $r['status']];
        $data['seo_resolved'] = $resolved + ['provider' => $data['seo_provider']];
        if ($providers['rankmath']) {
            $stored = null;
            if ($id) { $robots = get_post_meta($id, 'rank_math_robots', true); $stored = ['title' => (string) get_post_meta($id, 'rank_math_title', true), 'description' => (string) get_post_meta($id, 'rank_math_description', true), 'canonical' => (string) get_post_meta($id, 'rank_math_canonical_url', true), 'robots' => is_array($robots) ? array_values(array_filter($robots, 'is_string')) : []]; }
            // Final values include Rank Math defaults, variables and frontend filters, not just raw post meta.
            $data['rankmath'] = ['stored' => $stored, 'resolved' => count($active) === 1 ? $resolved : null];
        }
        return self::out($data);
    }
    private static function html($r) {
        if (!class_exists('DOMDocument')) { return ['url' => $r['url'], 'http_status' => $r['status'], 'error' => 'DOM_EXTENSION_UNAVAILABLE']; }
        $doc = new DOMDocument(); $prior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $r['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); libxml_clear_errors(); libxml_use_internal_errors($prior);
        $h1 = []; foreach ($doc->getElementsByTagName('h1') as $n) { $h1[] = trim($n->textContent); }
        $title = $doc->getElementsByTagName('title')->item(0)?->textContent; $description = null;
        $robots = []; foreach ($doc->getElementsByTagName('meta') as $n) { if (strtolower($n->getAttribute('name')) === 'description') { $description = $n->getAttribute('content'); } if (in_array(strtolower($n->getAttribute('name')), ['robots', 'googlebot'], true)) { $robots[] = ['name' => $n->getAttribute('name'), 'content' => $n->getAttribute('content')]; } }
        $canon = []; foreach ($doc->getElementsByTagName('link') as $n) { if (strtolower($n->getAttribute('rel')) === 'canonical') { $canon[] = $n->getAttribute('href'); } }
        preg_match_all('/data-elementor-id=["\x27]([0-9]+)["\x27]/', $r['body'], $m);
        return ['url' => $r['url'], 'http_status' => $r['status'], 'location' => $r['location'], 'title' => $title, 'description' => $description, 'h1' => $h1, 'h1_count' => count($h1), 'robots' => $robots, 'x_robots_tag' => $r['x_robots_tag'], 'canonical' => $canon, 'elementor_document_ids' => array_values(array_unique(array_map('intval', $m[1]))), 'body_limit_reached' => strlen($r['body']) >= 2000000];
    }
    private static function sitemap($a) {
        if (!self::exact($a, ['url'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $url = $a['url'] ?? home_url(count(array_filter(self::seo_providers())) > 0 ? '/sitemap_index.xml' : '/wp-sitemap.xml');
        $r = self::fetch($url); if (is_wp_error($r)) { return $r; }
        if ($r['status'] !== 200 || strlen($r['body']) >= 2000000) { return self::err('DTR_SITEMAP_FETCH_FAILED', 502); }
        if (!function_exists('simplexml_load_string')) { return self::err('DTR_XML_UNAVAILABLE', 409); }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $r['body'])) { return self::err('DTR_INVALID_SITEMAP'); }
        $prior = libxml_use_internal_errors(true); $xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($prior);
        if ($xml === false || !in_array($xml->getName(), ['sitemapindex', 'urlset'], true)) { return self::err('DTR_INVALID_SITEMAP'); }
        $items = []; $skipped = 0;
        foreach ($xml->xpath('//*[local-name()="sitemap" or local-name()="url"]/*[local-name()="loc"]') as $loc) { $u = self::url((string) $loc); if (is_wp_error($u)) { $skipped++; continue; } $items[] = $u; }
        return self::out(['url' => $r['url'], 'kind' => $xml->getName(), 'items' => $items, 'total' => count($items), 'skipped_unsafe' => $skipped, 'next_action' => $xml->getName() === 'sitemapindex' ? 'sitemap_for_each_child' : 'audit_with_sitemap_url']);
    }
    private static function audit($a) {
        if (!self::exact($a, ['sitemap_url', 'offset', 'limit', 'expected_sitemap_hash']) || !is_int($a['offset'] ?? 0) || ($a['offset'] ?? 0) < 0 || !self::integer($a['limit'] ?? 5, 10)) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $map = self::sitemap(isset($a['sitemap_url']) ? ['url' => $a['sitemap_url']] : []); if (is_wp_error($map)) { return $map; }
        if ($map->data['kind'] !== 'urlset') { return self::out(['sitemaps' => $map->data['items'], 'next_action' => 'audit_each_child_sitemap']); }
        if (isset($a['expected_sitemap_hash']) && (!is_string($a['expected_sitemap_hash']) || !hash_equals(self::hash($map->data['items']), $a['expected_sitemap_hash']))) { return self::err('DTR_SITEMAP_CHANGED', 409); }
        $offset = $a['offset'] ?? 0; $limit = $a['limit'] ?? 5; $items = []; $start = microtime(true);
        foreach (array_slice($map->data['items'], $offset, $limit) as $u) {
            if (microtime(true) - $start > 40) { break; }
            $r = self::fetch($u); $row = is_wp_error($r) ? ['url' => $u, 'error' => 'DTR_FETCH_FAILED'] : self::html($r);
            $id = url_to_postid($u); $row['post_id'] = $id ?: null; $row['wp_page_template'] = $id ? get_page_template_slug($id) : null; $items[] = $row;
        }
        $next = $offset + count($items);
        return self::out(['items' => $items, 'total' => $map->data['total'], 'offset' => $offset, 'next_offset' => $next < $map->data['total'] ? $next : null, 'sitemap_hash' => self::hash($map->data['items']), 'complete' => $next >= $map->data['total']]);
    }
    // Patch only values under settings, addressed by element ID and exact settings path.
    // Widget identity, layout hierarchy, script/code and arbitrary metadata cannot be replaced.
    public static function patch($data, $patches) {
        if (!is_array($patches) || count($patches) < 1 || count($patches) > 100) { return self::err('DTR_INVALID_PATCHES'); }
        $preview = [];
        foreach ($patches as $patch) {
            if (!self::exact($patch, ['element_id', 'path', 'before', 'after']) || !is_string($patch['element_id'] ?? null) || !is_array($patch['path'] ?? null) || count($patch['path']) < 1 || count($patch['path']) > 8 || !array_key_exists('before', $patch) || !array_key_exists('after', $patch)) { return self::err('DTR_INVALID_PATCH'); }
            $allowed = ['title', 'editor', 'text', 'description', 'button_text', 'link', 'url', 'alt', 'caption', 'template_id', 'selected_icon', 'image', 'tabs', 'accordion', 'slides', 'title_text', 'content', 'html_tag'];
            // html_tag changes could alter structure; do not allow them through this value API.
            if (!in_array($patch['path'][0], $allowed, true) || $patch['path'][0] === 'html_tag') { return self::err('DTR_PATCH_PATH_NOT_ALLOWED'); }
            foreach ($patch['path'] as $part) { if ((!is_string($part) && !is_int($part)) || preg_match('/(?:pass|secret|token|api.?key|script|code|html|css|__)/i', (string) $part)) { return self::err('DTR_PATCH_PATH_NOT_ALLOWED'); } }
            if ((!is_string($patch['after']) && !is_int($patch['after'])) || (is_string($patch['after']) && strlen($patch['after']) > 50000)) { return self::err('DTR_INVALID_PATCH'); }
            if (is_string($patch['after'])) {
                if (in_array(end($patch['path']), ['url', 'link'], true)) {
                    if ($patch['after'] !== '' && !str_starts_with($patch['after'], '/') && !str_starts_with($patch['after'], '#') && !preg_match('#^https?://#i', $patch['after']) && !preg_match('#^(mailto:|tel:)#i', $patch['after'])) { return self::err('DTR_INVALID_LINK'); }
                    if (str_starts_with($patch['after'], '//') || preg_match('/[\x00-\x1f]/', $patch['after'])) { return self::err('DTR_INVALID_LINK'); }
                } elseif (wp_kses_post($patch['after']) !== $patch['after']) { return self::err('DTR_UNSAFE_MARKUP'); }
            }
            if (end($patch['path']) === 'template_id') { $t = get_post((int) $patch['after']); if (!$t || $t->post_type !== 'elementor_library' || !current_user_can('read_post', $t->ID)) { return self::err('DTR_TEMPLATE_NOT_FOUND'); } }
            $found = 0; self::walk_patch($data, $patch, $found); if ($found !== 1) { return self::err('DTR_PATCH_CONFLICT', 409); }
            $preview[] = $patch;
        }
        return ['elements' => $data, 'preview' => $preview];
    }
    private static function walk_patch(&$nodes, $patch, &$found) {
        foreach ($nodes as &$n) {
            if (($n['id'] ?? '') === $patch['element_id']) {
                $ref = &$n['settings']; $valid = is_array($ref);
                foreach ($patch['path'] as $part) { if (!is_array($ref) || !array_key_exists($part, $ref)) { $valid = false; break; } $ref = &$ref[$part]; }
                if ($valid && $ref === $patch['before']) { $ref = $patch['after']; $found++; }
                unset($ref);
            }
            if (!empty($n['elements']) && is_array($n['elements'])) { self::walk_patch($n['elements'], $patch, $found); }
        }
        unset($n);
    }
    // Narrow structural operation: append Elementor's native Theme Post Content widget to an existing container.
    // This operation intentionally does not accept arbitrary widgets, CSS, code or hierarchy replacement.
    private static function insert_post_content($data, $parent_id) {
        if (!is_string($parent_id) || !preg_match('/^[a-f0-9]{7,8}$/D', $parent_id)) { return self::err('DTR_INVALID_CONTAINER'); }
        $ids = []; $parent_count = 0; $already = false;
        $scan = function ($nodes) use (&$scan, &$ids, &$already) {
            foreach ($nodes as $n) {
                if (!empty($n['id'])) { $ids[] = $n['id']; }
                if (($n['widgetType'] ?? '') === 'theme-post-content') { $already = true; }
                if (!empty($n['elements']) && is_array($n['elements'])) { $scan($n['elements']); }
            }
        };
        $scan($data);
        if ($already) { return self::err('DTR_POST_CONTENT_ALREADY_PRESENT', 409); }
        $new_id = substr(bin2hex(random_bytes(8)), 0, 7);
        while (in_array($new_id, $ids, true)) { $new_id = substr(bin2hex(random_bytes(8)), 0, 7); }
        $widget = ['id' => $new_id, 'elType' => 'widget', 'settings' => [], 'elements' => [], 'widgetType' => 'theme-post-content'];
        $add = function (&$nodes) use (&$add, $parent_id, $widget, &$parent_count) {
            foreach ($nodes as &$n) {
                if (($n['id'] ?? '') === $parent_id) {
                    if (!in_array($n['elType'] ?? '', ['container', 'section', 'column', 'e-flexbox'], true) || !isset($n['elements']) || !is_array($n['elements'])) { return; }
                    $n['elements'][] = $widget; $parent_count++;
                } else if (!empty($n['elements']) && is_array($n['elements'])) { $add($n['elements']); }
            }
            unset($n);
        };
        $add($data);
        if ($parent_count !== 1) { return self::err('DTR_CONTAINER_NOT_FOUND', 409); }
        return ['elements' => $data, 'preview' => ['action' => 'insert_native_post_content', 'parent_id' => $parent_id, 'widget_id' => $new_id, 'widget_type' => 'theme-post-content']];
    }
    // Narrow, approved structural cleanup of JHModelator My Account template only.
    private static function account_finish($data) {
        $login = 0; $content = 0; $heading = 0;
        $walk = function (&$nodes) use (&$walk, &$login, &$content, &$heading) {
            foreach ($nodes as $key => &$node) {
                $id = $node['id'] ?? '';
                if ($id === 'c7e4c38' && ($node['widgetType'] ?? '') === 'login') { unset($nodes[$key]); $login++; continue; }
                if ($id === '7ccfdd1' && ($node['widgetType'] ?? '') === 'theme-post-content') { $content++; }
                if ($id === 'a804194' && ($node['widgetType'] ?? '') === 'heading' && ($node['settings']['title'] ?? '') === 'Acceder') {
                    $node['settings']['title'] = 'Mi cuenta'; $heading++;
                }
                if (isset($node['elements']) && is_array($node['elements'])) { $walk($node['elements']); }
            }
            unset($node); $nodes = array_values($nodes);
        };
        $walk($data);
        if ($login !== 1 || $content !== 1 || $heading !== 1) { return self::err('DTR_ACCOUNT_TEMPLATE_CHANGED', 409); }
        return ['elements'=>$data,'preview'=>['action'=>'account_finish','remove_widget'=>'c7e4c38','keep_widget'=>'7ccfdd1','heading'=>'Mi cuenta','styling'=>'scoped_woocommerce_account_theme']];
    }
    private static function describe($op, $a) {
        if (!in_array($op, self::WRITES, true)) { return self::err('DTR_OPERATION_NOT_ALLOWED'); }
        if (in_array($op, ['trash', 'restore', 'delete', 'elementor_patch', 'elementor_insert_post_content', 'elementor_account_finish'], true)) {
            if (!self::exact($a, $op === 'elementor_patch' ? ['id', 'expected_hash', 'patches'] : ($op === 'elementor_insert_post_content' ? ['id', 'expected_hash', 'parent_id'] : ['id', 'expected_hash']))) { return self::err('DTR_INVALID_ARGUMENTS'); }
            $p = self::post($a['id'] ?? null, true); if (is_wp_error($p)) { return $p; }
            $before = self::state($p);
            if (!is_string($a['expected_hash'] ?? null) || !hash_equals(self::hash($before), $a['expected_hash'])) { return self::err('DTR_CONTENT_CHANGED', 409); }
            if ($op === 'elementor_account_finish') {
                if ($p->ID !== 2624 || $p->post_type !== 'elementor_library' || get_post_meta($p->ID, '_elementor_template_type', true) !== 'single-page' || get_post_meta($p->ID, '_elementor_conditions', true) !== ['include/singular/page/9']) { return self::err('DTR_ACCOUNT_TEMPLATE_SCOPE_DENIED', 409); }
                $data = self::elements($p->ID); if (is_wp_error($data)) { return $data; }
                $patched = self::account_finish($data); if (is_wp_error($patched)) { return $patched; }
                return ['target'=>(string)$p->ID,'before'=>$before,'preview'=>$patched['preview'],'after_elements'=>$patched['elements']];
            }
            if ($op === 'elementor_insert_post_content') {
                if ($p->post_status === 'trash' || $p->post_type !== 'elementor_library' || get_post_meta($p->ID, '_elementor_template_type', true) !== 'single-page') { return self::err('DTR_INVALID_TEMPLATE', 409); }
                $data = self::elements($p->ID); if (is_wp_error($data)) { return $data; }
                $patched = self::insert_post_content($data, $a['parent_id'] ?? null); if (is_wp_error($patched)) { return $patched; }
                return ['target' => (string) $p->ID, 'before' => $before, 'preview' => $patched['preview'], 'after_elements' => $patched['elements']];
            }
            if ($op === 'elementor_patch') {
                if ($p->post_status === 'trash') { return self::err('DTR_RESTORE_FIRST', 409); }
                $data = self::elements($p->ID); if (is_wp_error($data)) { return $data; } $patched = self::patch($data, $a['patches'] ?? null); if (is_wp_error($patched)) { return $patched; }
                return ['target' => (string) $p->ID, 'before' => $before, 'preview' => $patched['preview'], 'after_elements' => $patched['elements']];
            }
            if (!current_user_can('delete_post', $p->ID)) { return self::err('DTR_DELETE_DENIED', 403); }
            if ($p->ID === (int) get_option('page_on_front') || $p->ID === (int) get_option('page_for_posts') || $p->ID === (int) get_option('wp_page_for_privacy_policy')) { return self::err('DTR_SYSTEM_PAGE_PROTECTED', 409); }
            if (($op === 'trash' && $p->post_status === 'trash') || (in_array($op, ['restore', 'delete'], true) && $p->post_status !== 'trash')) { return self::err('DTR_INVALID_LIFECYCLE_STATE', 409); }
            if ($op === 'trash' && (!defined('EMPTY_TRASH_DAYS') || (int) EMPTY_TRASH_DAYS === 0)) { return self::err('DTR_TRASH_DISABLED', 409); }
            return ['target' => (string) $p->ID, 'before' => $before, 'preview' => ['action' => $op, 'id' => (int) $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'restore_to' => $op === 'restore' ? 'draft' : null]];
        }
        if ($op === 'cache_purge') {
            if (!self::exact($a, ['provider'])) { return self::err('DTR_INVALID_ARGUMENTS'); }
            $provider = self::cache_provider($a['provider'] ?? 'auto'); if (is_wp_error($provider)) { return $provider; }
            $scope = $provider === 'litespeed' ? 'all_caches_managed_by_litespeed_for_current_site' : ($provider === 'object_cache' ? 'object_cache' : 'page_cache');
            return ['target' => $provider, 'before' => ['provider' => $provider, 'available' => true], 'preview' => ['provider' => $provider, 'scope' => $scope]];
        }
        if (!self::exact($a, ['source', 'target', 'group_id']) || !is_string($a['source'] ?? null) || strlen($a['source']) > 191 || !preg_match('#^/(?!/)[^?\x00-\x20]*$#D', $a['source']) || !self::integer($a['group_id'] ?? null)) { return self::err('DTR_INVALID_REDIRECT'); }
        if (!is_callable(['Red_Item', 'create']) || !is_callable(['Red_Group', 'get'])) { return self::err('DTR_REDIRECTION_UNAVAILABLE', 409); }
        $source = self::url($a['source']); $target = self::url($a['target'] ?? null);
        if (is_wp_error($source) || is_wp_error($target) || untrailingslashit($source) === untrailingslashit($target)) { return self::err('DTR_INVALID_REDIRECT'); }
        $group = Red_Group::get($a['group_id']); if (!$group || !$group->is_enabled() || $group->get_module_id() !== 1) { return self::err('DTR_REDIRECT_GROUP_UNAVAILABLE'); }
        $probe = self::fetch($target); if (is_wp_error($probe) || $probe['status'] < 200 || $probe['status'] >= 300) { return self::err('DTR_REDIRECT_TARGET_NOT_FINAL', 409); }
        // Reject a duplicate source. This read is bounded and never writes Redirection tables directly.
        global $wpdb; $table = $wpdb->prefix . 'redirection_items';
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE url=%s AND status='enabled'", $a['source'])) > 0) { return self::err('DTR_REDIRECT_EXISTS', 409); }
        return ['target' => $a['source'], 'before' => ['source' => $a['source'], 'exists' => false], 'preview' => ['source' => $a['source'], 'target' => $target, 'group_id' => $a['group_id'], 'code' => 301, 'regex' => false]];
    }
    public static function plan($r) {
        $ok = DTR_MCP_Content::permission(true); if (is_wp_error($ok)) { return $ok; }
        $body = $r->get_json_params(); if (!self::exact($body, ['operation', 'arguments']) || strlen(wp_json_encode($body)) > 500000) { return self::err('DTR_INVALID_ARGUMENTS'); }
        $op = $body['operation'] ?? ''; $a = $body['arguments'] ?? null;
        $d = self::describe($op, $a); if (is_wp_error($d)) { return $d; }
        global $wpdb; $table = self::table();
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND created_at>%d", get_current_user_id(), time() - 3600)) >= 60) { return self::err('DTR_RATE_LIMIT', 429); }
        $id = bin2hex(random_bytes(16)); $payload = ['operation' => $op, 'arguments' => $a, 'description' => $d];
        if (!add_option('dtr_mcp_extended_' . $id, $payload, '', false)) { return self::err('DTR_STORAGE_FAILED', 500); }
        $row = ['plan_id' => $id, 'owner_id' => get_current_user_id(), 'operation' => 'extended_change', 'target' => $d['target'], 'value' => $op, 'before_hash' => self::hash($d['before']), 'offer_hash' => self::hash($payload), 'status' => 'pending', 'created_at' => time(), 'expires_at' => time() + 900];
        if (!$wpdb->insert($table, $row)) { delete_option('dtr_mcp_extended_' . $id); return self::err('DTR_STORAGE_FAILED', 500); }
        return self::out(['plan_id' => $id, 'status' => 'pending', 'preview' => $d['preview'], 'approval_required' => true, 'approval_mode' => 'owner_wordpress_session', 'delete_confirmation' => $op === 'delete' ? 'DELETE ' . $d['target'] : null, 'expires_at' => gmdate('c', $row['expires_at'])]);
    }
    public static function execute($r) {
        $ok = DTR_MCP_Content::permission(true); if (is_wp_error($ok)) { return $ok; }
        $a = $r->get_json_params(); if (!self::exact($a, ['plan_id']) || !is_string($a['plan_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $a['plan_id'])) { return self::err('DTR_INVALID_PLAN'); }
        global $wpdb; $table = self::table(); $id = $a['plan_id'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE plan_id=%s AND owner_id=%d", $id, get_current_user_id()), ARRAY_A);
        if (!$row || $row['operation'] !== 'extended_change') { return self::err('DTR_PLAN_NOT_FOUND', 404); }
        if ($row['status'] !== 'approved' || empty($row['approved_by']) || (int) $row['expires_at'] < time()) { return self::err('DTR_NOT_APPROVED_OR_EXPIRED', 409); }
        if (!add_option('dtr_mcp_write_lock', 'extended', '', false)) { return self::err('DTR_SITE_LOCKED', 409); }
        $claimed = false;
        try {
            $payload = get_option('dtr_mcp_extended_' . $id);
            if (!$payload || !hash_equals($row['offer_hash'], self::hash($payload))) { return self::err('DTR_PLAN_CHANGED', 409); }
            $d = self::describe($payload['operation'], $payload['arguments']); if (is_wp_error($d)) { return $d; }
            if (!hash_equals($row['before_hash'], self::hash($d['before']))) { return self::err('DTR_CONTENT_CHANGED', 409); }
            if (in_array($payload['operation'], ['elementor_patch', 'elementor_insert_post_content', 'elementor_account_finish', 'trash', 'restore', 'delete'], true)) {
                require_once ABSPATH . 'wp-admin/includes/post.php';
                if (wp_check_post_lock((int) $d['target'])) { return self::err('DTR_EDITOR_LOCKED', 409); }
            }
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET status='running' WHERE plan_id=%s AND owner_id=%d AND status='approved' AND expires_at>=%d", $id, get_current_user_id(), time())) !== 1) { return self::err('DTR_ALREADY_CLAIMED', 409); }
            $claimed = true; $result = self::perform($payload['operation'], $payload['arguments'], $d);
            $wpdb->update($table, ['status' => is_wp_error($result) ? 'failed' : 'completed', 'result_code' => is_wp_error($result) ? 'DTR_EXTENDED_SAVE_FAILED' : 'OK'], ['plan_id' => $id]);
            return is_wp_error($result) ? $result : self::out(['plan_id' => $id, 'status' => 'completed', 'result' => $result]);
        } catch (Throwable $e) { if ($claimed) { $wpdb->update($table, ['status' => 'failed', 'result_code' => 'DTR_EXTENDED_EXCEPTION'], ['plan_id' => $id]); } return self::err('DTR_EXTENDED_SAVE_FAILED', 500); }
        finally { delete_option('dtr_mcp_write_lock'); }
    }
    private static function perform($op, $a, $d) {
        $id = isset($a['id']) ? (int) $a['id'] : null;
        if ($op === 'trash') { $r = wp_trash_post($id); return $r && get_post_status($id) === 'trash' ? ['id' => $id, 'status' => 'trash'] : self::err('DTR_TRASH_FAILED', 500); }
        if ($op === 'restore') {
            // Restore into draft, so restoration never republishes content as a side effect.
            $draft = static function () { return 'draft'; }; add_filter('wp_untrash_post_status', $draft, PHP_INT_MAX);
            try { $r = wp_untrash_post($id); } finally { remove_filter('wp_untrash_post_status', $draft, PHP_INT_MAX); }
            return $r && get_post_status($id) === 'draft' ? ['id' => $id, 'status' => 'draft'] : self::err('DTR_RESTORE_FAILED', 500);
        }
        if ($op === 'delete') { $r = wp_delete_post($id, true); return $r && !get_post($id) ? ['id' => $id, 'deleted' => true] : self::err('DTR_DELETE_FAILED', 500); }
        if (in_array($op, ['elementor_patch', 'elementor_insert_post_content', 'elementor_account_finish'], true)) {
            $doc = \Elementor\Plugin::$instance->documents->get($id);
            if (!$doc || !is_callable([$doc, 'save'])) { return self::err('DTR_ELEMENTOR_DOCUMENT_UNAVAILABLE', 409); }
            wp_save_post_revision($id);
            if (!$doc->save(['elements' => $d['after_elements']])) { return self::err('DTR_ELEMENTOR_SAVE_FAILED', 500); }
            $after = self::elements($id);
            if ($op === 'elementor_account_finish') {
                if (is_wp_error($after)) { return self::err('DTR_ELEMENTOR_VERIFY_FAILED', 500); }
                $ids = []; $inspect = function ($nodes) use (&$inspect, &$ids) { foreach ($nodes as $n) { if (isset($n['id'])) { $ids[$n['id']] = $n; } if (isset($n['elements']) && is_array($n['elements'])) { $inspect($n['elements']); } } }; $inspect($after);
                if (isset($ids['c7e4c38']) || ($ids['7ccfdd1']['widgetType'] ?? '') !== 'theme-post-content' || ($ids['a804194']['settings']['title'] ?? '') !== 'Mi cuenta') { return self::err('DTR_ELEMENTOR_VERIFY_FAILED', 500); }
                update_option('dtr_mcp_woo_account_styling', true, false);
                return ['id'=>$id,'changes_applied'=>2,'styling_enabled'=>true,'saved_via'=>'elementor_document_api'];
            }
            if (is_wp_error($after) || !hash_equals(self::hash($after), self::hash($d['after_elements']))) { return self::err('DTR_ELEMENTOR_VERIFY_FAILED', 500); }
            return ['id' => $id, 'expected_hash' => self::hash(self::state(get_post($id))), 'changes_applied' => $op === 'elementor_insert_post_content' ? 1 : count($d['preview']), 'saved_via' => 'elementor_document_api'];
        }
        if ($op === 'cache_purge') {
            $provider = $d['preview']['provider'];
            if ($provider === 'wp_fastest_cache') { wpfc_clear_all_cache(); }
            elseif ($provider === 'wp_rocket') { rocket_clean_domain(); }
            elseif ($provider === 'litespeed') { do_action('litespeed_purge_all', 'DTR MCP approved purge'); }
            else { return wp_cache_flush() ? ['provider' => 'object_cache', 'invoked' => true] : self::err('DTR_CACHE_PURGE_FAILED', 500); }
            return ['provider' => $provider, 'scope' => $d['preview']['scope'], 'invoked' => true, 'frontend_freshness_verified' => false];
        }
        $r = Red_Item::create(['url' => $a['source'], 'action_data' => ['url' => $d['preview']['target']], 'group_id' => $a['group_id'], 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'regex' => false, 'match_data' => ['source' => ['flag_regex' => false, 'flag_case' => false, 'flag_trailing' => false, 'flag_query' => 'exact']]]);
        if (is_wp_error($r)) { return self::err('DTR_REDIRECT_SAVE_FAILED', 500); }
        $saved = $r->to_json();
        if ($saved['action_code'] !== 301 || $saved['url'] !== $a['source'] || !$saved['enabled'] || ($saved['action_data']['url'] ?? null) !== $d['preview']['target']) { return self::err('DTR_REDIRECT_VERIFY_FAILED', 500); }
        return ['id' => $saved['id'], 'source' => $saved['url'], 'target' => $d['preview']['target'], 'http_test_required' => true];
    }
    public static function review($row) {
        if ($row['operation'] !== 'extended_change') { return; }
        $payload = get_option('dtr_mcp_extended_' . $row['plan_id']);
        echo '<details><summary>Revisar cambio exacto</summary><pre style="white-space:pre-wrap;max-width:900px;max-height:600px;overflow:auto">' . esc_html(wp_json_encode($payload['description']['preview'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
    }
}