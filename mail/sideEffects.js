const net = require("net");
const { notify, isGameMail } = require("../lib/notifications");
const log = require("../lib/log").child("mail-side-effects");

// Side effects beyond mailbox storage: a Sent copy for the sender and a notification for
// the recipient. Dovecot LMTP owns storage; this service retains those application
// responsibilities. Listen only on loopback TCP, also reachable by the vmail delivery
// filter. One connection per message: first line is envelope sender TAB recipient,
// followed by the full message; response is OK\n or ERR <reason>\n.

const PORTA = 10046;

function iniciar(pool, porta = PORTA) {
	const q = (sql, args) => new Promise((ok, erro) =>
		pool.query(sql, args, (e, r) => e ? erro(e) : ok(r)));

	// mail/index.js creates a callback-based mysql2 pool, while notify expects the promise
	// API. Pass pool.promise() here. Passing the raw pool yields a non-iterable await result
	// and can later trigger an uncaught missing-callback error outside the surrounding
	// try/catch. pokemon-exchange already uses mysql2/promise.
	const poolPromise = typeof pool.promise === "function" ? pool.promise() : pool;

	// MailUtil::submitLocally marks webmail messages, which already archive and notify with
	// the correct identity. Repeating those side effects here would create duplicates.
	const doWebmail = m => /^x-reon-origin:\s*web\b/im.test(
		String(m).split("\r\n\r\n")[0] || "");

	async function arquivarEnviada(remetente, destino, mensagem) {
		const local = String(remetente || "").split("@")[0];
		if (!local) return;
		const quem = await q(
			"select id from sys_users where username = ? or dion_email_local = ? limit 1",
			[local, local]);
		if (!quem.length) return;   // External sender: there is no local Sent mailbox to update.
		await q("insert into sys_sent (user_id, recipient, origin, message) values (?, ?, 'game', ?)",
			[quem[0]["id"], String(destino).slice(0, 254), mensagem]);
	}

	async function avisar(remetente, destino, mensagem) {
		const local = String(destino || "").split("@")[0];
		const quem = await q(
			"select id from sys_users where dion_email_local = ? or username = ? limit 1",
			[local, local]);
		if (!quem.length) return;
		// The notification bell records arrival; it does not replace the unread-mail counter.
		// Preserve both.
		await notify(poolPromise, quem[0]["id"], "mail", {
			key: "notify.new-mail",
			params: { from: String(remetente).split("@")[0] },
			link: "/user/mail.php"
		});
	}

	const servidor = net.createServer(sock => {
		let buf = "";
		sock.setEncoding("binary");
		sock.on("data", d => { buf += d; });
		sock.on("error", () => {});
		sock.on("end", async () => {
			try {
				const corte = buf.indexOf("\n");
				if (corte < 0) throw new Error("sem cabeçalho");
				const [remetente, destino] = buf.slice(0, corte).split("\t");
				const mensagem = buf.slice(corte + 1);

				// Exclude game mail from these webmail side effects: its body is a cartridge payload,
				// hidden from webmail, and its game handler supplies any relevant notification.
				if (!isGameMail(mensagem) && !doWebmail(mensagem)) {
					await arquivarEnviada(remetente, destino, mensagem);
					await avisar(remetente, destino, mensagem);
				}
				sock.end("OK\n");
			} catch (e) {
				log.info("(efeitos) " + (e.message || e));
				sock.end("ERR " + (e.message || e) + "\n");
			}
		});
	});

	servidor.listen(porta, "127.0.0.1", () =>
		log.info("Efeitos de entrega escutando em 127.0.0.1:" + porta));
	return servidor;
}

module.exports = { iniciar, PORTA };
