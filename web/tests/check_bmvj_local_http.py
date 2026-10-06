#!/usr/bin/env python3
"""Offline HTTP contract check, including the real GB00 challenge flow."""
import argparse
import base64
import hashlib
import hmac
import http.client
import re
import struct
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urlparse
from urllib.request import Request, build_opener, ProxyHandler

parser = argparse.ArgumentParser()
parser.add_argument('--url', default='http://127.0.0.1:8088')
parser.add_argument('--body', type=Path, help='Expected complete externally supplied response body')
parser.add_argument('--filename', default='0000.G001.cgb')
args = parser.parse_args()
if urlparse(args.url).hostname not in ('127.0.0.1', 'localhost', '::1'):
    parser.error('This automated check only targets loopback')
root = Path(__file__).resolve().parents[1]
baseline = (root / 'cgb/download/A4/CGB-BMVJ/RomList.cgb').read_bytes()
expected = args.body.read_bytes() if args.body else b'SYNTHETIC-BMVJ-HTTP-BODY\0' + (
    root / 'tests/fixtures/bmvj/input_tester.flash').read_bytes()
opener = build_opener(ProxyHandler({}))


def request(name, authorization=None):
    url = args.url.rstrip('/') + '/cgb/download?' + urlencode({'name': '/A4/CGB-BMVJ/' + name})
    headers = {'Authorization': authorization} if authorization else {}
    try:
        response = opener.open(Request(url, headers=headers), timeout=10)
    except HTTPError as error:
        response = error
    with response:
        return response.code, response.headers, response.read()


def auth(challenge, user, password='fixture'):
    raw = base64.b64decode(challenge)
    sorted_bits = bytearray()
    for parity in (0, 1):
        for i in range(18):
            value = 0
            for byte in raw[2 * i:2 * i + 2]:
                for bit in (6 + parity, 4 + parity, 2 + parity, parity):
                    value = (value << 1) | ((byte >> bit) & 1)
            sorted_bits.append(value)
    plain = hashlib.md5((challenge + password).encode('ascii')).digest() + user.encode('ascii').ljust(20, b'\xff')
    encoded = bytearray()
    for plain_byte, mask in zip(plain, sorted_bits):
        value = plain_byte ^ mask
        encoded.append((value & 0xB6) | ((value & 1) << 3) | ((value & 8) << 3) | ((value & 64) >> 6))
    return 'GB00 name="' + base64.b64encode(raw[:32]).decode() + base64.b64encode(encoded).decode() + '"'


def login(user, password='fixture'):
    status, headers, body = request('RomList.cgb')
    assert status == 401 and body == b'', (status, len(body))
    challenge = re.fullmatch(r'GB00 name="([A-Za-z0-9+/=]+)"', headers['WWW-Authenticate']).group(1)
    return auth(challenge, user, password)


def sdk_catalog_post(authorization):
    target = urlparse(args.url)
    connection = http.client.HTTPConnection(target.hostname, target.port or 80, timeout=10)
    connection._http_vsn = 10
    connection._http_vsn_str = 'HTTP/1.0'
    connection.putrequest('POST', '/cgb/download?' + urlencode({'name': '/A4/CGB-BMVJ/RomList.cgb'}))
    connection.putheader('Authorization', authorization)
    connection.putheader('User-Agent', 'CGB-BMVJ-00')
    # Captured SDK request has neither Content-Length nor a request body.
    connection.endheaders()
    response = connection.getresponse()
    result = response.status, response.read()
    connection.close()
    return result


authorized = login('g000000007')
status, headers, catalog = request('RomList.cgb', authorized)
assert status == 200 and catalog[0] == baseline[0] + 1, (status, catalog[:20])
assert sdk_catalog_post(authorized) == (200, catalog), 'SDK empty POST must return the same authenticated catalog'
offsets = struct.unpack('<' + 'H' * catalog[0], catalog[1:1 + 2 * catalog[0]])
assert catalog[offsets[-1] + 6:offsets[-1] + 10] == args.filename.split('.')[1].encode()
status, headers, body = request(args.filename, authorized)
assert status == 200 and body == expected, (status, len(body), len(expected))
assert headers.get_content_type() == 'application/octet-stream'
status, _, body = request('h0000.cgb')
assert status == 200 and body == (root / 'cgb/download/A4/CGB-BMVJ/h0000.cgb').read_bytes()
opted_out = login('g000000008')
status, _, catalog = request('RomList.cgb', opted_out)
assert status == 200 and catalog == baseline, status
assert sdk_catalog_post(opted_out) == (200, baseline), 'SDK POST must preserve opt-out'
status, _, body = request(args.filename, opted_out)
assert status == 404 and body == b'', (status, body)
status, headers, _ = request('RomList.cgb', login('g000000007', 'wrong'))
assert status == 401 and headers.get('Gb-Status') == '201', status

# Device-auth is a separate signed gate; exercise the real REON handler too.
key = bytes([0x42]) * 32
prefix = 'g000000007|0123456789abcdef'


def device_request(action, counter, bad_signature=False):
    message = f'{prefix}|{action}|{counter}'
    signature = hmac.new(key, message.encode(), hashlib.sha256).hexdigest()
    params = {'ppp_id': 'g000000007', 'device': '0123456789abcdef', 'action': action,
              'counter': str(counter), 'sig': '0' * 64 if bad_signature else signature}
    url = args.url.rstrip('/') + '/api/adapter/device-auth?' + urlencode(params)
    try:
        response = opener.open(url, timeout=10)
    except HTTPError as error:
        response = error
    with response:
        return response.code, response.headers, response.read()


status, _, body = device_request('query', 1)
assert status == 200, (status, body)
remote = int(body.split()[0])
counter = remote + 2  # Allow rerunning against this disposable state.
status, _, body = device_request('authorize', counter)
assert status == 200 and body == b'', (status, body)
status, headers, body = device_request('query', counter + 1)
signed = hmac.new(key, f'{prefix}|query-response|{counter}|{counter + 1}'.encode(), hashlib.sha256).hexdigest()
assert status == 200 and body == f'{counter} {counter + 1} {signed}'.encode(), (status, body)
assert int(headers['Content-Length']) == len(body)
assert device_request('authorize', counter - 1)[0] == 403
assert device_request('query', counter + 2, True)[0] == 403
assert device_request('deauthorize', counter + 2)[0] == 200
print('PASS: real HTTP GB00 challenge/auth, catalog opt-in/out, static menu, exact response body, invalid password')
print('PASS: SDK HTTP/1.0 empty POST reuses GB00 and returns exact opted-in/out catalog')
print('PASS: real device-auth handler, signed query response, authorize/deauthorize, stale counter and bad signature')
print('Body SHA256:', hashlib.sha256(expected).hexdigest())
print('This script validates HTTP transport; consult docs for separate natural game trace evidence.')
