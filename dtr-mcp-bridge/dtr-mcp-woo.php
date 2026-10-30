<?php
if (!defined('ABSPATH')) { exit; }
final class DTR_MCP_Woo {
    private static function err($code, $status=403) { return new WP_Error($code, $code, ['status'=>$status]); }
    private static function table() { global $wpdb; return $wpdb->prefix.'dtr_mcp_woo_plans'; }
    public static function install() {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            plan_id char(32) NOT NULL,
            owner_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            changes_json longtext NOT NULL,
            before_hash char(64) NOT NULL,
            status varchar(20) NOT NULL,
            created_at bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            result_code varchar(80) NOT NULL DEFAULT '',
            PRIMARY KEY  (plan_id),
            KEY owner_status (owner_id,status)
        ) $charset;");
    }
    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_pre_dispatch', [__CLASS__, 'block_direct_writes'], 9, 3);
        add_action('admin_init', function () {
            if (current_user_can('manage_options') && get_option('dtr_mcp_woo_schema') !== '1') { self::install(); update_option('dtr_mcp_woo_schema','1',false); }
        });
        add_action('admin_menu', function() { add_submenu_page('tools.php','DTR MCP WooCommerce','DTR MCP WooCommerce','manage_woocommerce','dtr-mcp-woo',[__CLASS__,'admin_page']); });
        add_action('admin_post_dtr_mcp_woo_decide',[__CLASS__,'decide']);
    }
    private static function key_user() {
        if (!is_ssl() || !class_exists('WooCommerce') || !current_user_can('edit_products')) { return 0; }
        $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $header, $m)) { return 0; }
        $plain=base64_decode($m[1],true);
        if ($plain===false || !str_contains($plain, ':')) { return 0; }
        $key=explode(':',$plain,2)[0];
        if (!preg_match('/^ck_[a-f0-9]{40}$/D',$key)) { return 0; }
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT user_id, permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key=%s", wc_api_hash($key)),ARRAY_A);
        if (!$row || $row['permissions']!=='read_write' || (int)$row['user_id'] !== get_current_user_id()) { return 0; }
        return (int) $row['user_id'];
    }
    public static function block_direct_writes($result, $server, $request) {
        // WooCommerce API keys are read/write, but production changes must flow through approval plans.
        // This gate is deliberately based on the actual consumer key and verified authenticated user.
        if (!self::key_user()) { return $result; }
        $route=$request->get_route(); $method=strtoupper($request->get_method());
        if (in_array($method,['GET','HEAD','OPTIONS'],true)) { return $result; }
        // A plan request does not modify a product; execution independently requires an approved, one-time plan.
        if (in_array($route,['/wc/v3/dtr-mcp/product-plans','/wc/v3/dtr-mcp/product-execute','/wc/v3/dtr-mcp/settings-plans','/wc/v3/dtr-mcp/settings-execute'],true) && $method==='POST') { return $result; }
        if (preg_match('#^/wc/(?:v[123]|store)/#', $route) || str_starts_with($route,'/wc-analytics/')) {
            return self::err('DTR_WOO_DIRECT_WRITE_BLOCKED',403);
        }
        return $result;
    }
    public static function permission() {
        if (!is_ssl() || !function_exists('wc_get_product') || !function_exists('wc_api_hash')) { return self::err('DTR_WOO_UNAVAILABLE'); }
        if (!defined('DTR_MCP_ALLOW_WRITES') || DTR_MCP_ALLOW_WRITES!==true) { return self::err('DTR_WRITES_DISABLED'); }
        if (!self::key_user()) { return self::err('DTR_WOO_KEY_AUTH_REQUIRED'); }
        return true;
    }
    public static function routes() {
        register_rest_route('wc/v3','/dtr-mcp/product-plans',['methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],'callback'=>[__CLASS__,'plan']]);
        register_rest_route('wc/v3','/dtr-mcp/product-plans/(?P<plan_id>[a-f0-9]{32})',['methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],'callback'=>[__CLASS__,'status']]);
        register_rest_route('wc/v3','/dtr-mcp/product-execute',['methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],'callback'=>[__CLASS__,'execute']]);
        register_rest_route('wc/v3','/dtr-mcp/settings-plans',['methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],'callback'=>[__CLASS__,'settings_plan']]);
        register_rest_route('wc/v3','/dtr-mcp/settings-execute',['methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],'callback'=>[__CLASS__,'settings_execute']]);
    }
    // Only explicitly enumerated, non-secret settings are writable. No payment, tax or credentials.
    private static function setting_rules() {
        return [
            'woocommerce_currency'=>['EUR','USD','GBP','CHF','CAD','AUD','JPY'],
            'woocommerce_currency_pos'=>['left','right','left_space','right_space'],
            'woocommerce_price_num_decimals'=>['0','1','2','3','4'],
            'woocommerce_price_decimal_sep'=>[',','.'],
            'woocommerce_price_thousand_sep'=>['',',','.',' '],
            'woocommerce_weight_unit'=>['kg','g','lbs','oz'],
            'woocommerce_dimension_unit'=>['m','cm','mm','in','yd'],
            'woocommerce_manage_stock'=>['yes','no'],
            'woocommerce_hide_out_of_stock_items'=>['yes','no'],
            'woocommerce_notify_low_stock'=>['yes','no'],
            'woocommerce_notify_no_stock'=>['yes','no'],
            'woocommerce_enable_coupons'=>['yes','no']
        ];
    }
    private static function setting_hash($key) {
        return hash('sha256', wp_json_encode([$key, get_option($key, null)]));
    }
    public static function settings_plan($request) {
        if (!current_user_can('manage_woocommerce')) { return self::err('DTR_WOO_SETTINGS_DENIED'); }
        $key=$request->get_param('setting_id'); $value=$request->get_param('value');
        $rules=self::setting_rules();
        if (!is_string($key) || !isset($rules[$key]) || !is_string($value) || !in_array($value,$rules[$key],true)) {
            return self::err('DTR_WOO_SETTING_NOT_ALLOWED',400);
        }
        $old=get_option($key,null);
        if (!is_string($old) && $old!==null) { return self::err('DTR_WOO_SETTING_TYPE_MISMATCH',409); }
        $now=time(); $id=bin2hex(random_bytes(16)); $before=self::setting_hash($key);
        $change=['kind'=>'setting','setting_id'=>$key,'previous'=>$old,'value'=>$value];
        global $wpdb;
        $ok=$wpdb->insert(self::table(),['plan_id'=>$id,'owner_id'=>get_current_user_id(),'product_id'=>0,'changes_json'=>wp_json_encode($change),'before_hash'=>$before,'status'=>'pending','created_at'=>$now,'expires_at'=>$now+900]);
        if (!$ok) { return self::err('DTR_WOO_STORE_FAILED',500); }
        return ['plan_id'=>$id,'status'=>'pending','setting_id'=>$key,'before'=>$old,'proposed'=>$value,'expires_at'=>gmdate('c',$now+900),'approval_url'=>admin_url('tools.php?page=dtr-mcp-woo')];
    }
    public static function settings_execute($request) {
        if (!current_user_can('manage_woocommerce')) { return self::err('DTR_WOO_SETTINGS_DENIED'); }
        $id=$request->get_param('plan_id');
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) { return self::err('DTR_WOO_INVALID_PLAN',400); }
        $r=self::row($id);
        if (!$r || (int)$r['owner_id']!==get_current_user_id() || (int)$r['product_id']!==0) { return self::err('DTR_WOO_PLAN_NOT_FOUND',404); }
        if ($r['status']!=='approved' || (int)$r['approved_by']!==(int)$r['owner_id'] || (int)$r['expires_at']<time()) { return self::err('DTR_WOO_NOT_APPROVED',409); }
        $c=json_decode($r['changes_json'],true); $rules=self::setting_rules();
        if (!is_array($c) || ($c['kind']??'')!=='setting' || !isset($c['setting_id'],$c['value']) || !is_string($c['setting_id']) || !is_string($c['value']) || !isset($rules[$c['setting_id']]) || !in_array($c['value'],$rules[$c['setting_id']],true)) { return self::err('DTR_WOO_SETTING_NOT_ALLOWED',409); }
        if (!hash_equals($r['before_hash'],self::setting_hash($c['setting_id']))) { return self::err('DTR_WOO_CONCURRENT_CHANGE',409); }
        global $wpdb;
        $claimed=$wpdb->query($wpdb->prepare("UPDATE ".self::table()." SET status='running' WHERE plan_id=%s AND owner_id=%d AND status='approved' AND expires_at>=%d",$id,get_current_user_id(),time()));
        if ($claimed!==1) { return self::err('DTR_WOO_ALREADY_CLAIMED',409); }
        try {
            update_option($c['setting_id'],$c['value']);
            if ((string)get_option($c['setting_id'],'')!==$c['value']) { throw new RuntimeException('Option verification failed'); }
            $wpdb->update(self::table(),['status'=>'done','result_code'=>'OK'],['plan_id'=>$id]);
            return ['plan_id'=>$id,'status'=>'done','setting_id'=>$c['setting_id']];
        } catch (Throwable $e) {
            $wpdb->update(self::table(),['status'=>'failed','result_code'=>'SAVE_FAILED'],['plan_id'=>$id]);
            return self::err('DTR_WOO_SAVE_FAILED',500);
        }
    }
    private static function product_hash($p) {
        return hash('sha256', wp_json_encode([$p->get_id(),$p->get_name(),$p->get_status(),$p->get_catalog_visibility(),$p->get_regular_price(),$p->get_sale_price(),$p->get_manage_stock(),$p->get_stock_quantity(),$p->get_stock_status(),$p->get_sku(),$p->get_date_modified()?->getTimestamp()]));
    }
    private static function clean_changes($a) {
        if (!is_array($a) || !$a || count($a)>6) { return false; }
        $allowed=['name','status','catalog_visibility','regular_price','sale_price','manage_stock','stock_quantity','stock_status'];
        foreach($a as $k=>$v) {
            if (!in_array($k,$allowed,true)) { return false; }
            if (in_array($k,['name','status','catalog_visibility','stock_status'],true) && !is_string($v)) { return false; }
            if ($k==='name' && ($v==='' || strlen($v)>180)) { return false; }
            if ($k==='status' && !in_array($v,['draft','publish','private'],true)) { return false; }
            if ($k==='catalog_visibility' && !in_array($v,['visible','catalog','search','hidden'],true)) { return false; }
            if ($k==='stock_status' && !in_array($v,['instock','outofstock','onbackorder'],true)) { return false; }
            if ($k==='manage_stock' && !is_bool($v)) { return false; }
            if ($k==='stock_quantity' && (!is_int($v) || $v<0 || $v>10000000)) { return false; }
            if (in_array($k,['regular_price','sale_price'],true) && (!is_string($v) || ($v!=='' && (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',$v))))) { return false; }
        }
        return $a;
    }
    public static function plan($request) {
        $id=(int)$request->get_param('product_id'); $changes=self::clean_changes($request->get_param('changes'));
        if ($id<1 || !$changes) { return self::err('DTR_WOO_INVALID_CHANGES',400); }
        $p=wc_get_product($id);
        if (!$p || !$p->is_type('simple') || !current_user_can('edit_post',$id)) { return self::err('DTR_WOO_PRODUCT_DENIED',403); }
        $now=time(); $plan=bin2hex(random_bytes(16)); $h=self::product_hash($p);
        global $wpdb;
        $success=$wpdb->insert(self::table(),['plan_id'=>$plan,'owner_id'=>get_current_user_id(),'product_id'=>$id,'changes_json'=>wp_json_encode($changes),'before_hash'=>$h,'status'=>'pending','created_at'=>$now,'expires_at'=>$now+900]);
        if (!$success) { return self::err('DTR_WOO_STORE_FAILED',500); }
        return ['plan_id'=>$plan,'status'=>'pending','product_id'=>$id,'before_hash'=>$h,'changes'=>$changes,'expires_at'=>gmdate('c',$now+900),'approval_url'=>admin_url('tools.php?page=dtr-mcp-woo')];
    }
    private static function row($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE plan_id=%s',$id),ARRAY_A); }
    public static function status($request) {
        $r=self::row($request['plan_id']);
        if (!$r || (int)$r['owner_id']!==get_current_user_id()) { return self::err('DTR_WOO_PLAN_NOT_FOUND',404); }
        return ['plan_id'=>$r['plan_id'],'status'=>($r['expires_at']<time() && in_array($r['status'],['pending','approved'],true))?'expired':$r['status'],'product_id'=>(int)$r['product_id'],'approved_by'=>(int)$r['approved_by'],'result_code'=>$r['result_code']];
    }
    public static function execute($request) {
        $id=$request->get_param('plan_id');
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) { return self::err('DTR_WOO_INVALID_PLAN',400); }
        $r=self::row($id);
        if (!$r || (int)$r['owner_id']!==get_current_user_id()) { return self::err('DTR_WOO_PLAN_NOT_FOUND',404); }
        if ($r['status']!=='approved' || (int)$r['approved_by']!==(int)$r['owner_id'] || $r['expires_at']<time()) { return self::err('DTR_WOO_NOT_APPROVED',409); }
        $p=wc_get_product((int)$r['product_id']);
        if (!$p || !$p->is_type('simple') || !current_user_can('edit_post',(int)$r['product_id'])) { return self::err('DTR_WOO_PRODUCT_DENIED'); }
        if (!hash_equals($r['before_hash'],self::product_hash($p))) { return self::err('DTR_WOO_CONCURRENT_CHANGE',409); }
        $changes=self::clean_changes(json_decode($r['changes_json'],true));
        if (!$changes) { return self::err('DTR_WOO_INVALID_CHANGES',409); }
        global $wpdb;
        if ($wpdb->query($wpdb->prepare("UPDATE ".self::table()." SET status='running' WHERE plan_id=%s AND status='approved' AND expires_at>=%d",$id,time()))!==1) { return self::err('DTR_WOO_ALREADY_CLAIMED',409); }
        try {
            foreach($changes as $key=>$value) { $setter='set_'.$key; $p->$setter($value); }
            $p->save(); $wpdb->update(self::table(),['status'=>'done','result_code'=>'OK'],['plan_id'=>$id]);
            return ['plan_id'=>$id,'status'=>'done','product_id'=>(int)$r['product_id']];
        } catch (Throwable $e) {
            $wpdb->update(self::table(),['status'=>'failed','result_code'=>'SAVE_FAILED'],['plan_id'=>$id]);
            return self::err('DTR_WOO_SAVE_FAILED',500);
        }
    }
    public static function admin_page() {
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) { wp_die('Acceso denegado.'); }
        global $wpdb; $uid=get_current_user_id();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE owner_id=%d ORDER BY created_at DESC LIMIT 40',$uid),ARRAY_A);
        echo '<div class="wrap"><h1>DTR MCP — Aprobaciones WooCommerce</h1><p>Solo el usuario propietario de las claves API puede aprobar. Cambios caducan en 15 minutos. Aprobar no ejecuta.</p>';
        foreach ($rows as $r) {
            echo '<div class="card"><h2>Plan '.esc_html($r['plan_id']).' · '.((int)$r['product_id']===0?'Ajuste WooCommerce':'Producto #'.esc_html($r['product_id'])).'</h2><p>Estado: '.esc_html($r['status']).'</p><pre>'.esc_html(wp_json_encode(json_decode($r['changes_json'],true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
            if ($r['status']==='pending' && (int)$r['expires_at']>=time()) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dtr_mcp_woo_decide"><input type="hidden" name="plan_id" value="'.esc_attr($r['plan_id']).'">';
                wp_nonce_field('dtr_woo_'.$r['plan_id']);
                echo '<button class="button button-primary" name="decision" value="approve">Aprobar</button> <button class="button" name="decision" value="reject">Rechazar</button></form>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    public static function decide() {
        if ($_SERVER['REQUEST_METHOD']!=='POST' || !is_user_logged_in() || !current_user_can('manage_woocommerce')) { wp_die('Acceso denegado.','',['response'=>403]); }
        $id=isset($_POST['plan_id']) && is_string($_POST['plan_id']) ? sanitize_text_field(wp_unslash($_POST['plan_id'])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { wp_die('Plan no válido.'); }
        check_admin_referer('dtr_woo_'.$id);
        $r=self::row($id);
        if (!$r || (int)$r['owner_id']!==get_current_user_id() || $r['status']!=='pending' || $r['expires_at']<time()) { wp_die('No autorizado o plan caducado.','',['response'=>403]); }
        $d=isset($_POST['decision']) && is_string($_POST['decision']) ? wp_unslash($_POST['decision']) : '';
        if (!in_array($d,['approve','reject'],true)) { wp_die('Decisión inválida.'); }
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET status=%s,approved_by=%d WHERE plan_id=%s AND owner_id=%d AND status=%s AND expires_at>=%d',$d==='approve'?'approved':'rejected',get_current_user_id(),$id,get_current_user_id(),'pending',time()));
        wp_safe_redirect(admin_url('tools.php?page=dtr-mcp-woo')); exit;
    }
}
DTR_MCP_Woo::boot();