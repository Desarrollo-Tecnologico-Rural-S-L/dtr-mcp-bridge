<?php
/** Bounded, deterministic transformations. Never persists data or grants approval. */
if (!defined('ABSPATH')) { exit; }
final class DTR_MCP_Elementor {
    const MAX_NODES = 4000;
    const MAX_DEPTH = 30;

    private static function fail($code, $status = 400) { return new WP_Error($code, $code, ['status' => $status]); }
    public static function hash($value) {
        $sort = static function ($v) use (&$sort) {
            if (!is_array($v)) { return $v; }
            if (!array_is_list($v)) { ksort($v, SORT_STRING); }
            return array_map($sort, $v);
        };
        return hash('sha256', wp_json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
    public static function atomic($node) {
        return str_starts_with($node['elType'] ?? '', 'e-') || str_starts_with($node['widgetType'] ?? '', 'e-') || isset($node['version'], $node['styles']);
    }
    public static function index($tree) {
        if (!is_array($tree) || !array_is_list($tree) || strlen(wp_json_encode($tree)) > 2000000) { return self::fail('DTR_INVALID_ELEMENTOR_DATA'); }
        $index = []; $error = null;
        $walk = static function ($nodes, $parent, $path, $depth) use (&$walk, &$index, &$error) {
            if ($depth > self::MAX_DEPTH || !is_array($nodes) || !array_is_list($nodes)) { $error = self::fail('DTR_TREE_LIMIT'); return; }
            foreach ($nodes as $position => $node) {
                if (!is_array($node) || !is_string($node['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $node['id']) || !is_string($node['elType'] ?? null) || !is_array($node['settings'] ?? null) || !is_array($node['elements'] ?? null)) { $error = self::fail('DTR_INVALID_ELEMENT'); return; }
                if (isset($index[$node['id']])) { $error = self::fail('DTR_DUPLICATE_ELEMENT_ID'); return; }
                if (count($index) >= self::MAX_NODES) { $error = self::fail('DTR_TREE_LIMIT'); return; }
                if (($node['elType'] === 'widget') && !is_string($node['widgetType'] ?? null)) { $error = self::fail('DTR_INVALID_WIDGET'); return; }
                $here = array_merge($path, [$position]);
                $index[$node['id']] = ['node' => $node, 'parent_id' => $parent, 'position' => $position, 'path' => $here];
                $walk($node['elements'], $node['id'], array_merge($here, ['elements']), $depth + 1);
                if ($error) { return; }
            }
        };
        $walk($tree, null, [], 0);
        return $error ?: $index;
    }
    private static function &at(&$tree, $path) {
        $ref = &$tree;
        foreach ($path as $key) { $ref = &$ref[$key]; }
        return $ref;
    }
    public static function sensitive($key) {
        return (bool) preg_match('/password|secret|token|api.?key|credential|authorization|webhook|smtp|recaptcha|captcha|honeypot|nonce|email_to|email_from|email_cc|email_bcc|submit_actions|integration|redirect_to/i', (string) $key);
    }
    public static function redact($data) {
        if (!is_array($data)) { return $data; }
        if (isset($data['field_type']) && in_array($data['field_type'], ['hidden','password'], true) && isset($data['field_value'])) { $data['field_value'] = '[REDACTED]'; }
        foreach ($data as $key => &$value) {
            if (self::sensitive($key) || in_array($key, ['custom_js', 'custom_code', '__dynamic__', 'shortcode'], true)) { $value = '[REDACTED]'; }
            elseif (is_array($value)) { $value = self::redact($value); }
            elseif (is_string($value) && preg_match('#https?://[^\s]+(?:@|[?&](?:key|token|secret|password|auth)=)#i', $value)) { $value = '[REDACTED]'; }
        }
        unset($value);
        return $data;
    }
    private static function value($value, $path, $depth = 0) {
        if ($depth > 12 || strlen(wp_json_encode($value)) > 100000) { return self::fail('DTR_VALUE_LIMIT'); }
        foreach ($path as $part) {
            if (self::sensitive($part) || in_array($part, ['__dynamic__', 'shortcode', 'custom_js', 'custom_code', 'custom_attributes'], true)) { return self::fail('DTR_PROTECTED_SETTING', 403); }
        }
        $code = in_array('html', $path, true) || in_array('custom_css', $path, true);
        if ($code && (!(defined('DTR_MCP_ALLOW_CODE_PATCHES') && DTR_MCP_ALLOW_CODE_PATCHES === true) || !current_user_can('unfiltered_html'))) { return self::fail('DTR_CODE_PATCHES_DISABLED', 403); }
        if (is_array($value)) {
            if (count($value) > 200) { return self::fail('DTR_VALUE_LIMIT'); }
            foreach ($value as $key => $v) { $ok = self::value($v, array_merge($path, [$key]), $depth + 1); if (is_wp_error($ok)) { return $ok; } }
            return true;
        }
        if (is_bool($value) || is_int($value) || $value === null || is_float($value) && is_finite($value)) { return true; }
        if (!is_string($value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) { return self::fail('DTR_INVALID_VALUE'); }
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/<\?|<\s*(script|iframe|object|embed|svg|math)\b|\bon[a-z]+\s*=|(?:javascript|vbscript|data)\s*:|\[[A-Za-z_][\w-]*(?:\s|\])|\{\{/i', $decoded)) { return self::fail('DTR_EXECUTABLE_CONTENT_DENIED', 403); }
        $leaf = (string) end($path);
        if (in_array($leaf, ['url', 'link', 'href', 'src'], true) && $value !== '') {
            if (preg_match('/[\x00-\x20\\\\]/', $value) || str_starts_with($value, '//') || !preg_match('#^(?:/(?!/)|\#|https?://|mailto:|tel:)#i', $value) || preg_match('#^https?://[^/]*@#i', $value)) { return self::fail('DTR_INVALID_LINK'); }
        }
        if (in_array('custom_css', $path, true)) {
            // Deliberately disallow CSS network loads and escape/comment obfuscation.
            if (preg_match('/[<>\\\\@]|\/\*|url\s*\(|expression\s*\(|behavior\s*:|-moz-binding/i', $value)) { return self::fail('DTR_UNSAFE_CSS', 403); }
        } elseif (wp_kses_post($value) !== $value) { return self::fail('DTR_UNSAFE_HTML', 403); }
        return true;
    }
    private static function form($before, $after) {
        $allowed = ['text', 'email', 'textarea', 'url', 'tel', 'radio', 'select', 'checkbox', 'number', 'date', 'time', 'acceptance'];
        $fields = $after['form_fields'] ?? [];
        if (!is_array($fields) || !array_is_list($fields)) { return self::fail('DTR_INVALID_FORM_FIELDS'); }
        $old = []; $ids = [];
        foreach ($before['form_fields'] ?? [] as $field) { $old[$field['_id'] ?? ''] = $field; }
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['_id'] ?? null) || isset($ids[$field['_id']])) { return self::fail('DTR_INVALID_FORM_FIELDS'); }
            $ids[$field['_id']] = true;
            if (!in_array($field['field_type'] ?? '', $allowed, true) && ($old[$field['_id']] ?? null) !== $field) { return self::fail('DTR_FORM_SECURITY_PROTECTED', 403); }
        }
        foreach ($old as $key => $field) {
            if (!in_array($field['field_type'] ?? '', $allowed, true) && !in_array($field, $fields, true)) { return self::fail('DTR_FORM_SECURITY_PROTECTED', 403); }
        }
        return true;
    }
    private static function control_type($value, $control) {
        $type = $control['type'] ?? '';
        if (in_array($type, ['select','choose'], true) && isset($control['options'])) { return is_string($value) && ($value === '' || array_key_exists($value, $control['options'])); }
        if ($type === 'switcher') { return is_string($value) && in_array($value, ['', $control['return_value'] ?? 'yes'], true); }
        if (in_array($type, ['text', 'textarea', 'wysiwyg', 'code', 'color', 'font', 'select', 'choose', 'switcher', 'hidden'], true)) { return is_string($value); }
        if ($type === 'number') { return is_int($value) || is_float($value) || $value === ''; }
        if ($type === 'slider' || $type === 'dimensions') {
            if (!is_array($value) || array_diff(array_keys($value), $type === 'slider' ? ['size','sizes','unit'] : ['top','right','bottom','left','unit','isLinked'])) { return false; }
            if (isset($value['unit']) && !in_array($value['unit'], ['px','em','rem','%','vw','vh','deg'], true)) { return false; }
            foreach ($value as $key=>$v) {
                if ($key === 'unit') { continue; }
                if ($key === 'isLinked') { if (!is_bool($v)) { return false; } continue; }
                if (!is_numeric($v) && $v !== '') { return false; }
            }
            return true;
        }
        if ($type === 'url') { return is_array($value) && !array_diff(array_keys($value), ['url','is_external','nofollow']) && (!isset($value['url']) || is_string($value['url'])); }
        if ($type === 'media') { return is_array($value) && !array_diff(array_keys($value), ['id','url','size','alt','source']) && (!isset($value['id']) || is_int($value['id']) && $value['id'] >= 0) && (!isset($value['url']) || is_string($value['url'])); }
        if ($type === 'repeater') {
            if (!is_array($value) || !array_is_list($value)) { return false; }
            $ids = [];
            foreach ($value as $item) {
                if (!is_array($item) || !is_string($item['_id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $item['_id']) || isset($ids[$item['_id']])) { return false; }
                $ids[$item['_id']] = true;
                foreach ($item as $key=>$v) {
                    if ($key === '_id') { continue; }
                    if (!isset($control['fields'][$key]) || !self::control_type($v, $control['fields'][$key])) { return false; }
                }
            }
            return true;
        }
        if (in_array($type, ['icons','select2','gallery'], true)) { return is_array($value) || $type === 'select2' && is_string($value); }
        return false; // Custom control types require an explicitly reviewed adapter.
    }
    private static function settings($node, $old, $new, $schemas) {
        $kind = $node['widgetType'] ?? $node['elType'];
        $controls = $schemas[$kind] ?? null;
        if (!is_array($controls)) { return self::fail('DTR_ELEMENT_SCHEMA_UNAVAILABLE', 409); }
        if ($kind === 'shortcode') { return self::fail('DTR_EXECUTABLE_CONTENT_DENIED', 403); }
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (array_key_exists($key, $old) && array_key_exists($key, $new) && $old[$key] === $new[$key]) { continue; }
            if (!is_string($key) || !isset($controls[$key])) { return self::fail('DTR_UNKNOWN_CONTROL'); }
            if ($kind === 'form' && $key !== 'form_fields' && ($controls[$key]['tab'] ?? '') !== 'style' && !in_array($key, ['form_name', 'button_text', 'show_labels', 'mark_required'], true)) { return self::fail('DTR_FORM_SECURITY_PROTECTED', 403); }
            $ok = self::value($new[$key] ?? null, [$key]); if (is_wp_error($ok)) { return $ok; }
            $control = $controls[$key];
            if ($kind === 'form' && $key === 'form_fields' && ($control['type'] ?? '') === 'form-fields-repeater') {
                $control['type'] = 'repeater';
            }
            if (array_key_exists($key, $new) && !self::control_type($new[$key], $control)) { return self::fail('DTR_CONTROL_TYPE_MISMATCH'); }
        }
        return $kind === 'form' ? self::form($old, $new) : true;
    }
    private static function compatible($parent, $node) {
        if (self::atomic($node) || $parent && self::atomic($parent)) { return false; }
        $p = $parent['elType'] ?? 'root'; $c = $node['elType'];
        return in_array($c, ['root' => ['section', 'container'], 'section' => ['column'], 'column' => ['widget', 'section', 'container'], 'container' => ['widget', 'container']][$p] ?? [], true);
    }
    public static function transform($tree, $operations, $schemas) {
        $index = self::index($tree); if (is_wp_error($index)) { return $index; }
        if (!is_array($operations) || !array_is_list($operations) || !$operations || count($operations) > 60 || strlen(wp_json_encode($operations)) > 500000) { return self::fail('DTR_INVALID_OPERATIONS'); }
        $preview = []; $seed = self::hash([$tree, $operations]); $counter = 0;
        foreach ($operations as $op) {
            if (!is_array($op) || !is_string($op['action'] ?? null)) { return self::fail('DTR_INVALID_OPERATION'); }
            $action = $op['action'];
            $keys = ['set' => ['action','element_id','path','before','after','before_exists'], 'unset' => ['action','element_id','path','before'], 'insert' => ['action','parent_id','position','element'], 'duplicate' => ['action','element_id','parent_id','position'], 'move' => ['action','element_id','parent_id','position'], 'remove' => ['action','element_id'], 'reorder' => ['action','parent_id','order']];
            if (!isset($keys[$action]) || array_diff(array_keys($op), $keys[$action])) { return self::fail('DTR_INVALID_OPERATION'); }
            if (isset($op['element_id']) && !is_string($op['element_id']) || isset($op['parent_id']) && !is_string($op['parent_id'])) { return self::fail('DTR_INVALID_ELEMENT_ID'); }
            $entry = $index[$op['element_id'] ?? ''] ?? null;
            if (in_array($action, ['set','unset','duplicate','move','remove'], true) && !$entry) { return self::fail('DTR_ELEMENT_NOT_FOUND', 409); }
            if ($entry && self::atomic($entry['node'])) { return self::fail('DTR_ATOMIC_WRITE_UNSUPPORTED', 409); }
            if ($action === 'set' || $action === 'unset') {
                $path = $op['path'] ?? null;
                if (!is_array($path) || !array_is_list($path) || !$path || count($path) > 12 || !array_key_exists('before', $op) || $action === 'set' && !array_key_exists('after', $op) || isset($op['before_exists']) && !is_bool($op['before_exists'])) { return self::fail('DTR_INVALID_PATCH'); }
                foreach ($path as $part) { if (!(is_int($part) && $part >= 0 && $part < 200) && !(is_string($part) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $part))) { return self::fail('DTR_INVALID_PATH'); } }
                $node = &self::at($tree, $entry['path']); $old = $node['settings']; $ref = &$node['settings'];
                $last = array_pop($path);
                foreach ($path as $part) { if (!is_array($ref) || !array_key_exists($part, $ref)) { return self::fail('DTR_PATCH_CONFLICT', 409); } $ref = &$ref[$part]; }
                if (!is_array($ref)) { return self::fail('DTR_PATCH_CONFLICT', 409); }
                $exists = array_key_exists($last, $ref);
                if ($exists !== ($op['before_exists'] ?? true) || $exists && $ref[$last] !== $op['before'] || !$exists && $op['before'] !== null) { return self::fail('DTR_PATCH_CONFLICT', 409); }
                if ($action === 'unset') { unset($ref[$last]); if (is_int($last)) { $ref = array_values($ref); } }
                else { $ref[$last] = $op['after']; }
                $ok = self::settings($node, $old, $node['settings'], $schemas); if (is_wp_error($ok)) { return $ok; }
                unset($ref, $node);
            } elseif ($action === 'remove') {
                $path = $entry['path']; $pos = array_pop($path); $siblings = &self::at($tree, $path); array_splice($siblings, $pos, 1); unset($siblings);
            } else {
                if (!array_key_exists('parent_id', $op) || $op['parent_id'] !== null && !is_string($op['parent_id'])) { return self::fail('DTR_INVALID_PARENT'); }
                $parent = $op['parent_id'] === null ? null : ($index[$op['parent_id']]['node'] ?? false);
                if ($parent === false || $parent && self::atomic($parent)) { return self::fail('DTR_INVALID_PARENT'); }
                $destination = $parent ? array_merge($index[$op['parent_id']]['path'], ['elements']) : [];
                if ($action === 'reorder') {
                    $children = &self::at($tree, $destination); $ids = array_column($children, 'id'); $order = $op['order'] ?? null;
                    if (!is_array($order) || !array_is_list($order) || count(array_filter($order, 'is_string')) !== count($order) || count($order) !== count($ids) || count(array_unique($order, SORT_REGULAR)) !== count($order) || array_diff($ids, $order) || array_diff($order, $ids)) { return self::fail('DTR_INVALID_ORDER'); }
                    $map = array_column($children, null, 'id'); $children = array_map(static fn($id) => $map[$id], $order); unset($children);
                } else {
                    $node = $action === 'insert' ? ($op['element'] ?? null) : $entry['node'];
                    if (!is_array($node)) { return self::fail('DTR_INVALID_ELEMENT'); }
                    if ($action === 'insert') {
                        if (array_diff(array_keys($node), ['elType','widgetType','settings','elements','isInner'])) { return self::fail('DTR_INVALID_ELEMENT'); }
                        if (($node['elType'] ?? '') === 'container') {
                            $node += ['isInner' => false];
                        }
                        $node += ['settings' => [], 'elements' => []];
                        if (!is_array($node['settings']) || $node['elements'] !== []) { return self::fail('DTR_INSERT_ONE_ELEMENT'); }
                    }
                    if (!is_string($node['elType'] ?? null) || !self::compatible($parent, $node)) { return self::fail('DTR_INCOMPATIBLE_PARENT'); }
                    if ($node['elType'] === 'section') { $node['isInner'] = ($parent['elType'] ?? '') === 'column'; }
                    if ($action === 'move') {
                        $sub = self::index([$node]); if (is_wp_error($sub) || isset($sub[$op['parent_id'] ?? ''])) { return self::fail('DTR_MOVE_CYCLE'); }
                        $path = $entry['path']; $pos = array_pop($path); $siblings = &self::at($tree, $path); array_splice($siblings, $pos, 1); unset($siblings);
                        $index = self::index($tree); $destination = $parent ? array_merge($index[$op['parent_id']]['path'], ['elements']) : [];
                    } else {
                        $remap = static function (&$n) use (&$remap, &$index, $seed, &$counter, $schemas) {
                            if (self::atomic($n)) { return self::fail('DTR_ATOMIC_WRITE_UNSUPPORTED', 409); }
                            $ok = self::settings($n, [], $n['settings'], $schemas); if (is_wp_error($ok)) { return $ok; }
                            do { $n['id'] = substr(hash('sha256', $seed . ':' . $counter++), 0, 8); } while (isset($index[$n['id']]));
                            $index[$n['id']] = true;
                            foreach ($n['elements'] as &$child) { $ok = $remap($child); if (is_wp_error($ok)) { return $ok; } }
                            return true;
                        };
                        $ok = $remap($node); if (is_wp_error($ok)) { return $ok; }
                    }
                    $children = &self::at($tree, $destination); $position = $op['position'] ?? null;
                    if (!is_int($position) || $position < 0 || $position > count($children)) { return self::fail('DTR_INVALID_POSITION'); }
                    array_splice($children, $position, 0, [$node]); unset($children);
                    $op['result_element_id'] = $node['id'];
                }
            }
            $index = self::index($tree); if (is_wp_error($index)) { return $index; }
            $preview[] = self::redact($op);
        }
        return ['elements' => $tree, 'preview' => $preview, 'after_hash' => self::hash($tree)];
    }

    /** Only installed controls are writable; no hardcoded widget allowlist. */
    public static function schemas() {
        if (!class_exists('Elementor\\Plugin')) { return []; }
        $plugin = \Elementor\Plugin::$instance;
        $devices = is_callable([$plugin->breakpoints ?? null, 'get_active_devices_list'])
            ? $plugin->breakpoints->get_active_devices_list() : [];
        $types = array_merge($plugin->elements_manager->get_element_types(), $plugin->widgets_manager->get_widget_types());
        $result = [];
        foreach ($types as $name => $type) {
            if (!is_callable([$type, 'get_controls'])) { continue; }
            $controls = $type->get_controls();
            // Elementor can register only the base dimensions control and expand devices in the editor.
            foreach ($controls as $key => $control) {
                if (($control['is_responsive'] ?? false) !== true || ($control['type'] ?? '') !== 'dimensions') { continue; }
                $allowed = $control['responsive']['devices'] ?? $devices;
                foreach (array_intersect($devices, $allowed) as $device) {
                    if ($device !== 'desktop') {
                        $controls[$key . '_' . $device] ??= $control;
                    }
                }
            }
            $result[$name] = $controls;
        }
        return $result;
    }
    public static function discovery() {
        $types = [];
        foreach (self::schemas() as $name => $controls) {
            foreach ($controls as $key => $control) {
                $types[$name][$key] = array_intersect_key($control, array_flip(['type', 'tab', 'label', 'responsive', 'options']));
                $types[$name][$key]['protected'] = self::sensitive($key) || in_array($key, ['__dynamic__','shortcode','custom_js','custom_code','custom_attributes'], true);
            }
        }
        $contract = json_decode(file_get_contents(__DIR__ . '/elementor-contract.json'), true);
        return ['schema_version' => 1, 'mutation_operation' => 'elementor_edit', 'input_schema'=>$contract, 'actions' => ['set','unset','insert','duplicate','move','reorder','remove'], 'approval_required' => true, 'limits' => ['operations' => 60, 'nodes' => self::MAX_NODES, 'depth' => self::MAX_DEPTH, 'document_bytes' => 2000000], 'widget_controls' => self::redact($types), 'v3_write' => true, 'v4_write' => false, 'javascript_write' => false];
    }
}
