const fs = require("fs");
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

// O SMTP próprio saiu em 12/09/2026. Quem atende a porta 25 é o Postfix desde
// o setup-postfix-bridge.sh, e `disable_smtp` estava ligado havia semanas --
// o código ficava aqui sem nunca rodar. Ver mail/README ou o histórico do git
// se algum dia for preciso olhar o que ele fazia.
// disable_pop3: ligado quando o Dovecot assume a porta 110. O serviço continua
// existindo pelos efeitos colaterais abaixo -- a cópia em Enviados e o sino --,
// que não são trabalho de servidor POP3 e não têm dono do lado do Dovecot.
let pop3 = config["disable_pop3"] === true ? null
	: new POP3Server(mysqlConfig, config["email_domain"], config["email_domain_dion"], config);
if (pop3 === null) console.log("POP3: a porta 110 e do Dovecot; servidor proprio desligado");

// O pool do MySQL vinha do POP3; sem ele, monta-se um aqui.
const poolEfeitos = pop3 ? pop3.mysql : require("mysql2").createPool(mysqlConfig);

// Cópia em Enviados e linha no sino. Moravam no deliver.js, que saiu do
// caminho quando o Postfix passou a entregar pelo LMTP do Dovecot -- e levou
// as duas junto, sem ninguém notar. Ver mail/sideEffects.js.
const sideEffects = require("./sideEffects");
sideEffects.iniciar(poolEfeitos);