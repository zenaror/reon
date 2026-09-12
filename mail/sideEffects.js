const net = require("net");
const { notify, isGameMail } = require("../lib/notifications");

// O que acontece ALÉM de guardar a mensagem: a cópia em Enviados de quem
// mandou, e a linha no sino de quem recebeu.
//
// Isto morava no deliver.js, o nosso agente de entrega. Quando o Postfix
// passou a entregar pelo LMTP do Dovecot, o deliver.js saiu do caminho -- e
// levou as duas coisas junto, calado. Correspondência continuava chegando e
// sendo servida certinho pelo POP3; só que ninguém era avisado e nada
// aparecia em Enviados.
//
// Não voltou como agente de entrega porque essa vaga é do Dovecot agora.
// Voltou como serviço: quem guarda a mensagem é o Dovecot, e quem cuida dos
// efeitos colaterais é isto aqui. Um servidor de e-mail de verdade não some
// com nenhum dos dois.
//
// Fala por TCP em 127.0.0.1, e não por socket de arquivo, pelo mesmo motivo
// que o relayPolicy: quem chama é o filtro de entrega, que roda como vmail, e
// acertar dono e grupo de um socket entre dois usuários de serviço custa mais
// do que uma porta fechada na máquina.
//
// Protocolo, uma conexão por mensagem:
//   linha 1: <remetente do envelope> TAB <destinatário>
//   resto:   a mensagem inteira
//   resposta: "OK\n" ou "ERR <motivo>\n"

const PORTA = 10046;

function iniciar(pool, porta = PORTA) {
	const q = (sql, args) => new Promise((ok, erro) =>
		pool.query(sql, args, (e, r) => e ? erro(e) : ok(r)));

	// Marcado por MailUtil::submitLocally() em tudo que sai do webmail, que já
	// arquiva e notifica sozinho, com a origem certa. Repetir aqui daria duas
	// cópias e duas linhas no sino.
	const doWebmail = m => /^x-reon-origin:\s*web\b/im.test(
		String(m).split("\r\n\r\n")[0] || "");

	async function arquivarEnviada(remetente, destino, mensagem) {
		const local = String(remetente || "").split("@")[0];
		if (!local) return;
		const quem = await q(
			"select id from sys_users where username = ? or dion_email_local = ? limit 1",
			[local, local]);
		if (!quem.length) return;   // veio de fora: não há Enviados onde pôr
		await q("insert into sys_sent (user_id, recipient, origin, message) values (?, ?, 'game', ?)",
			[quem[0]["id"], String(destino).slice(0, 254), mensagem]);
	}

	async function avisar(remetente, destino, mensagem) {
		const local = String(destino || "").split("@")[0];
		const quem = await q(
			"select id from sys_users where dion_email_local = ? or username = ? limit 1",
			[local, local]);
		if (!quem.length) return;
		// O sino não substitui o contador de não lidas: aquele diz "há algo
		// para ler", este diz "chegou a tal hora". O dono pediu os dois.
		await notify(pool, quem[0]["id"], "mail", {
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

				// Correspondência de jogo não entra em nenhum dos dois: o
				// corpo é carga binária de cartucho, ela não aparece no
				// webmail por decisão anterior, e quem age sobre ela levanta
				// a própria notificação quando tem o que dizer.
				if (!isGameMail(mensagem) && !doWebmail(mensagem)) {
					await arquivarEnviada(remetente, destino, mensagem);
					await avisar(remetente, destino, mensagem);
				}
				sock.end("OK\n");
			} catch (e) {
				console.log("(efeitos) " + (e.message || e));
				sock.end("ERR " + (e.message || e) + "\n");
			}
		});
	});

	servidor.listen(porta, "127.0.0.1", () =>
		console.log("Efeitos de entrega escutando em 127.0.0.1:" + porta));
	return servidor;
}

module.exports = { iniciar, PORTA };
