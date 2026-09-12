// SPDX-License-Identifier: GPL-3.0-or-later
//
// Entregar correspondência interna FALANDO SMTP, em vez de gravar direto na
// tabela de caixa de entrada.
//
// Por que mudou: gravar direto só funciona num servidor que use a nossa
// caixa. A pilha do REONTeam é Postfix entregando ao Dovecot, e lá o insert
// dava certo sem nada chegar a jogador nenhum -- falha silenciosa, sem erro
// em log algum. Submeter localmente funciona nos dois: aqui e lá, o Postfix
// entrega ao Dovecot por LMTP.
//
// Dois modos de transporte, e a escolha é do config:
//
//   sendmail          o padrão. Entrega pelo binário local.
//   local_smtp_host   fala SMTP com um servidor de submissão LOCAL.
//
// O segundo existe para ambiente que não tem binário de sendmail. Sem ele, o
// código só roda onde existe um MTA instalado, o que exclui contêiner enxuto
// -- foi a observação da Kabi, e ela está certa.
//
// ATENÇÃO ao nome da chave: é `local_smtp_host`, NÃO `smtp_host`. Naquela
// mora o relay EXTERNO (hoje o Brevo), e mandar correspondência interna por
// ela tentaria entregar @reon.dion.ne.jp à operadora japonesa de verdade --
// que é exatamente o defeito que esta biblioteca existe para não repetir. As
// duas chaves têm nomes parecidos e significados opostos; não as troque.
//
// A mensagem vai byte a byte como foi montada: quem a escreveu já a escreveu
// no formato que o cartucho interpreta. O envelope que o Postfix acrescenta é
// retirado na entrega, em pop3Connection.js, para o cabo serial não pagar por
// ele.

const { spawn } = require("child_process");

// Preenchido uma vez, no arranque do aplicativo, por configure(). Sem isso o
// transporte é o sendmail -- que é o que vale em produção.
let transporteSmtp = null;

// Chamado pelo aplicativo depois de ler o config.
//
// O módulo do nodemailer vem de FORA, passado por quem chama, e isso não é
// capricho: esta pasta não tem `node_modules` nem package.json, e o Node
// resolve dependência a partir do diretório de quem faz o require -- de
// `lib/` não se enxerga o `node_modules` de `app/`. Tentar `require` aqui
// dentro falha com MODULE_NOT_FOUND mesmo com a dependência instalada no
// aplicativo. Quem tem o módulo declarado é quem o entrega.
//
// Sem módulo e com `local_smtp_host` pedido, cai no sendmail e diz por quê --
// em vez de morrer no arranque de um cron que tinha trabalho a fazer.
function configure(config, nodemailer) {
	const host = (config || {})["local_smtp_host"];
	if (!host) { transporteSmtp = null; return; }
	if (!nodemailer) {
		process.stderr.write("rawmail: local_smtp_host pedido mas o nodemailer nao foi passado a configure(); usando sendmail\n");
		transporteSmtp = null;
		return;
	}
	transporteSmtp = nodemailer.createTransport({
		host: host,
		port: (config || {})["local_smtp_port"] || 25,
		secure: false,
		// Submissão local, na própria máquina ou na rede interna do
		// contêiner: não há certificado a validar nem autenticação a fazer.
		tls: { rejectUnauthorized: false },
		ignoreTLS: true
	});
}

// Pelo SMTP local, com a mensagem byte a byte -- `raw` entrega o que se deu,
// sem remontar cabeçalho nem reinterpretar corpo. É o que a carga de troca
// exige, e é o mesmo que o `sendmail -i` faz do outro lado.
function porSmtp(envelope, target, message) {
	return transporteSmtp.sendMail({
		envelope: { from: envelope, to: target },
		raw: Buffer.isBuffer(message) ? message : Buffer.from(String(message), "binary")
	}).then(() => true).catch(error => {
		process.stderr.write(`rawmail: SMTP local falhou: ${error.stack || error}\n`);
		return false;
	});
}

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

		if (transporteSmtp) {
			porSmtp(envelope, target, message).then(resolve);
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

module.exports = { sendRaw, configure };
