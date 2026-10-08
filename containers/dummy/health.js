"use strict";
const net=require('node:net'),dgram=require('node:dgram');
(async()=>{
 if(process.env.DUMMY_SERVICE==='email'){
  for(const port of [process.env.DUMMY_SMTP_LISTEN||25,process.env.DUMMY_POP3_LISTEN||110])await new Promise((resolve,reject)=>{const s=net.connect(Number(port),'127.0.0.1');s.setTimeout(2000);s.once('data',()=>{s.destroy();resolve();});s.once('error',reject);s.once('timeout',()=>{s.destroy();reject(Error('Mail timeout'));});});
  if(!await require('./mysql-store')('account',{name:'devadmin'}))throw Error('Missing development administrator');
 }else{
  await new Promise((resolve,reject)=>{const s=dgram.createSocket('udp4');const timer=setTimeout(()=>{s.close();reject(Error('DNS timeout'));},2000);s.once('error',reject);s.once('message',p=>{clearTimeout(timer);s.close();p.length>=16?resolve():reject(Error('Malformed DNS'));});s.send(Buffer.from('002a010000010000000000000564756d6d7904746573740000010001','hex'),5353,'127.0.0.1');});
 }process.exit(0);
})().catch(()=>process.exit(1));
