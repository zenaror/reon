// SPDX-License-Identifier: GPL-3.0-or-later
//
// Deliver internal mail through local SMTP or sendmail, preserving the assembled message
// bytes. Direct inbox inserts do not deliver mail on upstream Postfix/Dovecot
// installations. sendmail is the default; local_smtp_host supports environments without
// its binary. IMPORTANT: local_smtp_host is local submission, whereas smtp_host is the
// EXTERNAL relay. Using the latter for internal mail could route @reon.dion.ne.jp to the
// real Japanese operator. Postfix envelope headers are removed before delivery to the
// cartridge to avoid unnecessary serial traffic.

const { spawn } = require("child_process");

// Set once at application startup by configure(). Without configuration, use sendmail, the
// production default.
let transporteSmtp = null;

// Called after the application reads its configuration. The caller supplies nodemailer
// because lib/ has neither package.json nor node_modules and cannot resolve app-local
// dependencies. If local_smtp_host is requested without a mailer module, log the reason
// and fall back to sendmail instead of aborting a scheduled job.
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
		// Local submission on this host or the internal container network; no certificate
		// validation or authentication is performed here.
		tls: { rejectUnauthorized: false },
		ignoreTLS: true
	});
}

// Submit through local SMTP using raw bytes without reconstructing headers or interpreting
// the body. Trade payloads require the same byte preservation as sendmail -i.
function porSmtp(envelope, target, message) {
	return transporteSmtp.sendMail({
		envelope: { from: envelope, to: target },
		raw: Buffer.isBuffer(message) ? message : Buffer.from(String(message), "binary")
	}).then(() => true).catch(error => {
		process.stderr.write(`rawmail: SMTP local falhou: ${error.stack || error}\n`);
		return false;
	});
}

// Deliver an already assembled message. Resolve true when sendmail accepts it. Never
// throw: notification failure must not abort a completed trade.
function sendRaw(from, to, message) {
	return new Promise(resolve => {
		const envelope = String(from || "").trim();
		const target = String(to || "").trim();
		// Reject malformed addresses and leading hyphens that could become command-line options.
		// The -- separator below provides an additional boundary.
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
			// -i: a body line containing only a dot does not terminate input.
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
