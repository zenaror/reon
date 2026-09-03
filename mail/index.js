const fs = require("fs");
const SMTPServer = require("./smtp").SMTPServer;
const POP3Server = require("./pop3").POP3Server;
const { Command } = require('commander');
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

// disable_smtp: set by setup-postfix-bridge.sh once Postfix takes over port
// 25 (mydestination for reon.dion.ne.jp/gameboy.datacenter.ne.jp/the real
// bridge domain, delivering into sys_inbox via mail/deliver.js). POP3 keeps
// serving the same table regardless of who wrote the rows.
let smtp = config["disable_smtp"] === true ? null : new SMTPServer(mysqlConfig, config["email_domain"], config["email_domain_dion"]);
let pop3 = new POP3Server(mysqlConfig);