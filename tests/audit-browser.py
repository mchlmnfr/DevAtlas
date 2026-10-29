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
    temp = Path(tempfile.mkdtemp(prefix='.tmp-audit-browser-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    downloads = temp / 'downloads'
    downloads.mkdir()
    server_port, debug_port = helpers.port(), helpers.port()
    base = f'http://127.0.0.1:{server_port}/AnalystToolkit'
    schema = 'audit_browser_' + os.urandom(6).hex()
    (temp / 'fixture.json').write_text(json.dumps({'root': str(ROOT).replace('\\', '/'), 'schema': schema}))
    router = temp / 'router.php'
    router.write_text(r'''<?php
$f=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);$schema=$f['schema'];
if(!preg_match('/^audit_browser_[a-f0-9]{12}$/D',$schema))exit;
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
  echo json_encode(compact('actor','server','table'));exit;
 }
 $db->query('SET search_path TO "'.$schema.'",public');
 if($action==='snapshot'){echo json_encode(['events'=>$db->query('SELECT * FROM audit_events ORDER BY event_id')->getResultArray(),'changes'=>$db->query('SELECT * FROM audit_changes ORDER BY change_id')->getResultArray()]);exit;}
 if($action==='remove_actor'){$db->query("DELETE FROM users WHERE employee_id='viewer'");echo '{}';exit;}
 exit;
}
$db->query('SET search_path TO "'.$schema.'",public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if(str_starts_with($relative,'assets/')){$file=realpath($f['root'].'/'.$relative);$assets=realpath($f['root'].'/assets');if($file&&str_starts_with(strtolower($file),strtolower($assets.DIRECTORY_SEPARATOR))&&is_file($file)){header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;}}
$allowed=['index.php','login.php','logout.php','change_password.php','auth_email_preview.php'];
if(!in_array($relative,$allowed,true)&&!preg_match('#^modules/(applications|notifications|permissions|audit|bookmarks|directory|organization|usermgmt|settings|servers|requirements|datadict|diagrams|codereview|proginventory|logs|account)/[a-z_]+\.php$#D',$relative)){http_response_code(404);exit;}
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
        check(api('/modules/audit/api.php?action=history', csrf=False)['status'] == 401, 'Guests cannot read audit history')
        check(api('/modules/audit/client.php', {'page': '/AnalystToolkit/index.php', 'events': []}, csrf=False)['status'] == 401, 'Guests cannot submit browser reports')
        value('loginEmployeeId', 'viewer')
        value('loginPassword', 'WrongPasswordSentinel')
        browser.js("document.getElementById('loginForm').requestSubmit()")
        browser.wait("!!document.getElementById('loginError')")
        check(any(e['action'] == 'signin_password' and e['outcome'] == 'failed' and int(e['http_status']) == 200 for e in snapshot()['events']), 'Failed native login is audited even when its form returns HTTP 200')
        value('loginPassword', 'AuditFixture123!')
        browser.js("document.getElementById('loginForm').requestSubmit()")
        browser.wait("!!document.getElementById('appTopbar')")
        signed = [e for e in snapshot()['events'] if e['action'] == 'signin_password' and e['outcome'] == 'success']
        check(signed and int(signed[-1]['actor_id']) == fixture['actor'], 'Successful login records the signed-in actor')
        for module, action in [('organization','tree'),('usermgmt','list_users'),('settings','get_settings'),('servers','list_servers'),('directory','list_departments'),('bookmarks','list_bookmarks'),('requirements','list_requirements'),('datadict','list_tables'),('diagrams','list_diagrams'),('codereview','server_projects'),('logs','list_files')]:
            check(api('/modules/' + module + '/api.php?action=' + action)['status'] == 200, module + ' read succeeds with auditing enabled')
        go('/modules/proginventory/index.php', "!!document.getElementById('inventoryForm')")
        # Server exports can return binary responses and still finalize a request event.
        binary = browser.js("fetch(" + json.dumps(base + '/modules/proginventory/export_client.php') + ",{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({system_affected:'Fixture',files:[{name:'SourceNameSentinel.php',relative_path:'SourcePathSentinel/SourceNameSentinel.php',size:20,last_modified_ms:0}]})}).then(async r=>({status:r.status,size:(await r.arrayBuffer()).byteLength}))")
        check(binary['status'] == 200 and binary['size'] > 500, 'Program Inventory binary export works with auditing')
        check(api('/modules/account/upload_avatar.php', {}, csrf=False)['status'] == 419, 'Rejected account upload is audited without writing a photo')
        cr = api('/modules/codereview/api.php?action=scan', {'mode':'client','engine':'qms','files':[{'path':'Audit.php','content':'<?php /* SourceContentSentinel */ function bad_name() {}'}]})
        check(cr['status'] == 200, 'Code Review scan completes without copying source to audit history')
        first = api('/modules/bookmarks/api.php?action=save_bookmark', {'title':'PrivateTitleSentinel','url':'https://example.invalid/PrivateUrlSentinel'})
        check(first['status'] == 200 and first['body']['bookmark_id'], 'Create operation succeeds')
        link = first['body']['bookmark_id']
        check(api('/modules/bookmarks/api.php?action=save_bookmark', {'bookmark_id':link,'title':'Updated title','url':'https://example.invalid/updated'})['status'] == 200, 'Update operation succeeds')
        check(api('/modules/bookmarks/api.php?action=delete_bookmark', {'bookmark_id':link})['status'] == 200, 'Delete operation succeeds')
        check(api('/modules/bookmarks/api.php?action=save_bookmark', {}, csrf=False)['status'] == 419, 'CSRF failure is visible')
        check(api('/modules/bookmarks/api.php?action=save_bookmark', {'title':'Invalid','url':'javascript:bad()'})['status'] == 400, 'Validation failure is visible')
        credential = api('/modules/servers/api.php?action=save_credential', {'server_id':fixture['server'],'label':'Fixture vault','username':'PrivateUsernameSentinel','password':'VaultPasswordSentinel'})['body']['credential_id']
        check(api('/modules/servers/api.php?action=reveal_credential&credential_id=' + str(credential))['body']['password'] == 'VaultPasswordSentinel', 'Sensitive credential access succeeds and can be audited')
        check(api('/modules/settings/api.php?action=save_settings', {'section':'policy','default_password':'DefaultPasswordSentinel','password_expiry_days':120,'max_login_attempts':7})['status'] == 200, 'Policy update succeeds')
        generated = api('/modules/diagrams/api.php?action=generate_dictionary', {'diagram_name':'Fixture ERD','table_ids':[fixture['table']]})
        check(generated['status'] == 200, 'A transactional multi-row ERD action succeeds')
        diagram = generated['body']['diagram_id']
        check(api('/modules/diagrams/api.php?action=delete_diagram', {'diagram_id':diagram})['status'] == 200, 'Cascaded ERD deletion succeeds')
        go('/modules/audit/', "document.getElementById('auditRows').getAttribute('aria-busy')==='false'")
        check(browser.js("!!document.querySelector('#sidebarNav a[aria-current=page][href$=\"/audit/\"]')"), 'Audit Logs appears in the sidebar with its active state')
        click('#sidebarToggle')
        value('auditSearch', 'PrivateSearchSentinel')
        browser.js('new Promise(resolve=>setTimeout(resolve,2000))')
        value('auditSearch', '')
        browser.js('new Promise(resolve=>setTimeout(resolve,500))')
        data = snapshot()
        modules = {e['module'] for e in data['events']}
        check(set(['dashboard','authentication','account','audit','organization','usermgmt','settings','servers','directory','bookmarks','requirements','datadict','diagrams','codereview','logs','proginventory']) <= modules, 'Server request coverage includes every module and authentication')
        browser_events = [e for e in data['events'] if e['source'] == 'browser']
        check(any(e['action'] == 'ui.click' and json.loads(e['details']).get('control') == 'sidebarToggle' and e['outcome'] == 'reported' for e in browser_events), 'The shared browser tracker records clicks as reported activity')
        check(any(e['action'] == 'ui.search' for e in browser_events), 'Search interactions are recorded without search text')
        serialized = json.dumps(data)
        for secret in ['WrongPasswordSentinel','AuditFixture123!','SourceContentSentinel','PrivateTitleSentinel','PrivateUrlSentinel','VaultPasswordSentinel','PrivateUsernameSentinel','DefaultPasswordSentinel','PrivateSearchSentinel','SourceNameSentinel','SourcePathSentinel']:
            check(secret not in serialized, 'Audit excludes ' + secret)
        server_events = [e for e in data['events'] if e['source'] == 'server']
        check(any(e['action'] == 'save_bookmark' and e['outcome'] == 'denied' and int(e['http_status']) == 419 for e in server_events), 'CSRF outcomes are labelled denied')
        check(any(e['action'] == 'save_bookmark' and e['outcome'] == 'failed' and int(e['http_status']) == 400 for e in server_events), 'Validation outcomes are labelled failed')
        check(any(e['action'] == 'reveal_credential' and json.loads(e['details'])['credential_id'] == str(credential) for e in server_events), 'Credential access retains its record reference')
        erd_event = next(e for e in server_events if e['action'] == 'generate_dictionary')
        check(len([c for c in data['changes'] if c['request_id'] == erd_event['request_id']]) >= 3, 'Multi-row changes attach to one server request')
        deleted = next(e for e in server_events if e['action'] == 'delete_diagram')
        check(len([c for c in data['changes'] if c['request_id'] == deleted['request_id'] and c['operation'] == 'DELETE']) >= 3, 'Cascaded child-row deletions are retained')
        check({c['operation'] for c in data['changes'] if c['table_name'] == 'bookmarks'} == {'INSERT','UPDATE','DELETE'}, 'The audit includes all three mutation operations')
        report = history(module='bookmarks', source='server', actor=fixture['actor'])
        check(all(e['module'] == 'bookmarks' and int(e['actor_id']) == fixture['actor'] and e['source'] == 'server' for e in report['events']), 'Actor, module and source filters work together')
        check(history(q='bookmarks ' + str(link))['stats']['total'] >= 3, 'Search finds the affected table and record ID')
        check(history(q='Save settings')['stats']['total'] >= 1, 'Search matches readable action names with spaces')
        check(history(q='Revealed vault credential')['stats']['total'] >= 1, 'Search matches descriptive action labels')
        check(history(q='Parameters')['stats']['total'] >= 2, 'Search matches the displayed module name')
        anchored = history(source='server')
        api('/modules/bookmarks/api.php?action=list_bookmarks')
        check(history(source='server', anchor=anchored['anchor'])['stats']['total'] == anchored['stats']['total'], 'Pagination anchor excludes new requests')
        event = next(e for e in report['events'] if e['action'] == 'delete_bookmark' and e['outcome'] == 'success')
        details = api('/modules/audit/api.php?action=detail&event_id=' + event['event_id'])['body']
        check(details['changes'][0]['record_id'] == str(link) and isinstance(details['changes'][0]['changed_fields'], list), 'Details decode changed record references and field names')
        check(api('/modules/audit/api.php?action=delete', {})['status'] == 405, 'History API has no mutation endpoint')
        check(api('/modules/audit/api.php?action=history&from=2026-02-31')['status'] == 422, 'Invalid dates receive a useful validation response')
        import uuid
        batch = {'page':'/AnalystToolkit/modules/audit/','actor_id':99999,'events':[{'id':str(uuid.uuid4()),'kind':'click','control':'auditRefresh','password':'SpoofedSecretSentinel'}]}
        check(api('/modules/audit/client.php', batch)['body']['recorded'] == 1, 'Valid browser batch is accepted')
        check(api('/modules/audit/client.php', batch)['body']['recorded'] == 0, 'Browser retries are deduplicated')
        inserted = next(e for e in snapshot()['events'] if e['request_id'] == batch['events'][0]['id'])
        check(int(inserted['actor_id']) == fixture['actor'] and json.loads(inserted['details']) == {'control':'auditRefresh'}, 'Browser actor spoofing and unexpected payload fields are ignored')
        check(api('/modules/audit/client.php', {'page':'/outside.php','events':[]})['status'] == 422, 'Browser reports for unknown pages are rejected')
        check(history(module='settings')['stats']['total'] >= 2, 'Settings history contains its read and update')
        value('auditModule', 'settings', 'change')
        browser.wait("document.getElementById('auditRows').getAttribute('aria-busy')==='false'&&!!document.querySelector('[data-audit-event]')")
        check(browser.js("[...document.querySelectorAll('#auditRows tr')].every(r=>r.textContent.includes('Parameters'))"), 'Viewer module filter updates rows')
        click('[data-audit-event]')
        browser.wait("document.getElementById('auditDetailDialog').open&&!!document.querySelector('.audit-meta')")
        check(browser.js("document.getElementById('auditDetailBody').textContent.includes('Request reference')"), 'Details dialog shows readable context')
        fit('Audit details')
        browser.js("document.getElementById('auditDetailDialog').close()")
        click('#auditReset')
        browser.wait("document.getElementById('auditRows').getAttribute('aria-busy')==='false'")
        fit('Audit history')
        browser.js("AT.setTheme('dark')")
        check(browser.js("document.documentElement.dataset.theme==='dark'&&fixtureErrors.length===0"), 'Audit UI supports dark mode without JavaScript errors')
        if os.environ.get('AUDIT_SCREENSHOTS') == '1':
            for theme in ['light','dark']:
                browser.js('AT.setTheme(' + json.dumps(theme) + ')')
                for width in [390,1366]:
                    browser.call('Emulation.setDeviceMetricsOverride', {'width':width,'height':1000,'deviceScaleFactor':1,'mobile':width<600})
                    browser.js('new Promise(resolve=>setTimeout(resolve,200))')
                    (ROOT / f'.tmp-audit-{theme}-{width}.png').write_bytes(base64.b64decode(browser.call('Page.captureScreenshot', {'format':'png','captureBeyondViewport':False})['data']))
        exported = browser.js('fetch(' + json.dumps(base + '/modules/audit/export.php?module=bookmarks&source=server') + ').then(r=>r.text())')
        rows = list(csv.reader(io.StringIO(exported.lstrip('\ufeff'))))
        check(len(rows) > 1 and rows[0][0] == 'Event ID', 'Filtered CSV export contains headers and matching records')
        check(all(row[3].startswith("'=2+3") for row in rows[1:]), 'Spreadsheet formula prefixes in actor names are escaped')
        go('/logout.php', "!!document.getElementById('loginForm')")
        check(any(e['action'] == 'sign_out' and int(e['actor_id']) == fixture['actor'] for e in snapshot()['events']), 'Sign-out retains the previous actor after the session is destroyed')
        cli('remove_actor')
        check(any(e['action'] == 'sign_out' and e['actor_name'] == '=2+3 Reviewer' for e in snapshot()['events']), 'Actor snapshots survive deletion of the user account')
        log.flush()
        output = (temp / 'server.log').read_text(errors='replace')
        check(not re.search(r'(?:PHP )?(?:Warning|Fatal error|Notice):', output), 'No PHP warnings or fatal errors')
        entries = [line for file in (temp / 'logs').glob('app-*.log') for line in file.read_text(errors='replace').splitlines()]
        check(not any('Audit event could not be finalized' in line for line in entries), 'Every server event finalizes successfully')
        print(f'Audit browser / HTTP: {CHECKS} assertions passed; disposable database and files only.')
    except Exception:
        if browser:
            print('Fixture diagnostics:', browser.js("JSON.stringify({url:location.pathname,filters:[...document.querySelectorAll('.audit-filters input,.audit-filters select')].map(e=>[e.id,e.value]),rows:document.getElementById('auditRows')?.innerText,busy:document.getElementById('auditRows')?.getAttribute('aria-busy'),errors:window.fixtureErrors,alerts:[...document.querySelectorAll('[role=alert]:not([hidden])')].map(e=>e.textContent),overflow:[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+2&&getComputedStyle(e).position!=='fixed').slice(0,5).map(e=>({tag:e.tagName,id:e.id,class:e.className}))})"))
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
        log.close()
        try:
            cli('cleanup')
        finally:
            if temp.parent == ROOT / 'tests' and temp.name.startswith('.tmp-audit-browser-'):
                shutil.rmtree(temp, ignore_errors=True)


if __name__ == '__main__':
    main()
