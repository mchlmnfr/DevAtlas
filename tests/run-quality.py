"""Run first-party regression suites and retain concise, reviewable validation results."""
import concurrent.futures
import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = Path('C:/xampp/php/php.exe')
OUTPUT = ROOT / 'docs/quality/validation'
OUTPUT.mkdir(parents=True, exist_ok=True)
sys.stdout.reconfigure(encoding='utf-8', errors='backslashreplace')


def execute(path):
    started = time.monotonic()
    command = [str(PHP), str(path)] if path.suffix == '.php' else [sys.executable, str(path)]
    try:
        completed = subprocess.run(command, cwd=ROOT, capture_output=True, timeout=900,
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        text = (completed.stdout + completed.stderr).decode('utf-8', errors='replace')
        passed = completed.returncode == 0 and not re.search(r'(?:PHP )?(?:Warning|Fatal error|Parse error|Notice):', text)
        assertions = sum(map(int, re.findall(r'(\d+) assertions passed', text)))
        code = completed.returncode
    except subprocess.TimeoutExpired:
        text, passed, assertions, code = 'Test exceeded the 15-minute suite limit.', False, 0, -1
    (OUTPUT / (path.name + '.log')).write_text(text, encoding='utf-8')
    return {'suite': path.name, 'passed': passed, 'exit_code': code, 'assertions': assertions,
            'seconds': round(time.monotonic() - started, 2), 'log': 'validation/' + path.name + '.log'}


def main():
    suites = sorted((ROOT / 'tests').glob('*.php'))
    if '--php-only' not in sys.argv:
        suites += sorted((ROOT / 'tests').glob('*-browser.py'))
    selected = sys.argv[sys.argv.index('--suite') + 1] if '--suite' in sys.argv else None
    if selected:
        suites = [suite for suite in suites if suite.name == selected]
        if not suites:
            raise ValueError('Unknown suite: ' + selected)
    results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        futures = [pool.submit(execute, suite) for suite in suites]
        for future in concurrent.futures.as_completed(futures):
            result = future.result()
            results.append(result)
            print(('PASS ' if result['passed'] else 'FAIL ') + result['suite'] +
                  f" ({result['assertions']} assertions, {result['seconds']}s)", flush=True)
    prior = OUTPUT.parent / 'module-validation.json'
    if selected and prior.is_file():
        results += [item for item in json.loads(prior.read_text(encoding='utf-8'))['suites'] if item['suite'] != selected]
    results.sort(key=lambda item: item['suite'])
    report = {'generated_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'environment': 'Disposable PostgreSQL schemas, temporary files and Edge profiles; SMTP fixtures use loopback.',
              'assertions': sum(item['assertions'] for item in results), 'suites': results}
    (OUTPUT.parent / 'module-validation.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(f"{sum(item['passed'] for item in results)}/{len(results)} suites passed; {report['assertions']} assertions.")
    return 0 if all(item['passed'] for item in results) else 1


if __name__ == '__main__':
    raise SystemExit(main())
