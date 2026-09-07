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

// Must match MailUtil::INBOX_MAX -- the Mobile Trainer mailbox size.
const INBOX_MAX = 12;
const { Command } = require("commander");

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
		// The Mobile Trainer's mailbox holds INBOX_MAX; delivering past it
		// would put mail somewhere the game can never show it.
		//
		// Exit 75 (EX_TEMPFAIL) rather than a bounce: the box empties as soon
		// as the player syncs, so Postfix holding the message and retrying
		// delivers it, where refusing outright would lose it for good.
		const [full] = await conn.execute(
			"select count(*) as c from sys_inbox where recipient = ? and deleted_at is null",
			[rows[0]["id"]]
		);
		if (full[0]["c"] >= INBOX_MAX) {
			process.stderr.write(`deliver.js: mailbox full for ${recipientArg}, deferring\n`);
			process.exitCode = 75;
			return;
		}

		await conn.execute(
			"insert into sys_inbox (sender, recipient, message) values (?, ?, ?)",
			[opts.from, rows[0]["id"], message]
		);
	} finally {
		await conn.end();
	}
}

main().catch(error => {
	process.stderr.write(`deliver.js: ${error.stack || error}\n`);
	process.exitCode = 75;
});
