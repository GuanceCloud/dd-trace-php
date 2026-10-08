#!/usr/bin/env python3
"""Exercise emitted FastCGI bytes and actual DDTrace payloads against a mock agent.

Run in a PHP-FPM container with ddtrace installed and python3-msgpack available:
    python3 tests/integration/response_body/test_fpm.py
"""
import gzip
import http.server
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import sys
import tempfile
import threading
import time

import msgpack

FIXTURE = str(Path(__file__).with_name('fixture.php').resolve())
TRACES = []
LOCK = threading.Lock()


class Agent(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.reply({'endpoints': ['/v0.4/traces'], 'client_drop_p0s': False})

    def do_POST(self):
        if 'chunked' in self.headers.get('Transfer-Encoding', '').lower():
            chunks = []
            while True:
                size = int(self.rfile.readline().split(b';', 1)[0], 16)
                if not size:
                    while self.rfile.readline() not in (b'\r\n', b'\n', b''):
                        pass
                    break
                chunks.append(self.rfile.read(size))
                assert self.rfile.read(2) == b'\r\n'
            body = b''.join(chunks)
        else:
            body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        if self.path.endswith('/traces'):
            traces = msgpack.unpackb(body, raw=False, strict_map_key=False)
            with LOCK:
                TRACES.extend(traces)
        self.reply({'rate_by_service': {}})

    # The PHP C background sender uses libcurl upload mode (HTTP PUT).
    do_PUT = do_POST

    def reply(self, obj):
        payload = json.dumps(obj).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_):
        pass


def record(kind, data):
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data


def receive(sock, length):
    data = b''
    while len(data) < length:
        part = sock.recv(length - len(data))
        if not part:
            raise RuntimeError('unexpected FastCGI EOF')
        data += part
    return data


def request(port, path, method='GET'):
    params = {
        'SCRIPT_FILENAME': FIXTURE, 'SCRIPT_NAME': '/fixture.php',
        'REQUEST_URI': path, 'REQUEST_METHOD': method, 'SERVER_PROTOCOL': 'HTTP/1.1',
        'SERVER_NAME': 'localhost', 'SERVER_PORT': '80', 'HTTP_HOST': 'localhost',
        'REMOTE_ADDR': '127.0.0.1', 'QUERY_STRING': path.partition('?')[2],
        'HTTP_ACCEPT_ENCODING': 'gzip', 'REDIRECT_STATUS': '200',
    }
    data = b''
    for key, value in params.items():
        k, v = key.encode(), value.encode()
        def size(n):
            return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)
        data += size(len(k)) + size(len(v)) + k + v
    output, errors = b'', b''
    with socket.create_connection(('127.0.0.1', port), timeout=10) as sock:
        sock.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, data)
                     + record(4, b'') + record(5, b''))
        while True:
            _, kind, _, length, padding, _ = struct.unpack('!BBHHBB', receive(sock, 8))
            body = receive(sock, length)
            if padding:
                receive(sock, padding)
            if kind == 6:
                output += body
            elif kind == 7:
                errors += body
            elif kind == 3:
                break
    assert not errors, errors.decode(errors='replace')
    _, separator, body = output.partition(b'\r\n\r\n')
    assert separator, output
    return body


def span_for(service, path):
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        with LOCK:
            for trace in TRACES:
                for span in trace:
                    test_case = span.get('meta', {}).get('test.case', '')
                    if span.get('service') == service and test_case == path:
                        return span
        time.sleep(0.02)
    with LOCK:
        observed = [(s.get('service'), s.get('name'), s.get('meta')) for trace in TRACES for s in trace]
    raise AssertionError(f'no trace for {service} {path}; observed={observed!r}')


def run_pool(label, agent_port, config, cases, ini=None):
    with tempfile.TemporaryDirectory() as directory:
        os.chmod(directory, 0o777)
        conf = Path(directory, 'fpm.conf')
        # Pick a free port, then start FPM immediately. Only one pool is active.
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        conf.write_text(f'''[global]
error_log = {directory}/fpm.log
[test]
user = www-data
group = www-data
listen = 127.0.0.1:{port}
pm = static
pm.max_children = 1
clear_env = no
catch_workers_output = yes
''')
        env = os.environ.copy()
        # Ensure prior shell DD_TRACE settings cannot make these cases pass.
        for key in list(env):
            if key.startswith(('DD_', 'OTEL_')):
                del env[key]
        env.update({
            'DD_SERVICE': label, 'DD_AGENT_HOST': '127.0.0.1',
            'DD_TRACE_AGENT_PORT': str(agent_port), 'DD_TRACE_SAMPLE_RATE': '1',
            'DD_TRACE_AGENT_FLUSH_INTERVAL': '10', 'DD_TRACE_STARTUP_LOGS': '0',
            'DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS': '0',
            'DD_INSTRUMENTATION_TELEMETRY_ENABLED': '0', 'DD_CRASHTRACKING_ENABLED': '0',
            'DD_TRACE_SIDECAR_TRACE_SENDER': '0', **config,
            'DD_TRACE_LOG_FILE': f'{directory}/ddtrace.log',
            'DD_TRACE_LOG_LEVEL': 'debug,startup=off',
            'DD_TRACE_DEBUG': '1',
        })
        command = ['php-fpm', '-F', '-y', str(conf)]
        for key, value in (ini or {}).items():
            command.extend(['-d', f'{key}={value}'])
        process = subprocess.Popen(command, env=env,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            for _ in range(200):
                if process.poll() is not None:
                    raise AssertionError(process.communicate())
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.02)
            for path, expected_wire, expected_tag, truncated, method in cases:
                wire = request(port, path, method)
                if expected_wire == 'gzip':
                    assert gzip.decompress(wire) == b'{"ok":true}', (path, wire)
                else:
                    assert wire == expected_wire, (path, wire, expected_wire)
                span = span_for(label, path)
                meta = span.get('meta', {})
                assert meta.get('response_body') == expected_tag, (path, meta)
                assert meta.get('response_body_truncated') == truncated, (path, meta)
                if path == '/child':
                    with LOCK:
                        children = [s for trace in TRACES for s in trace
                                    if s.get('trace_id') == span['trace_id']
                                    and s.get('meta', {}).get('test.child') == 'true']
                    assert len(children) == 1, children
                    assert 'response_body' not in children[0].get('meta', {}), children
                print(f'PASS {label} {method} {path}', flush=True)
        finally:
            failed = sys.exc_info()[0] is not None
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
            stderr = process.stderr.read().decode(errors='replace')
            if failed:
                for logfile in ('ddtrace.log', 'fpm.log'):
                    file = Path(directory, logfile)
                    if file.exists():
                        print(file.read_text(errors='replace')[-12000:], flush=True)
            if process.returncode not in (0, -15):
                raise AssertionError(stderr)


def case(path, body, tag=None, truncated=None, method='GET'):
    return path, body, tag, truncated, method


def main():
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Agent)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    agent_port = server.server_port
    chinese = '{"message":"你好","ok":true}'
    common = [case('/json', chinese.encode(), chinese),
              case('/plain', b'plain response', 'plain response'),
              case('/child', b'{"child":true}', '{"child":true}'),
              case('/chunks', b'{"a":1,"b":2}', '{"a":1,"b":2}'),
              case('/buffered', b'{"OK":TRUE}', '{"OK":TRUE}'),
              case('/large', b'x' * 10000, 'x' * 8192, 'true'),
              case('/exact', b'x' * 8192, 'x' * 8192),
              case('/html', b'<p>not captured</p>'),
              case('/binary', b'\x00\x01\x02'),
              case('/empty', b''), case('/head', b'', method='HEAD'),
              case('/encoded', 'gzip'), case('/php-gzip', 'gzip'),
              case('/identity', b'{"ok":true}', '{"ok":true}'),
              case('/error', b'{"error":"example"}', '{"error":"example"}'),
              case('/finished', b'{"finished":true}', '{"finished":true}'),
              case('/json?second=1', chinese.encode(), chinese)]
    try:
        run_pool('body-enabled', agent_port, {'DD_TRACE_RESPONSE_BODY_ENABLED': '1'}, common)
        for label, config in [('body-default', {}), ('body-disabled', {'DD_TRACE_RESPONSE_BODY_ENABLED': '0'})]:
            run_pool(label, agent_port, config, [case('/json', chinese.encode())])
        run_pool('body-blacklist', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': 'true',
            'DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS': '/json,/private/*'}, [
                case('/json?token=1', chinese.encode()),
                case('/private/test', b'{"path":"\\/private\\/test"}'),
                case('/plain', b'plain response', 'plain response')])
        run_pool('body-limit', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '1', 'DD_TRACE_RESPONSE_BODY_MAX_SIZE': '5'},
                 [case('/plain', b'plain response', 'plain', 'true')])
        run_pool('body-zero', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '1', 'DD_TRACE_RESPONSE_BODY_MAX_SIZE': '0'},
                 [case('/plain', b'plain response')])
        run_pool('body-ini', agent_port, {}, [
            case('/plain', b'plain response', 'plain', 'true'),
            case('/json', chinese.encode())], ini={
                'datadog.trace.response_body_enabled': 'On',
                'datadog.trace.response_body_max_size': '5',
                'datadog.trace.response_body_blacklist_urls': '/json'})
        run_pool('body-whitelist', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '1',
            'DD_TRACE_RESPONSE_BODY_WHITELIST_URLS': ' /json, /api/* '}, [
                case('/json?mode=1', chinese.encode(), chinese),
                case('/api/v1', b'{"path":"\\/api\\/v1"}', '{"path":"\\/api\\/v1"}'),
                case('/json-suffix', b'{"path":"\\/json-suffix"}'),
                case('/api', b'{"path":"\\/api"}'),
                case('/plain', b'plain response'),
                case('/api/v2?query=1', b'{"path":"\\/api\\/v2"}', '{"path":"\\/api\\/v2"}'),
                case('/JSON', b'{"path":"\\/JSON"}')])
        run_pool('body-whitelist-empty', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '1', 'DD_TRACE_RESPONSE_BODY_WHITELIST_URLS': ''},
                 [case('/plain', b'plain response', 'plain response')])
        run_pool('body-whitelist-blacklist', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '1',
            'DD_TRACE_RESPONSE_BODY_WHITELIST_URLS': '/json,/plain,/private/*',
            'DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS': '/json,/private/*'}, [
                case('/json?token=1', chinese.encode()),
                case('/private/test', b'{"path":"\\/private\\/test"}'),
                case('/plain', b'plain response', 'plain response')])
        run_pool('body-whitelist-ini', agent_port, {}, [
            case('/plain', b'plain response', 'plain', 'true'),
            case('/json', chinese.encode()),
            case('/identity', b'{"ok":true}')], ini={
                'datadog.trace.response_body_enabled': 'On',
                'datadog.trace.response_body_max_size': '5',
                'datadog.trace.response_body_whitelist_urls': '/json,/plain',
                'datadog.trace.response_body_blacklist_urls': '/json'})
        run_pool('body-whitelist-star', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '1',
            'DD_TRACE_RESPONSE_BODY_WHITELIST_URLS': '*',
            'DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS': '/json'}, [
                case('/json', chinese.encode()),
                case('/plain', b'plain response', 'plain response')])
        run_pool('body-whitelist-disabled', agent_port, {
            'DD_TRACE_RESPONSE_BODY_ENABLED': '0', 'DD_TRACE_RESPONSE_BODY_WHITELIST_URLS': '*'},
                 [case('/plain', b'plain response')])
        print('All 43 FastCGI response and trace checks passed.', flush=True)
    finally:
        server.shutdown()


if __name__ == '__main__':
    main()
