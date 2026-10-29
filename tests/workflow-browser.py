"""Audit integration and browser checks in a disposable schema, account and Edge profile."""
import base64
import csv
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import socketserver
import threading
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
sys.stdout.reconfigure(encoding='utf-8', errors='backslashreplace')
spec = importlib.util.spec_from_file_location('browser_helpers', ROOT / 'tests/email-auth-browser.py')
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)
PHP, EDGE, Browser = helpers.PHP, helpers.EDGE, helpers.Browser
CHECKS = 0


def check(value, label):
    global CHECKS
    CHECKS += 1
    if not value:
        raise AssertionError(label)


def main():
    temp = Path(tempfile.mkdtemp(prefix='.tmp-workflow-browser-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    downloads = temp / 'downloads'
    downloads.mkdir()
    server_port, debug_port = helpers.port(), helpers.port()
    base = f'http://127.0.0.1:{server_port}/AnalystToolkit'
    smtp_port = helpers.port()
    smtp = socketserver.ThreadingTCPServer(('127.0.0.1', smtp_port), helpers.SMTP)
    smtp.daemon_threads = True
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    schema = 'workflow_browser_' + os.urandom(6).hex()
    (temp / 'fixture.json').write_text(json.dumps({'root': str(ROOT).replace('\\', '/'), 'schema': schema, 'url':base, 'smtp_port':smtp_port}))
    router = temp / 'router.php'
    router.write_text(r'''<?php
$f=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);$schema=$f['schema'];
if(!preg_match('/^workflow_browser_[a-f0-9]{12}$/D',$schema))exit;
if(PHP_SAPI==='cli')session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once $f['root'].'/lib/Logger.php';$p=new ReflectionProperty(Logger::class,'directory');$p->setAccessible(true);$p->setValue(null,__DIR__.'/logs');
require_once $f['root'].'/config/Database.php';$db=Database::getConnection('app');
if(PHP_SAPI==='cli'){
 set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n");exit(1);});
 $action=$argv[1]??'';
 if($action==='cleanup'){$db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');exit;}
 if($action==='setup'){
  $db->query(str_replace('toolkit',$schema,file_get_contents($f['root'].'/database/schema.sql')));$db->query('SET search_path TO "'.$schema.'",public');
  $db->query('DELETE FROM users');
  $db->query("INSERT INTO users(employee_id,password_hash,first_name,last_name,email,must_change_password,password_changed_at)VALUES('viewer',?,'=2+3','Reviewer','fixture@example.invalid',FALSE,NOW())", [password_hash('AuditFixture123!',PASSWORD_BCRYPT)]);
  $actor=(int)Database::Scalar($db->query('SELECT MAX(user_id) FROM users'));$db->query("UPDATE users SET role_key='admin' WHERE user_id=".$actor);
  $db->query("INSERT INTO servers(environment,server_name,ip_address)VALUES('Dev','Disposable server','127.0.0.1')");$server=(int)Database::Scalar($db->query('SELECT MAX(server_id) FROM servers'));
  $db->query("INSERT INTO dd_tables(source_database,schema_name,table_name)VALUES('Fixture','app','customers')");$table=(int)Database::Scalar($db->query('SELECT MAX(table_id) FROM dd_tables'));
  $db->query("INSERT INTO dd_columns(table_id,column_name,ordinal_position,data_type,is_primary_key)VALUES(?,'id',1,'integer',TRUE)", [$table]);
  $people=['admin'=>$actor];
  $stmtSql="INSERT INTO users(employee_id,password_hash,first_name,last_name,email,role_key,must_change_password,password_changed_at)VALUES(?,? ,?,'Fixture',?,?,FALSE,NOW()) RETURNING user_id";
  foreach(['analyst','reviewer','viewer'] as $role){$stmt = $db->query($stmtSql, [$role.'-role',password_hash('AuditFixture123!',PASSWORD_BCRYPT),ucfirst($role),$role.'@example.invalid',$role]);$people[$role]=(int)Database::Scalar($stmt);}
  $db->query("UPDATE system_settings SET smtp_host='127.0.0.1',smtp_port=?,smtp_encryption='none',smtp_from_email='fixture@example.invalid',auth_base_url=? WHERE id=1", [$f['smtp_port'],$f['url']]);
  $db->query("INSERT INTO expiry_items(item_type,item_name,server_id,expiry_date)VALUES('SSL Certificate','Fixture renewal',?,CURRENT_DATE+14)", [$server]);
  echo json_encode(compact('actor','server','table','people'));exit;
 }
 $db->query('SET search_path TO "'.$schema.'",public');
 if($action==='worker'){require_once $f['root'].'/lib/Notifications.php';echo json_encode(Notifications::generate($db,true)+Notifications::deliver($db));exit;}
 if($action==='revoke_dictionary'){$db->query("UPDATE role_permissions SET is_allowed=FALSE WHERE role_key='viewer' AND module='datadict' AND permission='view'");echo '{}';exit;}
 if($action==='snapshot'){echo json_encode(['events'=>$db->query('SELECT * FROM audit_events ORDER BY event_id')->getResultArray(),'changes'=>$db->query('SELECT * FROM audit_changes ORDER BY change_id')->getResultArray(),'runs'=>$db->query('SELECT * FROM review_runs ORDER BY review_id')->getResultArray(),'findings'=>$db->query('SELECT * FROM review_findings ORDER BY finding_id')->getResultArray(),'notifications'=>$db->query('SELECT * FROM notifications ORDER BY notification_id')->getResultArray(),'deliveries'=>$db->query('SELECT * FROM notification_deliveries ORDER BY delivery_id')->getResultArray()]);exit;}
 if($action==='remove_actor'){$db->query("DELETE FROM users WHERE employee_id='viewer'");echo '{}';exit;}
 exit;
}
$db->query('SET search_path TO "'.$schema.'",public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if(str_starts_with($relative,'assets/')){$file=realpath($f['root'].'/'.$relative);$assets=realpath($f['root'].'/assets');if($file&&str_starts_with(strtolower($file),strtolower($assets.DIRECTORY_SEPARATOR))&&is_file($file)){header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;}}
$allowed=['index.php','login.php','logout.php','change_password.php','auth_email_preview.php'];
if(!in_array($relative,$allowed,true)&&!preg_match('#^modules/(applications|notifications|permissions|audit|bookmarks|directory|organization|usermgmt|settings|servers|requirements|datadict|diagrams|codereview|proginventory|logs|account|updates)/[a-z_]+\.php$#D',$relative)){http_response_code(404);exit;}
$_SERVER['SCRIPT_NAME']='/AnalystToolkit/'.$relative;$file=$f['root'].'/'.$relative;if(!is_file($file)){http_response_code(404);exit;}require $file;
''', encoding='utf-8')
    processes, browser = [], None
    log = open(temp / 'server.log', 'wb')

    def cli(action):
        result = subprocess.run([str(PHP), str(router), action], capture_output=True, timeout=30, cwd=ROOT, creationflags=subprocess.CREATE_NO_WINDOW)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace'))
        return json.loads(result.stdout) if result.stdout.strip() else None

    def go(route, ready="document.readyState==='complete'"):
        browser.go(base + route)
        browser.wait("document.readyState==='complete'&&(" + ready + ')')
        if browser.js("!!document.getElementById('updatesDialog')"):
            notice = api('/modules/updates/api.php?action=notice')
            if notice['status'] == 200 and notice['body']['pending']:
                browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
                click('#updatesDone')
                browser.wait("!document.getElementById('updatesDialog').open")

    def click(selector):
        browser.js('document.querySelector(' + json.dumps(selector) + ').click()')

    def value(id, text, event='input'):
        browser.js('document.getElementById(' + json.dumps(id) + ').value=' + json.dumps(str(text)) + ';document.getElementById(' + json.dumps(id) + ').dispatchEvent(new Event(' + json.dumps(event) + ',{bubbles:true}))')

    def api(route, body=None, csrf=True):
        return browser.js('fetch(' + json.dumps(base + route) + ',{method:' + json.dumps('POST' if body is not None else 'GET') + ",headers:{'Content-Type':'application/json'" + (",'X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||document.querySelector('[name=csrf_token]')?.value||''" if csrf else '') + '}' + (',body:JSON.stringify(' + json.dumps(body) + ')' if body is not None else '') + '}).then(async r=>({status:r.status,body:await r.json()}))')

    def history(**filters):
        from urllib.parse import urlencode
        return api('/modules/audit/api.php?action=history&' + urlencode(filters))['body']

    def snapshot():
        # The server's shutdown hook may finish after fetch has consumed its response.
        time.sleep(.15)
        return cli('snapshot')

    def fit(label):
        for width in [320, 390, 768, 1024, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), label + f' fits {width}px')
        check(browser.js('fixtureErrors.length===0&&!window.fixtureXss'), label + ' has no script errors or executed markup')
        if os.environ.get('WORKFLOW_SCREENSHOTS') == '1':
            previous = browser.js('AT.getTheme()')
            for theme in ['light','dark']:
                browser.js('AT.setTheme(' + json.dumps(theme) + ')')
                for width in [390,1366]:
                    browser.call('Emulation.setDeviceMetricsOverride', {'width':width,'height':1000,'deviceScaleFactor':1,'mobile':width<600})
                    browser.js('new Promise(resolve=>setTimeout(resolve,200))')
                    name = label.lower().replace(' ','-')
                    (ROOT / f'.tmp-workflow-{name}-{theme}-{width}.png').write_bytes(base64.b64decode(browser.call('Page.captureScreenshot', {'format':'png','captureBeyondViewport':False})['data']))
            browser.js('AT.setTheme(' + json.dumps(previous) + ')')

    def login(employee):
        old_cookies = browser.call('Network.getCookies', {'urls':[base]})['cookies']
        old_session = next((c['value'] for c in old_cookies if c['name']=='PHPSESSID'),None)
        go('/logout.php', "!!document.getElementById('loginForm')")
        if old_session:
            late = urllib.request.Request(base + '/modules/audit/client.php',data=json.dumps({'page':'/AnalystToolkit/index.php','events':[]}).encode(),headers={'Content-Type':'application/json','Cookie':'PHPSESSID=' + old_session,'X-CSRF-Token':'expired-fixture-token'})
            try:
                response = urllib.request.urlopen(late)
            except urllib.error.HTTPError as error:
                response = error
            check(response.status==401 and not response.headers.get('Set-Cookie'), 'Late activity cannot set or restore an expired login cookie')
            response.close()
        value('loginEmployeeId', employee)
        value('loginPassword', 'AuditFixture123!')
        browser.js("document.getElementById('loginForm').requestSubmit()")
        browser.wait("!!document.getElementById('appTopbar')")

    try:
        fixture = cli('setup')
        server = subprocess.Popen([str(PHP), '-d', f'session.save_path={temp}', '-d', f'sys_temp_dir={temp}', '-S', f'127.0.0.1:{server_port}', str(router)], cwd=ROOT, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(server)
        until = time.time() + 15
        while time.time() < until:
            try:
                urllib.request.urlopen(base + '/login.php', timeout=1).close()
                break
            except OSError:
                time.sleep(.1)
        edge = subprocess.Popen([str(EDGE), '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--remote-allow-origins=*', '--user-data-dir=' + str(temp / 'edge'), f'--remote-debugging-port={debug_port}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(edge)
        until = time.time() + 20
        while time.time() < until:
            try:
                pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{debug_port}/json/list', timeout=1))
                if pages:
                    break
            except OSError:
                time.sleep(.1)
        browser = Browser(next(p['webSocketDebuggerUrl'] for p in pages if p['type'] == 'page'))
        browser.call('Page.setDownloadBehavior', {'behavior': 'allow', 'downloadPath': str(downloads)})
        go('/login.php', "!!document.getElementById('loginForm')")
        for module in ['applications','permissions','notifications']:
            check(api('/modules/' + module + '/api.php', csrf=False)['status'] == 401, 'Guest cannot access ' + module)
        login('viewer')
        go('/modules/applications/index.php', "document.getElementById('applicationRows').getAttribute('aria-busy')==='false'")
        check(browser.js("!!document.querySelector('#sidebarNav a[href$=\"/permissions/\"]')"), 'Administrator sees role management')
        click('#applicationAdd')
        value('ap_application_code', 'PORTAL')
        value('ap_application_name', 'Portal <img src=x onerror=window.fixtureXss=1>')
        value('ap_owner_user_id', fixture['people']['analyst'], 'change')
        value('ap_technical_owner_id', fixture['people']['reviewer'], 'change')
        browser.js("document.getElementById('applicationForm').requestSubmit()")
        browser.wait("!document.getElementById('applicationEditor').open&&document.getElementById('appStat_total').textContent==='1'")
        app = int(api('/modules/applications/api.php?action=list_applications')['body']['applications'][0]['application_id'])
        click('[data-open-application]')
        browser.wait("document.getElementById('applicationDetail').open&&document.getElementById('applicationDetailTitle').textContent.includes('Portal')")
        value('applicationAssetType', 'server', 'change')
        browser.wait("document.getElementById('applicationAsset').options.length>1")
        value('applicationAsset', fixture['server'], 'change')
        click('#applicationLink')
        browser.wait("!!document.querySelector('#applicationAssets [data-unlink]')")
        check(not browser.js("!!window.fixtureXss"), 'Catalog titles are escaped')
        check(len(api('/modules/applications/api.php?action=get_application&application_id=' + str(app))['body']['assets']) == 1, 'Catalog links an existing inventory asset')
        browser.js("document.getElementById('applicationDetail').close()")
        check(api('/modules/applications/api.php?action=save_application', {'application_code':'PORTAL','application_name':'Duplicate'})['status'] == 409, 'Duplicate application code is rejected')
        check(api('/modules/applications/api.php?action=save_application', {'application_code':'BAD','application_name':'Bad','application_url':'javascript:bad()'})['status'] == 422, 'Unsafe application URL is rejected')
        fit('Application catalog')
        go('/modules/usermgmt/index.php', "document.getElementById('userCount_all').textContent==='4'")
        click('[data-edit-user="' + str(fixture['people']['viewer']) + '"]')
        browser.wait("!document.getElementById('userFields').disabled")
        check(browser.js("document.getElementById('us_role_key').value==='viewer'"), 'User editor shows the assigned role')
        browser.js("document.getElementById('userEditor').close()")
        me = api('/modules/usermgmt/api.php?action=get_user&user_id=' + str(fixture['actor']))['body']
        me.update({'role_key':'viewer'})
        check(api('/modules/usermgmt/api.php?action=save_user', me)['status'] == 422, 'Last active Administrator cannot be demoted through the API')
        go('/modules/permissions/', "!!document.querySelector('#permissionRows input')")
        value('permissionRole', 'viewer', 'change')
        browser.js("document.querySelector('#permissionRows input[data-module=datadict][data-permission-key=export]').checked=true")
        click('#permissionSave')
        browser.wait("!document.getElementById('permissionSave').disabled")
        check(any(p['role_key'] == 'viewer' and p['module'] == 'datadict' and p['permission'] == 'export' and p['is_allowed'] for p in api('/modules/permissions/api.php?action=matrix')['body']['permissions']), 'Role matrix saves a specific permission')
        value('permissionRole', 'admin', 'change')
        check(browser.js("document.getElementById('permissionSave').disabled&&[...document.querySelectorAll('#permissionRows input')].every(e=>e.disabled&&e.checked)"), 'Administrator permissions are protected from removal')
        fit('Permission matrix')

        # Custom roles: native dialogs, assignment, effective access and protected administration.
        go('/modules/permissions/', "document.getElementById('permissionRole').options.length===6")
        click('#roleAdd')
        value('roleEditorName', 'Support specialist')
        check(browser.js("document.getElementById('roleEditorKey').value==='support_specialist'"), 'Role key is suggested from its display name')
        value('roleEditorDescription', 'Responsible for dictionary documentation.')
        browser.js("document.getElementById('roleForm').requestSubmit()")
        browser.wait("!document.getElementById('roleEditor').open&&document.getElementById('permissionRole').value==='support_specialist'")
        check(browser.js("document.getElementById('roleKind').textContent==='Custom role'&&!document.getElementById('roleEdit').hidden"), 'New custom role is selected and editable')
        check(browser.js("!document.querySelector('#permissionRows input[data-module=datadict][data-permission-key=view]').checked"), 'New roles start with minimal business access')
        browser.js("for(const [module,permission] of [['datadict','view'],['usermgmt','view'],['usermgmt','edit']]){const box=document.querySelector('#permissionRows input[data-module='+module+'][data-permission-key='+permission+']');box.checked=true;box.dispatchEvent(new Event('change',{bubbles:true}));}")
        check(browser.js("document.getElementById('permissionStatus').textContent.includes('Unsaved')"), 'Permission changes have a visible unsaved state')
        click('#permissionSave')
        browser.wait("!document.getElementById('permissionSave').disabled&&document.getElementById('permissionStatus').textContent===''")
        click('#roleEdit')
        value('roleEditorName', 'Service operations')
        check(browser.js("document.getElementById('roleEditorKey').readOnly"), 'Existing role keys stay fixed')
        browser.js("document.getElementById('roleForm').requestSubmit()")
        browser.wait("!document.getElementById('roleEditor').open&&document.getElementById('roleName').textContent==='Service operations'")
        fit('Custom roles')
        click('#roleCopy')
        check(browser.js("document.getElementById('roleEditorSource').value==='support_specialist'&&!Array.from(document.getElementById('roleEditorSource').options).some(o=>o.value==='admin')"), 'Copy dialog selects the source and excludes Administrator')
        value('roleEditorName', 'Temporary support')
        browser.js("document.getElementById('roleForm').requestSubmit()")
        browser.wait("!document.getElementById('roleEditor').open&&document.getElementById('permissionRole').value==='temporary_support'")
        check(browser.js("document.querySelector('#permissionRows input[data-module=datadict][data-permission-key=view]').checked"), 'Copied role includes configured permissions')
        click('#roleDelete')
        browser.wait("!!document.querySelector('.swal2-popup:not(.swal2-toast) .swal2-confirm')")
        click('.swal2-popup:not(.swal2-toast) .swal2-confirm')
        browser.wait("!Array.from(document.getElementById('permissionRole').options).some(o=>o.value==='temporary_support')")
        check(api('/modules/permissions/api.php?action=create_role', {'role_key':'support_specialist','role_name':'Duplicate'})['status'] == 409, 'Duplicate custom role keys return a useful conflict')
        check(api('/modules/permissions/api.php?action=delete_role', {'role_key':'analyst'})['status'] == 422, 'Preset deletion is refused by the API')
        check(api('/modules/permissions/api.php?action=create_role', {'role_key':'bad','role_name':'Bad','copy_from':'admin'})['status'] == 422, 'Administrator permissions cannot be cloned through the API')
        check(api('/modules/permissions/api.php?action=create_role', {'role_key':'no_csrf','role_name':'No CSRF'}, csrf=False)['status'] == 419, 'Custom role creation requires CSRF protection')
        go('/modules/usermgmt/index.php', "document.getElementById('userCount_all').textContent==='4'")
        click('[data-edit-user="' + str(fixture['people']['viewer']) + '"]')
        browser.wait("!document.getElementById('userFields').disabled")
        check(browser.js("Array.from(document.getElementById('us_role_key').options).some(o=>o.value==='support_specialist'&&o.textContent==='Service operations')"), 'Custom display names appear in the user assignment dropdown')
        value('us_role_key', 'support_specialist', 'change')
        browser.js("document.getElementById('userForm').requestSubmit()")
        browser.wait("!document.getElementById('userEditor').open&&document.getElementById('userRows').textContent.includes('Service operations')")
        check(api('/modules/permissions/api.php?action=delete_role', {'role_key':'support_specialist'})['status'] == 409, 'Assigned roles cannot be deleted')
        login('viewer-role')
        go('/modules/datadict/index.php', "!!document.getElementById('ddTableList')")
        check(browser.js("AT_ACCESS.role==='support_specialist'&&AT.can('view','datadict')&&!AT.can('edit','datadict')"), 'Assigned custom role controls the workspace permission matrix')
        check(browser.js("document.getElementById('userMenuDropdown').textContent.includes('Service operations')"), 'Account menu shows the custom role display name')
        check(api('/modules/datadict/api.php?action=list_tables')['status'] == 200, 'Custom role can access an explicitly allowed module')
        check(api('/modules/applications/api.php?action=list_applications')['status'] == 403, 'Custom role cannot access an ungranted business module')
        check(api('/modules/permissions/api.php?action=matrix')['status'] == 403, 'Custom role cannot read the administration matrix')
        check(api('/modules/permissions/api.php?action=create_role', {'role_key':'forged','role_name':'Forged'})['status'] == 403, 'Custom role cannot create roles through a forged request')
        own = api('/modules/usermgmt/api.php?action=get_user&user_id=' + str(fixture['people']['viewer']))['body']
        own.update({'role_key':'admin'})
        check(api('/modules/usermgmt/api.php?action=save_user', own)['status'] == 422, 'A custom role with user edit rights still cannot promote itself')
        own.update({'role_key':'analyst'})
        check(api('/modules/usermgmt/api.php?action=save_user', own)['status'] == 422, 'Non-administrators cannot change a custom role assignment')
        login('viewer')
        go('/modules/permissions/', "document.getElementById('permissionRole').options.length===7")
        support = next(r for r in api('/modules/permissions/api.php?action=matrix')['body']['roles'] if r['role_key']=='support_specialist')
        check(support['user_count'] == 1, 'Role catalog reports current assigned user count')
        own = api('/modules/usermgmt/api.php?action=get_user&user_id=' + str(fixture['people']['viewer']))['body']
        own.update({'role_key':'viewer'})
        check(api('/modules/usermgmt/api.php?action=save_user', own)['status'] == 200, 'Administrator can reassign a custom-role user to a preset')
        check(api('/modules/permissions/api.php?action=delete_role', {'role_key':'support_specialist'})['status'] == 200, 'Unassigned custom role can be deleted')
        check(any(c['table_name']=='app_roles' and c['record_id']=='support_specialist' for c in snapshot()['changes']), 'Role lifecycle changes are captured in audit history')

        matrix=api('/modules/permissions/api.php?action=matrix')['body']
        check(not any(p['is_allowed'] for p in matrix['permissions'] if p['role_key'] in ['analyst','reviewer','viewer'] and p['module'] in ['applications','requirements']), 'Applications and Requirements are excluded from preset roles by default')
        for role in ['analyst','reviewer','viewer']:
            check(api('/modules/permissions/api.php?action=save_permissions', {'role_key':role,'grants':[{'module':module,'permission':'view','is_allowed':True} for module in ['applications','requirements']]})['status']==200, 'Administrator can explicitly enable catalog and requirements viewing for '+role)

        login('analyst-role')
        for route in ['/modules/usermgmt/api.php?action=list_users','/modules/settings/api.php?action=get_settings','/modules/audit/api.php?action=history','/modules/permissions/api.php?action=matrix','/modules/servers/api.php?action=reveal_credential&credential_id=1']:
            check(api(route)['status'] == 403, 'Analyst cannot access ' + route)
        go('/modules/codereview/index.php', "document.getElementById('crApplication').options.length===2")
        check(browser.js("document.querySelectorAll('[data-engine]').length===5"), 'OWASP appears as the fifth review engine')
        click('[data-engine=owasp]')
        value('crApplication', app, 'change')
        source = '<?php $password="RAW_SECRET_SENTINEL"; echo $_GET["name"]; eval($_POST["code"]);'
        browser.js("(()=>{const d=new DataTransfer(),f=new File([" + json.dumps(source) + "],'sample.php');Object.defineProperty(f,'webkitRelativePath',{value:'Fixture/sample.php'});d.items.add(f);document.getElementById('crFolder').files=d.files;document.getElementById('crFolder').dispatchEvent(new Event('change',{bubbles:true}));})()")
        click('#crScanBtn')
        browser.wait("!!document.querySelector('.wf-coverage')&&!document.getElementById('crSavedReview').hidden")
        review = int(browser.js('CR.report.review_id'))
        check(browser.js("document.querySelectorAll('.wf-coverage article').length===10&&document.querySelector('.wf-coverage').textContent.includes('Manual review required')"), 'OWASP coverage shows all categories and explicit manual checks')
        check(browser.js("CR.report.finding_count>=3&&CR.report.files[0].findings.every(f=>f.remediation||f.rule==='PHP-PARSE')"), 'OWASP findings include remediation')
        check(api('/modules/codereview/history_api.php?action=assign_review', {'review_id':review,'assigned_to':fixture['people']['reviewer'],'due_at':'2026-10-24'})['status'] == 403, 'Analyst cannot bypass reviewer assignment permissions')
        click('#crExportXlsx')
        until = time.time() + 15
        while time.time() < until and not list(downloads.glob('*.xlsx')):
            time.sleep(.1)
        import zipfile
        with zipfile.ZipFile(next(downloads.glob('*.xlsx'))) as archive:
            shared = archive.read('xl/sharedStrings.xml').decode()
            check('OWASP category' in shared and 'Suggested action' in shared and 'RAW_SECRET_SENTINEL' not in shared, 'OWASP workbook includes categories and guidance without source secrets')
            check('OWASP Coverage' in archive.read('xl/workbook.xml').decode(), 'OWASP workbook has the coverage checklist')
        fit('OWASP review')
        login('viewer')
        go('/modules/codereview/history.php?review_id=' + str(review), "!!document.querySelector('.wf-details')")
        browser.wait("document.getElementById('reviewAssignee').options.length===3")
        value('reviewAssignee', fixture['people']['reviewer'], 'change')
        yesterday = browser.js("new Date(Date.now()-86400000).toLocaleDateString('en-CA',{timeZone:'Asia/Manila'})")
        value('reviewDue', yesterday, 'change')
        browser.js("document.getElementById('reviewAssignForm').requestSubmit()")
        browser.wait("document.getElementById('reviewDetailMeta').textContent.includes('Reviewer Fixture')")
        check(any(n['kind'] == 'review_assignment' and int(n['user_id']) == fixture['people']['reviewer'] for n in snapshot()['notifications']), 'Assignment creates a personal notification')
        browser.js("document.getElementById('reviewDetailDialog').close()")
        go('/modules/notifications/index.php', "document.getElementById('notificationDays').value!==''")
        check(not browser.js("document.getElementById('notificationGlobalEmail').checked"), 'Reminder email starts disabled')
        browser.js("document.getElementById('notificationGlobalEmail').checked=true;document.getElementById('notificationConfig').requestSubmit()")
        browser.wait("!document.getElementById('notificationConfigSave').disabled")
        check(api('/modules/notifications/api.php?action=config')['body']['email_enabled'], 'Administrator can enable reminder email with configured SMTP')
        result = cli('worker')
        check(result['sent'] >= 3 and len(helpers.MESSAGES) >= 3, 'Scheduled worker delivers owner and overdue-review emails through loopback SMTP')
        check(all('RAW_SECRET_SENTINEL' not in str(m) and 'http://127.0.0.1:' in str(m) for m in helpers.MESSAGES), 'Emails contain safe messages and the configured application URL')
        check(all(d['status'] == 'sent' for d in snapshot()['deliveries']), 'Successful delivery outcomes are persisted')
        fit('Notifications')
        login('reviewer-role')
        go('/modules/notifications/index.php', "document.getElementById('notificationRows').getAttribute('aria-busy')==='false'")
        check(browser.js("!document.getElementById('notificationConfig')&&document.querySelectorAll('.wf-notice').length>=3"), 'Reviewer sees a personal inbox without delivery administration')
        check(api('/modules/notifications/api.php?action=config')['status'] == 403, 'Reviewer cannot read delivery administration through the API')
        renewal_link = browser.js("[...document.querySelectorAll('[data-notification-open]')].find(a=>a.href.includes('renewal_id='))?.getAttribute('href')")
        check(bool(renewal_link), 'Renewal notification contains a record link')
        go(renewal_link.replace('/AnalystToolkit','',1), "document.getElementById('renewalSearch').value==='Fixture renewal'")
        check(browser.js("!document.getElementById('inventoryRenewals').hidden&&document.getElementById('inventoryRenewalRows').textContent.includes('Fixture renewal')"), 'Renewal link opens the matching inventory view')
        go('/modules/notifications/index.php', "document.getElementById('notificationRows').getAttribute('aria-busy')==='false'")
        other = next(n for n in snapshot()['notifications'] if int(n['user_id']) == fixture['people']['analyst'])
        api('/modules/notifications/api.php?action=mark_read', {'notification_id':other['notification_id']})
        check(not next(n for n in snapshot()['notifications'] if n['notification_id'] == other['notification_id'])['is_read'], 'Mark-read cannot alter another user’s inbox')
        click('[data-mark-read]')
        browser.wait("!!document.querySelector('.wf-notice .ws-tag')")
        check(any(n['is_read'] for n in snapshot()['notifications'] if int(n['user_id']) == fixture['people']['reviewer']), 'Personal mark-read works')
        go('/modules/codereview/history.php?review_id=' + str(review), "!!document.querySelector('[data-finding]')")
        check(browser.js("document.getElementById('reviewDetailsForm').closest('details').hidden"), 'Reviewer cannot edit title/application without edit permission')
        click('[data-finding]')
        value('findingStatus', 'Accepted', 'change')
        value('findingNote', 'Verified fixture exception')
        browser.js("document.getElementById('findingForm').requestSubmit()")
        browser.wait("!document.getElementById('findingEditor').open")
        check(api('/modules/codereview/history_api.php?action=complete_review', {'review_id':review,'note':'Reviewed'})['status'] == 422, 'Open findings block review completion')
        findings = api('/modules/codereview/history_api.php?action=findings&review_id=' + str(review))['body']['findings']
        for f in findings:
            if f['status'] != 'Accepted':
                check(api('/modules/codereview/history_api.php?action=findings_update', {'review_id':review,'finding_id':f['finding_id'],'status':'Accepted','note':'Reviewed exception','expected_updated_at':f['updated_at']})['status'] == 200, 'Reviewer records a finding disposition')
        check(api('/modules/codereview/history_api.php?action=complete_review', {'review_id':review,'note':'Manual scope and exceptions reviewed.'})['status'] == 200, 'A resolved review can be completed with a decision note')
        check(api('/modules/codereview/history_api.php?action=detail&review_id=' + str(review))['body']['completed_at'], 'Completion is persisted')
        fit('Review details')
        login('analyst-role')
        go('/modules/proginventory/index.php', "document.getElementById('pi_application_id').options.length===2")
        exported = browser.js("fetch(" + json.dumps(base + '/modules/proginventory/export_client.php') + ",{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({application_id:" + str(app) + ",system_affected:'Portal',file_version:'2.0',files:[{name:'index.php',relative_path:'Portal/index.php',size:1,last_modified_ms:0}]})}).then(async r=>({status:r.status,size:(await r.arrayBuffer()).byteLength}))")
        check(exported['status'] == 200 and exported['size'] > 500, 'Inventory export can be linked to an application')
        catalog = api('/modules/applications/api.php?action=get_application&application_id=' + str(app))['body']
        check(len(catalog['reviews']) == 1 and len(catalog['inventory_runs']) == 1 and catalog['inventory_runs'][0]['file_version'] == '2.0', 'Application details connect review and inventory history')
        check('RAW_SECRET_SENTINEL' not in json.dumps(snapshot()['runs'] + snapshot()['findings']), 'Saved metadata and findings exclude raw source values')
        login('viewer-role')
        go('/modules/applications/index.php', "document.getElementById('applicationRows').getAttribute('aria-busy')==='false'")
        check(browser.js("document.getElementById('applicationAdd').hidden&&!document.querySelector('#sidebarNav a[href$=\"/permissions/\"]')"), 'Viewer navigation and application controls reflect permissions')
        check(api('/modules/applications/api.php?action=save_application', {'application_code':'NO','application_name':'No'})['status'] == 403, 'Viewer cannot create catalog entries through a forged request')
        check(api('/modules/codereview/api.php?action=scan', {'mode':'client','engine':'owasp','files':[]})['status'] == 403, 'Viewer cannot start a scan through the API')
        go('/modules/codereview/index.php', "!!document.getElementById('crScanBtn')")
        check(browser.js("document.getElementById('crScanBtn').hidden&&document.getElementById('crExportXlsx').hidden"), 'Viewer scan and export buttons are hidden')
        check(browser.js("AT.can('export','datadict')"), 'Customized Viewer permission is reflected in the browser')
        go('/modules/bookmarks/index.php', "document.getElementById('bookmarkGrid').getAttribute('aria-busy')==='false'")
        check(browser.js("!!document.getElementById('appTopbar')&&!document.getElementById('bookmarkForm')&&fixtureErrors.length===0"), 'Signed-in Viewer can use public Quick Links inside the workspace')
        go('/modules/directory/index.php', "!!document.getElementById('directoryApp')")
        check(browser.js("!!document.getElementById('appTopbar')&&fixtureErrors.length===0"), 'Signed-in Viewer keeps the workspace layout in Call Directory')
        cli('revoke_dictionary')
        check(api('/modules/datadict/api.php?action=list_tables')['status'] == 403, 'Revoking module view applies on the next request')
        check(api('/modules/requirements/api.php?action=list_assets&type=table')['status'] == 403, 'Related-asset lookups respect the source module permission')
        go('/logout.php', "!!document.getElementById('loginForm')")
        check(api('/modules/bookmarks/api.php?action=list_bookmarks', csrf=False)['status'] == 200 and api('/modules/directory/api.php?action=list_departments', csrf=False)['status'] == 200, 'Public directory and Quick Links remain available to guests')
        log.flush()
        output = (temp / 'server.log').read_text(errors='replace')
        check(not re.search(r'(?:PHP )?(?:Warning|Fatal error|Notice):', output), 'Workflow requests produce no PHP warnings or fatal errors')
        print(f'Workflow / OWASP browser / HTTP: {CHECKS} assertions passed; isolated schemas and loopback SMTP only.')
    except Exception:
        if browser:
            print('Workflow diagnostics:', browser.js("JSON.stringify({url:location.pathname,errors:window.fixtureErrors,toasts:[...document.querySelectorAll('.toast')].map(e=>e.textContent),alerts:[...document.querySelectorAll('[role=alert]:not([hidden])')].map(e=>e.textContent),dialogs:[...document.querySelectorAll('dialog[open]')].map(e=>e.id)})"))
        raise
    finally:
        if browser:
            browser.sock.close()
        for process in reversed(processes):
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
        smtp.shutdown()
        smtp.server_close()
        log.close()
        try:
            cli('cleanup')
        finally:
            if temp.parent == ROOT / 'tests' and temp.name.startswith('.tmp-workflow-browser-'):
                shutil.rmtree(temp, ignore_errors=True)


if __name__ == '__main__':
    main()
