<?php
// Isolated contract doubles, deliberately not a WordPress integration environment.
define('ABSPATH', __DIR__ . '/fixtures/wp/');
define('ARRAY_A', 'ARRAY_A');
class WP_Error {
    public function __construct(public $code, public $message = '', public $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public function __construct(public $data) {}
    public function header($key, $value) {}
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
$GLOBALS['caps'] = true;
function current_user_can($cap, ...$args) { return $GLOBALS['caps']; }
function user_can($id, $cap) { return $GLOBALS['approver'] ?? true; }
function apply_filters($tag, $value, ...$args) { return $value; }
function wp_allowed_protocols() { return ['http','https','mailto','tel']; }
if (getenv('DTR_WP_KSES_PATH')) {
    require getenv('DTR_WP_KSES_PATH');
} else {
    function wp_kses_post($text) { return strip_tags($text, '<p><b><strong><em><a><span><div><br><ul><li>'); }
}
function get_current_user_id() { return 7; }
function add_action(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, ...$args) { if (isset($GLOBALS['options'][$key])) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function clean_post_cache($id) {}
function wp_save_post_revision($id) {}
function wp_check_post_lock($id) { return $GLOBALS['editor_lock'] ?? false; }
class DTR_MCP_Content {
    public static function permission($write = false) { return $GLOBALS['caps'] ? true : new WP_Error('DTR_ACCESS_DENIED'); }
    public static function type($type) { return $type === 'page' || $type === 'elementor_library'; }
    public static function template_read($post) { return false; }
}
class TestRequest extends ArrayObject {
    public function get_json_params() { return $this->getArrayCopy(); }
}
require __DIR__ . '/../dtr-mcp-bridge/dtr-mcp-elementor.php';
require __DIR__ . '/../dtr-mcp-bridge/dtr-mcp-extended.php';
$GLOBALS['checks'] = 0;
function check($condition, $name) {
    $GLOBALS['checks']++;
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
function error_is($result, $code) { return is_wp_error($result) && $result->get_error_code() === $code; }
