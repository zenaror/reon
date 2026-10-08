'use strict';
const net = require('node:net');
const crypto = require('node:crypto');
function reply(s,text) {s.write(text+'\r\n','latin1');}
// Await commands in wire order even when another container owns the mailbox.
function lines(socket,handler) {
  let input='', queued=0, chain=Promise.resolve();socket.setTimeout(60000,()=>socket.destroy());socket.on('error',()=>{});
  socket.on('data',chunk=>{
    input+=chunk.toString('latin1');if(input.length+queued>1048576)return socket.destroy();
    let end;while((end=input.indexOf('\r\n'))>=0 && !socket.destroyed){const line=input.slice(0,end);input=input.slice(end+2);queued+=line.length+2;
      chain=chain.then(async()=>{queued-=line.length+2;if(!socket.destroyed)await handler(line);}).catch(e=>{console.error('Mail command failed:',e.message);socket.destroy();});}
  });
}
function startSMTP(store) {
  return net.createServer(s=>{
    let sender='', recipients=[], data=null, size=0, auth=null;
    async function checkAuth(name,password) {const a=await store('auth',{name,password});reply(s,a?'235 Fixture authenticated':'535 Invalid fixture credentials');auth=null;}
    reply(s,'220 mail.reon.test dummy SMTP');
    lines(s,async line=>{
      if(auth){if(auth.stage==='name'){auth.name=Buffer.from(line,'base64').toString();auth.stage='password';reply(s,'334 UGFzc3dvcmQ6');}
        else if(auth.stage==='plain'){const parts=Buffer.from(line,'base64').toString().split('\0');await checkAuth(parts[1],parts[2]);}
        else await checkAuth(auth.name,Buffer.from(line,'base64').toString());return;}
      if(data!==null){if(line==='.') {await store('deliver',{recipients,raw:Buffer.from(data.join('\r\n')+'\r\n','latin1').toString('base64'),sender});reply(s,'250 Stored internally');data=null;recipients=[];}
        else {data.push(line.startsWith('..')?line.slice(1):line);size+=line.length+2;if(size>1048576)s.destroy();}return;}
      const [verb]=line.split(' ');
      switch(verb.toUpperCase()){
        case 'EHLO':reply(s,'250-mail.reon.test\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 1048576');break;
        case 'HELO':case 'NOOP':reply(s,'250 OK');break;
        case 'AUTH':{const [,method,initial]=line.split(' ');if((method||'').toUpperCase()==='PLAIN'){if(initial){const p=Buffer.from(initial,'base64').toString().split('\0');await checkAuth(p[1],p[2]);}else {auth={stage:'plain'};reply(s,'334 ');}}
          else if((method||'').toUpperCase()==='LOGIN'){auth={stage:initial?'password':'name',name:initial?Buffer.from(initial,'base64').toString():null};reply(s,initial?'334 UGFzc3dvcmQ6':'334 VXNlcm5hbWU6');}
          else reply(s,'504 Unsupported fixture mechanism');break;}
        case 'MAIL':sender=(line.match(/<([^>]*)>/)||[])[1]||'';recipients=[];reply(s,'250 OK');break;
        case 'RCPT':{const address=(line.match(/<([^>]*)>/)||[])[1]||'', [local,domain]=address.toLowerCase().split('@');
          const a=['reon.test','mail.reon.test','reon.dion.ne.jp'].includes(domain)?await store('account',{name:local}):null;
          const recipient=a||(domain==='reon.test'&&process.env.DUMMY_DB_PASSWORD?await store('capture-account',{address:address.toLowerCase()}):null);
          if(!recipient)reply(s,'550 Only known internal recipients');else {recipients.push(recipient.name);reply(s,'250 OK');}break;}
        case 'DATA':if(!recipients.length)reply(s,'503 Specify recipients');else{data=[];size=0;reply(s,'354 End with a dot');}break;
        case 'RSET':recipients=[];sender='';reply(s,'250 OK');break;
        case 'QUIT':reply(s,'221 Bye');s.end();break;
        default:reply(s,'502 Command not simulated');
      }
    });
  }).listen(Number(process.env.DUMMY_SMTP_LISTEN||2525),'0.0.0.0');
}
function startPOP3(store) {
  return net.createServer(s=>{
    const challenge='<'+crypto.randomBytes(16).toString('hex')+'@mail.reon.test>';
    let pending=null,user=null,snapshot=[],deleted=new Set();reply(s,'+OK dummy POP3 '+challenge);
    const multi=text=>text===''?reply(s,'.'):reply(s,text.replace(/(^|\r\n)\./g,'$1..').replace(/\r\n$/,'')+'\r\n.');
    async function authenticate(a){user=a;snapshot=await store('inbox',{name:a.name});reply(s,'+OK Mailbox open');}
    lines(s,async line=>{
      const [command,arg,hash]=line.split(' '),verb=command.toUpperCase();
      if(verb==='CAPA'){reply(s,'+OK');multi('USER\r\nUIDL\r\nTOP');return;}
      if(verb==='QUIT'){if(user)await store('delete',{name:user.name,ids:[...deleted].map(i=>snapshot[i].id)});reply(s,'+OK Bye');s.end();return;}
      if(!user){if(verb==='USER'){pending=arg;reply(s,'+OK Send password');return;}
        if(verb==='PASS' && pending){const a=await store('auth',{name:pending,password:arg});if(a){await authenticate(a);return;}}
        if(verb==='APOP'){const a=await store('auth',{name:arg,challenge,hash});if(a){await authenticate(a);return;}}
        reply(s,'-ERR Authentication required');return;}
      const active=snapshot.map((m,i)=>({m,i,size:Buffer.from(m.raw,'base64').length})).filter(x=>!deleted.has(x.i));const i=Number(arg)-1,item=active.find(x=>x.i===i);
      switch(verb){case 'STAT':reply(s,`+OK ${active.length} ${active.reduce((n,x)=>n+x.size,0)}`);break;
        case 'LIST':case 'UIDL':if(arg)reply(s,item?`+OK ${i+1} ${verb==='LIST'?item.size:item.m.id}`:'-ERR No message');else{reply(s,'+OK');multi(active.map(x=>`${x.i+1} ${verb==='LIST'?x.size:x.m.id}`).join('\r\n'));}break;
        case 'RETR':if(!item)reply(s,'-ERR No message');else{await store('retrieved',{name:user.name,id:item.m.id});reply(s,'+OK');multi(Buffer.from(item.m.raw,'base64').toString('latin1'));}break;
        case 'TOP':if(!item || !/^\d+$/.test(hash||''))reply(s,'-ERR Invalid TOP');else{const raw=Buffer.from(item.m.raw,'base64').toString('latin1'),separator=raw.indexOf('\r\n\r\n');const head=separator<0?raw:raw.slice(0,separator);const body=separator<0?'':raw.slice(separator+4).split('\r\n').slice(0,Number(hash)).join('\r\n');reply(s,'+OK');multi(head+'\r\n\r\n'+body);}break;
        case 'DELE':if(!item)reply(s,'-ERR No message');else{deleted.add(i);reply(s,'+OK');}break;
        case 'RSET':deleted.clear();reply(s,'+OK');break;
        case 'NOOP':reply(s,'+OK');break;
        default:reply(s,'-ERR Command not simulated');
      }
    });
  }).listen(Number(process.env.DUMMY_POP3_LISTEN||1110),'0.0.0.0');
}
module.exports={startSMTP,startPOP3};
