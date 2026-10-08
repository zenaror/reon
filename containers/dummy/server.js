"use strict";
const service=process.env.DUMMY_SERVICE||'email';
process.on('SIGTERM',()=>process.exit(0));
if(service==='dns')require('./dns-service')();
else if(service==='email'){
 if(!process.env.DUMMY_DB_PASSWORD)throw Error('Email needs the Dummy server MySQL database; start the Compose stack.');
 const store=require('./mysql-store'),mail=require('./mail-services');
 mail.startSMTP(store);mail.startPOP3(store);
}else throw Error('Unknown Dummy server service');
