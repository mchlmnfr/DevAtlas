"""Disposable UI/HTTP fixtures for Organization, Users, Parameters and Program Inventory."""
import base64
import importlib.util
import json
import mimetypes
import os
from pathlib import Path
import re
import shutil
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('email_browser_helpers', ROOT / 'tests/email-auth-browser.py')
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)
Browser, PHP, EDGE = helpers.Browser, helpers.PHP, helpers.EDGE
CHECKS = 0


def check(value, label):
    global CHECKS
    CHECKS += 1
    if not value:
        raise AssertionError(label)


def main():
    temp = Path(tempfile.mkdtemp(prefix='.tmp-workspace-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    downloads = temp / 'downloads'
    downloads.mkdir()
    project = temp / 'server-project'
    (project / 'vendor').mkdir(parents=True)
    (project / 'index.php').write_text('fixture content must never appear in the report')
    (project / '=2+3.php').write_text('formula-looking filename')
    (project / 'vendor' / 'ignored.php').write_text('excluded')
    schema = 'workspace_fixture_' + os.urandom(6).hex()
    server_port, smtp_port, debug_port = helpers.port(), helpers.port(), helpers.port()
    base = f'http://127.0.0.1:{server_port}/AnalystToolkit'
    data = {'root': str(ROOT).replace('\\', '/'), 'schema': schema, 'url': base, 'smtp_port': smtp_port}
    (temp / 'fixture.json').write_text(json.dumps(data))
    router = temp / 'router.php'
    router.write_text(r'''<?php
$f=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);$schema=$f['schema'];
if(!preg_match('/^workspace_fixture_[a-f0-9]{12}$/D',$schema))exit;
if(PHP_SAPI==='cli')session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once $f['root'].'/lib/Logger.php';$prop=new ReflectionProperty(Logger::class,'directory');$prop->setAccessible(true);$prop->setValue(null,__DIR__.'/logs');
require_once $f['root'].'/config/Database.php';$db=Database::getConnection('app');
if(PHP_SAPI==='cli'){
 set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n");exit(1);});
 if(($argv[1]??'')==='setup'){
  $sql=str_replace('toolkit',$schema,file_get_contents($f['root'].'/database/schema.sql'));$db->query($sql);
  $db->query('SET search_path TO "'.$schema.'", public');$db->query('DELETE FROM users;DELETE FROM org_groups');
  $db->query("INSERT INTO org_groups(group_name,description)VALUES('Technology & Delivery','Systems, data and applications'),('Corporate Services','Shared services')");
  $g=(int)Database::Scalar($db->query("SELECT group_id FROM org_groups WHERE group_name='Technology & Delivery'"));
  $db->query("INSERT INTO org_divisions(group_id,division_name)VALUES(?,'Application Engineering') RETURNING division_id", [$g]);$d=(int)Database::Scalar($db->query('SELECT MAX(division_id) FROM org_divisions'));
  $db->query("INSERT INTO org_departments(division_id,department_name,description)VALUES(?,'Business Systems','Build and maintain internal applications')", [$d]);$dep=(int)Database::Scalar($db->query('SELECT MAX(department_id) FROM org_departments'));
  $db->query("INSERT INTO org_units(department_id,unit_name)VALUES(?,'Delivery Team')", [$dep]);$u=(int)Database::Scalar($db->query('SELECT MAX(unit_id) FROM org_units'));
  $db->query("INSERT INTO org_positions(unit_id,position_title)VALUES(?,'System Analyst')", [$u]);$p=(int)Database::Scalar($db->query('SELECT MAX(position_id) FROM org_positions'));
  $hash=password_hash('WorkspaceFixture123!',PASSWORD_BCRYPT);
  $stmtSql='INSERT INTO users(employee_id,password_hash,first_name,last_name,email,is_active,is_locked,must_change_password,password_changed_at,group_id,division_id,department_id,unit_id,position_id)VALUES(?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?) RETURNING user_id';
  $stmt = $db->query($stmtSql, ['viewer',$hash,'Mika','Santos','viewer@example.invalid','true','false','false',$g,$d,$dep,$u,$p]);$viewer=(int)Database::Scalar($stmt);
  for($i=1;$i<=28;$i++)$stmt = $db->query($stmtSql, ['EMP-'.str_pad($i,3,'0',STR_PAD_LEFT),$hash,$i===3?'Rae <img src=x onerror=window.fixtureXss=1>':'Emilia '.$i,'Cruz',"user$i@example.invalid",$i%7===0?'false':'true',$i%9===0?'true':'false',$i%5===0?'true':'false',...($i%2===0?[$g,$d,$dep,$u,$p]:[null,null,null,null,null])]);
  $db->query("UPDATE system_settings SET password_expiry_days=90,smtp_host='127.0.0.1',smtp_port=?,smtp_encryption='none',smtp_from_email='fixture@example.invalid',smtp_from_name='Fixture Mail',auth_base_url=? WHERE id=1", [$f['smtp_port'],$f['url']]);
  $db->query("UPDATE users SET role_key='admin' WHERE user_id=".$viewer);
  echo json_encode(['viewer'=>$viewer,'group'=>$g,'division'=>$d,'department'=>$dep,'unit'=>$u,'position'=>$p]);
 }elseif(($argv[1]??'')==='cleanup')$db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
 exit;
}
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}$db->query('SET search_path TO "'.$schema.'", public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if(str_starts_with($relative,'assets/')){$file=realpath($f['root'].'/'.$relative);$assets=realpath($f['root'].'/assets');if($file&&str_starts_with(strtolower($file),strtolower($assets.DIRECTORY_SEPARATOR))&&is_file($file)){$ext=pathinfo($file,PATHINFO_EXTENSION);header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png'][$ext]??'application/octet-stream'));readfile($file);exit;}}
if($relative==='index.php'){require_once $f['root'].'/lib/Auth.php';Auth::requirePage();echo '<h1 id="fixtureSignedIn">Signed in</h1>';exit;}
$allowed=['login.php','logout.php','forgot_password.php','reset_password.php','change_password.php','auth_email_preview.php','modules/organization/index.php','modules/organization/api.php','modules/usermgmt/index.php','modules/usermgmt/api.php','modules/settings/index.php','modules/settings/api.php','modules/proginventory/index.php','modules/proginventory/export.php','modules/proginventory/export_client.php','modules/audit/client.php','modules/notifications/api.php','modules/applications/api.php','modules/codereview/history_api.php'];
if(!in_array($relative,$allowed,true)){http_response_code(404);exit;}$_SERVER['SCRIPT_NAME']='/AnalystToolkit/'.$relative;require $f['root'].'/'.$relative;
''', encoding='utf-8')
    processes = []
    smtp = socketserver.ThreadingTCPServer(('127.0.0.1', smtp_port), helpers.SMTP)
    smtp.daemon_threads = True
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    log = open(temp / 'server.log', 'wb')
    browser = None

    def cli(action):
        result = subprocess.run([str(PHP), str(router), action], capture_output=True, timeout=30, cwd=ROOT, creationflags=subprocess.CREATE_NO_WINDOW)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace'))
        return json.loads(result.stdout) if result.stdout.strip() else None

    def go(route, ready):
        browser.go(base + route)
        browser.wait("document.readyState==='complete' && (" + ready + ')')

    def set_value(element, value, event='input'):
        browser.js(f"document.getElementById({json.dumps(element)}).value={json.dumps(str(value))};document.getElementById({json.dumps(element)}).dispatchEvent(new Event({json.dumps(event)},{{bubbles:true}}))")

    def click(selector):
        browser.js(f'document.querySelector({json.dumps(selector)}).click()')

    def fit(label):
        for width in [320, 390, 768, 1024, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'{label} fits {width}px.')

    def capture(label, width=1366):
        if os.environ.get('WORKSPACE_SCREENSHOTS') != '1':
            return
        browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': width < 600})
        browser.js('new Promise(resolve=>setTimeout(resolve,200))')
        (ROOT / f'.tmp-workspace-{label}-{width}.png').write_bytes(base64.b64decode(browser.call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': False})['data']))

    def submit(form):
        browser.js(f'document.getElementById({json.dumps(form)}).requestSubmit()')

    def api(route, body=None):
        return browser.js("fetch(" + json.dumps(base + route) + ",{method:" + json.dumps('POST' if body is not None else 'GET') + ",headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content}," + ("body:JSON.stringify(" + json.dumps(body) + '),' if body is not None else '') + "}).then(async response=>({status:response.status,body:await response.json()}))")

    def workbook(path):
        ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        with zipfile.ZipFile(path) as archive:
            strings = ET.fromstring(archive.read('xl/sharedStrings.xml'))
            text = [''.join(node.itertext()) for node in strings.findall('s:si', ns)]
            sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
            check(not sheet.findall('.//s:f', ns), 'Workbook values are literal text, never injected formulas.')
            return text, sheet.findall('s:sheetData/s:row', ns)

    try:
        fixture = cli('setup')
        server = subprocess.Popen([str(PHP), '-d', f'session.save_path={temp}', '-d', f'sys_temp_dir={temp}', '-d', f'upload_tmp_dir={temp}', '-S', f'127.0.0.1:{server_port}', str(router)], cwd=ROOT, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(server)
        until = time.time() + 15
        while time.time() < until:
            try:
                urllib.request.urlopen(base + '/login.php', timeout=1).close()
                break
            except (urllib.error.URLError, ConnectionError):
                time.sleep(.1)
        edge = subprocess.Popen([str(EDGE), '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--remote-allow-origins=*', '--user-data-dir=' + str(temp / 'edge'), f'--remote-debugging-port={debug_port}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(edge)
        until = time.time() + 20
        while time.time() < until:
            try:
                pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{debug_port}/json/list', timeout=1))
                if pages:
                    break
            except (urllib.error.URLError, ConnectionError):
                time.sleep(.1)
        browser = Browser(next(p['webSocketDebuggerUrl'] for p in pages if p['type'] == 'page'))
        browser.call('Page.setDownloadBehavior', {'behavior': 'allow', 'downloadPath': str(downloads)})
        go('/login.php', "!!document.getElementById('loginForm')")
        check(browser.js("document.querySelector('.brand-icon').complete && document.querySelector('.brand-icon').naturalWidth===64"), 'New logo renders on sign-in page.')
        set_value('loginEmployeeId', 'viewer')
        set_value('loginPassword', 'WorkspaceFixture123!')
        submit('loginForm')
        browser.wait("!!document.getElementById('fixtureSignedIn')")

        # Organization: search ancestors, create the full hierarchy, edit and delete safely.
        go('/modules/organization/index.php', "document.getElementById('orgCount_group').textContent==='2'")
        check(browser.js("document.querySelector('.brand .brand-icon').naturalWidth===64"), 'New logo renders in the shared sidebar.')
        fit('Organization')
        capture('organization')
        set_value('orgSearch', 'System Analyst')
        check(browser.js("document.getElementById('orgTree').textContent.includes('Technology & Delivery') && document.getElementById('orgTree').textContent.includes('System Analyst')"), 'Organization search retains matching entry ancestors.')
        set_value('orgSearch', 'NotFoundFixture')
        check(browser.js("!document.getElementById('orgEmpty').hidden"), 'Organization has an understandable no-results state.')
        click('#orgClear')
        click('#orgAddGroup')
        browser.wait("document.getElementById('orgEditor').open")
        set_value('orgName', 'Research <team>')
        set_value('orgDescription', 'Data & analytics')
        submit('orgForm')
        browser.wait("!document.getElementById('orgEditor').open && document.getElementById('orgDetail').textContent.includes('Research <team>')")
        check(browser.js("document.getElementById('orgCount_group').textContent==='3' && !document.querySelector('#orgDetail team')"), 'New group is saved and displayed as escaped text.')
        for depth, label in enumerate(['Research Division', 'Research Department', 'Research Unit', 'Research Position']):
            click('#orgDetail [data-add]')
            set_value('orgName', label)
            submit('orgForm')
            browser.wait("!document.getElementById('orgEditor').open && document.getElementById('orgDetail').textContent.includes(" + json.dumps(label) + ')')
            check(browser.js("document.querySelector('.org-detail-name').textContent===" + json.dumps(label)), 'Child hierarchy level saved correctly.')
        click('#orgDetail [data-edit]')
        set_value('orgName', 'Research Analyst')
        submit('orgForm')
        browser.wait("!document.getElementById('orgEditor').open && document.querySelector('.org-detail-name').textContent==='Research Analyst'")
        click('#orgDetail [data-delete]')
        browser.wait("!!document.querySelector('.swal2-popup')")
        click('.swal2-cancel')
        browser.js('new Promise(resolve=>setTimeout(resolve,80))')
        check(browser.js("document.querySelector('.org-detail-name').textContent==='Research Analyst'"), 'Canceling organization deletion preserves the entry.')
        click('#orgDetail [data-delete]')
        browser.wait("!!document.querySelector('.swal2-popup')")
        click('.swal2-confirm')
        browser.wait("document.getElementById('orgCount_position').textContent==='1'")
        check(True, 'Confirmed empty position deletion works.')
        capture('organization-mobile', 390)
        # Organization editor must fit at the smallest supported viewport.
        click('#orgDetail [data-edit]')
        browser.call('Emulation.setDeviceMetricsOverride', {'width': 320, 'height': 850, 'deviceScaleFactor': 1, 'mobile': True})
        check(browser.js("(()=>{const r=document.getElementById('orgEditor').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.height<=innerHeight})()"), 'Organization editor fits a 320px viewport.')
        click('[data-dialog-close=orgEditor]')

        # Users: filtered cards, pagination, create/edit placement and account actions.
        go('/modules/usermgmt/index.php', "document.getElementById('userCount_all').textContent==='29'")
        fit('Users')
        capture('users')
        click('#userNext')
        check(browser.js("document.getElementById('userPageSummary').textContent.includes('Page 2')"), 'User pagination advances.')
        set_value('userSearch', 'EMP-003')
        check(browser.js("document.querySelectorAll('#userRows tr').length===1 && !document.querySelector('#userRows img[src=x]') && !window.fixtureXss"), 'User search renders untrusted names as text.')
        set_value('userSearch', '')
        click('[data-status=locked]')
        check(browser.js("document.getElementById('userStatus').value==='locked' && document.getElementById('userRows').textContent.includes('Locked')"), 'User summary cards filter account status.')
        set_value('userStatus', 'all', 'change')
        click('#userAdd')
        browser.wait("document.getElementById('userEditor').open && !document.getElementById('saveUserBtn').disabled")
        set_value('us_employee_id', 'NEW-001')
        set_value('us_first_name', 'Lena')
        set_value('us_last_name', 'Garcia')
        set_value('us_password', 'NewFixture123!')
        set_value('us_email', 'new@example.invalid')
        for key in ['group', 'division', 'department', 'unit', 'position']:
            set_value('us_' + key + '_id', fixture[key], 'change')
        capture('user-editor')
        browser.call('Emulation.setDeviceMetricsOverride', {'width': 320, 'height': 900, 'deviceScaleFactor': 1, 'mobile': True})
        check(browser.js("(()=>{const d=document.getElementById('userEditor');return d.scrollWidth<=d.clientWidth+1&&d.getBoundingClientRect().right<=innerWidth})()"), 'User editor is contained at 320px.')
        check(browser.js("(()=>{const r=document.getElementById('saveUserBtn').getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight})()"), 'User editor save controls stay visible while its body scrolls.')
        submit('userForm')
        browser.wait("!document.getElementById('userEditor').open && document.getElementById('userCount_all').textContent==='30'")
        set_value('userSearch', 'NEW-001')
        check(browser.js("document.getElementById('userRows').textContent.includes('Lena Garcia') && document.getElementById('userRows').textContent.includes('Business Systems')"), 'New account and complete organization placement are saved.')
        click('#userRows [data-edit-user]')
        browser.wait("!document.getElementById('saveUserBtn').disabled && document.getElementById('us_position_id').value!==''")
        check(browser.js("document.getElementById('us_position_id').value===" + json.dumps(str(fixture['position']))), 'Editing hydrates all organization selections.')
        set_value('us_group_id', '', 'change')
        check(browser.js("document.getElementById('us_position_id').disabled && document.getElementById('us_position_id').value===''"), 'Changing the parent clears dependent organization selections.')
        click('[data-dialog-close=userEditor]')
        click('#userRows [data-user-actions]')
        click('#userActions [data-op=reset]')
        set_value('rp_password', 'Temporary789!')
        set_value('rp_confirmation', 'Different789!')
        submit('resetPasswordForm')
        browser.wait("!document.getElementById('userResetError').hidden")
        check(browser.js("document.getElementById('userResetError').textContent.includes('do not match')"), 'Reset confirmation prevents accidental mismatched passwords.')
        set_value('rp_confirmation', 'Temporary789!')
        submit('resetPasswordForm')
        browser.wait("!document.getElementById('resetPasswordModal').open")
        click('#userRows [data-user-actions]')
        click('#userActions [data-op=toggle]')
        browser.wait("!!document.querySelector('.swal2-popup')")
        click('.swal2-confirm')
        browser.wait("!document.getElementById('userActions').open && document.getElementById('userRows').textContent.includes('Disabled')")
        check(True, 'Account disable confirmation updates the row.')
        capture('users-mobile', 390)

        # Parameters: independently saved sections preserve another section's draft.
        go('/modules/settings/index.php', "!document.getElementById('settings-policy').querySelector('.settings-fields').disabled")
        fit('Parameters')
        capture('parameters')
        set_value('s_password_expiry_days', 45)
        click('#tab-smtp')
        set_value('s_smtp_from_name', 'Unsaved sender draft')
        click('#tab-policy')
        submit('settings-policy')
        browser.wait("document.getElementById('dirty-policy').hidden && !document.getElementById('settings-policy').querySelector('.settings-fields').disabled")
        click('#tab-smtp')
        check(browser.js("document.getElementById('s_smtp_from_name').value==='Unsaved sender draft' && !document.getElementById('dirty-smtp').hidden"), 'Policy save preserves an unsaved SMTP draft.')
        saved = api('/modules/settings/api.php?action=get_settings')['body']
        check(saved['password_expiry_days'] == 45 and saved['smtp_from_name'] == 'Fixture Mail', 'Section save changes only its own database settings.')
        click('[data-discard=smtp]')
        check(browser.js("document.getElementById('s_smtp_from_name').value==='Fixture Mail' && document.getElementById('dirty-smtp').hidden"), 'Discard restores only the selected section.')
        click('#settingsTestEmail')
        submit('testEmailForm')
        browser.wait("!document.getElementById('testEmailResult').hidden")
        check(browser.js("document.getElementById('testEmailResult').textContent.includes('accepted')"), 'SMTP test uses the saved local mail catcher and explains acceptance.')
        click('[data-dialog-close=testEmailModal]')
        click('#tab-recovery')
        set_value('s_auth_base_url', 'https://example.invalid/Other')
        submit('settings-recovery')
        browser.wait("!document.getElementById('error-recovery').hidden && !document.getElementById('settings-recovery').querySelector('.settings-fields').disabled")
        check(True, 'Recovery URL validation remains visible in its section.')
        set_value('s_auth_base_url', base + '/')
        submit('settings-recovery')
        browser.wait("document.getElementById('dirty-recovery').hidden")
        check(browser.js("document.getElementById('s_auth_base_url').value===" + json.dumps(base)), 'Saved recovery address is normalized in the form.')
        capture('parameters-mobile', 390)
        # API rejects writes via GET and invalid section values.
        check(api('/modules/settings/api.php?action=save_settings')['status'] == 405, 'Settings write method gate remains enforced.')
        check(api('/modules/settings/api.php?action=save_settings', {'section': 'invalid'})['status'] == 400, 'Unknown settings sections are rejected.')

        # Program Inventory: synthetic browser files, real workbook downloads and server scan.
        go('/modules/proginventory/index.php', "!!document.getElementById('inventoryForm')")
        fit('Program Inventory')
        capture('inventory-empty')
        set_value('pi_system_affected', 'Fixture Portal')
        browser.js("window.fixtureMetadataBody=null;const originalFetch=window.fetch;window.fetch=(url,options)=>{if(String(url).includes('export_client.php'))fixtureMetadataBody=options.body;return originalFetch(url,options)};const transfer=new DataTransfer();for(let i=0;i<25;i++){const filename=i===0?'=2+3.php':'file-'+i+'.php';const f=new File(['DO_NOT_UPLOAD_CONTENT_SENTINEL'],filename,{lastModified:1761000000000});Object.defineProperty(f,'webkitRelativePath',{value:'Fixture/src/'+filename});transfer.items.add(f);}for(const [name,path] of [['ignore.php','Fixture/vendor/ignore.php'],['vendor','Fixture/vendor']]){const f=new File(['DO_NOT_UPLOAD_CONTENT_SENTINEL'],name,{lastModified:1761000000000});Object.defineProperty(f,'webkitRelativePath',{value:path});transfer.items.add(f);}document.getElementById('folderPicker').files=transfer.files;document.getElementById('folderPicker').dispatchEvent(new Event('change',{bubbles:true}));")
        check(browser.js("document.getElementById('inventoryReportCount').textContent==='26' && document.getElementById('inventoryReportExcluded').textContent==='1'"), 'Folder rules exclude directories and retain a file with the same name.')
        check(browser.js("document.getElementById('inventoryReportId').textContent==='FP-00001'"), 'Report ID preview follows project initials.')
        click('#inventoryNext')
        check(browser.js("document.getElementById('inventoryPageSummary').textContent.startsWith('21')"), 'File metadata preview is paginated.')
        set_value('inventorySearch', '=2+3')
        check(browser.js("document.querySelectorAll('#inventoryRows tr').length===1"), 'Preview search filters file metadata.')
        capture('inventory')
        submit('inventoryForm')
        browser.wait("!document.getElementById('inventorySuccess').hidden")
        check(browser.js("fixtureMetadataBody&&!fixtureMetadataBody.includes('DO_NOT_UPLOAD_CONTENT_SENTINEL') && JSON.parse(fixtureMetadataBody).files.length===26"), 'Export sends all included metadata and never file contents or just filtered rows.')
        until = time.time() + 15
        while time.time() < until and not list(downloads.glob('*.xlsx')):
            time.sleep(.1)
        files = list(downloads.glob('*.xlsx'))
        check(len(files) == 1, 'Client export downloads a real Excel workbook.')
        strings, rows = workbook(files[0])
        check('=2+3.php' in strings and 'vendor' in strings and 'ignored.php' not in strings, 'Workbook preserves literal filenames and directory exclusions.')
        check(len(rows) == 29 and 'FP-00001' in strings and 'REMARKS' in strings, 'Workbook retains all 26 included files and the existing 11-column format.')
        click('#modeBtnServer')
        set_value('pi_server_path', str(project))
        set_value('pi_system_affected', 'Fixture Server')
        check(browser.js("document.getElementById('inventoryPreviewSection').hidden && !document.getElementById('clientGenerateBtn').disabled"), 'Server source has its own understandable scan/export state.')
        capture('inventory-server')
        submit('inventoryForm')
        browser.wait("!document.getElementById('inventorySuccess').hidden")
        until = time.time() + 15
        while time.time() < until and len(list(downloads.glob('*.xlsx'))) < 2:
            time.sleep(.1)
        files = sorted(downloads.glob('*.xlsx'), key=lambda p: p.stat().st_mtime)
        check(len(files) == 2, 'Server path scan downloads a second real workbook.')
        strings, rows = workbook(files[-1])
        check(len(rows) == 5 and '=2+3.php' in strings and 'ignored.php' not in strings, 'Server export scans fixture metadata and excludes vendor files.')
        set_value('pi_server_path', str(temp / 'does-not-exist'))
        submit('inventoryForm')
        browser.wait("!document.getElementById('inventoryError').hidden")
        check(browser.js("document.getElementById('inventoryError').textContent.includes('does not exist')"), 'Server scan errors are explained inline.')
        click('#modeBtnClient')
        set_value('inventorySearch', '')
        fit('Populated inventory')
        capture('inventory-mobile', 390)
        # Dark mode and native dialog controls remain usable on all four modules.
        for route, label, ready in [('/modules/organization/index.php','organization',"document.getElementById('orgCount_group').textContent!=='—'"),('/modules/usermgmt/index.php','users',"document.getElementById('userCount_all').textContent!=='—'"),('/modules/settings/index.php','parameters',"!document.getElementById('settings-policy').querySelector('.settings-fields').disabled"),('/modules/proginventory/index.php','inventory',"!!document.getElementById('inventoryForm')")]:
            go(route, ready)
            browser.js("if(AT.getTheme()!=='dark')AT.toggleTheme()")
            check(browser.js("document.documentElement.dataset.theme==='dark' && fixtureErrors.length===0"), label + ' supports dark theme without runtime errors.')
            capture(label + '-dark')
        log.flush()
        output = (temp / 'server.log').read_text(errors='replace')
        check(not re.search(r'(?:PHP )?(?:Warning|Fatal error|Notice):', output), 'Server produced no PHP warnings/fatal errors.')
        print(f'Workspace UI/HTTP: {CHECKS} assertions passed; isolated DB and loopback SMTP only.')
    except Exception:
        if browser and '/modules/settings/' in browser.js('location.pathname'):
            print('Settings diagnostics:', browser.js("(async()=>{const r=await fetch(document.getElementById('settingsApp').dataset.api+'?action=get_settings');const data=await r.json();return JSON.stringify({status:r.status,keys:Object.keys(data),error:data.error,loadError:!document.getElementById('settingsLoadError').hidden,fields:[...document.querySelectorAll('.settings-fields')].map(e=>({parent:e.parentElement.className,disabled:e.disabled})),scripts:[...document.scripts].map(e=>e.getAttribute('src')).filter(Boolean)});})()"))
        if browser:
            print('Fixture browser diagnostics:', browser.js("JSON.stringify({url:location.pathname,width:innerWidth,errors:window.fixtureErrors,toasts:[...document.querySelectorAll('.toast')].map(e=>e.textContent),alerts:[...document.querySelectorAll('[role=alert]:not([hidden])')].map(e=>e.textContent),dialogs:[...document.querySelectorAll('dialog[open]')].map(e=>e.id),overflow:[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+2&&getComputedStyle(e).position!=='fixed').slice(0,8).map(e=>({tag:e.tagName,id:e.id,class:e.className,right:e.getBoundingClientRect().right}))})"))
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
            if temp.parent == ROOT / 'tests' and temp.name.startswith('.tmp-workspace-'):
                shutil.rmtree(temp, ignore_errors=True)


if __name__ == '__main__':
    main()
