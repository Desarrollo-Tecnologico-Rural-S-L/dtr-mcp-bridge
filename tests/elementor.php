<?php
require __DIR__ . '/bootstrap.php';
if (in_array('--code-enabled', $argv, true)) { define('DTR_MCP_ALLOW_CODE_PATCHES', true); }
$schemas = [
    'container'=>['padding'=>['type'=>'dimensions'], 'padding_mobile'=>['type'=>'dimensions']],
    'heading'=>['title'=>['type'=>'text'], 'header_size'=>['type'=>'select','options'=>['h1'=>'H1','h2'=>'H2']], 'link'=>['type'=>'url'], 'title_color'=>['type'=>'color'], 'custom_css'=>['type'=>'code']],
    'text-editor'=>['editor'=>['type'=>'wysiwyg']],
    'button'=>['text'=>['type'=>'text'],'link'=>['type'=>'url']],
    'image'=>['image'=>['type'=>'media']],
    'accordion'=>['tabs'=>['type'=>'repeater','fields'=>['tab_title'=>['type'=>'text'],'tab_content'=>['type'=>'wysiwyg']]]],
    'html'=>['html'=>['type'=>'code']],
    'icon-list'=>['icon_list'=>['type'=>'repeater','fields'=>['text'=>['type'=>'text'],'link'=>['type'=>'url']]]],
    'form'=>['email_to'=>['type'=>'text'], 'submit_actions'=>['type'=>'select2'], 'button_text'=>['type'=>'text'], 'color'=>['type'=>'color','tab'=>'style'], 'form_fields'=>['type'=>'repeater','fields'=>['field_type'=>['type'=>'text'], 'field_label'=>['type'=>'text'], 'required'=>['type'=>'switcher']]]],
    'section'=>[], 'column'=>[], 'theme-post-content'=>[],
];
function widget($id, $type, $settings = []) { return ['id'=>$id,'elType'=>'widget','widgetType'=>$type,'settings'=>$settings,'elements'=>[]]; }
$tree = [['id'=>'parent01','elType'=>'container','settings'=>[], 'elements'=>[
    widget('title001','heading',['title'=>'Before','link'=>['url'=>'https://example.org']]),
    widget('html0001','html',['html'=>'<p>Before</p>']),
    widget('icons001','icon-list',['icon_list'=>[['_id'=>'row01','text'=>'First','link'=>['url'=>'/first']]]]),
    widget('form0001','form',['email_to'=>'private@example.org','submit_actions'=>['email'],'form_fields'=>[['_id'=>'field01','field_type'=>'text','field_label'=>'Name']]]),
]], ['id'=>'parent02','elType'=>'container','settings'=>[], 'elements'=>[]]];
function edit($id, $path, $before, $after) { return ['action'=>'set','element_id'=>$id,'path'=>$path,'before'=>$before,'after'=>$after]; }
function run_ops($ops) { global $tree,$schemas; return DTR_MCP_Elementor::transform($tree, $ops, $schemas); }
check(count(DTR_MCP_Elementor::index($tree)) === 6, 'read complete tree');
check(DTR_MCP_Elementor::hash(['b'=>2,'a'=>1]) === DTR_MCP_Elementor::hash(['a'=>1,'b'=>2]), 'canonical object hash');
check(DTR_MCP_Elementor::hash([1,2]) !== DTR_MCP_Elementor::hash([2,1]), 'list order participates in hash');
$r = run_ops([edit('title001',['title'],'Before','After')]);
check(!is_wp_error($r) && $r['elements'][0]['elements'][0]['settings']['title'] === 'After', 'text edit');
check(!is_wp_error(run_ops([edit('title001',['link','url'],'https://example.org','/new')])), 'nested link edit');
check(error_is(run_ops([edit('title001',['link'],['url'=>'https://example.org'],['url'=>'javascript:alert(1)'])]),'DTR_EXECUTABLE_CONTENT_DENIED'), 'regression nested URL bypass');
check(error_is(run_ops([edit('title001',['title'],'Before','<img src=x onerror=alert(1)>')]),'DTR_EXECUTABLE_CONTENT_DENIED'), 'regression script in text property');
$op = edit('parent01',['padding_mobile'],null,['top'=>'10','right'=>'10','bottom'=>'10','left'=>'10','unit'=>'px','isLinked'=>true]); $op['before_exists']=false;
check(!is_wp_error(run_ops([$op])), 'new responsive setting');
check(error_is(run_ops([edit('title001',['title'],'wrong','After')]),'DTR_PATCH_CONFLICT'), 'exact before conflict');
check(error_is(run_ops([edit('title001',['title'],'Before',99)]),'DTR_CONTROL_TYPE_MISMATCH'), 'wrong control type');
$tag=edit('title001',['header_size'],null,'script'); $tag['before_exists']=false;
check(error_is(run_ops([$tag]),'DTR_CONTROL_TYPE_MISMATCH'), 'select cannot introduce executable tag');
check(!is_wp_error(run_ops([edit('icons001',['icon_list',0,'text'],'First','Updated')])), 'repeater nested edit');
check(!is_wp_error(run_ops([edit('form0001',['form_fields',0,'field_label'],'Name','Your name')])), 'form presentation edit');
check(error_is(run_ops([edit('form0001',['email_to'],'private@example.org','attacker@example.org')]),'DTR_FORM_SECURITY_PROTECTED'), 'form recipient protected');
check(error_is(run_ops([edit('form0001',['submit_actions'],['email'],['webhook'])]),'DTR_FORM_SECURITY_PROTECTED'), 'form actions protected');
check(error_is(run_ops([edit('form0001',['form_fields',0,'field_type'],'text','hidden')]),'DTR_FORM_SECURITY_PROTECTED'), 'form hidden field injection');
$html = run_ops([edit('html0001',['html'],'<p>Before</p>','<p>After</p>')]);
check(defined('DTR_MCP_ALLOW_CODE_PATCHES') ? !is_wp_error($html) : error_is($html,'DTR_CODE_PATCHES_DISABLED'), 'HTML explicit opt in');
check(error_is(run_ops([edit('html0001',['html'],'<p>Before</p>','<script>alert(1)</script>')]), defined('DTR_MCP_ALLOW_CODE_PATCHES') ? 'DTR_EXECUTABLE_CONTENT_DENIED' : 'DTR_CODE_PATCHES_DISABLED'), 'JavaScript never enabled by HTML flag');
$insert = ['action'=>'insert','parent_id'=>'parent02','position'=>0,'element'=>['elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'New']]];
$r = run_ops([$insert]);
check(!is_wp_error($r) && count($r['elements'][1]['elements']) === 1, 'insert registered widget');
check($r === run_ops([$insert]), 'deterministic structural plan');
$r = run_ops([['action'=>'duplicate','element_id'=>'title001','parent_id'=>'parent02','position'=>0]]);
check(!is_wp_error($r) && $r['elements'][1]['elements'][0]['id'] !== 'title001', 'duplicate generates new ID');
$r = run_ops([['action'=>'move','element_id'=>'title001','parent_id'=>'parent02','position'=>0]]);
check(!is_wp_error($r) && $r['elements'][1]['elements'][0]['id'] === 'title001' && count($r['elements'][0]['elements']) === 3, 'move preserves ID');
check(error_is(run_ops([['action'=>'move','element_id'=>'parent01','parent_id'=>'parent01','position'=>0]]),'DTR_MOVE_CYCLE'), 'move cycle rejected');
check(!is_wp_error(run_ops([['action'=>'reorder','parent_id'=>'parent01','order'=>['form0001','icons001','html0001','title001']]])), 'reorder exact permutation');
check(error_is(run_ops([['action'=>'reorder','parent_id'=>'parent01','order'=>['title001']]]),'DTR_INVALID_ORDER'), 'reorder cannot delete children');
check(!is_wp_error(run_ops([['action'=>'remove','element_id'=>'title001']])), 'remove widget');
$duplicate = $tree; $duplicate[1]['id']='parent01';
check(error_is(DTR_MCP_Elementor::index($duplicate),'DTR_DUPLICATE_ELEMENT_ID'), 'duplicate existing IDs rejected');
check(error_is(run_ops([['action'=>'exec','command'=>'phpinfo']]),'DTR_INVALID_OPERATION'), 'unknown operation rejected');
check(error_is(run_ops([$insert + ['unexpected'=>true]]),'DTR_INVALID_OPERATION'), 'unplanned fields rejected');
$atomic = [['id'=>'atomic01','elType'=>'e-flexbox','settings'=>[], 'elements'=>[], 'version'=>'0.0','styles'=>[]]];
check(!is_wp_error(DTR_MCP_Elementor::index($atomic)), 'V4 lossless structural read');
check(error_is(DTR_MCP_Elementor::transform($atomic,[edit('atomic01',['test'],null,'x')],$schemas),'DTR_ATOMIC_WRITE_UNSUPPORTED'), 'V4 cannot silently use V3 writer');
$GLOBALS['caps']=false;
check(error_is(DTR_MCP_Extended::plan(new TestRequest([])),'DTR_ACCESS_DENIED'), 'plan permission enforced');
check(error_is(DTR_MCP_Extended::execute(new TestRequest([])),'DTR_ACCESS_DENIED'), 'execute permission enforced');
$GLOBALS['caps']=true;
check(error_is(DTR_MCP_Extended::read(new TestRequest(['operation'=>'elementor','query_json'=>[]])),'DTR_INVALID_ARGUMENTS'), 'malformed query rejected without PHP TypeError');
check(error_is(DTR_MCP_Extended::plan(new TestRequest(['operation'=>'elementor_edit','arguments'=>'bad'])),'DTR_INVALID_ARGUMENTS'), 'malformed arguments rejected');
check(DTR_MCP_Elementor::redact(['email_to'=>'private','api_key'=>'secret']) === ['email_to'=>'[REDACTED]','api_key'=>'[REDACTED]'], 'sensitive values redacted');
$examples = [
    'image'=>['image'=>['id'=>20,'url'=>'https://example.org/image.png']],
    'button'=>['text'=>'Go','link'=>['url'=>'/go','is_external'=>true]],
    'text-editor'=>['editor'=>'<p><strong>Rich text</strong></p>'],
    'accordion'=>['tabs'=>[['_id'=>'tab01','tab_title'=>'Question','tab_content'=>'<p>Answer</p>']]],
];
foreach ($examples as $kind=>$settings) {
    $op=$insert; $op['element']=['elType'=>'widget','widgetType'=>$kind,'settings'=>$settings];
    check(!is_wp_error(run_ops([$op])), 'insert '.$kind.' with structured settings');
}
check(error_is(run_ops([edit([],['title'],'Before','After')]),'DTR_INVALID_ELEMENT_ID'),'malformed element ID');
check(error_is(run_ops([['action'=>'reorder','parent_id'=>'parent01','order'=>[[],[],[],[]]]]),'DTR_INVALID_ORDER'),'malformed reorder IDs');
$op=edit('title001',['custom_css'],null,'selector { color: red; }'); $op['before_exists']=false;
check(defined('DTR_MCP_ALLOW_CODE_PATCHES') ? !is_wp_error(run_ops([$op])) : error_is(run_ops([$op]),'DTR_CODE_PATCHES_DISABLED'),'CSS explicit opt in');
if (defined('DTR_MCP_ALLOW_CODE_PATCHES')) {
    $op['after']='selector { background: url(https://example.org); }';
    check(error_is(run_ops([$op]),'DTR_UNSAFE_CSS'),'CSS external loads rejected');
    $GLOBALS['caps']=false; $op['after']='selector {color:red}';
    check(error_is(run_ops([$op]),'DTR_CODE_PATCHES_DISABLED'),'code requires unfiltered_html capability'); $GLOBALS['caps']=true;
}
echo 'Assertions: ' . $GLOBALS['checks'] . "\n";
