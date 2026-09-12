// SPDX-License-Identifier: GPL-3.0-or-later
//
// Entregar correspondência interna FALANDO SMTP, em vez de gravar direto na
// tabela de caixa de entrada.
//
// Por que mudou: gravar direto só funciona num servidor que use a nossa
// caixa. A pilha do REONTeam é Postfix entregando ao Dovecot, e lá o insert
// dava certo sem nada chegar a jogador nenhum -- falha silenciosa, sem erro
// em log algum. Submeter localmente funciona nos dois: aqui o Postfix roteia
// reon.dion.ne.jp por virtual_transport = reoninbox (deliver.js, que grava na
// tabela), e lá pelo Dovecot.
//
// NÃO usa `smtp_host` do config: nessa chave mora o relay externo (Brevo), e
// mandar correspondência interna por ela tentaria entregar @reon.dion.ne.jp
// à operadora japonesa de verdade. A submissão é local, pelo binário, como
// lib/usermail.js e web/classes/MailUtil.php já fazem.
//
// A mensagem vai byte a byte como foi montada: quem a escreveu já a escreveu
// no formato que o cartucho interpreta. O envelope que o Postfix acrescenta é
// retirado na entrega, em pop3Connection.js, para o cabo serial não pagar por
// ele.

const { spawn } = require("child_process");

// Entrega uma mensagem já pronta. Resolve com true quando o sendmail a
// aceitou. Nunca lança: um cron que concluiu uma troca fez o trabalho dele
// tenha ou não saído o aviso, e derrubá-lo aqui perderia a troca.
function sendRaw(from, to, message) {
	return new Promise(resolve => {
		const envelope = String(from || "").trim();
		const target = String(to || "").trim();
		// Sem isso um endereço poderia começar com "-" e virar opção de
		// linha de comando. O "--" abaixo já separa, e a checagem recusa o
		// que nem parece endereço antes de chegar lá.
		if (!envelope.includes("@") || !target.includes("@")) {
			process.stderr.write(`rawmail: endereço inválido ${envelope} -> ${target}\n`);
			resolve(false);
			return;
		}

		let proc;
		try {
			// -i: uma linha com um ponto só no corpo não encerra a entrada.
			proc = spawn("/usr/sbin/sendmail", ["-i", "-f", envelope, "--", target]);
		} catch (error) {
			process.stderr.write(`rawmail: ${error.stack || error}\n`);
			resolve(false);
			return;
		}

		proc.on("error", error => {
			process.stderr.write(`rawmail: ${error.stack || error}\n`);
			resolve(false);
		});
		proc.on("close", code => {
			if (code !== 0) process.stderr.write(`rawmail: sendmail saiu com ${code}\n`);
			resolve(code === 0);
		});

		proc.stdin.on("error", () => {});
		proc.stdin.end(Buffer.isBuffer(message) ? message : Buffer.from(String(message), "binary"));
	});
}

module.exports = { sendRaw };
