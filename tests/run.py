#!/usr/bin/env python3
"""Run standalone PHP checks and real cURL requests against a local fake mower.
No physical mower is contacted. The transport fixture temporarily binds 127.0.0.1:80.
Usage: python3 tests/run.py --php php
"""
import argparse
import base64
import collections
import http.server
import json
import os
import pathlib
import subprocess
import threading
import time
import urllib.parse

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='php')
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
counts = collections.Counter()

class Fixture(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        parsed = urllib.parse.urlsplit(self.path)
        command = urllib.parse.parse_qs(parsed.query).get('cmd', [''])[0]
        counts[command] += 1
        if parsed.path != '/json' or self.headers.get('Authorization') != 'Basic ' + base64.b64encode(b'test: secret ').decode():
            self.send_response(401)
            self.end_headers()
            return
        responses = {'invalid-json': '{', 'html': '<html>login</html>', 'empty': '', 'null': 'null', 'scalar': '42', 'array': '[]',
                     'missing-flag': '{"name":"other device"}', 'string-flag': '{"successful":"false"}'}
        if command == 'error':
            variant = urllib.parse.parse_qs(parsed.query).get('case', ['latin1'])[0]
            entries = [
                {'error_code': 203, 'error_message': 'Fehler 203'},
                {'error_code': 80, 'error_message': 'Schneidsystem unregelmäßig'},
                {'error_code': 2, 'error_message': 'Kein Schleifensignal'},
            ]
            body = json.dumps({'successful': True, 'errors': entries}, ensure_ascii=False)
            if variant == 'latin1':
                body = body.encode('latin-1')
            elif variant == 'mixed':
                entries[2]['error_message'] = 'Mäher 🚜 – kein Schleifensignal'
                body = json.dumps({'successful': True, 'errors': entries}, ensure_ascii=False).encode()
                body = body.replace('unregelmäßig'.encode(), 'unregelmäßig'.encode('latin-1'))
            elif variant == 'broken':
                body = body.encode('latin-1')[:-1]
            elif variant == 'control':
                body = body.encode('latin-1').replace(b'Schneidsystem', b'Schneid\x01system')
            elif variant == 'html':
                body = '<html>login</html>'
            elif variant == 'delayed':
                time.sleep(2)
            elif variant == 'empty':
                body = ''
        elif command == 'gps' and 'case' in urllib.parse.parse_qs(parsed.query):
            variant = urllib.parse.parse_qs(parsed.query)['case'][0]
            body = json.dumps({'successful': True, 'gps': {'satellites': 11, 'latitude': '48°30.0000 N', 'longitude': '9°15.0000 E'}}, ensure_ascii=False)
            if variant == 'latin1':
                body = body.encode('latin-1')
            elif variant == 'broken':
                body = body.encode('latin-1')[:-1]
        elif command == 'status-sleeping':
            body = json.dumps({'successful': True, 'status': {'status': 17}})
        elif command == 'invalid-utf8':
            body = b'{"successful":true,"name":"M\xe4her"}'
        elif command == 'counts':
            body = json.dumps({'successful': True, 'counts': counts})
        elif command in ['rejected', 'health', 'gps']:
            body = json.dumps({'successful': False, 'error_code': {'rejected': 3, 'health': 254, 'gps': 8}[command]})
        else:
            body = responses.get(command, '{"successful":true}')
        self.send_response(503 if command == 'http-error' else 200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(body if isinstance(body, bytes) else body.encode())

env = os.environ.copy()
env['NO_PROXY'] = '127.0.0.1'
env['no_proxy'] = '127.0.0.1'
for filename in ['update_error_list.php', 'runtime.php', 'timers.php', 'configuration.php']:
    result = subprocess.run([args.php, str(root / 'tests' / filename)], cwd=root, env=env)
    if result.returncode:
        raise SystemExit(result.returncode)
with http.server.ThreadingHTTPServer(('127.0.0.1', 80), Fixture) as server:
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        result = subprocess.run([args.php, str(root / 'tests' / 'http_transport.php')], cwd=root, env=env)
    finally:
        server.shutdown()
        thread.join()
raise SystemExit(result.returncode)
