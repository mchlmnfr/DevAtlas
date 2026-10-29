"""Local integration/browser checks; disposable DB, accounts, sessions and SMTP catcher.
Run: python tests/email-auth-browser.py (requires PHP, PostgreSQL and Microsoft Edge).
No real account is used and no mail leaves the loopback interface.
"""
import base64
import email.policy
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import socketserver
import struct
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from email.parser import BytesParser

ROOT = Path(__file__).resolve().parents[1]
PHP = Path('C:/xampp/php/php.exe')
EDGE = Path('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
PASSWORD = 'BrowserFixture123!'
CHECKS = 0
MESSAGES = []


def check(condition, label):
    global CHECKS
    CHECKS += 1
    if not condition:
        raise AssertionError(label)


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 local-fixture ESMTP\r\n')
        while line := self.rfile.readline():
            cmd = line.split(b' ', 1)[0].strip().upper()
            if cmd in (b'EHLO', b'HELO'):
                self.wfile.write(b'250-local-fixture\r\n250 SIZE 1000000\r\n')
            elif cmd == b'DATA':
                self.wfile.write(b'354 End with dot\r\n')
                data = b''
                while (line := self.rfile.readline()) not in (b'.\r\n', b''):
                    data += line[1:] if line.startswith(b'..') else line
                MESSAGES.append(BytesParser(policy=email.policy.default).parsebytes(data))
                self.wfile.write(b'250 Accepted\r\n')
            elif cmd == b'QUIT':
                self.wfile.write(b'221 Bye\r\n')
                break
            else:
                self.wfile.write(b'250 OK\r\n')


class Browser:
    def __init__(self, url):
        parsed = urllib.parse.urlsplit(url)
        self.sock = socket.create_connection((parsed.hostname, parsed.port), timeout=15)
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall(f'GET {parsed.path} HTTP/1.1\r\nHost: {parsed.netloc}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\nOrigin: http://localhost\r\n\r\n'.encode())
        response = b''
        while b'\r\n\r\n' not in response:
            response += self.sock.recv(1)
        if b'101' not in response:
            raise RuntimeError('Browser debugging handshake failed.')
        self.seq = 0
        self.call('Page.enable')
        self.call('Runtime.enable')
        self.call('Page.addScriptToEvaluateOnNewDocument', {'source': "window.fixtureErrors=[];addEventListener('error',e=>fixtureErrors.push(e.message));addEventListener('unhandledrejection',e=>fixtureErrors.push(String(e.reason)));"})

    def exact(self, length):
        value = b''
        while len(value) < length:
            chunk = self.sock.recv(length - len(value))
            if not chunk:
                raise RuntimeError('Browser connection closed.')
            value += chunk
        return value

    def call(self, method, params=None):
        self.seq += 1
        payload = json.dumps({'id': self.seq, 'method': method, 'params': params or {}}).encode()
        mask = os.urandom(4)
        length = len(payload)
        header = bytes([0x81, 0x80 | length]) if length < 126 else bytes([0x81, 0xfe]) + struct.pack('!H', length)
        self.sock.sendall(header + mask + bytes(v ^ mask[i % 4] for i, v in enumerate(payload)))
        while True:
            first, second = self.exact(2)
            length = second & 0x7f
            if length == 126:
                length = struct.unpack('!H', self.exact(2))[0]
            elif length == 127:
                length = struct.unpack('!Q', self.exact(8))[0]
            frame = self.exact(length)
            if first & 0x0f != 1:
                continue
            result = json.loads(frame)
            if result.get('id') == self.seq:
                if 'error' in result:
                    raise RuntimeError(result['error'])
                return result.get('result', {})

    def js(self, expression):
        result = self.call('Runtime.evaluate', {'expression': expression, 'returnByValue': True, 'awaitPromise': True})
        if 'exceptionDetails' in result:
            raise RuntimeError('Browser script failed: ' + result['exceptionDetails'].get('text', 'unknown'))
        return result.get('result', {}).get('value')

    def wait(self, expression):
        until = time.time() + 15
        while time.time() < until:
            try:
                if self.js(expression):
                    return
            except RuntimeError:
                pass
            time.sleep(.08)
        raise AssertionError('Browser did not reach the expected page state.')

    def go(self, url):
        self.call('Page.navigate', {'url': url})
        self.wait("document.readyState==='complete'")


def main():
    temp = Path(tempfile.mkdtemp(prefix='.tmp-email-auth-', dir=ROOT / 'tests'))
    (temp / '.htaccess').write_text('Require all denied\n')
    schema = 'email_browser_fixture_' + os.urandom(6).hex()
    server_port, smtp_port, debug_port = port(), port(), port()
    base = f'http://127.0.0.1:{server_port}/AnalystToolkit'
    fixture = {'schema': schema, 'root': str(ROOT).replace('\\', '/'), 'url': base, 'smtp_port': smtp_port}
    (temp / 'fixture.json').write_text(json.dumps(fixture))
    php = r'''<?php
$fixture=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);
if (!preg_match('/^email_browser_fixture_[a-f0-9]{12}$/D',$fixture['schema'])) exit;
if (PHP_SAPI==='cli') session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once $fixture['root'].'/lib/Logger.php';
$logProperty=new ReflectionProperty(Logger::class,'directory');$logProperty->setAccessible(true);$logProperty->setValue(null,__DIR__.'/logs');
require_once $fixture['root'].'/config/Database.php';
$db=Database::getConnection('app');$schema=$fixture['schema'];
if (PHP_SAPI==='cli') {
    set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n");exit(1);});
    $action=$argv[1]??'';
    if ($action==='setup') {
        $db->query(str_replace('toolkit',$schema,file_get_contents($fixture['root'].'/database/schema.sql')));
        $db->query('SET search_path TO "'.$schema.'",public');
        $db->query("DELETE FROM users;ALTER SEQUENCE users_user_id_seq RESTART WITH 1;DELETE FROM system_settings");
        $db->query("INSERT INTO system_settings(id,password_expiry_days,smtp_host,smtp_port,smtp_encryption,smtp_from_email,auth_base_url)
            VALUES(1,0,'127.0.0.1',?,'none','fixture@example.invalid',?)", [$fixture['smtp_port'],$fixture['url']]);
        $hash=password_hash('BrowserFixture123!',PASSWORD_BCRYPT);
        foreach(['support','person','noemail','locked','disabled','forced'] as $name) {
            $db->query('INSERT INTO users(employee_id,password_hash,first_name,last_name,email) VALUES(?,?,?,\'Fixture\',?)', [$name,$hash,ucfirst($name),$name==='noemail'?null:$name.'@example.invalid']);
        }
        $db->query("UPDATE users SET must_change_password=FALSE,password_changed_at=NOW();UPDATE users SET role_key='admin' WHERE employee_id='support'");
        $db->query("UPDATE users SET is_locked=TRUE WHERE employee_id='locked';UPDATE users SET is_active=FALSE WHERE employee_id='disabled';UPDATE users SET must_change_password=TRUE WHERE employee_id='forced'");
    } elseif ($action==='cleanup') $db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    else {
        $db->query('SET search_path TO "'.$schema.'", public');
        if ($action==='age') $db->query("UPDATE auth_email_limits SET last_attempt_at=NOW()-INTERVAL '16 minutes',window_started_at=NOW()-INTERVAL '16 minutes';UPDATE auth_email_challenges SET created_at=NOW()-INTERVAL '2 minutes'");
        if ($action==='disable-mail') $db->query('UPDATE system_settings SET smtp_host=NULL');
        if ($action==='enable-mail') $db->query("UPDATE system_settings SET smtp_host='127.0.0.1'");
        if ($action==='invalidate-cache') foreach(glob(__DIR__.'/sess_*')?:[] as $path){$data=file_get_contents($path);$data=preg_replace('/auth_checked_at\|i:\d+;/','auth_checked_at|i:0;',$data);file_put_contents($path,$data);}
    }
    exit;
}
if (PHP_SAPI!=='cli-server') {http_response_code(404);exit;}
$db->query('SET search_path TO "'.$schema.'", public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$relative=str_starts_with($path,'/AnalystToolkit/')?substr($path,16):'';
$relative=RouteUrls::LegacyPath($relative);
if (str_starts_with($relative,'assets/')) {
    $file=realpath($fixture['root'].'/'.$relative);$root=realpath($fixture['root'].'/assets');
    if ($file && str_starts_with(strtolower($file),strtolower($root.DIRECTORY_SEPARATOR)) && is_file($file)) {
        header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','woff2'=>'font/woff2','png'=>'image/png'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;
    }
}
if ($relative==='index.php') {require_once $fixture['root'].'/lib/Auth.php';Auth::requirePage();header('Content-Type: text/html');echo '<h1 id="fixtureSignedIn">Signed in as '.htmlspecialchars($_SESSION['employee_id']).'</h1>';exit;}
if (!in_array($relative,['login.php','logout.php','forgot_password.php','reset_password.php','auth_email_preview.php','change_password.php','modules/settings/api.php','modules/usermgmt/api.php','modules/audit/client.php','modules/notifications/api.php'],true)) {http_response_code(404);exit;}
$_SERVER['SCRIPT_NAME']='/AnalystToolkit/'.$relative;
require $fixture['root'].'/'.$relative;
'''
    router = temp / 'router.php'
    router.write_text(php, encoding='utf-8')
    processes = []
    smtp = socketserver.ThreadingTCPServer(('127.0.0.1', smtp_port), SMTP)
    smtp.daemon_threads = True
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    log = open(temp / 'server.log', 'wb')

    def cli(action):
        result = subprocess.run([str(PHP), str(router), action], cwd=ROOT, capture_output=True, timeout=20, creationflags=subprocess.CREATE_NO_WINDOW)
        if result.returncode or result.stdout.strip():
            raise RuntimeError('Fixture operation failed: ' + result.stderr.decode(errors='replace'))

    def client():
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(who, route, data=None, method=None, headers=None):
        body = data if isinstance(data, bytes) else urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(base + route, data=body, headers=headers or {}, method=method)
        try:
            response = who.open(req, timeout=20)
        except urllib.error.HTTPError as response:
            return response.code, response.read().decode(), response.headers, response.url
        return response.status, response.read().decode(), response.headers, response.url

    def token(html):
        match = re.search(r'name="(?:csrf-token|csrf_token)"\s+(?:content|value)="([a-f0-9]+)"', html)
        if not match:
            raise AssertionError('CSRF token is missing.')
        return match[1]

    def api(who, route, data, csrf):
        return request(who, route, json.dumps(data).encode(), headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf})

    def login(who, employee='support', password=PASSWORD, redirect=''):
        _, body, _, _ = request(who, '/login.php')
        return request(who, '/login.php', {'csrf_token': token(body), 'employee_id': employee, 'password': password, 'redirect': redirect})

    def email_text(purpose):
        mail = next(m for m in reversed(MESSAGES) if ('sign-in code' if purpose == 'signin' else 'Reset your password') in str(m['Subject']))
        check(mail['To'].endswith('@example.invalid'), 'SMTP catcher receives only fixture recipients.')
        return mail.get_body(preferencelist=('plain',)).get_content().replace('\r\n', '\n')

    try:
        cli('setup')
        server = subprocess.Popen([str(PHP), '-d', f'session.save_path={temp}', '-d', f'sys_temp_dir={temp}', '-d', f'upload_tmp_dir={temp}', '-S', f'127.0.0.1:{server_port}', str(router)], cwd=ROOT, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(server)
        until = time.time() + 15
        while time.time() < until:
            try:
                urllib.request.urlopen(base + '/login.php', timeout=1).close()
                break
            except (urllib.error.URLError, ConnectionError):
                time.sleep(.1)
        guest, support = client(), client()
        status, body, headers, _ = request(guest, '/login.php')
        check(status == 200 and 'Forgot password?' in body and 'Email code' in body, 'Login exposes both requested account-access options.')
        check('no-store' in headers['Cache-Control'] and headers['Referrer-Policy'] == 'no-referrer', 'Authentication pages prevent caching and referrer leaks.')
        before = len(MESSAGES)
        _, invalid, _, _ = request(guest, '/forgot_password.php', {'identifier': 'person'})
        check('Your session expired' in invalid and len(MESSAGES) == before, 'CSRF rejection does not send email.')
        _, body, _, _ = request(guest, '/forgot_password.php')
        _, sent, _, _ = request(guest, '/forgot_password.php', {'csrf_token': token(body), 'identifier': 'person'})
        check('If the account is eligible' in sent and 'auth-fallback' not in sent and 'emailPreviewLink' not in sent, 'Recovery has generic confirmation without a public fallback message.')
        text = email_text('reset')
        reset_url = re.search(r'http://[^\s]+/reset_password\.php\?id=[a-f0-9]{32}&token=[a-f0-9]{64}', text).group()
        reset_id = urllib.parse.parse_qs(urllib.parse.urlsplit(reset_url).query)['id'][0]
        _, denied, _, denied_url = request(guest, '/auth_email_preview.php?id=' + reset_id)
        check('/login?' in denied_url and 'Choose a new password</h3>' not in denied, 'Guest cannot read a reset preview.')
        status, signed, _, _ = login(support)
        check(status == 200 and 'Signed in as support' in signed, 'Support fixture signs in through the actual password form.')
        _, details, _, _ = request(support, '/auth_email_preview.php?id=' + reset_id)
        check('Sent' in details and reset_url.replace('&', '&amp;') in details, 'Signed-in support can inspect a delivered reset email.')
        _, reset_form, _, clean_url = request(guest, reset_url.removeprefix(base))
        check(clean_url == base + '/reset-password' and 'token=' not in reset_form, 'Reset token is staged in the session and removed from rendered page/URL.')
        _, mismatch, _, _ = request(guest, '/reset_password.php', {'csrf_token': token(reset_form), 'new_password': 'ResetFixture456!', 'confirm_password': 'Mismatch123!'})
        check('passwords do not match' in mismatch and 'value="ResetFixture456!' not in mismatch, 'Reset validation preserves no password value.')
        _, complete, _, _ = request(guest, '/reset_password.php', {'csrf_token': token(mismatch), 'new_password': 'ResetFixture456!', 'confirm_password': 'ResetFixture456!'})
        check('Password updated.' in complete, 'Password recovery completes through native POST.')
        _, used, _, _ = request(guest, reset_url.removeprefix(base))
        check('invalid, expired or already used' in used, 'Used reset link cannot be opened again.')
        person = client()
        check('Signed in as person' in login(person, 'person', 'ResetFixture456!')[1], 'Reset password works in the real sign-in form.')
        _, support_page, _, _ = request(support, '/auth_email_preview.php')
        csrf = token(support_page)
        status, _, _, _ = request(support, '/modules/settings/api.php?action=save_settings', method='GET')
        check(status == 405, 'Settings writes cannot use GET.')
        status, _, _, _ = request(support, '/modules/usermgmt/api.php?action=reset_password', {'user_id': 2, 'password': 'OtherPassword123!'})
        check(status == 419, 'User password reset requires CSRF.')
        cli('age')
        cli('disable-mail')
        noemail = client()
        _, form, _, _ = request(noemail, '/forgot_password.php')
        _, confirmation, _, _ = request(noemail, '/forgot_password.php', {'csrf_token': token(form), 'identifier': 'noemail'})
        _, support_previews, _, _ = request(support, '/auth_email_preview.php')
        preview_id = re.search(r'<tr>\s*<td>noemail</td>.*?email-details\?id=([a-f0-9]+)', support_previews, re.S).group(1)
        _, details, _, _ = request(support, '/auth_email_preview.php?id=' + preview_id)
        check('No email on file' in details and 'Unavailable' in details and 'reset_password.php?id=' in details, 'Protected fallback works without an email address or SMTP.')
        cli('age')
        cli('enable-mail')
        edge = subprocess.Popen([str(EDGE), '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--remote-allow-origins=*', '--user-data-dir=' + str(temp / 'edge'), f'--remote-debugging-port={debug_port}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(edge)
        until = time.time() + 20
        while time.time() < until:
            try:
                pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{debug_port}/json/list', timeout=1))
                if pages:
                    break
            except (urllib.error.URLError, ConnectionError):
                time.sleep(.15)
        browser = Browser(next(p['webSocketDebuggerUrl'] for p in pages if p['type'] == 'page'))
        def capture(label):
            if os.environ.get('EMAIL_AUTH_KEEP_SCREENSHOTS') != '1':
                return
            for width in [390, 1366]:
                browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1000, 'deviceScaleFactor': 1, 'mobile': width < 600})
                browser.js('new Promise(resolve=>setTimeout(resolve,250))')
                (ROOT / f'.tmp-email-auth-{label}-{width}.png').write_bytes(base64.b64decode(browser.call('Page.captureScreenshot', {'format': 'png'})['data']))
        browser.go(base + '/login.php?mode=code')
        browser.js("document.getElementById('codeIdentifier').value='person';document.querySelector('[data-auth-form]').requestSubmit()")
        browser.wait("document.readyState==='complete' && !!document.getElementById('emailCode')")
        code = re.search(r'\n([0-9]{6})\n', email_text('signin')).group(1)
        check(browser.js("document.querySelector('[data-cooldown]').disabled"), 'Resend countdown is visible and disabled initially.')
        check(browser.js("!document.querySelector('.auth-fallback')&&!document.getElementById('emailPreviewLink')"), 'Email-code form has no fallback message.')
        for width in [320, 390, 768, 1024, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 950, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'Code sign-in fits {width}px without horizontal overflow.')
        browser.js("(()=>{const theme=document.querySelector('[data-theme-select]');theme.value='dark';theme.dispatchEvent(new Event('change',{bubbles:true}));})()")
        check(browser.js("document.documentElement.dataset.theme==='dark'"), 'Email-code form supports dark theme.')
        check(browser.js('fixtureErrors.length===0'), 'Email-code form has no browser runtime errors.')
        capture('code')
        browser.js("document.getElementById('emailCode').value=" + json.dumps(code) + ";document.querySelector('[data-auth-form]').requestSubmit()")
        browser.wait("!!document.getElementById('fixtureSignedIn')")
        check(browser.js("document.getElementById('fixtureSignedIn').textContent==='Signed in as person'"), 'Delivered code signs in through the actual browser form.')
        browser.go(base + '/logout.php')
        browser.go(base + '/forgot_password.php')
        cli('age')
        browser.js("document.getElementById('recoveryIdentifier').value='person';document.querySelector('[data-auth-form]').requestSubmit()")
        browser.wait("document.readyState==='complete' && !!document.querySelector('.auth-notice')")
        reset_url = re.search(r'http://[^\s]+/reset_password\.php\?id=[a-f0-9]{32}&token=[a-f0-9]{64}', email_text('reset')).group()
        for width in [320, 768, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 950, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'Recovery confirmation fits {width}px.')
        capture('recovery')
        browser.go(reset_url)
        browser.wait("document.readyState==='complete' && !!document.getElementById('newPassword')")
        check(browser.js("location.search===''"), 'Browser reset form has a clean URL.')
        for width in [320, 390, 768, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 950, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'Reset form fits {width}px.')
        capture('reset')
        browser.js("document.getElementById('newPassword').value='BrowserReset789!';document.getElementById('confirmPassword').value='BrowserReset789!';document.querySelector('[data-auth-form]').requestSubmit()")
        browser.wait("document.readyState==='complete' && document.body.textContent.includes('Password updated.')")
        check(browser.js('fixtureErrors.length===0'), 'Recovery completion has no browser runtime errors.')
        browser.go(base + '/login.php')
        browser.js("document.getElementById('loginEmployeeId').value='support';document.getElementById('loginPassword').value=" + json.dumps(PASSWORD) + ";document.getElementById('loginForm').requestSubmit()")
        browser.wait("!!document.getElementById('fixtureSignedIn')")
        browser.go(base + '/auth_email_preview.php')
        browser.wait("document.readyState==='complete' && document.body.textContent.includes('Authentication emails')")
        check(browser.js('fixtureErrors.length===0'), 'Protected preview list has no runtime errors.')
        for width in [320, 390, 768, 1366]:
            browser.call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 950, 'deviceScaleFactor': 1, 'mobile': width < 600})
            check(browser.js('document.documentElement.scrollWidth<=innerWidth+1'), f'Protected preview fits {width}px.')
        capture('preview')
        browser.sock.close()
        # Existing administration/password flows must invalidate new credentials correctly.
        _, settings_body, _, _ = request(support, '/modules/settings/api.php?action=get_settings')
        settings = json.loads(settings_body)
        check(settings['smtp_password'] == '' and settings['auth_base_url'] == base, 'Settings expose canonical URL and never return an SMTP password.')
        settings['auth_base_url'] = 'https://attacker.example/Other'
        check(api(support, '/modules/settings/api.php?action=save_settings', settings, csrf)[0] == 400, 'Settings reject a reset URL outside the application path.')
        settings['auth_base_url'] = base
        check(api(support, '/modules/settings/api.php?action=save_settings', settings, csrf)[0] == 200, 'Updated email settings save through the actual API.')
        cli('invalidate-cache')
        _, old_session, _, old_session_url = request(person, '/index.php')
        check('/login?' in old_session_url, 'Recovery invalidates an earlier browser session at its next account check.')
        current = client()
        check('Signed in as person' in login(current, 'person', 'BrowserReset789!')[1], 'Browser-reset password authenticates normally.')
        check(api(support, '/modules/usermgmt/api.php?action=reset_password', {'user_id': 2, 'password': 'Temporary456!'}, csrf)[0] == 200, 'User Management manual reset increments the credential version.')
        cli('invalidate-cache')
        check('/login?' in request(current, '/index.php')[3], 'Manual reset invalidates existing sessions.')
        forced_person = client()
        _, forced_page, _, forced_url = login(forced_person, 'person', 'Temporary456!')
        check(forced_url.endswith('/change-password') and 'must change your password' in forced_page, 'Manual reset retains the required-password-change gate.')
        _, changed, _, _ = request(forced_person, '/change_password.php', {'csrf_token': token(forced_page), 'current_password': 'Temporary456!', 'new_password': 'ChangedByOwner789!', 'confirm_password': 'ChangedByOwner789!'})
        check('Password changed successfully' in changed, 'Existing change-password form updates password and session version.')
        cli('invalidate-cache')
        check('Signed in as person' in request(forced_person, '/index.php')[1], 'Own password change keeps the current session usable.')
        cli('age')
        pending = client()
        _, form, _, _ = request(pending, '/forgot_password.php')
        _, pending_confirmation, _, _ = request(pending, '/forgot_password.php', {'csrf_token': token(form), 'identifier': 'person'})
        pending_id = re.search(r'reset_password\.php\?id=([a-f0-9]+)', email_text('reset')).group(1)
        user_update = {'user_id': 2, 'employee_id': 'person', 'first_name': 'Person', 'last_name': 'Fixture', 'email': 'changed@example.invalid', 'is_active': True}
        check(api(support, '/modules/usermgmt/api.php?action=save_user', user_update, csrf)[0] == 200, 'Account email edits work with credential versioning.')
        _, revoked, _, _ = request(support, '/auth_email_preview.php?id=' + pending_id)
        check('no longer available' in revoked and 'reset_password.php?id=' not in revoked, 'Email edits revoke existing protected reset previews.')
        cli('invalidate-cache')
        check('/login?' in request(forced_person, '/index.php')[3], 'Email edits invalidate other account sessions.')
        user_update.update({'user_id': 1, 'employee_id': 'support', 'first_name': 'Support', 'email': 'support-updated@example.invalid'})
        check(api(support, '/modules/usermgmt/api.php?action=save_user', user_update, csrf)[0] == 200, 'Self account update succeeds.')
        cli('invalidate-cache')
        check('Signed in as support' in request(support, '/index.php')[1], 'Self email update retains the current versioned session.')
        log.flush()
        output = (temp / 'server.log').read_text(errors='replace')
        check(not re.search(r'(?:PHP )?(?:Warning|Fatal error|Notice):', output), 'Isolated server produced no PHP warnings or fatal errors.')
        print(f'Email authentication HTTP/browser: {CHECKS} assertions passed; local SMTP only.')
    finally:
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
            if temp.parent == ROOT / 'tests' and temp.name.startswith('.tmp-email-auth-'):
                shutil.rmtree(temp, ignore_errors=True)


if __name__ == '__main__':
    main()
