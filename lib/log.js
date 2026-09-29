// SPDX-License-Identifier: GPL-3.0-or-later
//
// Leveled, structured log lines for the Node services (mail/ and app/*/).
//
// Until now everything went through console.log/console.error: no level, so
// `journalctl -p err` could not tell a failure from a progress line, and every
// message was free text. Each line is now one JSON object -- level, component,
// msg, plus `error` (message and stack) when an Error is passed -- and, when
// the process runs under systemd, is prefixed with the syslog priority
// (`<3>`), which is how journald learns the level: `journalctl -p warning -u
// reon-mail` now shows only what needs attention.
//
// Deliberately dependency-free, and required by relative path from mail/ and
// from app/*/ (same approach as lib/notifications.js). It takes console-style
// arguments so an existing call converts by changing only its name:
//
//   const log = require("../../lib/log").child("pokemon-exchange");
//   log.info("Finished exchange; performed", n, "trade(s)");
//   log.error("Exchange failed, rolling back:", err);   // an Error last = structured
//
// LOG_LEVEL=debug|info|warn|error (default info) filters what is written.
// Output goes to stdout, except warn and error, which go to stderr.

const util = require("util");

const PRIORITY = { debug: 7, info: 6, warn: 4, error: 3 };
const RANK = { debug: 0, info: 1, warn: 2, error: 3 };

function threshold() {
	const wanted = String(process.env.LOG_LEVEL || "info").toLowerCase();
	return RANK[wanted] !== undefined ? RANK[wanted] : RANK.info;
}

// systemd sets JOURNAL_STREAM when stdout/stderr are connected to the journal.
// Only then is the priority prefix wanted; anywhere else (a terminal, docker)
// it would just be noise, and a timestamp is added instead because nothing
// downstream will add one.
const underJournal = () => Boolean(process.env.JOURNAL_STREAM);

function write(level, component, args) {
	if (RANK[level] < threshold()) return;

	let error = null;
	const parts = args.slice();
	if (parts.length > 0 && parts[parts.length - 1] instanceof Error) {
		error = parts.pop();
	}

	const record = { level, component };
	if (!underJournal()) record.ts = new Date().toISOString();
	record.msg = util.format(...parts);
	if (error) record.error = { message: error.message, stack: error.stack };

	const line = (underJournal() ? "<" + PRIORITY[level] + ">" : "") + JSON.stringify(record) + "\n";
	(RANK[level] >= RANK.warn ? process.stderr : process.stdout).write(line);
}

function child(component) {
	return {
		debug: (...args) => write("debug", component, args),
		info: (...args) => write("info", component, args),
		warn: (...args) => write("warn", component, args),
		error: (...args) => write("error", component, args),
	};
}

module.exports = { child };
