"""Daily log API and Edge checks in a disposable schema, session directory and browser profile."""
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
    temp = Path(tempfile.mkdtemp(prefix='.tmp-daily-logs-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    port, debug = h.port(), h.port()
    base = f'http://127.0.0.1:{port}/AnalystToolkit'
    schema = 'daily_browser_' + os.urandom(6).hex()
    (temp / 'fixture.json').write_text(json.dumps({'root': str(ROOT).replace('\\', '/'), 'schema': schema}))
    router = temp / 'router.php'
    router.write_text(r'''<?php
$f=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);$schema=$f['schema'];
if(!preg_match('/^daily_browser_[a-f0-9]{12}$/D',$schema))exit;
if(PHP_SAPI==='cli')session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once $f['root'].'/lib/Logger.php';$p=new ReflectionProperty(Logger::class,'directory');$p->setValue(null,__DIR__.'/logs');
require_once $f['root'].'/config/Database.php';$db=Database::getConnection('app');
if(PHP_SAPI==='cli'){
 set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n");exit(1);});
 if($argv[1]==='cleanup'){$db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');exit;}
 if($argv[1]==='revoke_report_export'){$db->query('SET search_path TO "'.$schema.'",public');$db->query("UPDATE role_permissions SET is_allowed=FALSE WHERE role_key='analyst' AND module='reports' AND permission='export'");echo '{}';exit;}
 if($argv[1]==='setup'){
  $db->query(str_replace('toolkit',$schema,file_get_contents($f['root'].'/database/schema.sql')));$db->query('SET search_path TO "'.$schema.'",public');
  $db->query('DELETE FROM users');
  $g=Database::Scalar($db->query("INSERT INTO org_groups(group_name)VALUES('Fixture') RETURNING group_id"));
  $v=Database::Scalar($db->query("INSERT INTO org_divisions(group_id,division_name)VALUES($g,'Fixture') RETURNING division_id"));
  $d=Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name)VALUES($v,'Fixture') RETURNING department_id"));
  $u=Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($d,'Section A') RETURNING unit_id"));
  $o=Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($d,'Section B') RETURNING unit_id"));
  $sSql="INSERT INTO users(employee_id,password_hash,first_name,last_name,unit_id,role_key,must_change_password,password_changed_at)VALUES(?,?,?,'Fixture',?,?,FALSE,NOW()) RETURNING user_id";
  $people=[];foreach(['employee'=>[$u,'analyst'],'reviewer'=>[$u,'reviewer'],'backup'=>[$u,'reviewer'],'outsider'=>[$o,'reviewer'],'admin'=>[null,'admin']] as $name=>[$unit,$role]){$s = $db->query($sSql, [$name,password_hash('DailyFixture123!',PASSWORD_BCRYPT),ucfirst($name),$unit,$role]);$people[$name]=(int)Database::Scalar($s);}
  $db->query("UPDATE users SET group_id=$g,division_id=$v,department_id=$d WHERE unit_id IS NOT NULL");
  $db->query('INSERT INTO daily_log_reviewers(unit_id,reviewer_user_id,assigned_by)VALUES(?,?,?)', [$o,$people['outsider'],$people['admin']]);
  $db->query("UPDATE role_permissions SET is_allowed=TRUE WHERE role_key='reviewer' AND module='leaves' AND permission='review'");
  $db->query('UPDATE notification_settings SET email_enabled=FALSE');echo json_encode(['people'=>$people,'unit'=>$u,'other'=>$o]);exit;
 }
 exit;
}
$db->query('SET search_path TO "'.$schema.'",public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if(str_starts_with($relative,'assets/')){$file=realpath($f['root'].'/'.$relative);$assets=realpath($f['root'].'/assets');if($file&&str_starts_with(strtolower($file),strtolower($assets.DIRECTORY_SEPARATOR))&&is_file($file)){header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;}}
if(!in_array($relative,['index.php','login.php','logout.php'],true)&&!preg_match('#^modules/(approvals|organization|leaves|dailylogs|teammonitoring|reviewlogs|reports|holidays|wiki|updates|notifications|audit|permissions|usermgmt)/[a-z_]+\.php$#D',$relative)){http_response_code(404);exit;}
$file=$f['root'].'/'.$relative;$_SERVER['SCRIPT_NAME']='/AnalystToolkit/'.$relative;if(!is_file($file)){http_response_code(404);exit;}require $file;
''', encoding='utf-8')
    processes, browser = [], None
    log = open(temp / 'server.log', 'wb')

    def cli(action):
        r = subprocess.run([str(h.PHP), str(router), action], capture_output=True, timeout=30, cwd=ROOT, creationflags=subprocess.CREATE_NO_WINDOW)
        if r.returncode:
            raise RuntimeError(r.stderr.decode(errors='replace'))
        return json.loads(r.stdout) if r.stdout.strip() else None

    def go(route):
        browser.go(base + route)
        if '/modules/reports/' in route:
            browser.wait("document.getElementById('reportFrom').value!==''||!document.getElementById('reportsError').hidden")
        elif '/modules/holidays/' in route:
            browser.wait("document.getElementById('holidayCount').textContent.includes(' in ')||!document.getElementById('holidayError').hidden")
        elif '/modules/leaves/' in route:
            browser.wait("document.getElementById('leaveCalendar').getAttribute('aria-busy')==='false'")
        elif '/modules/approvals/' in route and 'tab=leaves' in route:
            browser.wait("document.getElementById('leaveReviewCount').textContent.includes('Page')")
        elif '/modules/usermgmt/' in route:
            browser.wait("document.getElementById('userRows').children.length>0")
        elif '/modules/wiki/' in route:
            browser.wait("document.getElementById('wikiCount').textContent.includes(' of ')")
        elif '/modules/permissions/' in route:
            browser.wait("document.getElementById('permissionRole').options.length>0")
        elif '/modules/' in route:
            browser.wait("document.getElementById('logCount').textContent.includes('Page')||!document.getElementById('dailyError').hidden")

    def value(id, val, event='input'):
        browser.js(f"document.getElementById({json.dumps(id)}).value={json.dumps(str(val))};document.getElementById({json.dumps(id)}).dispatchEvent(new Event({json.dumps(event)},{{bubbles:true}}))")

    def click(selector):
        browser.js('document.querySelector(' + json.dumps(selector) + ').click()')

    def api(module, action, data=None, csrf=True, method=None, query=''):
        route=f'/modules/{module}/api.php?action={action}' + query
        return browser.js("(async()=>{const r=await fetch(" + json.dumps(base + route) + ",{method:" + json.dumps(method or ('POST' if data is not None else 'GET')) + ",headers:{'Content-Type':'application/json','X-CSRF-Token':" + ("document.querySelector('meta[name=csrf-token]')?.content||''" if csrf else "''") + "}" + (",body:JSON.stringify(" + json.dumps(data) + ")" if data is not None else '') + "});return {status:r.status,body:await r.json()};})()")

    def login(name, dismiss_updates=True):
        browser.go(base + '/logout.php')
        browser.wait("!!document.getElementById('loginForm')")
        value('loginEmployeeId', name)
        value('loginPassword', 'DailyFixture123!')
        click('#loginForm button[type=submit]')
        browser.wait("!!document.getElementById('appTopbar')")
        if dismiss_updates and api('updates','notice')['body']['pending']:
            browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
            click('#updatesDone');browser.wait("!document.getElementById('updatesDialog').open")

    def fit(label):
        for width in [320, 390, 768, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width':width,'height':1000,'deviceScaleFactor':1,'mobile':width<600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'{label} fits {width}px')
        check(browser.js('fixtureErrors.length===0&&!window.fixtureXss'), label + ' has no script errors or executed markup')

    try:
        f=cli('setup')
        processes.append(subprocess.Popen([str(h.PHP),'-d',f'session.save_path={temp}','-d',f'sys_temp_dir={temp}','-S',f'127.0.0.1:{port}',str(router)],cwd=ROOT,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW))
        for _ in range(100):
            try:
                urllib.request.urlopen(base+'/login.php',timeout=1).close()
                break
            except OSError:
                time.sleep(.1)
        processes.append(subprocess.Popen([str(h.EDGE),'--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-extensions','--remote-allow-origins=*','--user-data-dir='+str(temp/'edge'),f'--remote-debugging-port={debug}','about:blank'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW))
        for _ in range(150):
            try:
                pages=json.load(urllib.request.urlopen(f'http://127.0.0.1:{debug}/json/list',timeout=1))
                if pages:
                    break
            except OSError:
                time.sleep(.1)
        browser=h.Browser(next(p['webSocketDebuggerUrl'] for p in pages if p['type']=='page'))
        browser.go(base+'/login.php')
        check(api('dailylogs','catalog')['status']==401 and api('reports','catalog')['status']==401,'Guest daily logs and reports access is denied')
        login('admin',False)
        browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
        notes=api('updates','history')['body']['releases']
        check(browser.js("document.querySelector('#updatesHistory [data-release]').dataset.release==="+json.dumps(notes[0]['id'])),"What's New automatically opens with the newest release first")
        fit('Update history modal')
        check(api('updates','acknowledge',{'release_id':notes[0]['id']},csrf=False)['status']==419,'Update acknowledgement requires CSRF protection')
        check(api('updates','acknowledge',{'release_id':'forged-release'})['status']==409,'Forged release acknowledgement is rejected')
        click('#updatesDone');browser.wait("!document.getElementById('updatesDialog').open")
        check(not api('updates','notice')['body']['pending'],'Closing update history saves the current user acknowledgement')
        go('/index.php')
        check(not api('updates','notice')['body']['pending'],'Acknowledgement survives navigation')
        click('#helpMenu summary');click('#helpMenu [data-updates-open]')
        browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
        check(browser.js("location.pathname==='/AnalystToolkit/'&&!document.querySelector('#helpMenu a[href*=updates]')"),"Help opens What's New in a modal without navigating")
        click('#updatesClose');browser.wait("!document.getElementById('updatesDialog').open")
        check(browser.js("!document.querySelector('#sidebarNav a[href*=wiki]')&&document.querySelector('#helpMenu a[href*=wiki]').target==='_blank'"),'Wiki is accessed from Help in a separate tab, outside the sidebar')
        check(browser.js("document.querySelector('#sidebarNav a[href*=holidays]').closest('.nav-section').querySelector('.nav-heading').textContent==='Parameter'"),'Holidays appears in the Parameter sidebar group')
        go('/modules/wiki/index.php')
        check(browser.js("document.querySelectorAll('[data-wiki-guide]').length")==len(api('permissions','matrix')['body']['capabilities']),'Wiki covers every registered module')
        check(browser.js("!document.getElementById('sidebarNav')&&!!document.querySelector('.docs-header')&&document.querySelector('[data-wiki-guide=reports]').textContent.includes('MADMONFERO')"),'Wiki has a standalone layout and keeps report-name instructions')
        check(browser.js("[...document.querySelectorAll('.wiki-planned article')].length===10&&document.getElementById('roadmap').textContent.includes('no committed delivery dates')"),'Roadmap distinguishes delivered functionality from ten planned areas')
        value('wikiSearch','weekly approval')
        check(browser.js("!document.querySelector('[data-wiki-guide=approvals]').hidden&&document.querySelector('[data-wiki-guide=servers]').hidden"),'Wiki search filters guides and keeps relevant approval instructions')
        value('wikiSearch','zzzxxyynothing')
        check(browser.js("!document.getElementById('wikiEmpty').hidden&&document.querySelector('.wiki-roadmap').hidden===false"),'Wiki no-results state preserves the roadmap')
        browser.js("location.hash='module-dailylogs'")
        browser.wait("document.getElementById('wikiSearch').value===''")
        check(browser.js("!document.querySelector('[data-wiki-guide=dailylogs]').hidden"),'Workflow links reveal guides hidden by a previous search')
        fit('Documents Wiki')
        value('wikiSearch','zzzxxyynothing')
        click('.wiki-start a[href="#module-dailylogs"]')
        check(browser.js("document.getElementById('wikiSearch').value===''&&!document.getElementById('module-dailylogs').hidden"),'Repeated workflow anchor reveals a filtered guide')
        click('.docs-header [data-updates-open]');browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
        check(browser.js("!!document.querySelector('#updatesHistory [data-release]')&&!document.getElementById('sidebarNav')"),'Standalone Wiki can open the update-history modal')
        click('#updatesDone');browser.wait("!document.getElementById('updatesDialog').open")
        go('/modules/reviewlogs/index.php');click('#sectionReviewersOpen')
        browser.wait("document.querySelectorAll('[data-reviewer-unit]').length===2")
        value('sectionReviewer_'+str(f['unit']),f['people']['reviewer'],'change');click('[data-reviewer-unit="'+str(f['unit'])+'"] button[type=submit]')
        browser.wait("!document.getElementById('sectionReviewersRefresh').disabled")
        check(api('reviewlogs','reviewer_assignments')['body']['units'][0]['reviewer_user_id']==f['people']['reviewer'],'Administrator assigns the section weekly reviewer')
        go('/modules/teammonitoring/index.php');value('filterWeek','2026-10-26');click('#logFilter')
        browser.wait("document.getElementById('weekCovered').textContent.includes('Oct 26')")
        go('/modules/holidays/index.php')
        value('holidayDate','2026-10-27');value('holidayName','Organization holiday');click('#holidaySave')
        browser.wait("!document.getElementById('holidaySave').disabled&&document.getElementById('holidayRows').textContent.includes('Organization holiday')")
        check(len(api('holidays','holidays',query='&year=2026')['body']['holidays'])==1,'Organization holiday saves through the UI')
        fit('Organization holidays')
        first_holiday=api('holidays','holidays',query='&year=2026')['body']['holidays'][0]
        click('[data-holiday-edit]');value('holidayName','Updated organization holiday');click('#holidaySave')
        browser.wait("!document.getElementById('holidaySave').disabled&&document.getElementById('holidayRows').textContent.includes('Updated organization holiday')")
        check(api('holidays','save_holiday',{**first_holiday,'holiday_name':'Stale update'})['status']==409,'Standalone holiday editor rejects stale changes')
        check(api('holidays','save_holiday',{'holiday_date':'2026-10-28','holiday_name':'Missing CSRF'},csrf=False)['status']==419,'Holiday writes require CSRF protection')
        click('[data-holiday-edit]');value('holidayName','Organization holiday');click('#holidaySave')
        browser.wait("!document.getElementById('holidaySave').disabled&&document.getElementById('holidayRows').textContent.includes('Organization holiday')")
        value('holidayYear','2025','change');browser.wait("document.getElementById('holidayCount').textContent.includes('2025')")
        check(browser.js("document.getElementById('holidayRows').textContent.includes('No configured holidays')"),'Standalone calendar filters holidays by year')
        value('holidayYear','2026','change');browser.wait("document.getElementById('holidayCount').textContent.includes('2026')")
        check(api('teammonitoring','holidays',query='&year=2026')['status']==404,'Holiday management no longer uses Team Monitoring endpoints')
        check(api('holidays','save_holiday',{'holiday_date':'2026-10-27','holiday_name':'Duplicate'})['status']==409,'Duplicate organization holiday is rejected')
        check(api('holidays','save_holiday',{'holiday_date':'2026-02-30','holiday_name':'Bad date'})['status']==422,'Invalid holiday date is rejected')
        check(api('reviewlogs','review_logs',{'items':[]})['status']==404,'The old individual approval endpoint is removed')
        go('/modules/usermgmt/index.php');click('#userAdd')
        browser.wait("document.getElementById('userEditor').open&&!document.getElementById('userFields').disabled")
        check(browser.js("document.getElementById('us_birthday').type==='date'&&!document.getElementById('us_birthday').required"),'User creation includes an optional Birthday field')
        value('us_employee_id','birthday-fixture');value('us_first_name','Birthday');value('us_last_name','Fixture');value('us_birthday','1990-10-28')
        click('#saveUserBtn');browser.wait("!document.getElementById('userEditor').open")
        birthday_user=next(user for user in api('usermgmt','list_users')['body'] if user['employee_id']=='birthday-fixture')
        birthday_record=api('usermgmt','get_user',query='&user_id='+str(birthday_user['user_id']))['body']
        check(birthday_record['birthday']=='1990-10-28','Creating a user stores their birthday')
        check(api('usermgmt','save_user',{**birthday_record,'birthday':'2026-02-30'})['status']==422,'Invalid birthdays are rejected by the API')
        check(api('usermgmt','save_user',{**birthday_record,'birthday':'2099-01-01'})['status']==422,'Future birthdays are rejected by the API')
        without_birthday={key:val for key,val in birthday_record.items() if key!='birthday'}
        check(api('usermgmt','save_user',without_birthday)['status']==200 and api('usermgmt','get_user',query='&user_id='+str(birthday_user['user_id']))['body']['birthday']=='1990-10-28','Account updates that omit birthday preserve the existing value')
        colleague_birthday=api('usermgmt','get_user',query='&user_id='+str(f['people']['backup']))['body']
        check(api('usermgmt','save_user',{**colleague_birthday,'birthday':'1991-10-28'})['status']==200,'Existing section personnel can have their birthday updated')
        login('employee');go('/modules/dailylogs/index.php');fit('Personal daily logs')
        check(api('holidays','save_holiday',{'holiday_date':'2026-10-28','holiday_name':'Denied'})['status']==403,'Non-administrator cannot add holidays')
        go('/modules/holidays/index.php')
        check(browser.js("!document.getElementById('holidayForm')&&!!document.querySelector('#sidebarNav a[href*=holidays]')"),'Personnel can view the standalone calendar without administrator editing controls')
        fit('Read-only holidays')
        go('/modules/dailylogs/index.php')
        check(api('dailylogs','calendar',query='&date=2026-10-27')['body']['working'] is False,'Personal daily log calendar identifies the organization holiday')
        check(api('reviewlogs','list_weeks')['status']==403,'Monitoring access does not grant weekly review access')
        descriptions = {
            'TM': 'Discussion/Meeting within ITG',
            'UM': 'Discussion/Meeting with users',
            'EM': 'Discussion/Meeting with external parties outside BMI',
            'AN': 'Business Requirement Analysis for Projects/Business Impact Analysis for Service Delivery',
            'TD': 'Preparation of Technical Documents (System Design, Process Flow, Data Flow, Program & Report Inventory)',
            'ED': "Knowledge Transfer Session, Mentoring (for TL's and Officers only), Research, Training (external / internal)",
            'AD': 'Administrative Meeting within Unit, Coaching, PCF/PAR preparation',
            'DD': 'Program Development/Coding, Unit testing session, Script creation',
            'UT': 'Testing proper, Documentation of testing',
            'CR': 'Code review against Coding standards',
            'DR': 'Documentary standards validation',
            'VC': 'Program / System Revision Monitoring',
            'HD': 'System Support, Bug Resolution, Problem Isolation, Inquiry',
            'ST': 'SIT Proper, Documentation of results',
            'UA': 'Assistance on UAT, Deployment to UAT environment',
            'PI': 'Post Implementation Review including Documentation',
            'DP': 'Preparation of MOM, Project Dashboard update, Project Documentation (Charter, Understanding Document, Test Scripts, Deployment Documents), Daily / Monthly Reports preparation',
            'RV': 'Documentation review, SMF review, SOS review, UD review, TD review, PD review, Cost and Benefit Analysis, Project / SCR Assessment',
            'SF': 'Other functions not related to ITG functions such as: FRA meeting, Compliance Advocate Meeting, CIMS Meeting',
            'MR': "ITMSC Meeting, ManCom Meeting, Board of Directors Presentation, Other Board Committees' presentation",
        }
        catalog = api('dailylogs','catalog')['body']
        check(catalog['activity_descriptions'] == descriptions, 'Catalog contains the exact twenty supplied descriptions')
        click('#logAdd')
        check(browser.js("document.getElementById('entryActivityHelpText').textContent.includes('Choose an activity')&&document.getElementById('entryActivity').getAttribute('aria-describedby')==='entryActivityHelp'"), 'New entry provides an accessible activity guide')
        for code, description in descriptions.items():
            value('entryActivity',code,'change')
            check(browser.js("document.getElementById('entryActivityHelpText').textContent==="+json.dumps(description)+"&&document.getElementById('entryActivityHelpTitle').textContent.includes("+json.dumps(catalog['activities'][code])+")"), code+' immediately shows its title and full description')
        value('entryActivity','DP','change')
        for theme in ['light','dark']:
            browser.js('AT.setTheme('+json.dumps(theme)+')')
            fit('Activity description in '+theme)
            check(browser.js("document.getElementById('entryActivityHelpText').getBoundingClientRect().width<=document.getElementById('logEditor').getBoundingClientRect().width"), 'Long activity guidance wraps within the editor in '+theme)
        check(browser.js("!document.getElementById('entryKind')&&!document.getElementById('leaveFields')"), 'Daily Logs offers work activities while leave applications use their own module')
        click('[data-close=logEditor]');click('#logAdd')
        check(browser.js("document.getElementById('entryActivity').value===''&&document.getElementById('entryActivityHelpText').textContent.includes('Choose an activity')"), 'Reopening Add resets the selected description')
        value('entryDate','2026-10-26','change');value('entryClass','SD','change');value('entryActivity','HD','change')
        click('#logSaveDraft');browser.wait("!document.getElementById('logEditor').open&&!!document.querySelector('[data-edit]')")
        click('[data-edit]');browser.wait("document.getElementById('logEditor').open")
        check(browser.js("document.getElementById('entryActivity').value==='HD'&&document.getElementById('entryActivityHelpText').textContent==='System Support, Bug Resolution, Problem Isolation, Inquiry'"), 'Editing a saved draft restores its activity description')
        value('entryPlanned','=HYPERLINK("https://example.invalid")');value('entryActual','Work <img src=x onerror=window.fixtureXss=1>');value('entryHours','8');click('#logSubmit')
        browser.wait("!document.getElementById('logEditor').open&&document.getElementById('logRows').textContent.includes('Submitted')")
        id=api('dailylogs','list_logs')['body']['logs'][0]['log_id']
        submitted=api('dailylogs','get_log',query='&log_id='+str(id))['body']
        click('[data-edit="'+str(id)+'"]');browser.wait("document.getElementById('logEditor').open")
        check(browser.js("document.getElementById('logSaveDraft').hidden&&document.getElementById('logSubmit').textContent==='Save Changes'&&document.getElementById('logEditorHint').textContent.includes('weekly approval')"),'Submitted editor offers Save Changes and keeps the pending approval status')
        value('entryHours','7.5');click('#logSubmit')
        browser.wait("!document.getElementById('logEditor').open&&document.getElementById('logRows').textContent.includes('7.50')")
        updated=api('dailylogs','get_log',query='&log_id='+str(id))['body']
        check(updated['status']=='Submitted' and float(updated['duration_hours'])==7.5,'Saving a submitted edit updates hours and retains Submitted')
        check(api('dailylogs','save_log',submitted)['status']==409,'Submitted edits reject an outdated record version')
        check(api('dailylogs','save_log',{**updated,'status':'Draft'})['status']==409,'Submitted logs cannot be changed back to Draft')
        check(api('dailylogs','delete_log',updated)['status']==409,'Submitted logs remain protected from deletion')
        click('[data-view="'+str(id)+'"]');browser.wait("document.getElementById('logDetail').open")
        check(browser.js("!!document.getElementById('detailEdit')&&!document.getElementById('detailDelete')"),'Submitted details allow editing without exposing Delete')
        click('#detailEdit');browser.wait("document.getElementById('logEditor').open")
        value('entryHours','8');value('entryRemarks','Corrected submitted work');click('#logSubmit')
        browser.wait("!document.getElementById('logEditor').open&&document.getElementById('logRows').textContent.includes('8.00')")
        check(api('dailylogs','get_log',query='&log_id='+str(id))['body']['remarks']=='Corrected submitted work','Submitted detail editing saves the corrected entry')
        click('#logAdd')
        check(browser.js("!document.getElementById('logSaveDraft').hidden&&document.getElementById('logSubmit').textContent==='Submit'"),'Adding a new log restores the Draft and Submit actions')
        click('[data-close=logEditor]')
        work={'activity_date':'2026-10-26','entry_kind':'activity','status':'Submitted','classification':'SD','activity_type':'HD','planned_task':'Planned work','actual_activity':'Completed work','duration_hours':8}
        ids=[id]
        for date,hours in [('2026-10-28',4.25),('2026-10-29',4),('2026-10-30',8),('2026-10-31',2)]:
            r=api('dailylogs','save_log',{**work,'activity_date':date,'duration_hours':hours});check(r['status']==200,'Employee can submit a dated work entry');ids.append(r['body']['log_id'])
        leave={'activity_date':'2026-10-29','entry_kind':'leave','status':'Submitted','leave_type':'VL','leave_period':'AM','duration_hours':4,'remarks':'AM leave'}
        check(api('dailylogs','save_log',leave)['status']==422,'Daily Logs no longer accepts leave applications')
        go('/modules/leaves/index.php?month=2026-10')
        check(browser.js("!!document.querySelector('#sidebarNav a[href*=leaves]')&&document.querySelectorAll('#leaveCalendar [role=columnheader]').length===7"),'Leaves has its own navigation and a seven-day calendar')
        check(browser.js("document.querySelector('.leave-birthday')?.textContent.includes('Backup Fixture')&&!document.querySelector('.leave-birthday')?.textContent.includes('1991')"),'Department calendar shows personnel birthdays without their birth years')
        check(browser.js("document.getElementById('leaveScope').textContent.includes('All sections')&&!document.querySelector('[data-leave-date=\"2026-10-27\"]')"),'Department calendar identifies holidays and prevents applying from a holiday cell')
        click('[data-leave-date="2026-10-29"]')
        check(browser.js("document.getElementById('leaveDate').value==='2026-10-29'&&document.getElementById('leaveEditor').open"),'Selecting a calendar date opens a prefilled leave application')
        value('leavePeriod','AM','change');value('leaveRemarks','PRIVATE_BROWSER_LEAVE_REASON')
        check(browser.js("document.getElementById('leaveHours').value==='4'&&document.getElementById('leaveHours').readOnly"),'Half-day leave calculates four hours')
        click('#leaveSubmit');browser.wait("!document.getElementById('leaveEditor').open&&document.getElementById('leaveMineRows').textContent.includes('Pending approval')")
        leave_id=api('leaves','calendar',query='&month=2026-10')['body']['mine']['rows'][0]['log_id']
        check(browser.js("!!document.querySelector('.leave-event[data-leave-view=\""+str(leave_id)+"\"]')"),'Submitted leave appears on the department calendar')
        check(api('leaves','save_leave',{**leave,'activity_date':'2026-10-27'})['status']==422,'Leave cannot be submitted on a holiday')
        check(api('leaves','save_leave',{**leave,'activity_date':'2026-10-31'})['status']==422,'Leave cannot be submitted on a weekend')
        check(api('leaves','save_leave',leave,csrf=False)['status']==419,'Leave writes require CSRF protection')
        check(api('leaves','review_leave',{'log_id':leave_id,'version':1,'decision':'Reviewed'})['status']==403,'Personnel without the separate approval grant cannot approve leave')
        for theme in ['light','dark']:
            browser.js('AT.setTheme('+json.dumps(theme)+')');fit('Leave calendar in '+theme)
        browser.js("AT.setTheme('light')")
        click('#leaveAdd');value('leaveDate','2026-11-02','change');value('leaveDateTo','2026-11-08','change');click('#leaveSubmit')
        browser.wait("!document.getElementById('leaveEditor').open&&document.getElementById('leaveMonth').value==='2026-11'&&document.getElementById('leaveCalendarTitle').textContent.includes('November')")
        range_id=api('leaves','calendar',query='&month=2026-11')['body']['mine']['rows'][0]['log_id']
        range_record=api('leaves','get_leave',query='&log_id='+str(range_id))['body']
        check(range_record['date_from']=='2026-11-02' and range_record['date_to']=='2026-11-08' and range_record['working_days']==5 and range_record['total_hours']==40,'Date From and Date To produce one five-working-day application')
        check(browser.js("document.getElementById('leaveMineRows').querySelectorAll('tr').length===1&&document.querySelectorAll('.leave-calendar-day[aria-label^=\"2026-11\"] .leave-event').length===5"),'A leave range is listed once and appears on every covered calendar date')
        draft=api('dailylogs','save_log',{'activity_date':'2026-10-30','status':'Draft'})['body']['log_id']
        check(api('teammonitoring','get_log',query='&log_id='+str(draft))['status']==404,'Monitoring does not disclose private drafts')
        go('/modules/teammonitoring/index.php');value('filterWeek','2026-10-26');click('#logFilter')
        browser.wait("document.getElementById('logRows').textContent.includes('28.25')")
        check(browser.js("document.getElementById('logRows').textContent.includes('3.75 hrs still needed')&&document.getElementById('logRows').textContent.includes('Organization holiday')"),'Monitoring shows entered hours, remaining hours and holiday cells')
        fit('Entered and remaining hours')
        login('reviewer');go('/modules/reviewlogs/index.php')
        check(browser.js("document.querySelectorAll('[data-select]').length===1"),'Five work entries appear as one employee-week approval')
        w=api('reviewlogs','get_week',query='&user_id='+str(f['people']['employee'])+'&week_start=2026-10-26')['body'];oldtoken=w['token']
        login('employee');go('/modules/dailylogs/index.php')
        current=api('dailylogs','get_log',query='&log_id='+str(id))['body']
        check(api('dailylogs','save_log',{**current,'remarks':'Updated while awaiting review'})['status']==200,'An owner can edit a submitted log after the reviewer opens its week')
        login('reviewer');go('/modules/reviewlogs/index.php')
        check(api('dailylogs','save_log',{**current,'remarks':'Denied reviewer edit'})['status']==404,'A reviewer cannot edit another employee\'s submitted log')
        check(api('reviewlogs','review_weeks',{'items':[{'user_id':f['people']['employee'],'week_start':'2026-10-26','token':oldtoken}]})['status']==409,'Content-only edits require the reviewer to refresh the weekly approval snapshot')
        click('[data-week]');browser.wait("document.getElementById('weekReview').open")
        check(browser.js("document.querySelectorAll('#weekReviewRows [data-view]').length===5&&!window.fixtureXss"),'Weekly review shows work entries and escapes markup')
        fit('Weekly review')
        click('#weekReviewRows [data-view]');browser.wait("document.getElementById('logDetail').open")
        check(browser.js("!document.getElementById('detailApprove')&&!document.getElementById('detailReturn')&&!window.fixtureXss"),'Individual entry details have no approval controls')
        click('[data-close=logDetail]');click('#weekReturn')
        check(browser.js("!document.getElementById('weekReviewError').hidden"),'Returning a week requires one comment')
        value('weekReviewComment','Please correct the weekly entries');click('#weekReturn')
        browser.wait("!document.getElementById('weekReview').open")
        check(all(r['status']=='Returned' for r in api('reviewlogs','get_week',query='&user_id='+str(f['people']['employee'])+'&week_start=2026-10-26')['body']['logs']),'One return decision returns all employee-week entries')
        login('employee');go('/modules/dailylogs/index.php')
        for log_id in ids:
            row=api('dailylogs','get_log',query='&log_id='+str(log_id))['body'];row['status']='Submitted'
            check(api('dailylogs','save_log',row)['status']==200,'Returned week entries can be corrected and resubmitted')
        r=api('dailylogs','save_log',{**work,'activity_date':'2026-10-28','duration_hours':3.75});ids.append(r['body']['log_id'])
        login('reviewer');go('/modules/reviewlogs/index.php')
        check(api('reviewlogs','review_weeks',{'items':[{'user_id':f['people']['employee'],'week_start':'2026-10-26','token':oldtoken}]})['status']==409,'Weekly approval rejects an old snapshot')
        check(api('reviewlogs','review_weeks',{'items':[{'log_id':id,'version':1}]})['status']==422,'Forged individual approval payloads are rejected')
        click('[data-week]');browser.wait("document.getElementById('weekReview').open");click('#weekApprove')
        browser.wait("!document.getElementById('weekReview').open&&document.getElementById('logRows').textContent.includes('No employee weeks')")
        approved=api('reviewlogs','get_week',query='&user_id='+str(f['people']['employee'])+'&week_start=2026-10-26')['body']
        check(len(approved['logs'])==6 and all(r['status']=='Reviewed' for r in approved['logs']) and approved['decision']['status']=='Reviewed','One approval approves the complete employee work week')
        leave_review=api('leaves','get_leave',query='&log_id='+str(leave_id))['body']
        check(leave_review['status']=='Submitted','Weekly work approval leaves the leave application pending its own decision')
        go('/modules/leaves/index.php?month=2026-10')
        check(browser.js("!document.getElementById('leaveReviewRows')"),'The leave calendar no longer contains an approval queue')
        click('.leave-event[data-leave-view="'+str(leave_id)+'"]');browser.wait("document.getElementById('leaveDetail').open")
        check(browser.js("!document.getElementById('leaveApprove')&&!!document.querySelector('#leaveDetailActions a[href*=approvals]')"),'Calendar details link approvers to the separate approval module')
        click('[data-leave-close=leaveDetail]')
        go('/modules/approvals/index.php?tab=leaves')
        check(browser.js("document.querySelectorAll('.approval-tabs a').length===2&&!!document.querySelector('#sidebarNav a.active[href*=approvals]')"),'Approvals combines separate Daily Logs and Leave tabs')
        click('#leaveReviewRows [data-leave-view="'+str(leave_id)+'"]')
        browser.wait("document.getElementById('leaveDetail').open")
        check(browser.js("document.getElementById('leaveDetailFields').textContent.includes('PRIVATE_BROWSER_LEAVE_REASON')&&!!document.getElementById('leaveApprove')"),'Authorized section approver can read remarks and approve the leave application')
        click('#leaveApprove');browser.wait("!!document.querySelector('.swal2-popup:not(.swal2-toast) .swal2-confirm')")
        click('.swal2-popup:not(.swal2-toast) .swal2-confirm');browser.wait("!document.getElementById('leaveDetail').open")
        check(api('leaves','get_leave',query='&log_id='+str(leave_id))['body']['status']=='Reviewed','Leave approval uses SweetAlert and its own request decision')
        click('#leaveReviewRows [data-leave-view="'+str(range_id)+'"]');browser.wait("document.getElementById('leaveDetail').open")
        check(browser.js("document.getElementById('leaveDetailFields').textContent.includes('2026-11-08')&&document.getElementById('leaveDetailFields').textContent.includes('40.00')"),'The approval detail shows the full date range and total hours')
        click('#leaveApprove');browser.wait("!!document.querySelector('.swal2-popup:not(.swal2-toast) .swal2-confirm')")
        click('.swal2-popup:not(.swal2-toast) .swal2-confirm');browser.wait("!document.getElementById('leaveDetail').open")
        check(api('leaves','get_leave',query='&log_id='+str(range_id))['body']['status']=='Reviewed','One approval covers the complete leave range')
        go('/modules/teammonitoring/index.php');value('filterWeek','2026-10-26');click('#logFilter')
        browser.wait("document.getElementById('logRows').textContent.includes('32.00 hrs entered')")
        check(browser.js("document.getElementById('logRows').textContent.includes('0.00 hrs still needed')&&document.getElementById('logRows').textContent.includes('4 working days')"),'Completed week uses four working days and shows zero remaining hours')
        go('/modules/reports/index.php');value('reportWeek','2026-10-26');click('#reportGenerate')
        browser.wait("document.getElementById('reportCount').textContent.includes('3 personnel')")
        check(browser.js("document.getElementById('reportType').value==='weekly'"),'Section Reports defaults to the sample weekly summary')
        click('[data-summary="'+str(f['people']['employee'])+'"]');browser.wait("!document.getElementById('summaryReport').hidden")
        check(browser.js("document.getElementById('summaryEmployee').textContent==='EFIXTURE'&&document.querySelectorAll('#summaryRows tr').length===20&&document.querySelector('#summaryTotals td:last-child').textContent==='28.00'"),'Sample summary contains formatted employee name, twenty activity types and totals')
        check(browser.js("document.getElementById('summaryLeaves').textContent.includes('VL')&&document.getElementById('summaryLeaves').textContent.includes('4.00')"),'Sample report shows the separate leave table')
        check(browser.js("getComputedStyle(document.querySelector('.dl-summary-table thead th')).backgroundColor==='rgb(198, 217, 241)'"),'Sample report matches reference header color')
        fit('Sample weekly summary');browser.js("AT.setTheme('dark')");fit('Dark sample summary');browser.js("AT.setTheme('light')")
        browser.js("document.body.classList.add('dl-print-summary')");browser.call('Emulation.setEmulatedMedia',{'media':'print'})
        check(len(base64.b64decode(browser.call('Page.printToPDF',{'printBackground':True})['data']))>5000,'Sample report prints to PDF')
        browser.call('Emulation.setEmulatedMedia',{'media':''});browser.js("document.body.classList.remove('dl-print-summary')");click('[data-close=weeklySummary]')
        browser.js("window.fixtureAnchorClick=HTMLAnchorElement.prototype.click;HTMLAnchorElement.prototype.click=function(){window.fixtureDownloadName=this.download;};")
        click('#reportExport');browser.wait("!document.getElementById('reportExport').disabled")
        check(browser.js("window.fixtureDownloadName?.startsWith('weekly_summary_section_')&&document.getElementById('reportsError').hidden"),'Sample section report downloads as one Excel workbook')
        browser.js("HTMLAnchorElement.prototype.click=window.fixtureAnchorClick")
        exported=browser.js("(async()=>{const r=await fetch("+json.dumps(base+'/modules/reports/export.php?report_type=weekly&unit_id='+str(f['unit'])+'&week_start=2026-10-26')+");const bytes=new Uint8Array(await r.arrayBuffer());return {status:r.status,signature:[...bytes.slice(0,2)],size:bytes.length};})()")
        check(exported['status']==200 and exported['signature']==[80,75] and exported['size']>5000,'Sample export endpoint streams a valid XLSX')
        value('reportType','daily','change');value('reportFrom','2026-10-26');value('reportTo','2026-11-01');click('#reportGenerate')
        browser.wait("document.getElementById('reportRows').textContent.includes('30.00')")
        check(browser.js("document.getElementById('reportRows').textContent.includes('4.00')"),'Detailed entry report remains available with leave and weekend records')
        fit('Detailed reports')
        login('employee');go('/modules/dailylogs/index.php')
        check(api('dailylogs','get_log',query='&log_id='+str(draft))['body']['status']=='Draft','Weekly approval preserves the owner draft')
        api('dailylogs','save_log',{**work,'activity_date':'2026-10-30','duration_hours':1})
        login('reviewer');go('/modules/reviewlogs/index.php')
        reopened=api('reviewlogs','get_week',query='&user_id='+str(f['people']['employee'])+'&week_start=2026-10-26')['body']
        check(reopened['status']=='Submitted' and all(r['status']=='Submitted' for r in reopened['logs']),'Adding a submission reopens the whole week for approval')
        click('#logSelectAll');check(browser.js("document.getElementById('logSelected').textContent==='1 selected'"),'Bulk selection counts employee weeks')
        click('#logBulkApprove');browser.wait("document.getElementById('logRows').textContent.includes('No employee weeks')")
        value('filterStatus','Reviewed','change');click('#logFilter');browser.wait("!!document.querySelector('[data-week]')")
        click('[data-week]');browser.wait("document.getElementById('weekReview').open")
        check(browser.js("document.getElementById('weekApprove').hidden&&!document.getElementById('weekReturn').hidden"),'An approved week can be reopened as a whole for correction')
        value('weekReviewComment','Reopen the approved week');click('#weekReturn');browser.wait("!document.getElementById('weekReview').open")
        login('outsider');go('/modules/dailylogs/index.php')
        go('/modules/leaves/index.php?month=2026-10')
        check(browser.js("!!document.querySelector('.leave-event[data-leave-view=\""+str(leave_id)+"\"]')"),'Another section in the same department can view approved leave')
        peer_leave=api('leaves','get_leave',query='&log_id='+str(leave_id))['body']
        check('remarks' not in peer_leave and not peer_leave['can_review'],'Department peers cannot read private reasons or approve other sections')
        check(api('leaves','review_leave',{'log_id':leave_id,'version':peer_leave['version'],'decision':'Returned','review_comment':'Denied'})['status']==409,'An approval grant cannot bypass the section boundary')
        check(api('reviewlogs','get_week',query='&user_id='+str(f['people']['employee'])+'&week_start=2026-10-26')['status']==404,'Another section cannot open the employee week')
        otherlog=api('dailylogs','save_log',work)['body']['log_id']
        check(api('teammonitoring','monitoring',query='&unit_id='+str(f['unit']))['status']==409,'Forged section filters are rejected')
        login('admin');go('/modules/teammonitoring/index.php');value('filterWeek','2026-10-26');click('#logFilter')
        browser.wait("document.getElementById('weekCovered').textContent.includes('Oct 26')")
        go('/modules/holidays/index.php');browser.wait("!!document.querySelector('[data-holiday-delete]')")
        click('[data-holiday-delete]');browser.wait("!!document.querySelector('.swal2-cancel')")
        click('.swal2-cancel');browser.wait("!document.querySelector('.atlas-alert-host')")
        check(browser.js("!!document.querySelector('[data-holiday-delete]')"),'Canceling SweetAlert keeps the organization holiday')
        click('[data-holiday-delete]');browser.wait("!!document.querySelector('.swal2-confirm')");click('.swal2-confirm')
        browser.wait("document.getElementById('holidayRows').textContent.includes('No configured holidays')")
        check(api('teammonitoring','monitoring',query='&week_start=2026-10-26&q=Employee')['body']['members'][0]['working_days']==5,'Deleting an organization holiday restores the working day')
        login('admin');go('/modules/permissions/index.php')
        check(api('permissions','create_role',{'role_key':'team_observer','role_name':'Team Observer'})['status']==200,'Administrator can create a new role for team viewing')
        go('/modules/permissions/index.php');value('permissionRole','team_observer','change')
        check(browser.js("[...document.querySelectorAll('.wf-matrix-group th')].some(t=>t.textContent==='Parameter')&&!!document.querySelector('[data-module=wiki]')&&!!document.querySelector('[data-module=holidays]')"),'Permission matrix includes standalone modules under sidebar groups')
        check(browser.js("!document.querySelector('#permissionRows input[data-module=teammonitoring][data-permission-key=view]').checked"),'New custom role starts with Team Monitoring View unchecked')
        click('#permissionRows input[data-module=holidays][data-permission-key=view]')
        click('#permissionRows input[data-module=teammonitoring][data-permission-key=view]');click('#permissionSave')
        browser.wait("!document.getElementById('permissionSave').disabled&&document.getElementById('permissionStatus').textContent===''")
        check(any(p['role_key']=='team_observer' and p['module']=='teammonitoring' and p['permission']=='view' and p['is_allowed'] for p in api('permissions','matrix')['body']['permissions']),'Permission matrix saves Team Monitoring View for the custom role')
        colleague=api('usermgmt','get_user',query='&user_id='+str(f['people']['backup']))['body'];colleague['role_key']='team_observer'
        check(api('usermgmt','save_user',colleague)['status']==200,'Administrator can assign the custom role to a section member')
        login('backup');go('/modules/teammonitoring/index.php')
        check(browser.js("!!document.querySelector('#sidebarNav a[href*=teammonitoring]')&&document.getElementById('dailyError').hidden"),'Custom role sees Team Monitoring in the sidebar and can open it')
        fit('Custom role team monitoring')
        check(api('holidays','holidays',query='&year=2026')['status']==200,'Custom role can be granted standalone Holiday View')
        check(api('holidays','save_holiday',{'holiday_date':'2026-10-28','holiday_name':'Denied'})['status']==403,'Holiday View does not grant administrator editing')
        go('/modules/wiki/index.php')
        check(browser.js("!!document.querySelector('[data-wiki-guide=teammonitoring] .btn')&&!document.querySelector('[data-wiki-guide=permissions] .btn')"),'Wiki links respect custom role module access')
        go('/modules/teammonitoring/index.php')
        check(api('teammonitoring','get_log',query='&log_id='+str(id))['status']==200,'Custom role can read section log details through monitoring')
        check(api('teammonitoring','get_log',query='&log_id='+str(otherlog))['status']==404,'Custom monitoring role cannot read another section log')
        check(api('teammonitoring','monitoring',query='&unit_id='+str(f['other']))['status']==409,'Custom monitoring role cannot forge another section filter')
        check(api('reviewlogs','list_logs')['status']==403 and api('reports','catalog')['status']==403,'Team Monitoring grant does not automatically enable reviews or Reports')
        login('admin');go('/modules/permissions/index.php');value('permissionRole','team_observer','change')
        click('#permissionRows input[data-module=holidays][data-permission-key=view]');click('#permissionRows input[data-module=wiki][data-permission-key=view]')
        click('#permissionRows input[data-module=teammonitoring][data-permission-key=view]');click('#permissionSave')
        browser.wait("!document.getElementById('permissionSave').disabled&&document.getElementById('permissionStatus').textContent===''")
        login('backup')
        check(api('teammonitoring','monitoring')['status']==403,'Revoking Team Monitoring View immediately blocks the custom role API')
        check(browser.js("!document.querySelector('#sidebarNav a[href*=teammonitoring]')"),'Revoked Team Monitoring access disappears from the sidebar')
        check(api('holidays','holidays')['status']==403,'Revoking Holiday View blocks standalone calendar access')
        check(browser.js("(async()=>{const r=await fetch("+json.dumps(base+'/modules/wiki/index.php')+");return r.status===403;})()"),'Revoking Wiki View blocks direct page access')
        check(browser.js("!document.querySelector('#sidebarNav a[href*=holidays]')&&!document.querySelector('#helpMenu a[href*=wiki]')"),'Revoked Wiki and Holiday views disappear from navigation and Help')
        login('admin')
        colleague=api('usermgmt','get_user',query='&user_id='+str(f['people']['backup']))['body'];colleague['role_key']='section_head'
        check(api('usermgmt','save_user',colleague)['status']==200,'Administrator can assign Section Head role')
        login('backup');go('/modules/dailylogs/index.php')
        own_leave=api('leaves','save_leave',{'activity_date':'2026-11-04','leave_type':'VL','leave_period':'WHOLE','status':'Submitted'})['body']['log_id']
        go('/modules/approvals/index.php?tab=leaves');click('#leaveReviewRows [data-leave-view="'+str(own_leave)+'"]')
        browser.wait("document.getElementById('leaveDetail').open")
        check(browser.js("!!document.getElementById('leaveApprove')"),'Section Head sees approval controls on their own leave')
        click('#leaveApprove');browser.wait("!!document.querySelector('.swal2-popup:not(.swal2-toast) .swal2-confirm')")
        click('.swal2-popup:not(.swal2-toast) .swal2-confirm');browser.wait("!document.getElementById('leaveDetail').open")
        check(api('leaves','get_leave',query='&log_id='+str(own_leave))['body']['status']=='Reviewed','Section Head can approve their own leave through the calendar')
        for day in ['2026-11-02','2026-11-03']:
            check(api('dailylogs','save_log',{**work,'activity_date':day})['status']==200,'Section Head submits own work for weekly review')
        go('/modules/reviewlogs/index.php')
        weeks=api('reviewlogs','list_weeks')['body']['weeks']
        check(len(weeks)==1 and weeks[0]['user_id']==f['people']['backup'],'Unassigned Section Head sees own week in review queue')
        check(api('reviewlogs','get_week',query='&user_id='+str(f['people']['employee'])+'&week_start=2026-10-26')['status']==404,'Section Head self-review does not expose another employee week')
        click('[data-week]');browser.wait("document.getElementById('weekReview').open")
        check(browser.js("!document.getElementById('weekApprove').hidden"),'Self-approval button is visible for Section Head')
        click('#weekApprove');browser.wait("!document.getElementById('weekReview').open")
        week=api('reviewlogs','get_week',query='&user_id='+str(f['people']['backup'])+'&week_start=2026-11-02')['body']
        check(week['status']=='Reviewed' and all(log['reviewed_by']==f['people']['backup'] for log in week['logs']),'One Section Head self-approval covers the complete week')
        login('admin');colleague['role_key']='department_head'
        check(api('usermgmt','save_user',colleague)['status']==200,'Administrator can assign Department Head role')
        login('backup');go('/modules/dailylogs/index.php')
        check(api('dailylogs','save_log',{**work,'activity_date':'2026-11-09'})['status']==200,'Department Head submits own work')
        go('/modules/reviewlogs/index.php');click('[data-week]');browser.wait("document.getElementById('weekReview').open")
        click('#weekApprove');browser.wait("!document.getElementById('weekReview').open")
        check(api('reviewlogs','get_week',query='&user_id='+str(f['people']['backup'])+'&week_start=2026-11-09')['body']['status']=='Reviewed','Department Head can approve own week through the UI')
        print(f'Daily log browser/API checks: {CHECKS} assertions passed.')
    finally:
        if browser:
            try:
                browser.call('Browser.close')
            except Exception:
                pass
            browser.sock.close()
        for p in reversed(processes):
            if p.poll() is None:
                p.terminate()
            try:
                p.wait(timeout=10)
            except subprocess.TimeoutExpired:
                p.kill();p.wait()
        try:
            cli('cleanup')
        finally:
            log.close()
            target=temp.resolve();allowed=(ROOT/'tests').resolve()
            if target.parent==allowed and target.name.startswith('.tmp-daily-logs-'):
                shutil.rmtree(target,ignore_errors=True)


if __name__=='__main__':
    main()
