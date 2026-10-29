"""Local browser/HTTP regression: Quick Links, ERD snapshots and Code Review.
Uses a disposable PostgreSQL schema, account, sessions and Edge profile.
"""
import base64
import importlib.util
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
import zipfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
sys.stdout.reconfigure(encoding='utf-8', errors='backslashreplace')
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('browser_helpers', ROOT / 'tests/email-auth-browser.py')
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)
PHP, EDGE, Browser = helpers.PHP, helpers.EDGE, helpers.Browser
CHECKS = 0


def check(value, message):
    global CHECKS
    CHECKS += 1
    if not value:
        raise AssertionError(message)


def main():
    temp = Path(tempfile.mkdtemp(prefix='.tmp-modeling-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    downloads = temp / 'downloads'
    downloads.mkdir()
    server_port, debug_port = helpers.port(), helpers.port()
    base = f'http://127.0.0.1:{server_port}/AnalystToolkit'
    schema = 'modeling_fixture_' + os.urandom(6).hex()
    (temp / 'fixture.json').write_text(json.dumps({'root': str(ROOT).replace('\\', '/'), 'schema': schema}))
    router = temp / 'router.php'
    router.write_text(r'''<?php
$f=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);$schema=$f['schema'];
if(!preg_match('/^modeling_fixture_[a-f0-9]{12}$/D',$schema))exit;
if(PHP_SAPI==='cli')session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once $f['root'].'/lib/Logger.php';$p=new ReflectionProperty(Logger::class,'directory');$p->setAccessible(true);$p->setValue(null,__DIR__.'/logs');
require_once $f['root'].'/config/Database.php';$db=Database::getConnection('app');
if(PHP_SAPI==='cli'){
 set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n");exit(1);});
 if(($argv[1]??'')==='cleanup'){$db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');exit;}
 if(($argv[1]??'')!=='setup')exit;
 $db->query(str_replace('toolkit',$schema,file_get_contents($f['root'].'/database/schema.sql')));$db->query('SET search_path TO "'.$schema.'",public');
 $db->query("DELETE FROM users;DELETE FROM dd_tables;DELETE FROM diagrams;DELETE FROM bookmarks");
 $db->query("INSERT INTO users(employee_id,password_hash,first_name,last_name,email,must_change_password,password_changed_at)VALUES('viewer',?,'Model','Reviewer','fixture@example.invalid',FALSE,NOW())", [password_hash('ModelFixture123!',PASSWORD_BCRYPT)]);
 $tableSql="INSERT INTO dd_tables(source_database,schema_name,table_name)VALUES('Fixture','sales',?) RETURNING table_id";
 $ids=[];foreach(['customers','orders','unresolved'] as $name){$table = $db->query($tableSql, [$name]);$ids[$name]=(int)Database::Scalar($table);}
 $colSql="INSERT INTO dd_columns(table_id,column_name,ordinal_position,data_type,numeric_precision,numeric_scale,is_primary_key,is_foreign_key,is_nullable,fk_ref_table,fk_ref_column)VALUES(?,?,?,?,?,?,?,?,?,?,?)";
 foreach($ids as $name=>$id)$col = $db->query($colSql, [$id,'id',1,'integer',null,null,'true','false','false',null,null]);
 $col = $db->query($colSql, [$ids['orders'],'customer_id',2,'integer',null,null,'false','true','false','sales.customers','id']);
 $col = $db->query($colSql, [$ids['orders'],'amount',3,'numeric',10,0,'false','false','true',null,null]);
 $col = $db->query($colSql, [$ids['unresolved'],'unknown_id',2,'integer',null,null,'false','true','true','missing_table','id']);
 $db->query("UPDATE users SET role_key='admin' WHERE employee_id='viewer'");
 $db->query("INSERT INTO diagrams(diagram_type,diagram_name)VALUES('DFD','Retained legacy diagram')");
 $legacy=(int)Database::Scalar($db->query('SELECT MAX(diagram_id) FROM diagrams'));
 $db->query("INSERT INTO diagram_nodes(diagram_id,node_key,label,node_subtype)VALUES(?,'legacy','Legacy process','process')", [$legacy]);
 // Exercise the upgrade against old-style rows and prove it is repeatable.
 $before=$db->query("SELECT diagram_id,diagram_name,diagram_type,description FROM diagrams ORDER BY diagram_id")->getResultArray();
 $db->query('ALTER TABLE diagrams DROP COLUMN generation_meta;ALTER TABLE diagram_nodes DROP COLUMN erd_meta;ALTER TABLE diagram_edges DROP COLUMN erd_meta');
 $migration=str_replace('toolkit',$schema,file_get_contents($f['root'].'/database/migration_016_erd_generator.sql'));
 $db->query($migration);$db->query($migration);
 if($before!==$db->query("SELECT diagram_id,diagram_name,diagram_type,description FROM diagrams ORDER BY diagram_id")->getResultArray())throw new RuntimeException('Upgrade changed existing diagrams.');
 $linkSql='INSERT INTO bookmarks(title,url,description,tags)VALUES(?,?,?,?)';
 for($i=1;$i<=26;$i++)$link = $db->query($linkSql, ['Resource '.str_pad($i,2,'0',STR_PAD_LEFT),'https://example.invalid/resource/'.$i,'Shared resource '.$i,$i%2?'operations':'development']);
 $link = $db->query($linkSql, ['Unsafe legacy','javascript:alert(1)','Requires URL maintenance','legacy']);
 $link = $db->query($linkSql, ['Markup <img src=x onerror=window.fixtureXss=1>','https://example.invalid/markup','Text <script>window.fixtureXss=1</script>','development']);
 $link = $db->query($linkSql, ['Legacy credentials','https://someone:secret@example.invalid','Do not expose embedded credentials','legacy']);
 echo json_encode($ids+['legacy'=>$legacy]);exit;
}
$db->query('SET search_path TO "'.$schema.'",public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if(str_starts_with($relative,'assets/')){$file=realpath($f['root'].'/'.$relative);$assets=realpath($f['root'].'/assets');if($file&&str_starts_with(strtolower($file),strtolower($assets.DIRECTORY_SEPARATOR))&&is_file($file)){header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;}}
if($relative==='index.php'){require_once $f['root'].'/lib/Auth.php';Auth::requirePage();echo '<h1 id="fixtureSignedIn">Signed in</h1>';exit;}
$allowed=['login.php','logout.php','modules/bookmarks/index.php','modules/bookmarks/api.php','modules/codereview/index.php','modules/codereview/api.php','modules/codereview/export.php','modules/diagrams/index.php','modules/diagrams/api.php','modules/diagrams/excel_export.php','modules/diagrams/graphml_export.php','modules/diagrams/excel_import.php','modules/datadict/index.php','modules/datadict/api.php','modules/audit/client.php','modules/notifications/api.php','modules/applications/api.php','modules/codereview/history_api.php'];
$allowed[]='modules/updates/api.php';
if(!in_array($relative,$allowed,true)){http_response_code(404);exit;}$_SERVER['SCRIPT_NAME']='/AnalystToolkit/'.$relative;require $f['root'].'/'.$relative;
''', encoding='utf-8')
    processes, browser = [], None
    log = open(temp / 'server.log', 'wb')

    def cli(action):
        r = subprocess.run([str(PHP), str(router), action], capture_output=True, timeout=30, cwd=ROOT, creationflags=subprocess.CREATE_NO_WINDOW)
        if r.returncode:
            raise RuntimeError(r.stderr.decode(errors='replace'))
        return json.loads(r.stdout) if r.stdout.strip() else None

    def go(route, ready):
        browser.go(base + route)
        browser.wait("document.readyState==='complete'&&(" + ready + ')')

    def click(selector):
        browser.js('document.querySelector(' + json.dumps(selector) + ').click()')

    def value(id, text, event='input'):
        browser.js('document.getElementById(' + json.dumps(id) + ').value=' + json.dumps(str(text)) + ';document.getElementById(' + json.dumps(id) + ').dispatchEvent(new Event(' + json.dumps(event) + ',{bubbles:true}))')

    def submit(id):
        browser.js('document.getElementById(' + json.dumps(id) + ').requestSubmit()')

    def api(route, body=None, method=None, csrf=True):
        return browser.js('fetch(' + json.dumps(base + route) + ',{method:' + json.dumps(method or ('POST' if body is not None else 'GET')) + ",headers:{'Content-Type':'application/json'" + (",'X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content" if csrf else '') + '}' + (',body:JSON.stringify(' + json.dumps(body) + ')' if body is not None else '') + '}).then(async r=>({status:r.status,body:await r.json()}))')

    def erd(action, body=None, **kwargs):
        return api('/modules/diagrams/api.php?action=' + action, body, **kwargs)

    def fit(label):
        for width in [320, 390, 768, 1024, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), label + f' fits {width}px')
        check(browser.js('fixtureErrors.length===0&&!window.fixtureXss'), label + ' has no runtime errors or executed markup')

    def capture(label, width=1366):
        if os.environ.get('MODELING_SCREENSHOTS') == '1':
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': width < 600})
            browser.js('new Promise(resolve=>setTimeout(resolve,200))')
            (ROOT / f'.tmp-modeling-{label}-{width}.png').write_bytes(base64.b64decode(browser.call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': False})['data']))

    def download(ext):
        until = time.time() + 120
        while time.time() < until:
            paths = list(downloads.glob('*.' + ext))
            if paths:
                return max(paths, key=lambda p: p.stat().st_mtime)
            time.sleep(.1)
        raise AssertionError('No ' + ext + ' download')

    def upload(path):
        encoded = base64.b64encode(path.read_bytes()).decode()
        return browser.js("(()=>{const bytes=Uint8Array.from(atob(" + json.dumps(encoded) + "),c=>c.charCodeAt(0)),form=new FormData();form.append('diagram_id'," + str(model_id) + ");form.append('file',new File([bytes],'model.xlsx',{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));return fetch(" + json.dumps(base + '/modules/diagrams/excel_import.php') + ",{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:form}).then(async r=>({status:r.status,body:await r.json()}));})()")

    try:
        fixture = cli('setup')
        check(bool(fixture['legacy']), 'Migration preserves legacy diagrams and can run twice')
        server = subprocess.Popen([str(PHP), '-d', f'session.save_path={temp}', '-d', f'sys_temp_dir={temp}', '-d', f'upload_tmp_dir={temp}', '-S', f'127.0.0.1:{server_port}', str(router)], cwd=ROOT, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(server)
        until = time.time() + 15
        while time.time() < until:
            try:
                urllib.request.urlopen(base + '/login.php', timeout=1).close()
                break
            except (OSError, ConnectionError):
                time.sleep(.1)
        edge = subprocess.Popen([str(EDGE), '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--remote-allow-origins=*', '--user-data-dir=' + str(temp / 'edge'), f'--remote-debugging-port={debug_port}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(edge)
        until = time.time() + 20
        while time.time() < until:
            try:
                pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{debug_port}/json/list', timeout=1))
                if pages:
                    break
            except (OSError, ConnectionError):
                time.sleep(.1)
        browser = Browser(next(p['webSocketDebuggerUrl'] for p in pages if p['type'] == 'page'))
        browser.call('Page.setDownloadBehavior', {'behavior': 'allow', 'downloadPath': str(downloads)})
        go('/modules/bookmarks/index.php', "document.getElementById('bookmarkGrid').getAttribute('aria-busy')==='false'")
        check(browser.js("document.querySelectorAll('.link-card').length===12&&!document.getElementById('linkAdd')&&!document.getElementById('bookmarkForm')"), 'Guest can browse paginated links and has no editor')
        rows = api('/modules/bookmarks/api.php?action=list_bookmarks')['body']
        check(len(rows) == 29 and all(r['url'] is None for r in rows if not r['is_available']), 'Public list masks unsafe legacy addresses and embedded credentials')
        check(api('/modules/bookmarks/api.php?action=list_bookmarks&q=secret')['body'] == [], 'Guest search cannot match masked credentials')
        for action in ['save_bookmark', 'delete_bookmark']:
            check(api('/modules/bookmarks/api.php?action=' + action, {}, csrf=False)['status'] == 401, 'Guests cannot mutate Quick Links')
        check(erd('list_diagrams')['status'] == 401, 'ERD API requires sign-in')
        click('#linksNext')
        check(browser.js("document.getElementById('linksPageSummary').textContent.includes('13')"), 'Guest pagination works')
        value('bmSearch', 'Markup')
        check(browser.js("document.querySelectorAll('.link-card').length===1&&!window.fixtureXss"), 'Link markup is escaped')
        click('[data-pin]')
        click('#linksPinned')
        browser.go(base + '/modules/bookmarks/index.php')
        browser.wait("document.getElementById('bookmarkGrid').getAttribute('aria-busy')==='false'")
        click('#linksPinned')
        check(browser.js("document.querySelectorAll('.link-card').length===1&&document.querySelector('[data-pin]').getAttribute('aria-pressed')==='true'"), 'Guest pins persist on the device')
        click('#linksReset')
        value('linksTag', 'operations', 'change')
        check(browser.js("document.getElementById('linksPageSummary').textContent.includes('13')"), 'Tag filtering counts matching links')
        click('#linksReset')
        fit('Public Quick Links')
        capture('links')
        capture('links-mobile', 390)
        go('/login.php', "!!document.getElementById('loginForm')")
        check(browser.js("!!document.querySelector('a[href$=\"/modules/bookmarks/\"]')"), 'Login provides a public Quick Links entry')
        value('loginEmployeeId', 'viewer')
        value('loginPassword', 'ModelFixture123!')
        submit('loginForm')
        browser.wait("!!document.getElementById('fixtureSignedIn')")
        go('/modules/bookmarks/index.php', "document.getElementById('bookmarkGrid').getAttribute('aria-busy')==='false'")
        browser.wait("document.getElementById('updatesDialog').open&&document.querySelectorAll('#updatesHistory [data-release]').length>0")
        click('#updatesDone');browser.wait("!document.getElementById('updatesDialog').open")
        click('#linkAdd')
        value('bm_title', 'New fixture')
        value('bm_url', 'https://example.invalid/new')
        value('bm_tags', 'new, new, fixture')
        submit('bookmarkForm')
        browser.wait("!document.getElementById('bookmarkModal').open&&document.getElementById('linksTotal').textContent==='30'")
        check(api('/modules/bookmarks/api.php?action=save_bookmark', {'title': 'unsafe', 'url': 'javascript:bad()'})['status'] == 400, 'Unsafe new URLs are rejected')
        check(api('/modules/bookmarks/api.php?action=save_bookmark', {}, csrf=False)['status'] == 419, 'Quick Link writes require CSRF')
        check(api('/modules/bookmarks/api.php?action=save_bookmark', method='GET')['status'] == 405, 'GET cannot mutate Quick Links')
        value('bmSearch', 'New fixture')
        click('[data-edit]')
        value('bm_title', 'Updated fixture')
        submit('bookmarkForm')
        browser.wait("!document.getElementById('bookmarkModal').open")
        value('bmSearch', 'Updated fixture')
        browser.wait("document.querySelectorAll('.link-card').length===1")
        click('[data-delete]')
        browser.wait("!!document.querySelector('.swal2-popup')")
        click('.swal2-confirm')
        browser.wait("document.getElementById('linksTotal').textContent==='29'")
        check(browser.js("document.getElementById('linksEmpty').hidden===false"), 'Signed-in link create, edit and delete work')
        # Dictionary generation, model boundaries and persisted layout.
        go('/modules/diagrams/index.php', "document.getElementById('erdLibraryCount').textContent.includes('0')")
        check(erd('get_diagram&diagram_id=' + str(fixture['legacy']))['status'] == 404, 'Non-ERD diagrams are retained but excluded from the module')
        check(erd('save_diagram', {'diagram_name': 'Bad', 'diagram_type': 'DFD'})['status'] == 422, 'Non-ERD creation is rejected')
        check(erd('save_diagram', {'diagram_name': 'Bad'}, csrf=False)['status'] == 419, 'ERD writes require CSRF')
        check(erd('save_diagram', method='GET')['status'] == 405, 'GET cannot mutate ERDs')
        click('#erdGenerateOpen')
        browser.wait("document.querySelectorAll('[data-table]').length===3")
        value('erdName', 'Fixture ERD')
        click('[data-table="' + str(fixture['orders']) + '"]')
        click('[data-table="' + str(fixture['unresolved']) + '"]')
        submit('erdGenerateForm')
        browser.wait("!document.getElementById('erdEditor').hidden&&!document.getElementById('erdGenerateDialog').open")
        model_id = int(browser.js("new URL(location.href).searchParams.get('diagram_id')"))
        diagram = erd('get_diagram&diagram_id=' + str(model_id))['body']
        check(len([n for n in diagram['nodes'] if n['node_subtype'] == 'entity']) == 3 and len(diagram['edges']) == 1, 'Related table closure brings the referenced table into the ERD')
        check(len(diagram['generation_meta']['issues']) == 1 and diagram['edges'][0]['cardinality'] == '0..N:1', 'Unresolved references are reported, with inferred cardinality retained')
        check(browser.js("document.getElementById('erdSvg').textContent.includes('numeric(10,0)')&&document.getElementById('erdSvg').textContent.includes('PK')&&document.getElementById('erdSvg').textContent.includes('FK')"), 'Canvas displays types and PK/FK markers')
        fit('Generated ERD')
        capture('erd')
        capture('erd-mobile', 390)
        browser.call('Emulation.setDeviceMetricsOverride', {'width': 1366, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': False})
        key = next(n['node_key'] for n in diagram['nodes'] if n['node_subtype'] == 'entity')
        check(erd('save_layout', {'diagram_id': model_id, 'positions': [{'node_key': 'foreign', 'pos_x': 1, 'pos_y': 1}]})['status'] == 422, 'Layout validates node membership')
        check(erd('save_layout', {'diagram_id': model_id, 'positions': [{'node_key': key, 'pos_x': 260, 'pos_y': 330}]})['status'] == 200, 'Layout can be saved')
        go('/modules/diagrams/index.php?diagram_id=' + str(model_id), "!document.getElementById('erdEditor').hidden")
        check(browser.js("document.querySelector('[data-entity="+json.dumps(key)+"]').getAttribute('transform').includes('260 330')"), 'Saved table positions reload')
        click('#erdAddTable')
        value('erdEntityName', '=2+3 <table>')
        browser.js("document.querySelector('.ec-name').value='custom_id';document.querySelector('.ec-type').value='integer';document.querySelector('.ec-pk').checked=true")
        submit('erdEntityForm')
        browser.wait("!document.getElementById('erdEntityDialog').open&&document.getElementById('erdStats').textContent.includes('4 tables')")
        check(browser.js("document.getElementById('erdSvg').textContent.includes('=2+3 <table>')&&!window.fixtureXss"), 'Manual tables support literal labels without executing markup')
        click('#erdArrange')
        click('#erdSaveLayout')
        browser.wait("document.getElementById('erdSaveLayout').disabled&&document.getElementById('erdSaveLayout').textContent==='Layout saved'")
        click('#erdAddEdge')
        value('erdEdgeSource', 'dd_t' + str(fixture['orders']), 'change')
        value('erdEdgeTarget', 'dd_t' + str(fixture['unresolved']), 'change')
        value('erdEdgeLabel', 'Manual connection')
        submit('erdEdgeForm')
        browser.wait("!document.getElementById('erdEdgeDialog').open&&document.getElementById('erdEdgeCount').textContent==='2 relationships'")
        check(browser.js("document.getElementById('erdEdges').textContent.includes('Manual')"), 'Manual relationships can be added')
        graphml = browser.js("fetch(document.getElementById('erdGraphml').href).then(r=>r.text())")
        check(ET.fromstring(graphml).tag.endswith('graphml') and 'numeric(10,0)' in graphml, 'GraphML is valid XML and includes typed columns')
        click('#erdSvgExport')
        check(ET.parse(download('svg')).getroot().tag.endswith('svg'), 'SVG download parses as XML')
        click('#erdExcel')
        xlsx = download('xlsx')
        with zipfile.ZipFile(xlsx) as z:
            text = z.read('xl/sharedStrings.xml').decode()
            check('=2+3 &lt;table&gt;' in text and 'source_column_key' in text, 'Workbook includes literal labels and relationship lineage')
            check(all(b'<f>' not in z.read(name) for name in z.namelist() if name.startswith('xl/worksheets/sheet')), 'Workbook contains no injected formulas')
        check(upload(xlsx)['status'] == 200, 'Excel snapshot round-trips successfully')
        bad = temp / 'formula.xlsx'
        with zipfile.ZipFile(xlsx) as src, zipfile.ZipFile(bad, 'w', zipfile.ZIP_DEFLATED) as dst:
            for name in src.namelist():
                data = src.read(name)
                if name == 'xl/worksheets/sheet1.xml':
                    tree = ET.fromstring(data)
                    ns = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
                    cell = tree.find('.//'+ns+'c[@r="B2"]')
                    cell.attrib.pop('t', None)
                    for item in list(cell):
                        cell.remove(item)
                    ET.SubElement(cell, ns+'f').text = '2+3'
                    data = ET.tostring(tree, encoding='utf-8', xml_declaration=True)
                dst.writestr(name, data)
        check(upload(bad)['status'] == 422, 'Formula workbooks are rejected before changing the model')
        current = erd('get_diagram&diagram_id=' + str(model_id))['body']
        check(len(current['nodes']) == 11 and len(current['edges']) == 2, 'Rejected import preserves all saved tables, columns and relationships')
        # A dictionary table shortcut opens generation with that table selected.
        go('/modules/datadict/index.php', "document.querySelectorAll('.dd-table-item').length===3")
        click('.dd-table-item[data-table="' + str(fixture['orders']) + '"]')
        browser.wait("!!document.querySelector('#ddDetail a[href*=\"/diagrams/index.php?table_id=\"]')")
        click('#ddDetail a[href*="/diagrams/index.php?table_id="]')
        browser.wait("document.getElementById('erdGenerateDialog')?.open&&document.querySelectorAll('[data-table]:checked').length===1")
        check(browser.js("document.getElementById('erdName').value==='orders ERD'"), 'Data Dictionary shortcut preselects its table for ERD generation')
        # All review engines operate on small disposable source files.
        go('/modules/codereview/index.php', "!!document.getElementById('crInitial')")
        fit('Code Review setup')
        capture('review')
        browser.js(r"""(()=>{const f=new File(['<?php\nfunction bad_name($input) { eval($input); return $input; }\n// TODO: review\n'],'sample.php',{type:'text/plain'});Object.defineProperty(f,'webkitRelativePath',{value:'fixture/sample.php'});Object.defineProperty(document.getElementById('crFolder'),'files',{configurable:true,value:[f]});document.getElementById('crFolder').dispatchEvent(new Event('change'));})()""")
        for engine in ['standard', 'security', 'owasp', 'qms', 'inventory']:
            click('[data-engine="' + engine + '"]')
            click('#crScanBtn')
            browser.wait("CR.controller===null&&CR.report?.engine===" + json.dumps(engine))
            check(browser.js('CR.report.file_count===1&&!document.getElementById("crReportBar").hidden&&!document.getElementById("crExportXlsx").disabled'), engine + ' completes and offers export')
            if engine == 'standard':
                check(browser.js("document.querySelectorAll('#crResultBody tr').length>0&&CR.report.finding_count>0"), 'Standard diagnostics has a readable findings workspace')
                value('crResultSearch', 'no-match-fixture')
                check(browser.js("document.getElementById('crResultBody').textContent.includes('No matching')"), 'Findings search has a useful empty state')
                click('#crFilesTab')
                value('crResultSearch', '')
                check(browser.js("document.getElementById('crResultBody').textContent.includes('sample.php')"), 'Files reviewed tab shows coverage')
            if engine == 'qms':
                check(browser.js("document.querySelectorAll('.qms-criterion').length===24&&document.querySelectorAll('#qmsFindingsBody tr').length>0"), 'QMS retains all 24 criteria and finding filters')
                fit('QMS results')
                capture('qms')
            if engine == 'inventory':
                check(browser.js("CR.report.function_count===1&&document.querySelectorAll('#crInventoryBody td').length===5"), 'Function inventory has a compact five-column preview')
                click('#crExportOds')
                check(zipfile.is_zipfile(download('ods')), 'Full source inventory still exports a real ODS workbook')
        # Exercise the full browser -> scan API -> saved history -> workbook path at 3,000 files.
        browser.js(r"""(()=>{const files=Array.from({length:3000},(_,i)=>{const f=new File(['<?php\n/*'+ 'x'.repeat(4096) +'*/\nfunction FixtureValue($value) { return $value; }\n'],'file-'+String(i+1).padStart(4,'0')+'.php',{type:'text/plain'});Object.defineProperty(f,'webkitRelativePath',{value:'large-fixture/'+f.name});return f;});Object.defineProperty(document.getElementById('crFolder'),'files',{configurable:true,value:files});document.getElementById('crFolder').dispatchEvent(new Event('change'));})()""")
        click('[data-engine="qms"]');click('#crScanBtn')
        until=time.time()+120
        while time.time()<until and browser.js('CR.controller!==null'):
            time.sleep(.2)
        check(browser.js("CR.controller===null&&CR.report?.file_count===3000&&CR.report.engine==='qms'&&!!CR.report.review_id"), '3,000 browser files exceeding 8 MB scan and save successfully')
        check(browser.js("CR.report.files[2999].path==='large-fixture/file-3000.php'&&CR.report.files.reduce((n,f)=>n+f.qms_checks.length,0)===72000&&CR.report.finding_count<=5000"), 'Large QMS scan retains all file criteria within finding limits')
        saved=api('/modules/codereview/history_api.php?action=detail&review_id='+str(browser.js('CR.report.review_id')))
        check(saved['status']==200 and saved['body']['file_count']==3000 and len(saved['body']['report_meta']['files'])==3000, 'Saved review history reloads all 3,000 files')
        for old in downloads.glob('*.xlsx'):
            old.unlink()
        click('#crExportXlsx')
        workbook=download('xlsx')
        check(zipfile.is_zipfile(workbook), '3,000-file QMS export creates a real XLSX workbook')
        with zipfile.ZipFile(workbook) as z:
            ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
            summary=ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
            criteria=ET.fromstring(z.read('xl/worksheets/sheet4.xml'))
            check(len(summary.findall('s:sheetData/s:row',ns))==3001, 'Workbook includes all 3,000 file summary rows')
            check(len(criteria.findall('s:sheetData/s:row',ns))==72001, 'Workbook includes all 72,000 file criterion rows')
        browser.js("(()=>{const files=[...document.getElementById('crFolder').files];files.push(new File(['<?php'],'excess.php'));Object.defineProperty(document.getElementById('crFolder'),'files',{configurable:true,value:files});})()")
        check(browser.js("CrClientFiles(CrOptions()).then(()=>false,e=>e.message.includes('3,000 files'))"), 'Browser rejects the 3,001st supported source file')
        value('crSearch', 'different')
        check(browser.js("!document.getElementById('crStale').hidden"), 'Changed scan settings mark the previous results stale')
        value('crSearch', '')
        browser.js("Object.defineProperty(document.getElementById('crFolder'),'files',{configurable:true,value:[{name:'slow.php',webkitRelativePath:'fixture/slow.php',size:20,lastModified:Date.now(),text:()=>new Promise(r=>setTimeout(()=>r('<?php function Slow() {}'),400))}]})")
        click('#crScanBtn')
        click('#crCancel')
        browser.wait('CR.controller===null')
        check(browser.js("document.getElementById('crProgress').textContent==='Review canceled'&&!document.getElementById('crConfig').disabled"), 'Cancellation restores controls without replacing the last report')
        for route, ready in [('/modules/bookmarks/index.php',"document.getElementById('bookmarkGrid').getAttribute('aria-busy')==='false'"),('/modules/diagrams/index.php?diagram_id='+str(model_id),"!document.getElementById('erdEditor').hidden"),('/modules/codereview/index.php',"!!document.getElementById('crInitial')")]:
            go(route, ready)
            browser.js("if(AT.getTheme()!=='dark')AT.toggleTheme()")
            check(browser.js("document.documentElement.dataset.theme==='dark'&&fixtureErrors.length===0"), 'Module supports dark theme')
            capture(route.split('/')[2]+'-dark')
        log.flush()
        check(not re.search(r'(?:PHP )?(?:Warning|Fatal error|Notice):', (temp / 'server.log').read_text(errors='replace')), 'No PHP warnings, notices or fatal errors')
        print(f'Modeling / Quick Links / Code Review: {CHECKS} assertions passed; disposable database and files only.')
    except Exception:
        if browser:
            print('Browser diagnostics:', browser.js("JSON.stringify({url:location.pathname,errors:window.fixtureErrors,alerts:[...document.querySelectorAll('[role=alert]:not([hidden])')].map(e=>e.textContent),dialogs:[...document.querySelectorAll('dialog[open]')].map(e=>e.id),text:document.body.innerText.slice(-1800)})"))
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
            if temp.parent == ROOT / 'tests' and temp.name.startswith('.tmp-modeling-'):
                shutil.rmtree(temp, ignore_errors=True)


if __name__ == '__main__':
    main()
