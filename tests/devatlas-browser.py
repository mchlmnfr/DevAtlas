"""DevAtlas identity, local assets, theme behavior and responsive module smoke checks."""
import base64
import importlib.util
import json
import os
from pathlib import Path
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
h = importlib.util.module_from_spec(spec)
spec.loader.exec_module(h)
CHECKS = 0


def check(ok, label):
    global CHECKS
    CHECKS += 1
    if not ok:
        raise AssertionError(label)


def main():
    temp = Path(tempfile.mkdtemp(prefix='.tmp-devatlas-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    port, debug = h.port(), h.port()
    base = f'http://127.0.0.1:{port}/AnalystToolkit'
    schema = 'brand_browser_' + os.urandom(6).hex()
    (temp / 'fixture.json').write_text(json.dumps({'root': str(ROOT).replace('\\', '/'), 'schema': schema}))
    router = temp / 'router.php'
    router.write_text(r'''<?php
$f=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);$schema=$f['schema'];
if(!preg_match('/^brand_browser_[a-f0-9]{12}$/D',$schema))exit;
if(PHP_SAPI==='cli')session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once $f['root'].'/lib/Logger.php';$p=new ReflectionProperty(Logger::class,'directory');$p->setValue(null,__DIR__.'/logs');
require_once $f['root'].'/config/Database.php';$db=Database::getConnection('app');
if(PHP_SAPI==='cli'){
 if($argv[1]==='cleanup'){$db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');exit;}
 if($argv[1]==='setup'){
  $db->query(str_replace('toolkit',$schema,file_get_contents($f['root'].'/database/schema.sql')));$db->query('SET search_path TO "'.$schema.'",public');
  $db->query('DELETE FROM users');
  $sSql="INSERT INTO users(employee_id,password_hash,first_name,last_name,role_key,must_change_password,password_changed_at)VALUES('brand-admin',?,'DevAtlas','Fixture','admin',FALSE,NOW())";$s = $db->query($sSql, [password_hash('BrandFixture123!',PASSWORD_BCRYPT)]);
  $g=Database::Scalar($db->query("INSERT INTO org_groups(group_name)VALUES('SearchFixture Group') RETURNING group_id"));
  $v=Database::Scalar($db->query("INSERT INTO org_divisions(group_id,division_name)VALUES($g,'SearchFixture Division') RETURNING division_id"));
  $d=Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name)VALUES($v,'SearchFixture Department') RETURNING department_id"));
  $u=Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($d,'SearchFixture Section A') RETURNING unit_id"));
  $other=Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($d,'SearchFixture Section B') RETURNING unit_id"));
  $sSql="INSERT INTO users(employee_id,password_hash,first_name,last_name,unit_id,role_key,must_change_password,password_changed_at,created_at)VALUES(?,?,?,'SearchFixture',?,'analyst',FALSE,NOW(),'2026-10-01') RETURNING user_id";
  $s = $db->query($sSql, ['search-worker',password_hash('BrandFixture123!',PASSWORD_BCRYPT),'Alice',$u]);$worker=Database::Scalar($s);
  $s = $db->query($sSql, ['search-outsider',password_hash('BrandFixture123!',PASSWORD_BCRYPT),'Other',$other]);
  $db->query("INSERT INTO daily_logs(user_id,activity_date,entry_kind,classification,activity_type,planned_task,actual_activity,duration_hours,status)VALUES($worker,date_trunc('week',CURRENT_DATE)::date,'activity','SD','AN','SearchFixture plan','SearchFixture actual',8,'Submitted'),($worker,CURRENT_DATE,'activity','SD','AN','Draft','PrivateSearchDraft',2,'Draft')");
  $db->query("INSERT INTO servers(server_name,environment,ip_address)VALUES('SearchFixture Server','Dev','192.0.2.51')");
  $t=Database::Scalar($db->query("INSERT INTO dd_tables(table_name,display_name)VALUES('search_fixture','SearchFixture Table') RETURNING table_id"));
  $db->query("INSERT INTO dd_columns(table_id,column_name,data_type)VALUES($t,'SearchFixtureColumn','TEXT')");
  $db->query("INSERT INTO diagrams(diagram_type,diagram_name)VALUES('ERD','SearchFixture ERD')");
  $sSql='INSERT INTO bookmarks(title,url)VALUES(?,?)';$s = $db->query($sSql, ['SearchFixture <img src=x onerror=window.fixtureXss=1>','https://example.invalid/']);
  $db->query('UPDATE notification_settings SET email_enabled=FALSE');exit;
 }
 exit;
}
$db->query('SET search_path TO "'.$schema.'",public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if(str_starts_with($relative,'assets/')){$file=realpath($f['root'].'/'.$relative);$assets=realpath($f['root'].'/assets');if($file&&str_starts_with(strtolower($file),strtolower($assets.DIRECTORY_SEPARATOR))&&is_file($file)){header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','woff2'=>'font/woff2','png'=>'image/png'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;}}
if(!in_array($relative,['index.php','login.php','logout.php','forgot_password.php','reset_password.php','change_password.php'],true)&&!preg_match('#^modules/[a-z]+/[a-z_]+\.php$#D',$relative)){http_response_code(404);exit;}
$file=$f['root'].'/'.$relative;$_SERVER['SCRIPT_NAME']='/AnalystToolkit/'.$relative;if(!is_file($file)){http_response_code(404);exit;}require $file;
''', encoding='utf-8')
    processes, browser = [], None
    log = open(temp / 'server.log', 'wb')

    def cli(action):
        result = subprocess.run([str(h.PHP), str(router), action], capture_output=True, timeout=30, cwd=ROOT, creationflags=subprocess.CREATE_NO_WINDOW)
        if result.returncode:
            raise RuntimeError(result.stderr.decode(errors='replace'))

    def go(route):
        browser.go(base + route)
        browser.js('document.fonts.ready.then(()=>true)')
        if '/login' not in route and '/modules/wiki/' not in route and '/forgot_' not in route and '/reset_' not in route and '/change_password' not in route:
            browser.wait("document.readyState==='complete'")

    def select_theme(mode):
        browser.js("(()=>{const mode="+json.dumps(mode)+",theme=document.querySelector('[data-theme-select]');if(theme){theme.value=mode;theme.dispatchEvent(new Event('change',{bubbles:true}));}else{AT.toggleUserMenu();document.querySelector('[data-account-theme='+mode+']').click();AT.closeUserMenu();}})()")
        browser.wait("getComputedStyle(document.body).backgroundColor===(document.documentElement.dataset.theme==='dark'?'rgb(11, 17, 32)':'rgb(248, 250, 252)')")

    def shot(name):
        if not os.environ.get('DEVATLAS_SCREENSHOTS'):
            return
        path = Path(os.environ['DEVATLAS_SCREENSHOTS']) / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(base64.b64decode(browser.call('Page.captureScreenshot', {'format':'png','captureBeyondViewport':False})['data']))

    def viewport(width):
        browser.call('Emulation.setDeviceMetricsOverride', {'width':width,'height':1000,'deviceScaleFactor':1,'mobile':width<600})

    def fit(label):
        for width in [320,390,768,1440]:
            viewport(width)
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'{label} fits {width}px')

    try:
        cli('setup')
        processes.append(subprocess.Popen([str(h.PHP),'-d',f'session.save_path={temp}','-d',f'sys_temp_dir={temp}','-S',f'127.0.0.1:{port}',str(router)],cwd=ROOT,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW))
        for _ in range(100):
            try:
                urllib.request.urlopen(base+'/login.php',timeout=1).close();break
            except OSError:
                time.sleep(.1)
        processes.append(subprocess.Popen([str(h.EDGE),'--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-extensions','--remote-allow-origins=*','--user-data-dir='+str(temp/'edge'),f'--remote-debugging-port={debug}','about:blank'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW))
        for _ in range(150):
            try:
                pages=json.load(urllib.request.urlopen(f'http://127.0.0.1:{debug}/json/list',timeout=1))
                if pages:break
            except OSError:
                time.sleep(.1)
        browser=h.Browser(next(p['webSocketDebuggerUrl'] for p in pages if p['type']=='page'))
        browser.call('Emulation.setEmulatedMedia', {'features':[{'name':'prefers-color-scheme','value':'light'}]})
        viewport(1440);go('/login.php')
        check(browser.js("fetch('"+base+"/modules/account/search.php?q=SearchFixture').then(r=>r.status)")==401,'Guests cannot access record search')
        check(browser.js("location.pathname==='/AnalystToolkit/login'"),'Legacy sign-in link redirects to the clean route')
        check(browser.js("![...document.querySelectorAll('a[href]')].some(a=>a.origin===location.origin&&a.pathname.endsWith('.php'))"),'Sign-in navigation emits clean URLs')

        check(browser.js("document.title.includes('DevAtlas')&&document.body.innerText.includes('DevAtlas')&&!/analysttoolkit/i.test(document.body.innerText)"),'Sign-in uses DevAtlas identity')
        check(browser.js("!document.querySelector('header [data-theme-select]')&&!!document.querySelector('footer [data-theme-select]')"),'Sign-in appearance choices are in the footer, outside the header')
        check(browser.js("document.querySelectorAll('.login-header .login-public-resources a').length===2&&!document.querySelector('.login-form-wrap .login-public-directory')"),'Call Directory and Quick Links sit in the login header, outside the form')
        check(browser.js("document.getElementById('loginStoryTitle').textContent.includes('One clear view.')&&document.querySelectorAll('.atlas-story-grid>div').length===4"),'Login story introduces the DevAtlas workspace')

        check(browser.js("getComputedStyle(document.body).fontFamily.startsWith('Poppins')&&[...document.fonts].some(f=>f.family==='Poppins'&&f.status==='loaded')"),'Poppins font loads locally')
        check(browser.js("Swal.version==='11.26.25'&&[...document.scripts].some(s=>s.src.includes('/assets/vendor/sweetalert2/'))"),'SweetAlert2 is bundled locally on sign-in')
        browser.js("window.confirmResult=null;void AT.confirmAction('Keep <img src=x onerror=alert(1)> as text.').then(value=>window.confirmResult=value)")
        browser.wait("!!document.querySelector('.swal2-popup[role=dialog]')")
        browser.wait("document.querySelector('.swal2-icon .lucide').getBBox().width>0")
        check(browser.js("document.activeElement.classList.contains('swal2-cancel')&&!document.querySelector('.swal2-html-container img')&&!!document.querySelector('.swal2-icon .lucide use')"),'Confirmation focuses Cancel, escapes content and uses a Lucide icon')
        check(browser.js("getComputedStyle(document.querySelector('.swal2-icon')).opacity==='1'&&document.querySelector('.swal2-icon .lucide').getBoundingClientRect().height===28"),'Lucide feedback icon is immediately visible at its intended size')
        browser.call('Input.dispatchKeyEvent',{'type':'keyDown','key':'Escape','code':'Escape','windowsVirtualKeyCode':27})
        browser.call('Input.dispatchKeyEvent',{'type':'keyUp','key':'Escape','code':'Escape','windowsVirtualKeyCode':27})
        browser.wait('window.confirmResult===false')
        check(browser.js("!document.querySelector('.atlas-alert-host')"),'Escape cancels confirmation and removes the top-layer host')
        browser.js("window.confirmResult=null;void AT.confirmAction('Proceed with the fixture?').then(value=>window.confirmResult=value)")
        browser.wait("!!document.querySelector('.swal2-confirm')")
        browser.js("AT.toast('Queued notification');Swal.clickConfirm()")
        browser.wait('window.confirmResult===true')
        browser.wait("!!document.querySelector('.swal2-toast')")
        check(browser.js("document.querySelector('.swal2-title').textContent==='Queued notification'"),'Notifications wait until a confirmation has resolved')
        check(browser.js("(()=>{const p=Swal.getPopup(),r=p.getBoundingClientRect();return r.height<=120&&r.right<=innerWidth&&r.left>=0&&p.scrollWidth<=p.clientWidth})()"),'A transaction toast stays compact at the right edge without growing through empty grid rows')
        browser.js('Swal.close()')
        check(browser.js("document.documentElement.dataset.themeMode==='system'&&document.documentElement.dataset.theme==='light'"),'Default theme follows System')
        select_theme('dark')
        check(browser.js("document.documentElement.dataset.theme==='dark'&&localStorage.getItem('devatlas_theme')==='dark'"),'Dark selection persists')
        shot('login-dark.png');fit('Sign-in')
        select_theme('system')
        browser.call('Emulation.setEmulatedMedia', {'features':[{'name':'prefers-color-scheme','value':'dark'}]})
        browser.wait("document.documentElement.dataset.theme==='dark'")
        check(browser.js("AT.getThemePreference()==='system'"),'System preference remains selected while appearance changes')
        select_theme('light')
        browser.call('Emulation.setEmulatedMedia', {'features':[{'name':'prefers-color-scheme','value':'light'}]})
        check(browser.js("document.documentElement.dataset.theme==='light'"),'Explicit Light overrides device preference')
        shot('login-light.png')
        for route,label in [('/modules/directory/index.php','Public directory'),('/modules/bookmarks/index.php','Public links')]:
            go(route)
            check(browser.js("!!document.querySelector('footer [data-theme-select]')&&!document.querySelector('header [data-theme-select]')&&document.body.innerText.includes('DevAtlas')"),label+' has DevAtlas identity and footer appearance choices')
            fit(label)
        go('/login.php')
        browser.js("document.getElementById('loginEmployeeId').value='brand-admin';document.getElementById('loginPassword').value='BrandFixture123!';document.querySelector('#loginForm button[type=submit]').click()")
        browser.wait("!!document.getElementById('appTopbar')")
        browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
        latest_title=max(json.loads((ROOT/'docs/releases.json').read_text(encoding='utf-8')),key=lambda release:release['order'])['title']
        check(browser.js("document.querySelector('#updatesHistory h3').textContent")==latest_title,'Release history shows the latest published update')
        browser.js("document.getElementById('updatesDone').click()")
        browser.wait("!document.getElementById('updatesDialog').open")
        browser.js('document.fonts.ready.then(()=>true)')
        check(browser.js("document.querySelector('.sidebar .brand').innerText.includes('DevAtlas')&&document.querySelectorAll('#sidebarNav .lucide use').length===document.querySelectorAll('#sidebarNav a').length"),'DevAtlas sidebar renders Lucide module icons')
        check(browser.js("getComputedStyle(document.querySelector('.sidebar')).backgroundColor==='rgb(15, 23, 42)'"),'Light sidebar uses midnight navy')
        check(browser.js("!!document.querySelector('[data-action=approvals]')&&document.querySelector('[data-action=approvals]').textContent==='1'&&!!document.querySelector('[data-approval-week]')"),'Action dashboard counts employee weeks and provides a review link')
        browser.js("document.querySelector('[data-approval-week]').click()")
        browser.wait("document.getElementById('weekReview')?.open")
        check(browser.js("document.getElementById('weekReviewTitle').textContent.includes('Alice')"),'Dashboard review action opens the selected employee week')
        go('/index.php')
        check(browser.js("!document.querySelector('.topbar [data-theme-select]')"),'Application header has no theme control')
        check(browser.js("htmx.version==='2.0.11'&&Alpine.version==='3.17.5'"),'Pinned local HTMX and Alpine runtimes load')
        check(browser.js("fetch('"+base+"/modules/account/search.php?q=SearchFixture',{headers:{'HX-Request':'true'}}).then(async r=>r.headers.get('X-DevAtlas-Stack')==='CodeIgniter/4.7.5'&&r.headers.get('Content-Type').includes('text/html')&&(await r.text()).includes('data-record'))"),'CodeIgniter returns a scoped HTML search fragment for HTMX')
        check(browser.js("fetch('"+base+"/modules/account/search.php?q=SearchFixture').then(r=>r.json()).then(data=>Array.isArray(data.results))"),'Existing JSON search clients retain their response contract')
        check(browser.js("!!document.querySelector('link[href*=tailwind]')&&!!Alpine.$data(document.getElementById('moduleSearchWrap')).Search"),'Compiled Tailwind and Alpine search component are active')
        check(browser.js("!document.querySelector('#sidebarNav a[href*=applications]')&&!document.querySelector('#sidebarNav a[href*=requirements]')"),'Applications and Requirements are hidden for administrators too')
        browser.js("AT.searchModules('Applications')")
        check(browser.js("!document.querySelector('#moduleSearchResults a[href*=applications]')"),'Hidden module is absent from module search')
        browser.js("document.getElementById('moduleSearch').blur()")


        browser.js("document.getElementById('moduleSearch').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))")
        fit('Dashboard');shot('dashboard-light.png')
        select_theme('dark')
        check(browser.js("getComputedStyle(document.querySelector('.sidebar')).backgroundColor==='rgb(2, 6, 23)'&&getComputedStyle(document.querySelector('.topbar')).backgroundColor==='rgb(15, 23, 42)'"),'Dark sidebar and navbar match requested palette')
        shot('dashboard-dark.png')
        browser.js('AT.toggleUserMenu()')
        check(browser.js("document.querySelectorAll('[data-account-theme]').length===3&&document.querySelector('[data-account-theme=dark]').getAttribute('aria-pressed')==='true'"),'Account menu mirrors all three theme choices')
        browser.js('AT.closeUserMenu()')
        modules=['applications','requirements','datadict','diagrams','codereview','servers','proginventory','directory','bookmarks','teammonitoring','dailylogs','leaves','approvals','reviewlogs','reports','holidays','usermgmt','organization','permissions','settings','logs','audit','notifications','wiki']
        for module in modules:
            go('/modules/'+module+'/index.php')
            check(browser.js("!location.pathname.endsWith('.php')&&!document.querySelector('a[href*=\"/index.php\"]')"),module+' uses clean navigation and canonical page routes')
            check(browser.js("Swal.version==='11.26.25'&&getComputedStyle(document.body).fontFamily.startsWith('Poppins')"),module+' has shared Poppins and SweetAlert2 support')
            check(browser.js("[...document.querySelectorAll('svg:not(#erdSvg)')].every(svg=>svg.classList.contains('lucide'))"),module+' uses Lucide for interface icons')
            check(browser.js("document.title.endsWith('DevAtlas')&&(!!document.querySelector('[data-theme-select]')||!!document.querySelector('[data-account-theme]'))&&!/analysttoolkit/i.test(document.body.innerText)"),module+' has DevAtlas identity and accessible appearance choices')
            check(browser.js("document.documentElement.dataset.theme==='dark'&&getComputedStyle(document.body).backgroundColor==='rgb(11, 17, 32)'"),module+' preserves dark preference and background')
            viewport(390)
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'),module+' fits mobile')
            viewport(1440);select_theme('light')
            check(browser.js("getComputedStyle(document.body).backgroundColor==='rgb(248, 250, 252)'&&getComputedStyle(document.body).color==='rgb(15, 23, 42)'"),module+' uses light surfaces and text')
            select_theme('dark')
            check(browser.js('fixtureErrors.length===0'),module+' has no runtime errors')
        go('/modules/usermgmt/index.php')
        browser.wait("typeof WUI!=='undefined'")
        browser.js("WUI.open('userEditor')")
        browser.js("window.confirmResult=null;void WUI.confirm('Nested confirmation','Review the fixture action.','Continue').then(value=>window.confirmResult=value)")
        browser.wait("!!document.querySelector('.swal2-popup')")
        check(browser.js("document.getElementById('userEditor').open&&document.querySelector('.swal2-popup').closest('dialog').matches(':modal')&&document.querySelectorAll('dialog:modal').length===2"),'SweetAlert confirmation remains above an open module editor')
        check(browser.js("getComputedStyle(document.querySelector('.swal2-popup')).backgroundColor==='rgb(30, 41, 59)'&&getComputedStyle(document.querySelector('.swal2-popup')).fontFamily.startsWith('Poppins')"),'Dark feedback uses the shared card palette and Poppins')
        browser.js('Swal.clickCancel()');browser.wait('window.confirmResult===false')
        check(browser.js("document.getElementById('userEditor').open&&!document.querySelector('.atlas-alert-host')"),'Cancel retains the underlying editor')
        browser.js("WUI.close('userEditor')")
        viewport(320)
        browser.js("void AT.alert('A long feedback message wraps inside a mobile viewport.','error')")
        browser.wait("!!document.querySelector('.swal2-popup')")
        browser.wait("document.querySelector('.swal2-icon .lucide').getBBox().width>0")
        check(browser.js("document.querySelector('.swal2-popup').getBoundingClientRect().right<=innerWidth&&document.querySelector('.swal2-popup').getBoundingClientRect().left>=0"),'SweetAlert fits a 320px viewport')
        shot('feedback-dark-mobile.png');browser.js('Swal.clickConfirm()')
        browser.wait("!document.querySelector('.atlas-alert-host')")
        browser.js("AT.toast('Saved successfully.')")
        browser.wait("!!document.querySelector('.swal2-toast')&&Swal.isVisible()")
        check(browser.js("(()=>{const p=Swal.getPopup(),r=p.getBoundingClientRect();return r.height<=120&&r.right<=innerWidth&&r.left>=0&&p.scrollWidth<=p.clientWidth})()"),'A transaction toast stays compact and within a 320px mobile viewport')
        browser.js('Swal.close()');viewport(1440)
        go('/modules/permissions/index.php')
        browser.wait("!!document.querySelector('#permissionRows input')")
        check(browser.js("!document.querySelector('#permissionRows [data-module=applications]')&&!document.querySelector('#permissionRows [data-module=requirements]')"),'Permission matrix hides the two modules without rewriting grants')
        go('/modules/settings/index.php');fit('Parameters');shot('parameters-dark.png')
        go('/modules/notifications/index.php')
        browser.wait("!document.getElementById('notificationConfigSave').disabled&&document.getElementById('notificationLogHour').value==='17'")
        check(browser.js("document.getElementById('notificationDailyEnabled').checked&&document.getElementById('notificationApprovalEnabled').checked&&!document.getElementById('notificationLogEmail').checked"),'Daily-log and approval reminders default to in-app delivery')
        browser.js("document.getElementById('notificationLogHour').value='16';document.getElementById('notificationLogLookback').value='7';document.getElementById('notificationApprovalDelay').value='2';document.getElementById('notificationConfig').requestSubmit()")
        browser.wait("!document.getElementById('notificationConfigSave').disabled")
        go('/modules/notifications/index.php')
        browser.wait("!document.getElementById('notificationConfigSave').disabled&&document.getElementById('notificationLogHour').value==='16'")
        check(browser.js("document.getElementById('notificationLogLookback').value==='7'&&document.getElementById('notificationApprovalDelay').value==='2'"),'Reminder timing, lookback and delay persist')
        fit('Reminder settings')
        go('/index.php')
        def search_ui(text):
            browser.js("(()=>{const input=document.getElementById('moduleSearch');input.focus();input.value="+json.dumps(text)+";input.dispatchEvent(new Event('input',{bubbles:true}));})()")
            browser.wait("!document.getElementById('moduleSearchStatus').textContent.includes('Searching')")
        search_ui('SearchFixture Server')
        browser.wait("!!document.querySelector('[data-record-result]')")
        check(browser.js("document.querySelector('[data-record-result]').textContent.includes('SearchFixture Server')"),'Workspace search returns matching records')
        browser.js("(()=>{const input=document.getElementById('moduleSearch');input.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));input.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));})()")
        browser.wait("document.getElementById('inventoryDetailName')?.textContent==='SearchFixture Server'")
        check(True,'Keyboard selection opens the actual server record')
        go('/index.php');search_ui('SearchFixture Table')
        browser.js("document.querySelector('[data-record-result][href*=table_id]').click()")
        browser.wait("document.querySelector('#ddDetail h2')?.textContent==='SearchFixture Table'")
        check(True,'A dictionary search result opens its table detail')
        go('/index.php');search_ui('SearchFixture')
        check(browser.js("!document.querySelector('#moduleSearchResults img')&&!window.fixtureXss&&document.querySelectorAll('[data-record-result]').length>=6"),'Record labels are escaped and results span multiple modules')
        viewport(320)
        check(browser.js("document.getElementById('moduleSearchPanel').getBoundingClientRect().right<=innerWidth+1"),'Record search fits a 320px viewport')
        viewport(1440);browser.js("document.getElementById('moduleSearch').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))")
        go('/logout.php');browser.wait("!!document.getElementById('loginForm')")
        browser.js("document.getElementById('loginEmployeeId').value='search-worker';document.getElementById('loginPassword').value='BrandFixture123!';document.querySelector('#loginForm button[type=submit]').click()")
        browser.wait("document.getElementById('updatesDialog')?.open")
        browser.wait("!!document.querySelector('#updatesHistory [data-release]')")
        browser.js("document.getElementById('updatesDone').click()")
        browser.wait("!document.getElementById('updatesDialog').open")
        check(browser.js("!document.querySelector('[data-action=approvals]')&&document.querySelector('[data-action=team-people]').textContent==='1'"),'Employee dashboard hides review actions and limits team counts to their section')
        search_ui('SearchFixture')
        check(browser.js("!document.getElementById('moduleSearchResults').textContent.includes('Other SearchFixture')&&document.getElementById('moduleSearchResults').textContent.includes('Alice SearchFixture')"),'Record results enforce section scope for employees')
        check(browser.js("fetch('"+base+"/modules/notifications/api.php?action=config').then(r=>r.status)")==403,'Employees cannot read administrator reminder configuration')
        go('/logout.php');browser.wait("!!document.getElementById('loginForm')")
        browser.js("document.getElementById('loginEmployeeId').value='brand-admin';document.getElementById('loginPassword').value='BrandFixture123!';document.querySelector('#loginForm button[type=submit]').click()")
        browser.wait("!!document.getElementById('appTopbar')")
        go('/modules/wiki/index.php');fit('Standalone Wiki');shot('wiki-dark.png')
        select_theme('system')
        browser.call('Emulation.setEmulatedMedia', {'features':[{'name':'prefers-color-scheme','value':'dark'}]})
        browser.wait("document.documentElement.dataset.theme==='dark'")
        go('/index.php')
        check(browser.js("AT.getThemePreference()==='system'&&AT.getTheme()==='dark'"),'System preference persists across module navigation')
        browser.call('Emulation.setEmulatedMedia', {'features':[{'name':'prefers-color-scheme','value':'light'}]})
        browser.wait("document.documentElement.dataset.theme==='light'")
        check(browser.js("AT.getThemePreference()==='system'"),'Device theme changes update the appearance without losing System selection')
        for route in ['/forgot_password.php','/reset_password.php','/change_password.php']:
            go(route);check(browser.js("document.title.includes('DevAtlas')&&!!document.querySelector('[data-theme-select]')"),route+' uses DevAtlas frame')
        for width in [390,1440]:
            viewport(width)
            check(browser.js("(()=>{const card=document.querySelector('body>.card'),box=card.getBoundingClientRect(),style=getComputedStyle(card);return Math.abs(box.left+box.width/2+(parseFloat(style.marginRight)-parseFloat(style.marginLeft))/2-innerWidth/2)<2&&Math.abs(box.top+box.height/2+(parseFloat(style.marginBottom)-parseFloat(style.marginTop))/2-innerHeight/2)<2;})()"),f'Password-change form remains centered at {width}px after stylesheet extraction')
        print(f'DevAtlas browser checks: {CHECKS} assertions passed.')
    finally:
        if browser:
            try:browser.call('Browser.close')
            except Exception:pass
            browser.sock.close()
        for process in reversed(processes):
            if process.poll() is None:process.terminate()
            try:process.wait(timeout=10)
            except subprocess.TimeoutExpired:process.kill();process.wait()
        try:cli('cleanup')
        finally:
            log.close()
            target=temp.resolve();allowed=(ROOT/'tests').resolve()
            if target.parent==allowed and target.name.startswith('.tmp-devatlas-'):shutil.rmtree(target,ignore_errors=True)


if __name__=='__main__':
    main()
