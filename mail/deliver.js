// SPDX-License-Identifier: GPL-3.0-or-later
//
// Postfix pipe-transport delivery agent (see setup-postfix-bridge.sh /
// master.cf's "reoninbox" transport). Invoked once per recipient for mail
// Postfix already accepted for a virtual_mailbox_domains match, with the
// raw message (headers unstuffed, terminating "." line stripped by Postfix
// itself) on stdin. Inserts into sys_inbox exactly like the DATA handler in
// smtpConnection.js used to, so reon-mail's POP3 server (unchanged) keeps
// serving the same mailbox regardless of which SMTP frontend wrote the row.
//
// Exit codes follow sysexits.h, which Postfix's pipe(8) understands:
//   0  = delivered
//   67 = EX_NOUSER   (permanent failure, bounce — shouldn't happen since
//                      Postfix already checked virtual_mailbox_maps before
//                      accepting the message, but handled defensively)
//   75 = EX_TEMPFAIL (transient failure, Postfix will retry later)

const fs = require("fs");
const mysql = require("mysql2/promise");
const { Command } = require("commander");
const { notify, isGameMail } = require("../lib/notifications");

const program = new Command();
program
	.requiredOption("-c, --config <path>", "Config file path.")
	.requiredOption("-f, --from <address>", "Envelope sender (Postfix ${sender}).")
	.argument("<recipient>", "Envelope recipient (Postfix ${recipient}).")
	.parse(process.argv);

const opts = program.opts();
const [recipientArg] = program.args;
const localPart = recipientArg.split("@")[0];

async function readStdin() {
	const chunks = [];
	for await (const chunk of process.stdin) chunks.push(chunk);
	return Buffer.concat(chunks).toString();
}


// Files a copy under the sending account, if the envelope sender belongs to
// one. A sender we do not know is mail from the outside world: there is no
// Sent folder to put it in, and it is not an error.
async function recordSent(conn, fromAddress, toAddress, message) {
	const local = String(fromAddress || "").split("@")[0];
	if (!local) return;
	const [who] = await conn.execute(
		"select id from sys_users where username = ? or dion_email_local = ? limit 1",
		[local, local]
	);
	if (who.length === 0) return;
	await conn.execute(
		"insert into sys_sent (user_id, recipient, origin, message) values (?, ?, 'game', ?)",
		[who[0]["id"], String(toAddress).slice(0, 254), message]
	);
}

async function main() {
	const config = JSON.parse(fs.readFileSync(opts.config));
	// Postfix's pipe(8) hands off messages with bare LF line endings
	// (it normalizes CRLF away internally), but pop3Connection.js's
	// header/body parsing (_getMail, TOP, RETR) hardcodes "\r\n\r\n" to find
	// the boundary — normalize back to CRLF so retrieval matches what
	// smtpConnection.js used to store directly.
	const message = (await readStdin()).replace(/\r\n/g, "\n").replace(/\n/g, "\r\n");

	const conn = await mysql.createConnection({
		host: config["mysql_host"],
		user: config["mysql_user"],
		password: config["mysql_password"],
		database: config["mysql_database"]
	});

	try {
		// Accepts either form of the address: the full username the person
		// picked, or the 8-character one the games are limited to. Both are
		// unique columns, so a single lookup can match on either without
		// risking the wrong account.
		const [rows] = await conn.execute(
			"select id from sys_users where username = ? or dion_email_local = ? limit 1",
			[localPart, localPart]
		);
		if (rows.length === 0) {
			process.stderr.write(`deliver.js: unknown recipient ${recipientArg}\n`);
			process.exitCode = 67;
			return;
		}
		await conn.execute(
			"insert into sys_inbox (sender, recipient, message) values (?, ?, ?)",
			[opts.from, rows[0]["id"], message]
		);

		// A copy in the sender's Sent folder, when the sender is one of ours.
		// This path carries game-to-game mail; the webmail records its own
		// internal sends, and outboundRelay.js records what leaves the server,
		// so no message is filed twice.
		await recordSent(conn, opts.from, recipientArg, message);

		// And a line in the bell, so a letter that lands while nobody is
		// looking still leaves a trace with a time on it. This does not
		// replace the mail badge -- that says "there is something to read",
		// this says "this arrived at this hour", and the owner asked for
		// both.
		//
		// Not for a game's own mail. That is delivered exactly as before and
		// POP3 serves it exactly as before, but the player never sees it on
		// the web and has nothing to do about it; the application that acts
		// on it raises its own notification when there is something to say.
		if (!isGameMail(message)) {
			await notify(conn, rows[0]["id"], "mail", {
				key: "notify.new-mail",
				params: { from: String(opts.from).split("@")[0] },
				link: "/user/mail.php"
			});
		}
	} finally {
		await conn.end();
	}
}

main().catch(error => {
	process.stderr.write(`deliver.js: ${error.stack || error}\n`);
	process.exitCode = 75;
});
