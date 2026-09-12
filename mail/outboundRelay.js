// SPDX-License-Identifier: GPL-3.0-or-later
//
// Postfix pipe-transport delivery agent for the one case reoninbox doesn't
// cover: a message reon-relay-policy already authorized to leave to a real
// internet address (see relayPolicy.js, smtpd_relay_restrictions). Same
// invocation shape the old local delivery agent had (${sender}/${recipient}
// + raw message on stdin), but instead of delivering into a mailbox, this
// submits the message onward to the relay -- replacing Postfix's own
// relayhost/smtp(8) path
// for this one case, specifically so the BODY can be fixed too, not just
// headers (smtp_generic_maps/smtp_header_checks can only touch headers).
//
// Why the body needs fixing: the Mobile Adapter GB keyboard only ever
// produces ISO-2022-JP (JIS X 0208, escaped via ESC $ B ... ESC ( B), which
// is what every game genuinely sends (confirmed against docs/dandocs-magb.md
// and real captured mail). Brevo's own re-templating (HTML wrapper, tracking
// pixel, forced UTF-8) doesn't decode that escape sequence first -- it comes
// out the other end as the literal escape bytes ("$B#T#e#s#t#e!!(B" instead
// of "Teste!!"). Decoding to real UTF-8 text *before* Brevo ever sees it
// sidesteps that entirely, regardless of what Brevo's own pipeline does
// afterward.
//
// Exit codes follow sysexits.h, same convention as deliver.js:
//   0  = delivered (Brevo accepted it)
//   67 = EX_NOUSER   (permanent failure -- malformed message, won't retry)
//   75 = EX_TEMPFAIL (transient failure -- Postfix will retry later)

const fs = require("fs");
const encoding = require("encoding-japanese");
const nodemailer = require("nodemailer");
const mysql = require("mysql2/promise");
const { Command } = require("commander");

const GAMEBOY_DOMAIN = "gameboy.datacenter.ne.jp";

const program = new Command();
program
	.requiredOption("-c, --config <path>", "Config file path.")
	.requiredOption("-f, --from <address>", "Envelope sender (Postfix ${sender}).")
	.argument("<recipient>", "Envelope recipient (Postfix ${recipient}).")
	.parse(process.argv);

const opts = program.opts();
const [recipientArg] = program.args;

async function readStdin() {
	const chunks = [];
	for await (const chunk of process.stdin) chunks.push(chunk);
	return Buffer.concat(chunks);
}

// Postfix's pipe(8) hands off with bare LF line endings (CRLF normalized
// away internally) and headers are always 7-bit/ASCII regardless of the
// body's charset, so it's safe to scan for the blank-line boundary as latin1
// text and only touch the body portion as a charset-aware Buffer.
function splitMessage(raw) {
	const sep = raw.indexOf("\n\n");
	if (sep === -1) return { headerText: raw.toString("latin1"), bodyBuf: Buffer.alloc(0) };
	return {
		headerText: raw.subarray(0, sep).toString("latin1"),
		bodyBuf: raw.subarray(sep + 2)
	};
}

function parseHeaders(headerText) {
	const lines = headerText.split("\n").map(line => line.replace(/\r$/, ""));
	const headers = [];
	for (const line of lines) {
		if (/^[ \t]/.test(line) && headers.length > 0) {
			headers[headers.length - 1][1] += " " + line.trim();
		} else {
			const idx = line.indexOf(":");
			if (idx === -1) continue;
			headers.push([line.slice(0, idx), line.slice(idx + 1).trim()]);
		}
	}
	return headers;
}

function getHeader(headers, name) {
	const found = headers.find(([n]) => n.toLowerCase() === name.toLowerCase());
	return found ? found[1] : null;
}

function setHeader(headers, name, value) {
	const idx = headers.findIndex(([n]) => n.toLowerCase() === name.toLowerCase());
	if (idx === -1) headers.push([name, value]);
	else headers[idx][1] = value;
}

function deleteHeader(headers, name) {
	return headers.filter(([n]) => n.toLowerCase() !== name.toLowerCase());
}

// Same rewrite setup-postfix-bridge.sh's smtp_generic_maps used to do --
// reon.dion.ne.jp/gameboy.datacenter.ne.jp are real domains this project has
// no DNS control over (no SPF/DKIM possible), so outbound mail must leave
// as the domain actually owned and Brevo-authenticated instead.
function rewriteDomain(address, dionDomain, mailDomain) {
	const re = new RegExp(`@(${escapeRegExp(dionDomain)}|${escapeRegExp(GAMEBOY_DOMAIN)})$`, "i");
	return address.replace(re, `@${mailDomain}`);
}

function escapeRegExp(s) {
	return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

// Same fix setup-postfix-bridge.sh's smtp_header_checks used to do --
// "From: addr (=?charset?B?...?=)" is the real, documented Mobile Adapter GB
// format (the encoded display name inside a parenthesized comment), which
// isn't valid RFC 2047 (encoded-words are only defined inside a phrase, not
// a comment) -- real providers silently drop it. Rewrite to the RFC-valid
// "From: =?charset?B?...?= <addr>" form, only for what's actually leaving.
function fixFromDisplayName(fromValue) {
	const m = fromValue.match(/^(\S+@\S+)\s+\((=\?[^)]+\?=)\)\s*$/);
	if (!m) return fromValue;
	return `${m[2]} <${m[1]}>`;
}

// Decodes the ISO-2022-JP body to real UTF-8 text and updates Content-Type/
// Content-Transfer-Encoding to match, so nothing downstream (Brevo's own
// re-templating included) needs to understand ISO-2022-JP itself anymore.
// Note: iconv-lite does NOT implement ISO-2022-JP (verified directly against
// real captured game mail before picking a library) -- encoding-japanese
// does, and its "JIS" mode is specifically the ESC-sequence-switched
// ISO-2022-JP variant the Mobile Adapter GB keyboard produces (confirmed
// byte-for-byte against real test mail covering the full keyboard: hiragana,
// katakana, fullwidth alnum, fullwidth symbols).
function decodeBody(headers, bodyBuf) {
	const contentType = getHeader(headers, "Content-Type") || "";
	const charsetMatch = contentType.match(/charset\s*=\s*"?([\w-]+)"?/i);
	const charset = charsetMatch ? charsetMatch[1].toLowerCase() : null;
	if (charset !== "iso-2022-jp") {
		return bodyBuf;
	}
	const unicodeArray = encoding.convert(Array.from(bodyBuf), { to: "UNICODE", from: "JIS" });
	const decoded = encoding.codeToString(unicodeArray);
	setHeader(headers, "Content-Type", contentType.replace(/charset\s*=\s*"?[\w-]+"?/i, "charset=utf-8"));
	setHeader(headers, "Content-Transfer-Encoding", "base64");
	return Buffer.from(decoded, "utf8");
}

function encodeBodyForTransfer(headers, bodyBuf) {
	const cte = (getHeader(headers, "Content-Transfer-Encoding") || "").toLowerCase();
	if (cte === "base64") {
		// Wrap at 76 chars per RFC 2045.
		const b64 = bodyBuf.toString("base64");
		return b64.replace(/.{1,76}/g, "$&\r\n");
	}
	return bodyBuf.toString("latin1");
}

function buildTransport(config) {
	const smtpHost = config["smtp_host"];
	const smtpAuth = config["smtp_auth"];
	if (!smtpHost) return null;
	return nodemailer.createTransport({
		host: smtpHost,
		port: config["smtp_port"] || 587,
		secure: config["smtp_secure"] === "smtps",
		requireTLS: config["smtp_secure"] === "starttls",
		auth: smtpAuth ? { user: config["smtp_user"], pass: config["smtp_pass"] } : undefined
	});
}

async function main() {
	const config = JSON.parse(fs.readFileSync(opts.config, "utf8"));
	const dionDomain = config["email_domain_dion"];
	const mailDomain = config["email_domain"];

	const transport = buildTransport(config);
	if (!transport) {
		process.stderr.write("outboundRelay.js: no smtp_host configured, cannot relay outbound\n");
		process.exitCode = 75;
		return;
	}

	const raw = await readStdin();
	const { headerText, bodyBuf } = splitMessage(raw);
	const headers = parseHeaders(headerText);

	const decodedBody = decodeBody(headers, bodyBuf);

	const fromHeader = getHeader(headers, "From");
	if (fromHeader) {
		const rewritten = fixFromDisplayName(rewriteDomain(fromHeader.replace(/^(\S+@\S+)/, m => rewriteDomain(m, dionDomain, mailDomain)), dionDomain, mailDomain));
		setHeader(headers, "From", rewritten);
	}
	headers.forEach(([name], idx) => {
		if (name.toLowerCase() === "return-path") headers.splice(idx, 1);
	});

	// Set by the webmail so the Sent copy can say which client it came from.
	// Read here and removed, so it never travels to the recipient.
	const origin = (getHeader(headers, "X-REON-Origin") || "game").toLowerCase() === "web" ? "web" : "game";
	for (let i = headers.length - 1; i >= 0; i--) {
		if (headers[i][0].toLowerCase() === "x-reon-origin") headers.splice(i, 1);
	}

	// Idem para a conversa. O webmail sabe se o que está saindo responde a
	// algo ou abre assunto novo; aqui, do outro lado do Postfix, não há como
	// saber -- então a resposta vem escrita na própria mensagem. Sai daqui
	// pelo mesmo motivo que o de origem: é cabeçalho de serviço nosso, não
	// tem por que chegar a quem recebe.
	const rawThread = getHeader(headers, "X-REON-Thread") || "";
	const threadKey = /^[0-9a-f]{32}$/.test(rawThread.trim()) ? rawThread.trim() : null;
	for (let i = headers.length - 1; i >= 0; i--) {
		if (headers[i][0].toLowerCase() === "x-reon-thread") headers.splice(i, 1);
	}

	// Cabeçalhos de controle do relay, vindos do config.
	//
	// Cada provedor tem o seu dialeto para a mesma instrução, e nenhum deles
	// pertence a este código: quem escolhe o relay é quem opera o servidor.
	// Por isso a lista vem de fora, e não de um `if` por fornecedor aqui
	// dentro -- trocar de relay passa a ser trocar config, não editar código.
	//
	// O caso que motivou isto: um relay que rastreia abertura precisa de uma
	// imagem, imagem precisa de HTML, e então ele converte o nosso text/plain
	// em HTML só para caber o pixel. Uma carta escrita num Game Boy chega
	// embrulhada em `<html><body>`, com pixel e link de descadastro. Desligar
	// o rastreamento é o que remove o motivo da conversão.
	//
	// Valores conhecidos, para quem for configurar:
	//   Mailjet   "X-Mailjet-TrackOpen": "0", "X-Mailjet-TrackClick": "0"
	//   Brevo     não tem -- rastreamento em transacional só sai em plano
	//             Enterprise, mediante pedido
	//   SMTP2GO   não precisa -- é por credencial no painel, e mensagem de
	//             texto puro não é reescrita de qualquer forma
	//
	// Trocou de relay? Troque estes cabeçalhos junto. Um X-Mailjet-* enviado a
	// outro provedor não desliga nada e ainda pode chegar visível a quem lê.
	for (const [nome, valor] of Object.entries(config["smtp_headers"] || {})) {
		// Nada com quebra de linha: um valor mal digitado no config viraria
		// cabeçalho injetado no meio da mensagem.
		if (/[\r\n]/.test(nome) || /[\r\n]/.test(String(valor))) {
			process.stderr.write(`outboundRelay.js: smtp_headers["${nome}"] ignorado (contem quebra de linha)\n`);
			continue;
		}
		setHeader(headers, nome, String(valor));
	}

	const envelopeFrom = rewriteDomain(opts.from, dionDomain, mailDomain);
	const bodyForTransfer = encodeBodyForTransfer(headers, decodedBody);

	const headerBlock = headers.map(([name, value]) => `${name}: ${value}`).join("\r\n");
	const rawMessage = `${headerBlock}\r\n\r\n${bodyForTransfer}`;

	try {
		await transport.sendMail({
			envelope: { from: envelopeFrom, to: recipientArg },
			raw: rawMessage
		});
	} catch (error) {
		process.stderr.write(`outboundRelay.js: send failed: ${error.stack || error}\n`);
		process.exitCode = 75;
		return;
	}

	// Filed only after the relay accepted it. A copy of something that never
	// left would be worse than no copy: it would read as proof of a send that
	// did not happen.
	//
	// Everything leaving the server passes through here -- the game's mail and
	// the webmail's external sends alike -- so this is the single place either
	// gets recorded, and neither is filed twice.
	await recordSent(config, opts.from, recipientArg, rawMessage, origin, threadKey);
}

// Files a copy under the sending account. The envelope sender is always one of
// ours on this path, but the lookup still guards: a name we cannot resolve
// gets no row rather than a wrong one.
async function recordSent(config, fromAddress, toAddress, rawMessage, origin, threadKey) {
	const local = String(fromAddress || "").split("@")[0];
	if (!local) return;

	const conn = await mysql.createConnection({
		host: config["mysql_host"],
		user: config["mysql_user"],
		password: config["mysql_password"],
		database: config["mysql_database"]
	});
	try {
		const [who] = await conn.execute(
			"select id from sys_users where username = ? or dion_email_local = ? limit 1",
			[local, local]
		);
		if (who.length === 0) return;
		await conn.execute(
			"insert into sys_sent (user_id, recipient, origin, thread_key, message) values (?, ?, ?, ?, ?)",
			[who[0]["id"], String(toAddress).slice(0, 254), origin, threadKey || null, rawMessage]
		);
	} catch (error) {
		// The message did go out; failing to file a copy must not report the
		// send as failed, or Postfix would retry and deliver it twice.
		process.stderr.write(`outboundRelay.js: could not record sent copy: ${error.message}\n`);
	} finally {
		await conn.end();
	}
}

main().catch(error => {
	process.stderr.write(`outboundRelay.js: ${error.stack || error}\n`);
	process.exitCode = 75;
});
