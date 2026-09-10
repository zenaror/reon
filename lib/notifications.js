// SPDX-License-Identifier: GPL-3.0-or-later
//
// Writing a notification from the Node side: the delivery agent and the
// cron applications, which are the two places where something happens to a
// player while nobody is looking at a web page.
//
// Deliberately dependency-free. It takes a connection the caller already
// has (mysql2's promise API, or anything with the same `execute`) rather
// than opening one of its own, so it can be required by relative path from
// mail/ and from app/*/ without either of those growing a package for it.
//
// The rule the web side states in NotificationUtil.php holds here too:
// there is no delete. A notification is the record that a thing happened.
//
// Text is stored as a translation key plus parameters, not as a finished
// sentence: the site speaks seven languages and a cron job speaks none of
// them, so the words are chosen when they are read. `body` is the exception
// -- it carries a detail line that is data rather than prose ("MAGIKARP ->
// GROWLITHE"), which reads the same in any language.

const CATEGORIES = ["mail", "trade", "game", "system", "admin"];

// A message is a game's own traffic when its headers say so: an exclusive
// type and a game code together. Same pair pop3Connection.js and the webmail
// key off, kept in step by being written out the same way in each.
function isGameMail(message) {
	const text = String(message || "");
	const head = text.split("\r\n\r\n")[0];
	return /^x-game-code:/im.test(head) && /^x-gbmail-type:\s*exclusive/im.test(head);
}

// Adds one notification. Returns the new id, or null when nothing was
// written. Never throws: every caller here is a delivery or a cron whose
// real work has already succeeded, and failing to tell someone about it must
// not undo it.
async function notify(conn, userId, category, opts = {}) {
	try {
		const id = Number(userId);
		if (!Number.isFinite(id) || id <= 0) return null;

		const cat = CATEGORIES.includes(category) ? category : "system";
		const key = opts.key ? String(opts.key).slice(0, 64) : null;
		const params = opts.params && Object.keys(opts.params).length
			? JSON.stringify(opts.params)
			: null;
		const title = opts.title ? String(opts.title).slice(0, 160) : null;
		const body = opts.body != null ? String(opts.body) : null;
		const link = opts.link ? String(opts.link).slice(0, 255) : null;
		const game = opts.game ? String(opts.game).slice(0, 40) : null;

		if (key === null && !title) return null;

		const [result] = await conn.execute(
			`insert into sys_notifications
			 (user_id, category, game, message_key, params, title, body, link)
			 values (?, ?, ?, ?, ?, ?, ?, ?)`,
			[id, cat, game, key, params, title, body, link]
		);
		return result && result.insertId ? result.insertId : null;
	} catch (error) {
		process.stderr.write(`notifications: ${error.stack || error}\n`);
		return null;
	}
}

module.exports = { notify, isGameMail, CATEGORIES };
