// SPDX-License-Identifier: GPL-3.0-or-later
//
// Sending a real e-mail to the address a player registered with -- the one
// they read on a phone, not the DION address their Game Boy reads. Used by
// the cron applications to tell someone something happened while their
// console was switched off.
//
// Handed to /usr/sbin/sendmail as an argument list, never as a shell
// string, so an address can never become part of a command. That is the
// same path web/classes/MailUtil.php uses for its outbound mail: Postfix
// routes it to default_transport = reonoutbound (mail/outboundRelay.js),
// which is where the rewriting and the policy already live. Nothing new is
// configured, and no SMTP library is involved.
//
// Never throws and never blocks the caller's real work: a cron that
// completed a trade has done its job whether or not the courtesy e-mail got
// out. Failures go to stderr, which the unit's journal keeps.

const { spawn } = require("child_process");

// Header values are single-line by definition. Anything that could end the
// header and start a new one is folded into a space first -- a newline in a
// subject is how a "Bcc:" gets injected into a message nobody meant to
// send.
function headerSafe(text) {
	return String(text == null ? "" : text).replace(/[\r\n]+/g, " ").trim();
}

// Looks up the address a player signed up with. Returns null when the
// account has none on file, which is not an error worth reporting.
async function registeredAddress(conn, userId) {
	try {
		const [rows] = await conn.execute(
			"select email from sys_users where id = ? limit 1",
			[Number(userId)]
		);
		if (rows.length === 0) return null;
		const email = String(rows[0]["email"] || "").trim();
		return email.includes("@") ? email : null;
	} catch (error) {
		process.stderr.write(`usermail: ${error.stack || error}\n`);
		return null;
	}
}

// Sends a plain-text UTF-8 message. Resolves to true when sendmail accepted
// it, false otherwise.
function sendPlain(from, to, subject, body) {
	return new Promise(resolve => {
		const headers = [
			"MIME-Version: 1.0",
			`From: REON <${headerSafe(from)}>`,
			`To: ${headerSafe(to)}`,
			`Subject: ${headerSafe(subject)}`,
			"Content-Type: text/plain; charset=utf-8",
			"Content-Transfer-Encoding: 8bit",
		];
		const message = headers.join("\r\n") + "\r\n\r\n" +
			String(body).replace(/\r\n|\r|\n/g, "\r\n");

		let proc;
		try {
			proc = spawn("/usr/sbin/sendmail", ["-i", "-f", from, "--", to]);
		} catch (error) {
			process.stderr.write(`usermail: ${error.stack || error}\n`);
			resolve(false);
			return;
		}

		proc.on("error", error => {
			process.stderr.write(`usermail: ${error.stack || error}\n`);
			resolve(false);
		});
		proc.on("close", code => resolve(code === 0));
		proc.stdin.on("error", () => {});
		proc.stdin.end(message);
	});
}

// The whole thing in one call: find the address, send, report. Returns false
// when there was nowhere to send.
async function mailUser(conn, config, userId, subject, body) {
	const to = await registeredAddress(conn, userId);
	if (!to) return false;
	const from = "noreply@" + (config["email_domain"] || "localhost");
	return sendPlain(from, to, subject, body);
}

module.exports = { mailUser, sendPlain, registeredAddress };
