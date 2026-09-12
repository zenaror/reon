// SPDX-License-Identifier: GPL-3.0-or-later
//
// De onde o POP3 tira a correspondência.
//
// Duas implementações atrás da mesma porta, escolhidas por `mail_store` no
// config.json:
//
//   "mysql"    a tabela sys_inbox. APAGADA em 12/09/2026 -- este caminho
//              não funciona mais e está aqui só como história. Há um despejo
//              em /var/backups/reon/ se algum dia for preciso olhar.
//   "dovecot"  as caixas do Dovecot, que é a estrutura do servidor do
//              REONTeam -- Postfix entrega, Dovecot guarda.
//
// A reversibilidade que as duas davam acabou junto com a tabela, e de
// propósito: voltar atrás deixou de ser uma linha de config no dia em que a
// correspondência passou a existir só no Dovecot.
//
// Aliás, este arquivo inteiro só roda se o nosso POP3 estiver ligado, e ele
// está desligado desde que o Dovecot assumiu a porta 110 (`disable_pop3`).
//
// O que NÃO muda de lado: todo o tratamento de que o Game Boy depende --
// redução de cabeçalho, correspondência interna entregue como está, XAPOP --
// continua em pop3Connection.js. Esta camada responde "quais mensagens" e
// "quais bytes"; o que se faz com eles é de lá.
//
// O backend do Dovecot fala por `doveadm`, não por IMAP. Não acrescenta
// dependência ao serviço que já teve um CVE de biblioteca de e-mail, e não
// exige um parser de IMAP escrito à mão, onde um literal mal lido trunca uma
// mensagem em silêncio -- a classe de defeito que já custou o Trade Corner.
// Conferido: `doveadm fetch text` devolve os bytes exatos do arquivo.
//
// Todas as operações recebem `ctx` = { id, name }: o id da conta (que o
// MySQL usa) e o nome da caixa (que o Dovecot usa).

const { execFile } = require("child_process");

// Palavra-chave IMAP no lugar da coluna retrieved_at. O cifrão é a convenção
// para palavra-chave de aplicação, e ela sobrevive a mudar de pasta.
const RETRIEVED = "$Retrieved";
const TRASH = "Trash";

// Correspondência de jogo que o cartucho JÁ COLETOU é apagada de vez, e não
// mandada para a lixeira: guardar cópia restaurável de uma troca concluída é
// caminho para receber o mesmo Pokémon duas vezes. Carta de jogo que o Mobile
// Trainer apagou sem baixar continua com a rede de proteção da lixeira.
function isCollectedGameMail(headerBlock) {
	return /^x-game-code:/im.test(headerBlock)
	    && /^x-gbmail-type:\s*exclusive/im.test(headerBlock);
}

const GAME_MAIL_SQL =
	"(message like '%X-Game-code:%' and message like '%X-GBmail-type: exclusive%')";

// ------------------------------------------------------------------ mysql

function mysqlStore(mysql) {
	const q = (sql, args) => new Promise((resolve, reject) =>
		mysql.query(sql, args, (e, r) => e ? reject(e) : resolve(r)));

	return {
		name: "mysql",

		// Ordenado por id sempre: é essa ordem que vira a numeração do POP3.
		// Sem ORDER BY quem escolhia era o otimizador, e ler uma mensagem no
		// webmail chegou a reembaralhar os números que o jogo endereça.
		async list(ctx) {
			const r = await q("select id, char_length(message) as size from sys_inbox " +
				"where recipient = ? and deleted_at is null order by id", [ctx.id]);
			return r.map(x => ({ id: x["id"], size: x["size"] }));
		},

		async fetch(ctx, id) {
			const r = await q("select message, sender, timestamp from sys_inbox where id = ?", [id]);
			if (!r.length) throw new Error("message " + id + " vanished from sys_inbox mid-session");
			return { message: r[0]["message"], sender: r[0]["sender"], timestamp: r[0]["timestamp"] };
		},

		async markRetrieved(ctx, id) {
			await q("update sys_inbox set retrieved_at = now() where id = ? and retrieved_at is null", [id]);
		},

		async remove(ctx, ids) {
			if (!ids.length) return;
			await q("delete from sys_inbox where id in (?) and deleted_at is null " +
				"and retrieved_at is not null and " + GAME_MAIL_SQL, [ids]);
			await q("update sys_inbox set deleted_at = now(), deleted_by = 'game' " +
				"where id in (?) and deleted_at is null", [ids]);
		},
	};
}

// ---------------------------------------------------------------- dovecot

// Ler a caixa de OUTRA conta exige privilégio que o usuário do serviço não
// tem -- e não deve ter. Então ele não lê: pede. O doveadm-server roda dentro
// do Dovecot, que é quem tem o privilégio; este é o socket por onde se pede.
// Mesma ideia do auxiliar de serviços do painel: quem pode não é quem pede.
const SOCKET = "/run/dovecot/doveadm-server";

// As opções vêm DEPOIS do subcomando inteiro, e há subcomandos de duas
// palavras: `flags add`, `mailbox create`. Pôr o -u depois da primeira
// palavra monta "doveadm flags -u x add ...", que o doveadm recusa -- e o
// RETR passava a responder duas vezes, um -ERR do markRetrieved falhando e
// logo em seguida o +OK da mensagem. Uma resposta a mais num protocolo de
// linha desalinha tudo o que vem depois.
function dove(cmd, user, rest, opts) {
	const palavras = Array.isArray(cmd) ? cmd : [cmd];
	return doveadm([...palavras, "-u", user, "-S", SOCKET, ...rest], opts);
}

function doveadm(args, { binary = false } = {}) {
	return new Promise((resolve, reject) => {
		execFile("/usr/bin/doveadm", args,
			{ encoding: binary ? "buffer" : "utf8", maxBuffer: 64 * 1024 * 1024 },
			(error, stdout, stderr) => {
				if (error) { error.message += ": " + String(stderr || "").trim(); reject(error); return; }
				resolve(stdout);
			});
	});
}

// `doveadm fetch` devolve "campo: valor" e separa registros com \f. O valor
// de `text` começa NA MESMA LINHA do rótulo: "text: Return-Path: <...>".
// Pular até a primeira quebra come a primeira linha da mensagem inteira --
// defeito que ficou escondido porque essa linha costuma ser o Return-Path,
// que a entrega descarta de qualquer jeito.
const MARCA = Buffer.from("text: ");
function afterTextMarker(out) {
	const buf = Buffer.isBuffer(out) ? out : Buffer.from(String(out), "binary");
	let corpo;
	if (buf.subarray(0, MARCA.length).equals(MARCA)) {
		corpo = buf.subarray(MARCA.length);
	} else {
		const at = buf.indexOf(Buffer.concat([Buffer.from("\n"), MARCA]));
		corpo = at < 0 ? buf.subarray(0, 0) : buf.subarray(at + 1 + MARCA.length);
	}
	// O doveadm fecha o campo com uma quebra de linha que é DELE, não da
	// mensagem: o arquivo no Maildir termina em "\n" e a saída do fetch vem
	// com "\n\n". Sem tirar esta, toda mensagem ganha uma linha em branco no
	// fim -- e num payload binário de troca isso não é cosmético.
	if (corpo.length && corpo[corpo.length - 1] === 0x0a) {
		corpo = corpo.subarray(0, corpo.length - 1);
	}
	return corpo;
}

function dovecotStore() {
	return {
		name: "dovecot",

		async list(ctx) {
			const out = await dove("fetch", ctx.name, ["uid size.physical", "mailbox", "INBOX"]);
			const items = [];
			for (const bloco of out.split("\f")) {
				const uid = /^uid:\s*(\d+)/m.exec(bloco);
				const size = /^size\.physical:\s*(\d+)/m.exec(bloco);
				if (uid && size) items.push({ id: Number(uid[1]), size: Number(size[1]) });
			}
			// O UID do IMAP faz o papel do id: cresce, não se repete e
			// sobrevive à sessão.
			items.sort((a, b) => a.id - b.id);
			return items;
		},

		// Os bytes exatos, mais o remetente do ENVELOPE -- que é o que decide
		// se a mensagem é interna, e por isso tem de ser o Return-Path e não
		// o From, que qualquer um escreve.
		async fetch(ctx, id) {
			const bruto = await dove("fetch", ctx.name,
				["text", "mailbox", "INBOX", "uid", String(id)], { binary: true });
			if (!bruto.length) throw new Error("message " + id + " vanished from the mailbox mid-session");

			const cab = await dove("fetch", ctx.name,
				["hdr.return-path hdr.date date.received", "mailbox", "INBOX", "uid", String(id)]);
			const rp = /^hdr\.return-path:\s*<?([^>\r\n]*)>?/mi.exec(cab);
			// O Date da própria mensagem ganha da hora de entrega. Importa
			// para as mensagens que vieram da migração: elas chegaram ao
			// Dovecot no dia do corte, mas foram escritas semanas antes, e é
			// a data de escrita que o cabeçalho carrega. Para as entregues
			// normalmente as duas coincidem, porque quem põe o Date é o
			// Postfix no momento da entrega.
			const quando = /^hdr\.date:\s*(.+)$/mi.exec(cab)
			            || /^date\.received:\s*(.+)$/mi.exec(cab);

			// O Maildir guarda com LF puro; o nosso POP3 procura "\r\n\r\n"
			// para separar cabeçalho de corpo, e TOP/RETR contam linhas do
			// mesmo jeito. Sem esta normalização o stripper não acha o
			// separador, devolve a mensagem crua e o envelope do Postfix vai
			// inteiro para o cabo serial. É a mesma conversão que o
			// deliver.js faz na direção contrária.
			const texto = afterTextMarker(bruto).toString("binary")
				.replace(/\r\n/g, "\n").replace(/\n/g, "\r\n");

			return {
				message: Buffer.from(texto, "binary"),
				sender: rp ? rp[1].trim() : "",
				timestamp: quando ? new Date(quando[1].trim()) : new Date(),
			};
		},

		async markRetrieved(ctx, id) {
			await dove(["flags", "add"], ctx.name, [RETRIEVED, "mailbox", "INBOX", "uid", String(id)]);
		},

		async remove(ctx, ids) {
			if (!ids.length) return;

			// Quais já foram coletadas: é a palavra-chave que faz o papel de
			// retrieved_at.
			const out = await dove("search", ctx.name,
				["mailbox", "INBOX", "uid", ids.join(","), "keyword", RETRIEVED]);
			const coletadas = out.split("\n")
				.map(l => Number(l.trim().split(/\s+/).pop()))
				.filter(n => Number.isFinite(n) && n > 0);

			const apagar = [];
			for (const id of coletadas) {
				const m = await this.fetch(ctx, id);
				const cab = m.message.toString("binary").split("\r\n\r\n")[0] || "";
				if (isCollectedGameMail(cab)) apagar.push(id);
			}
			const paraLixeira = ids.filter(id => !apagar.includes(id));

			if (apagar.length) {
				await dove("expunge", ctx.name, ["mailbox", "INBOX", "uid", apagar.join(",")]);
			}
			if (paraLixeira.length) {
				await dove(["mailbox", "create"], ctx.name, [TRASH]).catch(() => {});
				await dove("move", ctx.name,
					[TRASH, "mailbox", "INBOX", "uid", paraLixeira.join(",")]);
			}
		},
	};
}

function createStore(config, mysql) {
	return String(config["mail_store"] || "mysql").toLowerCase() === "dovecot"
		? dovecotStore()
		: mysqlStore(mysql);
}

module.exports = { createStore, dovecotStore, mysqlStore, RETRIEVED, TRASH };
