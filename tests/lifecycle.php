<?php
require __DIR__ . '/elementor.php';
require __DIR__ . '/fixtures/elementor-runtime.php';
class TestDB {
    public $prefix = 'wp_';
    public $rows = [];
    public $claim_conflict = false;
    public $audit_failure = false;
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_var($q) { return 0; }
    public function insert($table, $row) { $this->rows[$row['plan_id']] = $row; return 1; }
    public function get_row($q, $format) { return $this->rows[$q[1][0]] ?? null; }
    public function query($q) {
        [$sql,$a] = $q; $r = &$this->rows[$a[0]];
        if ($this->claim_conflict || !$r || $r['owner_id'] !== $a[1] || $r['status'] !== 'approved' || $r['expires_at'] < $a[2]) { return 0; }
        $r['status'] = 'running'; return 1;
    }
    public function update($table, $data, $where) {
        if ($this->audit_failure) { return false; }
        $this->rows[$where['plan_id']] = array_merge($this->rows[$where['plan_id']], $data); return 1;
    }
}
class TestElementType {
    public function __construct(private $controls) {}
    public function get_controls() { return $this->controls; }
}
class TestManager {
    public function get_element_types() { return []; }
    public function get_widget_types() { return array_map(static fn($controls)=>new TestElementType($controls), $GLOBALS['schemas']); }
}
class TestDocuments {
    public $mode = 'ok'; public $calls = 0;
    public function get($id) { return $this; }
    public function save($data) {
        $this->calls++;
        if ($this->mode === 'throw') { throw new RuntimeException('not exposed'); }
        if ($this->mode === 'false') { return false; }
        $GLOBALS['meta'][1]['_elementor_data'] = json_encode($this->mode === 'partial' ? [] : $data['elements']);
        return true;
    }
}
$GLOBALS['wpdb'] = new TestDB();
$documents = new TestDocuments();
\Elementor\Plugin::$instance = (object)['elements_manager'=>new TestManager(),'widgets_manager'=>new TestManager(),'documents'=>$documents];
$GLOBALS['posts'][1] = (object)['ID'=>1,'post_type'=>'page','post_status'=>'draft','post_title'=>'Test','post_content'=>'','post_modified_gmt'=>'2026-10-30 00:00:00'];
function reset_document() { global $tree, $documents; $GLOBALS['meta'][1]['_elementor_data'] = json_encode($tree); $documents->mode='ok'; }
function plan() {
    reset_document();
    $read = DTR_MCP_Extended::read(new TestRequest(['operation'=>'elementor','query_json'=>'{"id":1}']));
    $r = DTR_MCP_Extended::plan(new TestRequest(['operation'=>'elementor_edit','arguments'=>['id'=>1,'expected_hash'=>$read->data['expected_hash'],'operations'=>[edit('title001',['title'],'Before','After')]]]));
    check($r instanceof WP_REST_Response, 'plan creates preview');
    return $r->data['plan_id'];
}
// Fixture approval state only: never invokes or bypasses a real WordPress approval.
function fixture_approved($id) { $GLOBALS['wpdb']->rows[$id]['status']='approved'; $GLOBALS['wpdb']->rows[$id]['approved_by']=3; }
function execute_plan($id) { return DTR_MCP_Extended::execute(new TestRequest(['plan_id'=>$id])); }
$id=plan(); check(error_is(execute_plan($id),'DTR_NOT_APPROVED_OR_EXPIRED'),'pending plan cannot execute');
fixture_approved($id); $r=execute_plan($id);
check($r instanceof WP_REST_Response && $r->data['status']==='completed' && $r->data['result']['verified']===true,'approved plan persists and verifies');
$calls=$documents->calls; check(error_is(execute_plan($id),'DTR_NOT_APPROVED_OR_EXPIRED') && $documents->calls===$calls,'consumed plan cannot repeat save');
$id=plan(); fixture_approved($id); $GLOBALS['wpdb']->rows[$id]['expires_at']=time()-1;
check(error_is(execute_plan($id),'DTR_NOT_APPROVED_OR_EXPIRED'),'expired plan rejected');
$id=plan(); fixture_approved($id); $GLOBALS['meta'][1]['_elementor_data']='[]';
check(error_is(execute_plan($id),'DTR_CONTENT_CHANGED'),'concurrent tree edit rejected');
$id=plan(); fixture_approved($id); $GLOBALS['meta'][1]['_elementor_page_settings']=['background_color'=>'red'];
check(error_is(execute_plan($id),'DTR_CONTENT_CHANGED'),'concurrent page settings rejected');
$id=plan(); fixture_approved($id); $GLOBALS['options']['dtr_mcp_extended_'.$id]['arguments']['operations'][0]['after']='tampered';
check(error_is(execute_plan($id),'DTR_PLAN_CHANGED'),'payload tampering rejected');
$id=plan(); fixture_approved($id); $GLOBALS['approver']=false;
check(error_is(execute_plan($id),'DTR_APPROVER_PERMISSION_REVOKED'),'revoked approver rejected'); $GLOBALS['approver']=true;
$id=plan(); fixture_approved($id); $GLOBALS['editor_lock']=true;
check(error_is(execute_plan($id),'DTR_EDITOR_LOCKED'),'native editor lock respected'); $GLOBALS['editor_lock']=false;
$id=plan(); fixture_approved($id); $GLOBALS['options']['dtr_mcp_write_lock']='other';
check(error_is(execute_plan($id),'DTR_SITE_LOCKED') && get_option('dtr_mcp_write_lock')==='other','other writer lock preserved'); delete_option('dtr_mcp_write_lock');
$id=plan(); fixture_approved($id); $GLOBALS['wpdb']->claim_conflict=true;
check(error_is(execute_plan($id),'DTR_ALREADY_CLAIMED'),'atomic claim conflict'); $GLOBALS['wpdb']->claim_conflict=false;
$id=plan(); fixture_approved($id); $documents->mode='false';
check(error_is(execute_plan($id),'DTR_ELEMENTOR_SAVE_FAILED') && $GLOBALS['wpdb']->rows[$id]['status']==='failed','persistence error recorded');
$id=plan(); fixture_approved($id); $documents->mode='partial'; $r=execute_plan($id);
check($r instanceof WP_REST_Response && $r->data['status']==='verification_failed' && $r->data['result']['persisted']===true && $r->data['retry_safe']===false,'successful save failed verification distinguished');
$calls=$documents->calls; execute_plan($id); check($calls===$documents->calls,'verification failure never repeats save');
$id=plan(); fixture_approved($id); $documents->mode='throw'; $r=execute_plan($id);
check(error_is($r,'DTR_EXTENDED_EXECUTION_UNCERTAIN') && $GLOBALS['wpdb']->rows[$id]['status']==='uncertain','save exception is uncertain and terminal');
$id=plan(); fixture_approved($id); $GLOBALS['wpdb']->audit_failure=true; $r=execute_plan($id); $GLOBALS['wpdb']->audit_failure=false;
check(error_is($r,'DTR_AUDIT_WRITE_FAILED') && $GLOBALS['wpdb']->rows[$id]['status']==='running','audit failure leaves consumed plan');
echo 'Total assertions: '.$GLOBALS['checks']."\n";
