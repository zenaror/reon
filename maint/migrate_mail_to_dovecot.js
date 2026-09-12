// SPDX-License-Identifier: GPL-3.0-or-later
//
// CUMPRIDO em 12/09/2026, e a tabela que ele lê foi apagada depois -- este
// script não roda mais. Guardado porque é o registro de COMO a migração foi
// feita, e das decisões de mapeamento que ela tomou.
//
// Leva a correspondência que está em sys_inbox para as caixas do Dovecot,
// falando LMTP -- o mesmo caminho que o Postfix usa. Idempotente não é:
// rodar duas vezes entrega duas vezes. É de uso único, no corte.
//
//   node maint/migrate_mail_to_dovecot.js -c config.json [--dry-run]
//
// O que se preserva, e como:
//
//   o remetente do envelope   vira o MAIL FROM, que é de onde o POP3 decide
//                             se a mensagem é interna -- perder isso faria
//                             correspondência de jogo ser tratada como
//                             externa e reduzida, que é o defeito que já
//                             custou o Trade Corner
//   a hora de chegada         vira um cabeçalho Date na própria mensagem. O
//                             Dovecot carimba a hora da ENTREGA, que no
//                             corte é "agora"; sem isto as doze pareceriam
//                             ter chegado todas no mesmo minuto de 2026
//   já coletada               vira a palavra-chave $Retrieved
//   já lida no webmail        vira a flag \Seen
//   na lixeira                vai para a pasta Trash

const fs = require("fs");
const net = require("net");
const mysql = require("../mail/node_modules/mysql2/promise");
const { execFile } = require("child_process");
const { Command } = require("../mail/node_modules/commander");

const program = new Command();
program.option("-c, --config <path>", "config.json", "config.json")
       .option("--dry-run", "só mostra o que faria");
program.parse();
const opts = program.opts();
const config = JSON.parse(fs.readFileSync(opts.config, "utf8"));
const LMTP = "/var/spool/postfix/private/dovecot-lmtp";

function lmtpDeliver(from, to, message) {
	return new Promise((resolve, reject) => {
		const s = net.createConnection(LMTP);
		let buf = "", passo = 0;
		const roteiro = ["LHLO migrate", `MAIL FROM:<${from}>`, `RCPT TO:<${to}>`, "DATA"];
		s.on("data", d => {
			buf += d.toString("binary");
			let linha;
			while ((linha = buf.indexOf("\r\n")) >= 0) {
				const r = buf.slice(0, linha); buf = buf.slice(linha + 2);
				if (r[3] === "-") continue;               // resposta em várias linhas
				if (r[0] !== "2" && r[0] !== "3") { s.destroy(); reject(new Error(r)); return; }
				if (passo < roteiro.length) { s.write(roteiro[passo++] + "\r\n"); continue; }
				if (passo === roteiro.length) {           // depois do 354
					passo++;
					s.write(Buffer.from(message, "binary"));
					// O ponto final precisa começar em linha nova, mas só se
					// a mensagem já não terminar em quebra -- acrescentar uma
					// sempre deixa uma linha em branco a mais no fim, e o
					// corpo deixa de bater byte a byte com o original.
					s.write(message.endsWith("\r\n") ? ".\r\n" : "\r\n.\r\n");
					continue;
				}
				s.write("QUIT\r\n"); s.end(); resolve(r); return;
			}
		});
		s.on("error", reject);
	});
}

function doveadm(args) {
	return new Promise((resolve, reject) =>
		execFile("/usr/bin/doveadm", args, (e, out, err) =>
			e ? reject(new Error(String(err || e.message).trim())) : resolve(out)));
}

const RFC = t => new Date(t).toUTCString().replace("GMT", "+0000");

(async () => {
	const c = await mysql.createConnection({
		host: config["mysql_host"], user: config["mysql_user"],
		password: config["mysql_password"], database: config["mysql_database"],
	});

	const [linhas] = await c.execute(
		`select i.id, i.sender, i.message, i.timestamp, i.deleted_at, i.retrieved_at, i.read_at,
		        u.dion_email_local as caixa
		   from sys_inbox i join sys_users u on u.id = i.recipient
		  order by i.id`);

	console.log(`${linhas.length} mensagem(ns) a migrar`);
	let ok = 0, falhas = 0;

	for (const m of linhas) {
		const destino = `${m.caixa}@${config["email_domain_dion"]}`;
		const cru = m.message.toString("binary");
		// Date só entra se a mensagem já não tiver um -- duas datas não é
		// data, é ambiguidade, e é a mesma regra que o POP3 aplica.
		const temData = /^date:/im.test(cru.split("\r\n\r\n")[0] || "");
		const corpo = temData ? cru
			: cru.replace(/\r\n\r\n/, `\r\nDate: ${RFC(m.timestamp)}\r\n\r\n`);

		const rotulo = `  #${m.id} -> ${m.caixa}  ${corpo.length}b` +
			(m.retrieved_at ? " [coletada]" : "") + (m.read_at ? " [lida]" : "") +
			(m.deleted_at ? " [lixeira]" : "");

		if (opts.dryRun) { console.log(rotulo + "  (simulação)"); continue; }

		try {
			await lmtpDeliver(m.sender || `system@${config["email_domain_dion"]}`, destino, corpo);
			// O UID recém-criado é o maior da caixa.
			const lista = await doveadm(["fetch", "-u", m.caixa, "uid", "mailbox", "INBOX"]);
			const uids = [...lista.matchAll(/^uid:\s*(\d+)/gm)].map(x => Number(x[1]));
			const uid = Math.max(...uids);

			// Coletada pelo jogo e lida no webmail são coisas diferentes e
			// sempre foram duas colunas: o cartucho baixa sem ninguém ler, e
			// o site é lido sem o cartucho ter passado. São duas marcas.
			const marcas = [];
			if (m.retrieved_at) marcas.push("$Retrieved");
			if (m.read_at) marcas.push("\\Seen");
			for (const marca of marcas)
				await doveadm(["flags", "add", "-u", m.caixa, marca,
					"mailbox", "INBOX", "uid", String(uid)]);
			if (m.deleted_at) {
				await doveadm(["mailbox", "create", "-u", m.caixa, "Trash"]).catch(() => {});
				await doveadm(["move", "-u", m.caixa, "Trash", "mailbox", "INBOX", "uid", String(uid)]);
			}
			console.log(rotulo + `  uid=${uid} ok`);
			ok++;
		} catch (e) {
			console.error(rotulo + "  FALHOU: " + e.message);
			falhas++;
		}
	}

	console.log(`\nmigradas ${ok}, falharam ${falhas}`);
	if (falhas) process.exitCode = 1;
	await c.end();
})().catch(e => { console.error(e); process.exitCode = 1; });
