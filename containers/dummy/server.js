'use strict';
// Deliberately small local fixture server. It does not run REON's production app.
const http = require('node:http');
const net = require('node:net');
const dgram = require('node:dgram');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const DATA = process.env.SDK_DATA || '/data';
const CONTENT = path.resolve(process.env.SDK_CONTENT || '/content');
fs.mkdirSync(DATA, {recursive:true});
fs.mkdirSync(CONTENT, {recursive:true});
const accountsFile = path.join(CONTENT, 'accounts.json');
const accounts = fs.existsSync(accountsFile) ? JSON.parse(fs.readFileSync(accountsFile)) : [
  {name:'player01', password:'test0001', aliases:['g000000007']},
  {name:'player02', password:'test0002', aliases:['g000000008']}
];
function account(name) { return accounts.find(a => a.name === name || (a.aliases || []).includes(name)); }
const stateFile = path.join(DATA, 'mail.json');
let messages = fs.existsSync(stateFile) ? JSON.parse(fs.readFileSync(stateFile)) : [];
function save() { fs.writeFileSync(stateFile + '.new', JSON.stringify(messages)); fs.renameSync(stateFile + '.new', stateFile); }
function inbox(name) { return messages.filter(m => m.recipient === name); }
function deliver(name, raw, sender) {
  messages.push({id:crypto.randomUUID(), recipient:name, sender, raw:raw.toString('base64')}); save();
}
function routes() {
  const file = path.join(CONTENT, 'routes.json');
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file)) : [];
}
function send(res, code, body, headers={}) {
  const bytes = Buffer.isBuffer(body) ? body : Buffer.from(body);
  res.writeHead(code, {'Content-Type':'text/plain', ...headers, 'Content-Length':bytes.length}); res.end(bytes);
}
http.createServer((req,res) => {
  const url = new URL(req.url, 'http://sdk');
  try {
    if (url.pathname === '/_sdk/health') return send(res,200,'ok');
    if (url.pathname === '/_sdk/mail' && req.method === 'GET') return send(res,200,JSON.stringify(messages), {'Content-Type':'application/json'});
    if (url.pathname === '/_sdk/routes') return send(res,200,JSON.stringify(routes()), {'Content-Type':'application/json'});
    if (url.pathname === '/') return send(res,200,`<!doctype html><meta charset="utf-8"><title>REON dummy SDK</title><h1>REON dummy SDK</h1><p>Local fixtures for homebrew. No production accounts or external mail.</p><p>Mount /content/routes.json and response files to publish HTTP fixtures. Files reload on every request.</p><p>SMTP :2525 · POP3 :1110 · DNS UDP :5353</p><p>Default accounts: player01 / test0001 and player02 / test0002.</p><p><a href="/_sdk/routes">Routes</a> · <a href="/_sdk/mail">Internal mail</a></p>`, {'Content-Type':'text/html; charset=utf-8'});
    const route = routes().find(r => r.path === url.pathname && (r.method || 'GET') === req.method && (!r.host || r.host === url.hostname || r.host === (req.headers.host || '').split(':')[0]));
    if (!route) return send(res,404,'No fixture for this request');
    let body = route.bodyBase64 !== undefined ? Buffer.from(route.bodyBase64,'base64') : Buffer.from(route.body || '');
    if (route.file) {
      const file = fs.realpathSync(path.resolve(CONTENT,route.file));
      if (!file.startsWith(fs.realpathSync(CONTENT) + path.sep)) return send(res,403,'Fixture outside content directory');
      body = fs.readFileSync(file);
    }
    console.log(JSON.stringify({method:req.method,path:url.pathname,status:route.status || 200}));
    send(res,route.status || 200,body,route.headers || {});
  } catch (e) { console.error(e.message); send(res,500,'Invalid fixture configuration'); }
}).listen(8080,'0.0.0.0');

// Bounded line protocol; Latin-1 preserves fixture bytes without transcoding.
function lines(socket, handler) {
  let input = ''; socket.setTimeout(60000,()=>socket.destroy());
  socket.on('error',()=>{});
  socket.on('data',chunk=>{
    input += chunk.toString('latin1');
    if (input.length > 1048576) return socket.destroy();
    let end;
    while ((end=input.indexOf('\r\n')) >= 0 && !socket.destroyed) {
      const line=input.slice(0,end); input=input.slice(end+2); handler(line);
    }
  });
}
function reply(s,text) { s.write(text + '\r\n','latin1'); }
net.createServer(s=>{
  let sender='', recipients=[], data=null, auth=null;
  const checkAuth=(name,password)=>{const a=account(name);reply(s,a && a.password===password ? '235 Fixture authenticated' : '535 Invalid fixture credentials');auth=null;};
  reply(s,'220 mail.reon.test dummy SMTP');
  lines(s,line=>{
    if (auth) {
      if (auth.stage === 'name') {auth.name=Buffer.from(line,'base64').toString();auth.stage='password';reply(s,'334 UGFzc3dvcmQ6');}
      else if (auth.stage === 'plain') {const parts=Buffer.from(line,'base64').toString().split('\0');checkAuth(parts[1],parts[2]);}
      else checkAuth(auth.name,Buffer.from(line,'base64').toString());
      return;
    }
    if (data !== null) {
      if (line === '.') {
        const raw = Buffer.from(data.join('\r\n')+'\r\n','latin1');
        for (const name of recipients) deliver(name,raw,sender);
        reply(s,'250 Stored internally'); data=null; recipients=[];
      } else { data.push(line.startsWith('..') ? line.slice(1) : line); if (data.reduce((n,l)=>n+l.length,0)>1048576) s.destroy(); }
      return;
    }
    const [verb] = line.split(' ');
    switch(verb.toUpperCase()) {
      case 'EHLO': reply(s,'250-mail.reon.test\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 1048576'); break;
      case 'AUTH': {
        const [,method,initial]=line.split(' ');
        if ((method||'').toUpperCase()==='PLAIN') {
          if(initial) {const parts=Buffer.from(initial,'base64').toString().split('\0');checkAuth(parts[1],parts[2]);}
          else {auth={stage:'plain'};reply(s,'334 ');}
        } else if((method||'').toUpperCase()==='LOGIN') {
          auth={stage:initial?'password':'name',name:initial?Buffer.from(initial,'base64').toString():null};
          reply(s,initial?'334 UGFzc3dvcmQ6':'334 VXNlcm5hbWU6');
        } else reply(s,'504 Unsupported fixture mechanism');
        break;
      }
      case 'HELO': case 'NOOP': reply(s,'250 OK'); break;
      case 'MAIL': sender=(line.match(/<([^>]*)>/)||[])[1]||'';recipients=[]; reply(s,'250 OK');break;
      case 'RCPT': {
        const address=(line.match(/<([^>]*)>/)||[])[1]||'';
        const [local,domain]=address.toLowerCase().split('@'); const a=account(local);
        if (!a || !['mail.reon.test','reon.dion.ne.jp'].includes(domain)) reply(s,'550 Only known internal recipients');
        else {recipients.push(a.name); reply(s,'250 OK');} break;
      }
      case 'DATA': if (!recipients.length) reply(s,'503 Specify recipients'); else {data=[];reply(s,'354 End with a dot');} break;
      case 'RSET': recipients=[]; sender=''; reply(s,'250 OK');break;
      case 'QUIT':reply(s,'221 Bye');s.end();break;
      default:reply(s,'502 Command not simulated');
    }
  });
}).listen(2525,'0.0.0.0');
net.createServer(s=>{
  const challenge='<'+crypto.randomBytes(16).toString('hex')+'@mail.reon.test>';
  let pending=null, user=null, snapshot=[], deleted=new Set();
  reply(s,'+OK dummy POP3 '+challenge);
  const authenticate=a=>{ user=a; snapshot=inbox(a.name).slice(); reply(s,'+OK Mailbox open'); };
  const multi=text=>text === '' ? reply(s,'.') : reply(s,text.replace(/(^|\r\n)\./g,'$1..').replace(/\r\n$/,'')+'\r\n.');
  lines(s,line=>{
    const [command,arg,hash]=line.split(' '); const verb=command.toUpperCase();
    if (verb === 'QUIT') {
      if(user) {const ids=new Set([...deleted].map(i=>snapshot[i].id)); messages=messages.filter(m=>!ids.has(m.id));save();}
      reply(s,'+OK Bye');return s.end();
    }
    if(!user) {
      if(verb==='USER') {pending=account(arg);return reply(s,'+OK Send password');}
      if(verb==='PASS' && pending && arg===pending.password) return authenticate(pending);
      if(verb==='APOP') {
        const a=account(arg); const expected=a && crypto.createHash('md5').update(challenge+a.password).digest('hex');
        if(expected && /^[a-f0-9]{32}$/i.test(hash||'') && crypto.timingSafeEqual(Buffer.from(expected),Buffer.from(hash.toLowerCase()))) return authenticate(a);
      }
      return reply(s,'-ERR Authentication required');
    }
    const active=snapshot.map((m,i)=>({m,i,size:Buffer.from(m.raw,'base64').length})).filter(x=>!deleted.has(x.i));
    const i=Number(arg)-1; const item=active.find(x=>x.i===i);
    switch(verb) {
      case 'STAT':reply(s,`+OK ${active.length} ${active.reduce((n,x)=>n+x.size,0)}`);break;
      case 'LIST': case 'UIDL':
        if(arg) reply(s,item ? `+OK ${i+1} ${verb==='LIST'?item.size:item.m.id}` : '-ERR No message');
        else {reply(s,'+OK');multi(active.map(x=>`${x.i+1} ${verb==='LIST'?x.size:x.m.id}`).join('\r\n'));} break;
      case 'RETR': if(!item) reply(s,'-ERR No message');else {reply(s,'+OK');multi(Buffer.from(item.m.raw,'base64').toString('latin1'));} break;
      case 'DELE':if(!item) reply(s,'-ERR No message');else {deleted.add(i);reply(s,'+OK');}break;
      case 'RSET':deleted.clear();reply(s,'+OK');break;
      case 'NOOP':reply(s,'+OK');break;
      default:reply(s,'-ERR Command not simulated');
    }
  });
}).listen(1110,'0.0.0.0');
const dns=dgram.createSocket('udp4');
const ip=(process.env.SDK_EXTERNAL_IP||'127.0.0.1').split('.').map(Number);
if(ip.length!==4 || ip.some(n=>!Number.isInteger(n)||n<0||n>255)) throw Error('SDK_EXTERNAL_IP must be IPv4');
dns.on('error',e=>console.error(e.message));
dns.on('message',(packet,remote)=>{
  if(packet.length<12 || packet.readUInt16BE(4)!==1) return;
  let end=12;
  while(end<packet.length && packet[end]!==0) {const len=packet[end];if(len>63)return;end+=len+1;}
  end+=5; if(end>packet.length)return;
  const a=packet.readUInt16BE(end-4)===1 && packet.readUInt16BE(end-2)===1;
  const header=Buffer.alloc(12);packet.copy(header,0,0,2); header.writeUInt16BE(0x8180,2);header.writeUInt16BE(1,4);header.writeUInt16BE(a?1:0,6);
  const answer=a?Buffer.from([0xc0,0x0c,0,1,0,1,0,0,0,0,0,4,...ip]):Buffer.alloc(0);
  dns.send(Buffer.concat([header,packet.subarray(12,end),answer]),remote.port,remote.address);
});
dns.bind(5353,'0.0.0.0');
console.log('REON dummy SDK: HTTP 8080, SMTP 2525, POP3 1110, DNS UDP 5353; internal fixtures only');

// PID 1 needs explicit signal handlers. Mail state is written synchronously.
process.on('SIGTERM', () => process.exit(0));
process.on('SIGINT', () => process.exit(0));
