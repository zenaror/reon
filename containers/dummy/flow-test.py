#!/usr/bin/env python3
"""Exercise the real REON signup/login/config and Dummy server internal mail."""
import base64,hashlib,http.client,socket,struct
from pathlib import Path
import argparse,http.cookiejar,urllib.request,urllib.parse,re,html,uuid,smtplib,poplib,json
p=argparse.ArgumentParser();p.add_argument('--host',default='127.0.0.1');p.add_argument('--http',type=int,default=8080);p.add_argument('--smtp',type=int,default=2525);p.add_argument('--pop3',type=int,default=1110);p.add_argument('--state');p.add_argument('--dns',type=int,default=5354);a=p.parse_args()
base=f'http://{a.host}:{a.http}'
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(path):return client.open(base+path,timeout=15).read().decode()
def post(path,data):return client.open(base+path,urllib.parse.urlencode(data).encode(),timeout=15).read().decode()
def field(page,name):
 m=re.search(r'name="'+re.escape(name)+r'"[^>]*value="([^"]*)"',page);assert m,(name,'Missing form field');return html.unescape(m[1])
page=get('/');assert '<title>Home - REON</title>' in page and 'signup.php' in page
name='dev'+uuid.uuid4().hex[:8];email=name+'@reon.test';password='DummyTest123'
page=get('/signup.php');post('/signup.php',{'_csrf':field(page,'_csrf'),'email':email,'agree':'1'})
capture=get('/dummy/inbox.php?address='+email)
links=re.findall(r'href="([^"]*signup_cont\.php[^\"]*)"',capture);assert links,'Confirmation email was not captured'
link=html.unescape(links[-1]);parts=urllib.parse.urlsplit(link);page=get(parts.path+'?'+parts.query)
post('/signup_cont.php',{'_csrf':field(page,'_csrf'),'id':field(page,'id'),'key':field(page,'key'),'reonEmail':name,'password':password,'passwordConfirm':password,'tradeRegions':'efdsipuj','pokemonNewsCustomOptIn':'1'})
page=get('/login.php');post('/login.php',{'_csrf':field(page,'_csrf'),'email':name,'password':password})
config=client.open(base+'/user/adapter_config.php',timeout=15).read();assert len(config)==512 and config[:2]==b'MA' and b'LM' in config and b'DA' in config
assert config[0x10c]==1 and sum(config[0x105:0x160])&0xffff==int.from_bytes(config[0x103:0x105],'little')
address=config[0x2c:0x4a].split(b'\0')[0].decode();local=address.split('@')[0]
# The real device key is at the DA signature + 4 (version/length).
offset=config.index(b'DA');key=config[offset+5:offset+37].hex()
summary=get('/user/summary.php')
password8=html.unescape(re.search(r'data-password="([^"]+)"',summary)[1]);gid=config[12:22].decode()
def wire(path,headers=None,method='GET',body=None):
 c=http.client.HTTPConnection(a.host,a.http,timeout=15);c.request(method,path,body=body,headers=headers or {});r=c.getresponse();result=(r.status,dict(r.getheaders()),r.read());c.close();return result
def gb_header(challenge):
 raw=base64.b64decode(challenge);bits=[]
 for parity in (0,1):
  for i in range(18):
   value=0
   for byte in raw[2*i:2*i+2]:
    for bit in (6+parity,4+parity,2+parity,parity):value=(value<<1)|((byte>>bit)&1)
   bits.append(value)
 data=hashlib.md5((challenge+password8).encode()).digest()+gid.encode()+bytes([255])*10
 encoded=[]
 for x,y in zip(bits,data):
  z=x^y;encoded.append((z&0xb6)|((z&1)<<3)|((z&8)<<3)|((z&64)>>6))
 return 'GB00 name="'+base64.b64encode(raw[:32]).decode()+base64.b64encode(bytes(encoded)).decode()+'"'
def game(path,expected=200):
 code,headers,raw=wire(path);assert code==401,(path,code)
 challenge=re.search(r'name="([^"]+)"',headers['WWW-Authenticate'])[1]
 code,headers,raw=wire(path,{'Authorization':gb_header(challenge)})
 assert code==expected,(path,code,raw[:80]);return raw
# DNS and Mobile Trainer homepage are real network requests.
q=struct.pack('!HHHHHH',7,0x100,1,0,0,0)+b'\x07gameboy\x0adatacenter\x02ne\x02jp\0'+struct.pack('!HH',1,1)
with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as dns:
 dns.settimeout(5);dns.sendto(q,(a.host,a.dns));answer=dns.recv(512);assert answer[-4:]==socket.inet_aton(a.host)
assert wire('/cgb/trainer.html')[0]==200
assert game('/cgb/download?name=/01/MAGBTEST/0.smallbuffer.cgb')==bytes(range(128))
# Seeded production handlers and a developer-owned binary use the real router.
seeds=Path(__file__).resolve().parents[2]/'db/seeds/gbwars3'
original=(seeds/'maps/map_1001.cgb').read_bytes()
assert wire('/cgb/download?name=/18/CGB-BWWJ/map/0/map_1001.cgb')[2]==original
assert wire('/cgb/download?name=/18/CGB-BWWE/map/0/map_1001.cgb')[2]==b'\x21\x00'+original[2:]
assert wire('/cgb/download?name=/18/CGB-BWWE/mbox/mbox_00.cgb')[2]==(seeds/'messages_e/mbox_00.cgb').read_bytes()
content=Path(__file__).resolve().parent/'content';content.mkdir(exist_ok=True)
fixture=content/('0.probe-'+uuid.uuid4().hex+'.cgb')
try:
 fixture.write_bytes(bytes(range(256))*32)
 assert game('/cgb/download?name=/00/HBREW/'+fixture.name)==fixture.read_bytes()
finally:fixture.unlink(missing_ok=True)
message='From: '+address+'\r\nTo: '+address+'\r\nSubject: Dummy\r\n\r\nHomebrew test\r\n.JIS \x1b$B$"\x1b(B\r\nX-data\r\n'
with smtplib.SMTP(a.host,a.smtp,timeout=10) as smtp:
 smtp.sendmail(address,[address],message)
 try:smtp.sendmail(address,['outside@example.org'],message)
 except smtplib.SMTPRecipientsRefused:pass
 else:raise AssertionError('External relay accepted')
pop=poplib.POP3(a.host,a.pop3,timeout=10)
try:
 pop.apop(local,key);assert pop.stat()[0]>=1
 assert b'Homebrew test' in b'\r\n'.join(pop.retr(pop.stat()[0])[1])
finally:pop.quit()
mailpage=get('/user/mail.php');assert 'Dummy' in mailpage
thread=re.search(r'href="(/user/mail.php\?thread=[^"]+)"',mailpage);assert thread
assert 'Homebrew test' in get(html.unescape(thread[1]))
post('/user/mail.php',{'_csrf':field(mailpage,'_csrf'),'action':'send','to':address,'subject':'Web test','body':'Web to game'})
pop=poplib.POP3(a.host,a.pop3,timeout=10)
try:
 pop.apop(gid,key);n=pop.stat()[0];uid=int(pop.uidl(n).decode().split()[-1]);assert b'Web to game' in b'\r\n'.join(pop.retr(n)[1]);pop.dele(n)
finally:pop.quit()
trash=get('/user/mail.php?folder=trash');assert 'Web test' in trash
post('/user/mail.php',{'_csrf':field(trash,'_csrf'),'action':'restore','id':'INBOX:'+str(uid)})
assert 'Web test' in get('/user/mail.php')
get('/logout.php')
page=get('/login.php');post('/login.php',{'_csrf':field(page,'_csrf'),'email':'devadmin','password':'dummyadmin1'})
for path in ['/admin/','/admin/bmvj.php']:
 page=get(path);assert 'Fatal error' not in page and 'Traceback' not in page
# Publish through the actual administration upload form.
library=get('/admin/bmvj.php');game_id=next('G'+str(i) for i in range(900,1000) if 'G'+str(i) not in library)
payload=bytes(range(256))*32;page=get('/admin/bmvj.php?tab=upload')
fields={'_csrf':field(page,'_csrf'),'form_action':'save','game_id':game_id,'blocks_needed':'1','category_icon':'6','minigame_type':'1','price_yen':'0','title_text':'Dummy','description_text':'Local test','is_custom':'1','is_active':'1',**{k:'0' for k in ['min_level_react','min_level_smart','min_level_sense','min_hidden_level_a','min_hidden_level_b']}}
boundary='dummy'+uuid.uuid4().hex;chunks=[]
for k,v in fields.items():chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+v+'\r\n').encode())
chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="body"; filename="test.cgb"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+payload+b'\r\n');chunks.append(('--'+boundary+'--\r\n').encode())
client.open(urllib.request.Request(base+'/admin/bmvj.php',data=b''.join(chunks),headers={'Content-Type':'multipart/form-data; boundary='+boundary}),timeout=15).read()
assert client.open(base+'/admin/bmvj.php?download='+game_id,timeout=15).read()==payload
get('/logout.php');page=get('/login.php');post('/login.php',{'_csrf':field(page,'_csrf'),'email':name,'password':password})
catalog_path='/cgb/download?name=/A4/CGB-BMVJ/RomList.cgb';binary_path='/cgb/download?name=/A4/CGB-BMVJ/0000.'+game_id+'.cgb'
try:
 before=game(catalog_path);game(binary_path,404)
 page=get('/user/summary.php');post('/user/summary.php',{'_csrf':field(page,'_csrf'),'bmvjCustomOptIn':'1'})
 after=game(catalog_path);assert after[0]==before[0]+1 and game_id.encode() in after
 assert game(binary_path)==payload
 # Toggle the preference back off: direct download must be gated again.
 page=get('/user/summary.php');post('/user/summary.php',{'_csrf':field(page,'_csrf'),'bmvjCustomOptIn':'0'});game(binary_path,404)
finally:
 get('/logout.php');page=get('/login.php');post('/login.php',{'_csrf':field(page,'_csrf'),'email':'devadmin','password':'dummyadmin1'})
 page=get('/admin/bmvj.php');post('/admin/bmvj.php',{'_csrf':field(page,'_csrf'),'form_action':'delete','game_id':game_id,'confirmation':game_id})
if a.state:open(a.state,'w').write(json.dumps({'name':name,'email':email,'password':password,'password8':password8,'gid':gid,'local':local,'key':key,'config':base64.b64encode(config).decode()}))
print('PASS: real REON home, signup email, account, login, 512-byte adapter config, SMTP/APOP, external refusal, webmail, real admin, DNS, GB00, CGB upload/catalog/opt-in/download, web-to-game mail and restore')
