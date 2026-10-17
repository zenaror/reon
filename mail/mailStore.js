// SPDX-License-Identifier: GPL-3.0-or-later
//
// Mailbox storage abstraction selected by mail_store. The historical mysql backend used
// sys_inbox, removed on 2026-09-12; it is no longer a reversible production option. The
// dovecot backend uses doveadm rather than a custom IMAP parser and returns exact stored
// message bytes. This module runs only when the optional Node POP3 service is enabled;
// production Dovecot owns port 110. Cartridge formatting and authentication remain outside
// this storage layer. Operations receive ctx = { id, name }: database account ID and
// mailbox name.

const { execFile } = require("child_process");

// Application IMAP keyword replacing retrieved_at; the $ prefix is conventional and
// survives folder moves.
const RETRIEVED = "$Retrieved";
const TRASH = "Trash";

// Permanently delete game mail already retrieved by the cartridge: a restorable completed
// trade could grant the same Pokemon twice. Mail deleted by Mobile Trainer without
// retrieval still uses Trash.
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

		// Always order by ID: this order defines POP3 message numbers. Without ORDER BY, webmail
		// reads could change the optimizer order and renumber messages addressed by the cartridge.
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

// Dovecot backend. The mail service must not read another account mailbox with its own
// privileges. Request the operation over the privileged doveadm-server socket instead.
const SOCKET = "/run/dovecot/doveadm-server";

// Place options after the entire doveadm subcommand, including two-word commands such as
// flags add and mailbox create. Misplacing -u causes command rejection and could produce
// both -ERR and +OK for one RETR, desynchronizing the line protocol.
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

// doveadm fetch uses field: value and separates records with form feed. text starts on the
// label line, for example text: Return-Path: <...>. Skipping that first line would lose
// part of the message.
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
	// Remove only the extra newline added by doveadm fetch. A Maildir message ending in LF is
	// returned with two trailing LFs; retaining both would corrupt binary trade payload
	// length.
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
			// Use the IMAP UID as a monotonic, non-reused identifier that survives sessions.
			items.sort((a, b) => a.id - b.id);
			return items;
		},

		// Read exact message bytes and the envelope sender. Internal-mail classification must use
		// Return-Path, not the freely supplied From header.
		async fetch(ctx, id) {
			const bruto = await dove("fetch", ctx.name,
				["text", "mailbox", "INBOX", "uid", String(id)], { binary: true });
			if (!bruto.length) throw new Error("message " + id + " vanished from the mailbox mid-session");

			const cab = await dove("fetch", ctx.name,
				["hdr.return-path hdr.date date.received", "mailbox", "INBOX", "uid", String(id)]);
			const rp = /^hdr\.return-path:\s*<?([^>\r\n]*)>?/mi.exec(cab);
			// Prefer the message Date over delivery time. Migrated messages were delivered together at
			// cutover but retain their original writing dates. During normal Postfix delivery these
			// timestamps coincide.
			const quando = /^hdr\.date:\s*(.+)$/mi.exec(cab)
			            || /^date\.received:\s*(.+)$/mi.exec(cab);

			// Normalize Maildir LF to CRLF for the POP3 header separator and TOP/RETR line handling.
			// Otherwise header shaping misses the separator and sends the entire Postfix envelope over
			// the serial link. Delivery performs the reverse conversion.
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

			// Find messages already retrieved using the application keyword replacing retrieved_at.
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
