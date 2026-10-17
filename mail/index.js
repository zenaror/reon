const fs = require("fs");
const POP3Server = require("./pop3").POP3Server;
const { Command } = require('commander');
const log = require("../lib/log").child("mail");
const program = new Command();

program
  .option('-c, --config <path>', 'Config file path.', "../config.json")
  .parse(process.argv);

const options = program.opts();
const config = JSON.parse(fs.readFileSync(options.config));

const mysqlConfig = {
	host: config["mysql_host"],
	user: config["mysql_user"],
	password: config["mysql_password"],
	database: config["mysql_database"]
}

// Postfix owns production SMTP. The previous Node SMTP implementation was removed on
// 2026-09-12; use Git history for its implementation. disable_pop3 is enabled when Dovecot
// owns port 110. This service still handles Sent copies and notifications through the
// side-effects listener below.
let pop3 = config["disable_pop3"] === true ? null
	: new POP3Server(mysqlConfig, config["email_domain"], config["email_domain_dion"], config);
if (pop3 === null) log.info("POP3: a porta 110 e do Dovecot; servidor proprio desligado");

// Create a MySQL pool here when the optional POP3 service did not create one.
const poolEfeitos = pop3 ? pop3.mysql : require("mysql2").createPool(mysqlConfig);

// Sent copies and recipient notifications are handled by mail/sideEffects.js. They must
// remain active when Dovecot LMTP replaces the previous delivery agent.
const sideEffects = require("./sideEffects");
sideEffects.iniciar(poolEfeitos);