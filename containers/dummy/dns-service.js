'use strict';
const dgram=require('node:dgram');
module.exports=function startDNS(){
  const dns=dgram.createSocket('udp4');const ip=(process.env.DUMMY_EXTERNAL_IP||'127.0.0.1').split('.').map(Number);
  if(ip.length!==4||ip.some(n=>!Number.isInteger(n)||n<0||n>255))throw Error('DUMMY_EXTERNAL_IP must be IPv4');
  dns.on('error',e=>console.error(e.message));
  dns.on('message',(packet,remote)=>{
    if(packet.length<12||packet.readUInt16BE(4)!==1)return;let end=12;
    while(end<packet.length && packet[end]!==0){const len=packet[end];if(len>63)return;end+=len+1;}
    end+=5;if(end>packet.length)return;const a=packet.readUInt16BE(end-4)===1 && packet.readUInt16BE(end-2)===1;
    const header=Buffer.alloc(12);packet.copy(header,0,0,2);header.writeUInt16BE(0x8180,2);header.writeUInt16BE(1,4);header.writeUInt16BE(a?1:0,6);
    const answer=a?Buffer.from([0xc0,0x0c,0,1,0,1,0,0,0,0,0,4,...ip]):Buffer.alloc(0);
    dns.send(Buffer.concat([header,packet.subarray(12,end),answer]),remote.port,remote.address);
  });dns.bind(5353,'0.0.0.0');return dns;
};
