// Aplica as tratativas na correspondência que JÁ estava guardada.
//
// O filtro de entrega só molda o que chega. Tudo que foi entregue antes de ele
// existir -- inclusive as mensagens migradas do MySQL -- continua com o
// envelope do Postfix dentro. Enquanto o nosso POP3 servia a porta isso não
// aparecia, porque ele moldava na leitura; quando o Dovecot assumiu a 110 e
// passou a servir os bytes guardados, essa correspondência começou a chegar
// crua no Game Boy, com Return-Path, Received e Delivered-To.
//
// Passagem única. Usa o MESMO gameFormat.js do filtro e do POP3, então o
// resultado é byte a byte o que o jogo recebia antes da virada.
//
//   node shape_existing_mail.js            -- só mostra o que faria
//   node shape_existing_mail.js --aplicar  -- grava
//
// Preserva as marcas ($Retrieved, $WebRead, $DeletedByGame) porque são elas
// que dizem se o cartucho baixou, se a pessoa leu e quem apagou. Perde a data
// de gravação na pasta, que só importa para a retenção da lixeira -- e errar
// para o lado de guardar mais tempo é o lado certo de errar.

const fs = require("fs");
const path = require("path");
const { execFile } = require("child_process");
const mysql = require("/opt/reon/mail/node_modules/mysql2/promise");
const gameFormat = require("/opt/reon/mail/gameFormat.js");

const RAIZ = "/opt/reon";
const SOCKET = "/run/dovecot/doveadm-server";
const APLICAR = process.argv.includes("--aplicar");
const cfg = JSON.parse(fs.readFileSync(path.join(RAIZ, "config.json")));
const INTERNOS = [cfg["email_domain"], cfg["email_domain_dion"]]
	.filter(Boolean).map(d => String(d).toLowerCase());

function doveadm(args, entrada) {
	return new Promise((ok, erro) => {
		const p = execFile("/usr/bin/doveadm", args, { encoding: "binary", maxBuffer: 64 * 1024 * 1024 },
			(e, out) => e ? erro(e) : ok(out));
		if (entrada !== undefined) { p.stdin.write(entrada, "binary"); p.stdin.end(); }
	});
}

const MARCA = "text: ";
function depoisDaMarca(saida) {
	const at = saida.indexOf("\n" + MARCA);
	let corpo = at < 0
		? (saida.startsWith(MARCA) ? saida.slice(MARCA.length) : null)
		: saida.slice(at + 1 + MARCA.length);
	if (corpo === null) return null;
	if (corpo.endsWith("\n")) corpo = corpo.slice(0, -1);   // enquadramento do doveadm
	return corpo.replace(/\r\n/g, "\n").replace(/\n/g, "\r\n");
}

function cabecalhoDe(t) {
	const f = t.indexOf("\r\n\r\n");
	return f === -1 ? t : t.slice(0, f);
}

// As marcas que importam. \Seen fica de fora: quem a põe agora é o POP3 do
// Dovecot, ao entregar, e reaplicá-la aqui mentiria dizendo que o cartucho
// baixou algo que ele não baixou.
const MARCAS = ["$Retrieved", "$WebRead", "$DeletedByGame", "\\Answered", "\\Flagged"];

(async () => {
	const c = await mysql.createConnection({
		host: cfg["mysql_host"], user: cfg["mysql_user"],
		password: cfg["mysql_password"], database: cfg["mysql_database"]
	});
	const [contas] = await c.execute(
		"select dion_email_local as caixa from sys_users where dion_email_local <> '' order by id");
	await c.end();

	let vistas = 0, moldadas = 0, iguais = 0, falhas = 0;

	for (const { caixa } of contas) {
		for (const pasta of ["INBOX", "Trash"]) {
			let lista;
			try {
				lista = await doveadm(["fetch", "-u", caixa, "-S", SOCKET,
					"uid flags hdr.return-path text", "mailbox", pasta]);
			} catch (e) { continue; }

			for (const bloco of lista.split("\f")) {
				const u = /^uid:\s*(\d+)/m.exec(bloco);
				if (!u) continue;
				vistas++;
				const uid = u[1];
				const flags = (/^flags:\s*(.*)$/m.exec(bloco) || [, ""])[1];
				const rp = (/^hdr\.return-path:\s*(.*)$/m.exec(bloco) || [, ""])[1].trim().replace(/^<|>$/g, "");
				const bruto = depoisDaMarca(bloco);
				if (bruto === null) { falhas++; continue; }

				const data = /^Date:[ \t]*(.*)$/im.exec(cabecalhoDe(bruto));
				const interna = gameFormat.isInternalSender(rp, INTERNOS);
				let novo = interna ? gameFormat.stripTransportHeaders(bruto)
				                   : gameFormat.slimMessage(bruto);
				novo = gameFormat.withDate(novo, data ? new Date(data[1].trim()) : new Date());

				if (novo === bruto) { iguais++; continue; }
				const guardar = MARCAS.filter(m => flags.includes(m));
				console.log(`  ${caixa}/${pasta}:${uid}  ${bruto.length} -> ${novo.length} bytes` +
					(guardar.length ? `  [${guardar.join(" ")}]` : "") + (APLICAR ? "" : "  (simulacao)"));
				if (!APLICAR) { moldadas++; continue; }

				try {
					await doveadm(["save", "-u", caixa, "-S", SOCKET, "-m", pasta], novo);
					const novaLista = await doveadm(["fetch", "-u", caixa, "-S", SOCKET, "uid", "mailbox", pasta]);
					const uids = [...novaLista.matchAll(/^uid:\s*(\d+)/gm)].map(x => Number(x[1]));
					const novoUid = Math.max(...uids);
					for (const m of guardar) {
						await doveadm(["flags", "add", "-u", caixa, "-S", SOCKET, m,
							"mailbox", pasta, "uid", String(novoUid)]);
					}
					await doveadm(["expunge", "-u", caixa, "-S", SOCKET, "mailbox", pasta, "uid", uid]);
					moldadas++;
				} catch (e) {
					console.error(`  FALHOU ${caixa}/${pasta}:${uid}: ${e.message}`);
					falhas++;
				}
			}
		}
	}
	console.log(`\n${vistas} vista(s): ${moldadas} moldada(s), ${iguais} ja estavam certas, ${falhas} falha(s)` +
		(APLICAR ? "" : "  -- simulacao, nada gravado"));
})().catch(e => { console.error(e.stack || e); process.exit(1); });
