<?php
/**
 * Filename: WorkspaceExperience.php
 * Purpose: Validate and persist account-owned workspace documents and dashboard layouts.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 */
use CodeIgniter\Database\BaseConnection;

final class WorkspaceExperience
{
    public const TYPES = ['metrics','personal','approvals','team','renewals','records','links','calendar','reports','note','shortcuts','todo'];
    public static function Ready(BaseConnection $db): bool
    {
        $r = $db->query('SELECT to_regclass(?) IS NOT NULL AS ready', [DB_SCHEMA.'.user_workspace_state'])->getUnbufferedRow('array');
        return in_array($r['ready'] ?? false, [true,1,'1','t','true'], true);
    }
    private static function Text($value, int $length): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $length || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) throw new InvalidArgumentException('Enter valid workspace text.');
        return $value;
    }
    private static function Object($value): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('Invalid workspace document.');
        return $value;
    }
    private static function Keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed)) throw new InvalidArgumentException('Unknown workspace option.');
    }
    public static function Key($key): string
    {
        if (!is_string($key)) throw new InvalidArgumentException('Choose a workspace setting.');
        if (in_array($key, ['appearance','favorites','dashboard','draft.dailylogs','draft.leaves'], true)) return $key;
        if (preg_match('/^module\.([a-z]+)$/D', $key, $m) && isset(Audit::Modules()[$m[1]])) return $key;
        throw new InvalidArgumentException('Choose a valid workspace setting.');
    }
    private static function Authorize(string $key): void
    {
        $module = str_starts_with($key,'module.') ? substr($key,7) : (str_starts_with($key,'draft.') ? substr($key,6) : ($key==='dashboard' ? 'dashboard' : 'account'));
        Permissions::Require($module);
    }
    public static function Appearance(): array { return ['accent'=>'blue','textSize'=>'normal']; }
    public static function Module(): array { return ['filters'=>[], 'pageSize'=>[], 'views'=>[], 'tables'=>[]]; }
    public static function DefaultDashboard(array $legacy = []): array
    {
        $widgets = $legacy['widgets'] ?? UserUiPreferences::Defaults()['widgets']; $items=[]; $y=0; $x=0; $rowHeight=0;
        foreach ($widgets as $widget) {
            if (!$widget['visible']) continue;
            $type=$widget['id']; $w=in_array($type,['metrics','records','links'],true)?12:6; $h=$type==='metrics'?3:(in_array($type,['records','links'],true)?2:4);
            if ($x+$w>12) { $x=0;$y+=$rowHeight;$rowHeight=0; }
            $items[]=['id'=>'default_'.$type,'type'=>$type,'title'=>'','x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,'options'=>[]];
            $x+=$w;$rowHeight=max($rowHeight,$h);if($x>=12){$x=0;$y+=$rowHeight;$rowHeight=0;}
        }
        return ['version'=>2,'items'=>$items];
    }
    public static function Default(string $key): array
    {
        if ($key==='appearance') return self::Appearance();
        if ($key==='favorites') return ['modules'=>[]];
        if ($key==='dashboard') return self::DefaultDashboard();
        if (str_starts_with($key,'draft.')) return ['fields'=>[], 'record'=>'new'];
        return self::Module();
    }
    private static function Map($value, int $limit = 60, int $length = 400): array
    {
        $value=self::Object($value);if(count($value)>$limit)throw new InvalidArgumentException('Too many workspace fields.');$out=[];
        foreach($value as $key=>$text){if(!is_string($key)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,100}$/D',$key))throw new InvalidArgumentException('Invalid field identifier.');$out[$key]=self::Text($text,$length);}
        return $out;
    }
    private static function Link($value): string
    {
        $value=self::Text($value,1000);if(str_contains($value,"\r")||str_contains($value,"\n"))throw new InvalidArgumentException('Use a link on one line.');$value=trim($value);
        if(!preg_match('~^(?:https?://|mailto:|tel:|/(?!/))~i',$value)||preg_match('/[\r\n\\\\]/',$value))throw new InvalidArgumentException('Use an http, https, email, phone or application link.');
        $parts=parse_url($value);if($parts===false||isset($parts['user'])||isset($parts['pass']))throw new InvalidArgumentException('Use a link without embedded credentials.');
        return $value;
    }
    public static function Validate(string $key, array $value): array
    {
        self::Key($key);
        if(strlen(json_encode($value,JSON_THROW_ON_ERROR))>65536)throw new InvalidArgumentException('Workspace document is too large.');
        if($key==='appearance'){
            self::Keys($value,['accent','textSize']);$a=$value['accent']??'blue';$s=$value['textSize']??'normal';
            if(!in_array($a,['blue','teal','violet','slate'],true)||!in_array($s,['normal','large'],true))throw new InvalidArgumentException('Choose an available appearance.');
            return ['accent'=>$a,'textSize'=>$s];
        }
        if($key==='favorites'){
            self::Keys($value,['modules']);$modules=$value['modules']??[];
            if(!is_array($modules)||!array_is_list($modules)||count($modules)>12)throw new InvalidArgumentException('Choose up to twelve favourite modules.');
            foreach($modules as $module)if(!is_string($module)||!isset(Audit::Modules()[$module])||!Permissions::Visible($module)||!Permissions::Can($module))throw new InvalidArgumentException('Choose a module available to your role.');
            return ['modules'=>array_values(array_unique($modules))];
        }
        if($key==='dashboard'){
            self::Keys($value,['version','items']);if(isset($value['version'])&&$value['version']!==2)throw new InvalidArgumentException('Reload this dashboard version.');$items=$value['items']??[];
            if(!is_array($items)||!array_is_list($items)||count($items)>30)throw new InvalidArgumentException('Use up to thirty dashboard widgets.');
            $out=[];$ids=[];
            foreach($items as $item){
                $item=self::Object($item);self::Keys($item,['id','type','title','x','y','w','h','options']);
                $id=$item['id']??'';$type=$item['type']??'';
                if(!is_string($id)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,60}$/D',$id)||isset($ids[$id])||!in_array($type,self::TYPES,true))throw new InvalidArgumentException('Choose valid, unique widget instances.');
                $ids[$id]=true;
                foreach(['x'=>[0,11],'y'=>[0,200],'w'=>[2,12],'h'=>[1,16]] as $field=>[$min,$max])if(!is_int($item[$field]??null)||$item[$field]<$min||$item[$field]>$max)throw new InvalidArgumentException('Choose a valid widget position and size.');
                if($item['x']+$item['w']>12)throw new InvalidArgumentException('A widget must fit inside the dashboard grid.');
                $o=self::Object($item['options']??[]);self::Keys($o,['text','tasks','links','month','status']);$options=[];
                if(isset($o['text']))$options['text']=self::Text($o['text'],4000);
                if(isset($o['month'])){if(!is_string($o['month'])||!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D',$o['month']))throw new InvalidArgumentException('Choose a calendar month.');$options['month']=$o['month'];}
                if(isset($o['status'])){if(!in_array($o['status'],['all','Submitted','Reviewed','Returned','Draft'],true))throw new InvalidArgumentException('Choose a valid widget filter.');$options['status']=$o['status'];}
                if(isset($o['tasks'])){
                    if(!is_array($o['tasks'])||!array_is_list($o['tasks'])||count($o['tasks'])>30)throw new InvalidArgumentException('Use up to thirty to-do items.');$options['tasks']=[];
                    foreach($o['tasks'] as $task){$task=self::Object($task);self::Keys($task,['text','done']);if(!is_bool($task['done']??null))throw new InvalidArgumentException('Choose a valid to-do state.');$options['tasks'][]=['text'=>self::Text($task['text']??'',240),'done'=>$task['done']];}
                }
                if(isset($o['links'])){
                    if(!is_array($o['links'])||!array_is_list($o['links'])||count($o['links'])>12)throw new InvalidArgumentException('Use up to twelve shortcuts in a widget.');$options['links']=[];
                    foreach($o['links'] as $link){$link=self::Object($link);self::Keys($link,['title','url']);$options['links'][]=['title'=>self::Text($link['title']??'',80),'url'=>self::Link($link['url']??'')];}
                }
                $out[]=['id'=>$id,'type'=>$type,'title'=>self::Text($item['title']??'',80),'x'=>$item['x'],'y'=>$item['y'],'w'=>$item['w'],'h'=>$item['h'],'options'=>$options];
            }
            foreach($out as $i=>$a)foreach(array_slice($out,$i+1) as $b)if($a['x']<$b['x']+$b['w']&&$a['x']+$a['w']>$b['x']&&$a['y']<$b['y']+$b['h']&&$a['y']+$a['h']>$b['y'])throw new InvalidArgumentException('Dashboard widgets must not overlap.');
            return ['version'=>2,'items'=>$out];
        }
        if(str_starts_with($key,'draft.')){
            self::Keys($value,['fields','record']);$fields=self::Map($value['fields']??[],12,10000);
            $allowed=$key==='draft.dailylogs'?['entryDate','entryClass','entryActivity','entryPlanned','entryActual','entryHours','entryRemarks']:['leaveDate','leaveDateTo','leaveType','leavePeriod','leaveHours','leaveRemarks'];
            if(array_diff(array_keys($fields),$allowed))throw new InvalidArgumentException('This form field cannot be stored as a draft.');
            return ['record'=>self::Text($value['record']??'new',80),'fields'=>$fields];
        }
        self::Keys($value,['filters','pageSize','views','tables']);$out=self::Module();
        $out['filters']=self::Map($value['filters']??[]);$out['pageSize']=self::Map($value['pageSize']??[],12);
        foreach($out['pageSize'] as $size)if(!in_array($size,['5','10','12','15','20','25','50','100'],true))throw new InvalidArgumentException('Choose an available page size.');
        $viewNames=[];$views=$value['views']??[];if(!is_array($views)||!array_is_list($views)||count($views)>12)throw new InvalidArgumentException('Use up to twelve saved views per module.');
        foreach($views as $view){$view=self::Object($view);self::Keys($view,['name','filters','pageSize']);$name=trim(self::Text($view['name']??'',60));if($name===''||isset($viewNames[$name]))throw new InvalidArgumentException('Use a unique name for each saved view.');$viewNames[$name]=true;foreach(self::Map($view['pageSize']??[],12) as $size)if(!in_array($size,['5','10','12','15','20','25','50','100'],true))throw new InvalidArgumentException('Choose an available page size.');$out['views'][]=['name'=>self::Text($view['name']??'',60),'filters'=>self::Map($view['filters']??[]),'pageSize'=>self::Map($view['pageSize']??[],12)];}
        $tables=self::Object($value['tables']??[]);if(count($tables)>12)throw new InvalidArgumentException('Too many table layouts.');
        foreach($tables as $id=>$table){
            if(!is_string($id)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,80}$/D',$id))throw new InvalidArgumentException('Invalid table layout identifier.');
            $table=self::Object($table);self::Keys($table,['hidden','widths']);$hidden=$table['hidden']??[];$widths=self::Object($table['widths']??[]);
            if(!is_array($hidden)||!array_is_list($hidden)||count($hidden)>50||count($widths)>50)throw new InvalidArgumentException('Invalid column layout.');
            foreach($hidden as $column)if(!is_int($column)||$column<0||$column>100)throw new InvalidArgumentException('Invalid hidden column.');
            foreach($widths as $column=>$width)if(!ctype_digit((string)$column)||(int)$column>100||!is_int($width)||$width<60||$width>800)throw new InvalidArgumentException('Invalid column width.');
            $out['tables'][$id]=['hidden'=>array_values(array_unique($hidden)),'widths'=>$widths];
        }
        return $out;
    }
    public static function Read(BaseConnection $db,int $actor,string $key,bool $authorize=true): array
    {
        if($actor<=0)throw new DomainException('Sign in to use your workspace.');$key=self::Key($key);if($authorize)self::Authorize($key);
        $state=['value'=>self::Default($key),'revision'=>0];if(!self::Ready($db))return $state;
        $r=$db->query('SELECT value,revision FROM user_workspace_state WHERE user_id=? AND state_key=?',[$actor,$key])->getUnbufferedRow('array');
        if($r){$state['revision']=(int)$r['revision'];try{$decoded=json_decode($r['value'],true,64,JSON_THROW_ON_ERROR);if($key==='favorites'&&is_array($decoded['modules']??null))$decoded['modules']=array_values(array_filter($decoded['modules'],fn($m)=>is_string($m)&&Permissions::Visible($m)&&Permissions::Can($m)));$state['value']=self::Validate($key,self::Object($decoded));}catch(InvalidArgumentException|JsonException $e){}}
        return $state;
    }
    public static function Initial(BaseConnection $db,int $actor,string $module,array $legacy=[]): array
    {
        $docs=['appearance'=>self::Read($db,$actor,'appearance',false),'favorites'=>self::Read($db,$actor,'favorites',false)];
        if(isset(Audit::Modules()[$module]))$docs['module.'.$module]=self::Read($db,$actor,'module.'.$module,false);
        if($module==='dashboard'){$docs['dashboard']=self::Read($db,$actor,'dashboard');if($docs['dashboard']['revision']===0)$docs['dashboard']['value']=self::DefaultDashboard($legacy);}
        $catalog=[];foreach(Audit::Modules() as $key=>$label)if(Permissions::Visible($key)&&Permissions::Can($key)&&!in_array($key,['authentication','account','reviewlogs','updates','wiki'],true))$catalog[]=['id'=>$key,'label'=>$label,'url'=>RouteUrls::Url(APP_BASE_URL.($key==='dashboard'?'/index.php':'/modules/'.$key.'/index.php'))];
        return ['actor'=>$actor,'module'=>$module,'storage_ready'=>self::Ready($db),'documents'=>$docs,'catalog'=>$catalog,'endpoint'=>RouteUrls::Url(APP_BASE_URL.'/modules/account/experience.php')];
    }
    public static function Save(BaseConnection $db,int $actor,array $request): array
    {
        if($actor<=0)throw new DomainException('Sign in to use your workspace.');self::Keys($request,['changes','ui']);
        if(!self::Ready($db))throw new RuntimeException('Ask an administrator to enable the workspace experience update.');
        $changes=$request['changes']??[];if(!is_array($changes)||!array_is_list($changes)||count($changes)>4)throw new InvalidArgumentException('Invalid workspace changes.');
        $prepared=[];$seen=[];
        foreach($changes as $change){$change=self::Object($change);self::Keys($change,['key','value','revision']);$key=self::Key($change['key']??null);self::Authorize($key);
            if(isset($seen[$key])||!is_int($change['revision']??null)||$change['revision']<0||$change['revision']>2147483646)throw new InvalidArgumentException('Reload your workspace settings.');$seen[$key]=true;
            $prepared[]=['key'=>$key,'value'=>self::Validate($key,self::Object($change['value']??null)),'revision'=>$change['revision']];
        }
        $result=['documents'=>[],'storage_ready'=>true];$db->transBegin();
        try{
            foreach($prepared as $change){$json=json_encode($change['value'],JSON_THROW_ON_ERROR);$revision=$change['revision'];
                $r=$revision===0?$db->query('INSERT INTO user_workspace_state(user_id,state_key,value,revision) VALUES(?,?,?::jsonb,1) ON CONFLICT(user_id,state_key) DO NOTHING RETURNING revision',[$actor,$change['key'],$json])->getUnbufferedRow('array')
                    :$db->query('UPDATE user_workspace_state SET value=?::jsonb,revision=revision+1,updated_at=NOW() WHERE user_id=? AND state_key=? AND revision=? RETURNING revision',[$json,$actor,$change['key'],$revision])->getUnbufferedRow('array');
                if(!$r)throw new DomainException('These settings changed in another tab. Reload saved settings and try again.');
                $result['documents'][$change['key']]=['value'=>$change['value'],'revision'=>(int)$r['revision']];
            }
            if(isset($request['ui']))$result['ui']=UserUiPreferences::Save($db,$actor,self::Object($request['ui']));
            $db->transCommit();return $result;
        }catch(Throwable $e){$db->transRollback();throw $e;}
    }
}
