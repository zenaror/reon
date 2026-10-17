// SPDX-License-Identifier: GPL-3.0-or-later
//
// One-time migration completed on 2026-09-12; its source sys_inbox table was subsequently
// removed. Preserve this script as the migration record, not a rerunnable operation. node
// maint/migrate_mail_to_dovecot.js -c config.json [--dry-run] submits via LMTP. Envelope
// sender becomes MAIL FROM, original arrival time becomes Date, retrieved status becomes
// $Retrieved, web-read status becomes \Seen, and deleted messages go to Trash. Running
// twice delivers duplicates.

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
				if (passo === roteiro.length) {           // After the 354 response.
					passo++;
					s.write(Buffer.from(message, "binary"));
					// Start the SMTP terminator on a new line only if the message does not already end with
					// one; otherwise an extra blank line changes the original payload.
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
		// Insert Date only when absent; duplicate dates are ambiguous and the POP3 path follows
		// the same rule.
		const temData = /^date:/im.test(cru.split("\r\n\r\n")[0] || "");
		const corpo = temData ? cru
			: cru.replace(/\r\n\r\n/, `\r\nDate: ${RFC(m.timestamp)}\r\n\r\n`);

		const rotulo = `  #${m.id} -> ${m.caixa}  ${corpo.length}b` +
			(m.retrieved_at ? " [coletada]" : "") + (m.read_at ? " [lida]" : "") +
			(m.deleted_at ? " [lixeira]" : "");

		if (opts.dryRun) { console.log(rotulo + "  (simulação)"); continue; }

		try {
			await lmtpDeliver(m.sender || `system@${config["email_domain_dion"]}`, destino, corpo);
			// The newly created UID is the highest UID in the mailbox.
			const lista = await doveadm(["fetch", "-u", m.caixa, "uid", "mailbox", "INBOX"]);
			const uids = [...lista.matchAll(/^uid:\s*(\d+)/gm)].map(x => Number(x[1]));
			const uid = Math.max(...uids);

			// Cartridge retrieval and webmail reading are independent states; preserve both markers.
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
