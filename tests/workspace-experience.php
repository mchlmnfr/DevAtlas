<?php
/** Pure validation regression tests. No database, account changes or production writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/Audit.php';
require_once __DIR__.'/../lib/UserUiPreferences.php';
require_once __DIR__.'/../lib/WorkspaceExperience.php';
require_once __DIR__.'/../lib/ListPageSize.php';
$checks=0;
function ExCheck(bool $ok,string $label):void { global $checks;if(!$ok)throw new RuntimeException($label);$checks++; }
function ExReject(string $key,array $value,string $label):void {
    try { WorkspaceExperience::Validate($key,$value); }
    catch(InvalidArgumentException $e){ ExCheck(true,$label);return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
ExCheck(WorkspaceExperience::Validate('appearance',[])===WorkspaceExperience::Appearance(),'Usable appearance defaults');
foreach(['blue','teal','violet','slate'] as $accent)foreach(['normal','large'] as $size)ExCheck(WorkspaceExperience::Validate('appearance',['accent'=>$accent,'textSize'=>$size])===['accent'=>$accent,'textSize'=>$size],'Palette and text size round-trip');
foreach([['accent'=>'red'],['accent'=>['blue']],['textSize'=>false],['user_id'=>20],['accent'=>"blue\0"]] as $v)ExReject('appearance',$v,'Invalid appearance');
$legacy=UserUiPreferences::Defaults();$legacy['widgets'][0]['visible']=false;$legacy['widgets']=array_reverse($legacy['widgets']);
$converted=WorkspaceExperience::DefaultDashboard($legacy);
ExCheck(!in_array('metrics',array_column($converted['items'],'type'),true),'Legacy hidden widgets remain hidden');
ExCheck($converted['items'][0]['type']==='links','Legacy order is retained');
ExCheck(WorkspaceExperience::Validate('dashboard',$converted)===$converted,'Legacy layout produces a valid non-overlapping grid');
$item=['id'=>'note_1','type'=>'note','title'=>'Personal note','x'=>0,'y'=>0,'w'=>6,'h'=>3,'options'=>['text'=>'A note']];
ExCheck(WorkspaceExperience::Validate('dashboard',['version'=>2,'items'=>[$item]])['items'][0]===$item,'Position, size and content round-trip');
$duplicate=$item;$duplicate['id']='note_2';$duplicate['x']=6;
ExCheck(count(WorkspaceExperience::Validate('dashboard',['items'=>[$item,$duplicate]])['items'])===2,'Two independent instances may use one widget type');
foreach(['id'=>'<img>','type'=>'SQL','x'=>-1,'y'=>201,'w'=>13,'h'=>0] as $field=>$v){$bad=$item;$bad[$field]=$v;ExReject('dashboard',['items'=>[$bad]],'Invalid grid field '.$field);}
$bad=$item;$bad['x']=8;ExReject('dashboard',['items'=>[$bad]],'Widget must fit its columns');
ExReject('dashboard',['items'=>[$item,$item]],'Duplicate instance IDs');
$duplicate['x']=5;ExReject('dashboard',['items'=>[$item,$duplicate]],'Overlapping instances');
ExReject('dashboard',['version'=>1,'items'=>[]],'Unsupported grid version');
ExReject('dashboard',['items'=>array_fill(0,31,$item)],'Bounded widget count');
foreach(['javascript:alert(1)','data:text/html,hi','//example.test','https://name:pass@example.test','/\\example.test',"/test\r\n"] as $url){$bad=$item;$bad['type']='shortcuts';$bad['options']=['links'=>[['title'=>'Example','url'=>$url]]];ExReject('dashboard',['items'=>[$bad]],'Unsafe shortcut');}
foreach(['https://example.test/path','http://example.test','mailto:team@example.test','tel:+123456','/AnalystToolkit/modules/leaves/'] as $url){$valid=$item;$valid['type']='shortcuts';$valid['options']=['links'=>[['title'=>'Example','url'=>$url]]];ExCheck(WorkspaceExperience::Validate('dashboard',['items'=>[$valid]])['items'][0]['options']['links'][0]['url']===$url,'Safe shortcut');}
foreach([['text'=>str_repeat('a',4001)],['sql'=>'SELECT 1'],['tasks'=>[['text'=>'Task','done'=>'true']]],['month'=>'2026-13'],['status'=>'admin']] as $options){$bad=$item;$bad['options']=$options;ExReject('dashboard',['items'=>[$bad]],'Invalid widget option');}
ExCheck(WorkspaceExperience::Validate('draft.dailylogs',['fields'=>['entryClass'=>'SD','entryActual'=>str_repeat('a',2000)]])['fields']['entryClass']==='SD','Work draft fields support long activity text');
ExCheck(WorkspaceExperience::Validate('draft.leaves',['fields'=>['leaveDate'=>'2026-11-12','leaveHours'=>'8']])['fields']['leaveHours']==='8','Leave draft IDs match the actual form');
foreach(['password','password_hash','csrf','user_id','role_key'] as $field)ExReject('draft.dailylogs',['fields'=>[$field=>'sensitive']],'Credentials and identity excluded from drafts');
$module=['filters'=>['userSearch'=>'Sample'],'pageSize'=>['main'=>'15'],'views'=>[['name'=>'My view','filters'=>['userSearch'=>'Sample'],'pageSize'=>['main'=>'15']]],'tables'=>['users'=>['hidden'=>[2],'widths'=>[0=>120]]]];
ExCheck(WorkspaceExperience::Validate('module.usermgmt',$module)===$module,'Filters, saved views and table layout round-trip');
foreach([['pageSize'=>['main'=>'200']],['filters'=>['password'=>['text']]],['tables'=>['users'=>['widths'=>[0=>59]]]],['tables'=>['users'=>['widths'=>[101=>100]]]],['tables'=>['users'=>['hidden'=>[-1]]]],['views'=>[['name'=>'','filters'=>[]]]],['views'=>[['name'=>'Same'],['name'=>'Same']]],['role'=>'admin']] as $v)ExReject('module.usermgmt',$v,'Invalid module setting');
foreach(['5','10','12','15','20','25','50','100'] as $size){$_GET['page_size']=$size;ExCheck(ListPageSize::Get()===(int)$size,'Allowed page size');}
foreach(['0','-1','100000','1 OR 1=1','25.5',['25'],25] as $size){$_GET['page_size']=$size;ExCheck(ListPageSize::Get(50)===50,'Malformed page size falls back');}
unset($_GET['page_size']);
echo "Workspace experience: $checks checks passed.\n";
