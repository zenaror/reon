#!/usr/bin/env python3
"""Exercise a disposable SDK instance using only Python's standard library.

Mount --content at /content before starting the container. This overwrites
routes.json in that disposable fixture directory; never use real content.
"""
import argparse
import json
import poplib
from pathlib import Path
import smtplib
import socket
import struct
import urllib.request
import uuid

p = argparse.ArgumentParser()
p.add_argument('--host', default='127.0.0.1')
p.add_argument('--http', type=int, default=8080)
p.add_argument('--smtp', type=int, default=2525)
p.add_argument('--pop3', type=int, default=1110)
p.add_argument('--dns', type=int, default=5353)
p.add_argument('--content', type=Path, required=True)
a = p.parse_args()
a.content.mkdir(parents=True, exist_ok=True)
binary = bytes(range(256)) * 32
(a.content/'fixture.bin').write_bytes(binary)
(a.content/'routes.json').write_text(json.dumps([
    {'path':'/01/HBRJ/content.bin', 'file':'fixture.bin'},
    {'method':'POST', 'path':'/01/HBRJ/score', 'body':'OK'},
]))
base = f'http://{a.host}:{a.http}'
assert urllib.request.urlopen(base+'/01/HBRJ/content.bin', timeout=5).read() == binary
assert urllib.request.urlopen(urllib.request.Request(base+'/01/HBRJ/score', data=b'score=17'), timeout=5).read() == b'OK'
pop = poplib.POP3(a.host, a.pop3, timeout=5)
pop.user('player02'); pop.pass_('test0002'); before = pop.stat()[0]; pop.quit()
raw = (f'From: player01@reon.dion.ne.jp\r\nTo: player02@reon.dion.ne.jp\r\nSubject: SDK {uuid.uuid4()}\r\n\r\nHello homebrew\r\n.dot-stuffing\r\n').encode()
with smtplib.SMTP(a.host, a.smtp, timeout=5) as smtp:
    smtp.login('player01', 'test0001')
    smtp.sendmail('player01@reon.dion.ne.jp', ['player02@reon.dion.ne.jp'], raw)
    smtp.mail('player01@reon.dion.ne.jp')
    assert smtp.rcpt('external@example.invalid')[0] == 550
pop = poplib.POP3(a.host, a.pop3, timeout=5)
pop.apop('g000000008', 'test0002'); count = pop.stat()[0]
assert count == before + 1
assert b'\r\n'.join(pop.retr(count)[1]) + b'\r\n' == raw
pop.dele(count); pop.quit()
pop = poplib.POP3(a.host, a.pop3, timeout=5)
pop.user('player02'); pop.pass_('test0002'); assert pop.stat()[0] == before; pop.quit()
query = struct.pack('!6H', 42, 256, 1, 0, 0, 0) + b'\x04test\x04reon\x00' + struct.pack('!HH', 1, 1)
with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as dns:
    dns.settimeout(5); dns.sendto(query, (a.host, a.dns)); answer = dns.recv(512)
assert answer[-4:] == socket.inet_aton('127.0.0.1')
print('PASS: HTTP binary/POST, internal SMTP, external refusal, POP3 USER/PASS/APOP/RETR/DELE, dot-stuffing, DNS A')
