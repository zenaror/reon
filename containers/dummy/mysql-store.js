'use strict';
const mysql=require('mysql2/promise'),crypto=require('node:crypto'),format=require('./gameFormat');
const pool=mysql.createPool({host:'database',user:'reon',database:'reon',password:process.env.DUMMY_DB_PASSWORD,connectionLimit:4});
async function account(name) {
 const [rows]=await pool.execute('SELECT u.*,a.device_auth_key FROM sys_users u LEFT JOIN sys_device_authorization a ON a.user_id=u.id WHERE (u.username=? OR u.dion_email_local=? OR u.dion_ppp_id=?) AND u.banned_at IS NULL LIMIT 1',[name,name,name]);
 return rows[0]||null;
}
module.exports=async function store(op,v){
 const a=await account(v.name||'');
 if(op==='account')return a?{name:a.dion_email_local}:null;
 if(op==='capture-account')return {name:v.address};
 if(op==='auth'){
  if(!a)return null;
  const keys=[a.log_in_password];if(a.device_auth_key)keys.push(a.device_auth_key.toString('hex'));
  const valid=v.challenge?keys.some(k=>crypto.createHash('md5').update(v.challenge+k).digest('hex')===String(v.hash).toLowerCase()):v.password===a.log_in_password;
  return valid?{name:a.dion_email_local}:null;
 }
 if(op==='deliver'){
  for(const recipient of v.recipients){
   const u=await account(recipient);
   const original=Buffer.from(v.raw,'base64');
   let message=original;
   if(u){
    const text=original.toString('latin1'),date=/^Date:[ \t]*(.*)$/im.exec(text.split('\r\n\r\n')[0]);
    const shaped=format.isInternalSender(v.sender,['reon.test','reon.dion.ne.jp','mail.reon.test'])?format.stripTransportHeaders(text):format.slimMessage(text);
    message=Buffer.from(format.withDate(shaped,date?new Date(date[1]):new Date()),'latin1');
   }
   await pool.execute('INSERT INTO dummy_mail (recipient,sender,message) VALUES (?,?,?)',[u?u.dion_email_local:recipient,v.sender,message]);
   if(u&&!/^X-REON-Origin:[ \t]*web\b/im.test(original.toString('latin1'))){
    const sender=await account(v.sender.split('@')[0]);
    if(sender)await pool.execute("INSERT INTO sys_sent (user_id,recipient,origin,message) VALUES (?,?,'game',?)",[sender.id,recipient+'@reon.dion.ne.jp',message]);
   }
  }return {ok:true};
 }
 if(!a)throw Error('Unknown mailbox');
 if(op==='inbox'){
  const [rows]=await pool.execute("SELECT id,message FROM dummy_mail WHERE recipient=? AND folder='INBOX' AND deleted_at IS NULL ORDER BY id",[a.dion_email_local]);
  return rows.map(r=>({id:r.id,raw:r.message.toString('base64')}));
 }
 if(op==='retrieved'){await pool.execute('UPDATE dummy_mail SET retrieved_at=NOW() WHERE recipient=? AND id=?',[a.dion_email_local,v.id]);return {ok:true};}
 if(op==='delete'){
  for(const id of v.ids)await pool.execute("UPDATE dummy_mail SET deleted_at=NOW(),deleted_by='game' WHERE recipient=? AND id=?",[a.dion_email_local,id]);
  return {ok:true};
 }throw Error('Unknown mailbox operation');
};
