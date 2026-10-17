// Pure text transformations applied before mail reaches the Game Boy. Shared by
// deliveryFilter.js and the optional Node POP3 reader so both paths serve the same bytes.
// This layer has no connection, database or session state: text in, text out.

const encoding = require("encoding-japanese");

// Mobile Trainer requires a short subject. The truncation limit includes the ellipsis:
// seven characters followed by "...". Preserve this deliberate display constraint.
const SUBJECT_MAX_CHARS = 10;
const SUBJECT_ELLIPSIS = "...";

// Internal game mail already has cartridge-compatible formatting. Preserve its payload and
// headers; remove only the explicitly listed Postfix envelope fields. Header reduction
// would alter trade bytes. Use a short denylist, not an allowlist: dropping an unknown
// game header such as X-Game-result would discard completed trades.
function stripTransportHeaders(raw) {
	raw = raw.toString();
	let sep = raw.indexOf("\r\n\r\n");
	if (sep === -1) return raw;

	// Remove Date here and restore it below in _withDate using the mailbox timestamp.
	const DROP = [
		"return-path", "received", "delivered-to", "x-original-to",
		"message-id", "date",
		// Internal webmail sender marker used by outboundRelay and deliver.js; never expose it to
		// the cartridge.
		"x-reon-origin",
		// Internal instruction identifying the Sent conversation. It is not part of the recipient
		// message.
		"x-reon-thread",
	];

	let out = [];
	let dropping = false;
	for (let line of raw.slice(0, sep).split("\r\n")) {
		// A folded header continuation follows the treatment of its opening line; otherwise it
		// becomes a stray invalid header.
		if (/^[ \t]/.test(line)) {
			if (!dropping) out.push(line);
			continue;
		}
		let name = (line.split(":")[0] || "").trim().toLowerCase();
		dropping = DROP.indexOf(name) >= 0;
		if (!dropping) out.push(line);
	}

	return out.join("\r\n") + raw.slice(sep);
}

// Puts the mailbox timestamp in as the last header. Adds nothing when the
// message already states a Date -- two of them is not a date, it's an
// ambiguity -- and leaves a message with no header/body separator alone
// rather than splicing a header into the middle of its body.
function withDate(mailContent, timestamp) {
	let sep = mailContent.indexOf("\r\n\r\n");
	if (sep === -1) return mailContent;
	if (/^Date:/im.test(mailContent.slice(0, sep))) return mailContent;
	return mailContent.slice(0, sep + 2) + "Date: " + rfcDate(timestamp) +
		"\r\n" + mailContent.slice(sep + 2);
}

// Format the mailbox timestamp as an email Date, for example "Wed, 9 Sep 2026 21:35:45
// +0000". Raw JavaScript Date formatting is longer and is not the email header format.
// Apply the same format for both storage backends.
function rfcDate(timestamp) {
	const d = timestamp instanceof Date ? timestamp : new Date(timestamp);
	if (isNaN(d.getTime())) return String(timestamp);
	const dia = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"][d.getUTCDay()];
	const mes = ["Jan","Feb","Mar","Apr","May","Jun",
	             "Jul","Aug","Sep","Oct","Nov","Dec"][d.getUTCMonth()];
	const p2 = n => String(n).padStart(2, "0");
	return `${dia}, ${d.getUTCDate()} ${mes} ${d.getUTCFullYear()} ` +
		`${p2(d.getUTCHours())}:${p2(d.getUTCMinutes())}:${p2(d.getUTCSeconds())} +0000`;
}

// Whether the message came from inside REON rather than off the internet.
// Decided by the sender's domain, which is what deliver.js/smtp.js and the
// Trade Corner job all write into sys_inbox.sender.
function isInternalSender(sender, internalDomains) {
	let at = String(sender || "").lastIndexOf("@");
	if (at < 0) return false;
	let domain = String(sender).slice(at + 1).toLowerCase();
	return (internalDomains || []).indexOf(domain) >= 0;
}

// Real mail servers (Postfix included) attach Received/DKIM-Signature/
// X-Google-*/References noise and, for anything sent from a normal mail
// client, an HTML alternative part alongside the plain text one. None of
// that exists in mail written game-to-game (smtp.js/deliver.js only ever
// produced a handful of headers plus a single text/plain body), and a
// 2001-era client reading over the emulated GB Link Cable's serial link
// pays real seconds per byte for it. This only ever narrows the header
// list and, for multipart messages, replaces the body with the
// text/plain part's own (transfer-decoded) content — it never touches
// bytes belonging to a single-part body, so the 7-bit-safe ISO-2022-JP
// content native bottle mail already uses passes through untouched.
function slimMessage(raw) {
	// sys_inbox.message is a BLOB column, so mysql2 hands this back as a
	// Buffer, not a string — Buffer#slice() returns another Buffer,
	// which has no .split(), so this must convert before any of the
	// string methods below run.
	raw = raw.toString();
	let sep = raw.indexOf("\r\n\r\n");
	if (sep === -1) return raw;
	let headers = parseHeaders(raw.slice(0, sep));
	let body = raw.slice(sep + 4);
	let contentType = headers["content-type"] ? headers["content-type"].value : "";

	let multipart = /^multipart\//i.test(contentType) && /boundary="?([^";]+)"?/i.exec(contentType);
	if (multipart) {
		let part = extractTextPlainPart(body, multipart[1]);
		if (part) {
			body = part.body;
			contentType = part.contentType;
		}
	}

	// The X-Game-* headers are not noise to strip: they are the mobile
	// protocol itself. Pokémon Crystal's Trade Corner reads the finished
	// trade out of X-Game-result and silently discards any message where
	// it is missing, which is exactly what dropping it here caused.
	const KEEP = [
		"mime-version", "from", "to", "subject",
		"x-game-title", "x-game-code", "x-game-result", "x-gbmail-type",
		// Retain reply linkage for webmail: an internet reply needs this header to identify its
		// original message. Delivery-time shaping affects both web and game readers, unlike the
		// previous POP3-only reduction. The roughly 70-byte overhead fits the 15 KB message limit.
		"in-reply-to",
	];
	let lines = [];
	// Preserve the full subject for webmail only when the cartridge subject was actually
	// shortened. SUBJECT_MAX_CHARS is a Game Boy display constraint and must not truncate the
	// website subject.
	if (headers["subject"]) {
		const inteiro = normalizeHeaderValue(headers["subject"].value);
		if (truncateSubject(inteiro) !== inteiro) {
			lines.push("X-REON-Subject: " + inteiro);
		}
	}
	for (let key of KEEP) {
		if (!headers[key]) continue;
		let value = normalizeHeaderValue(headers[key].value);
		if (key === "subject") value = truncateSubject(value);
		lines.push(headers[key].name + ": " + value);
	}
	lines.push("Content-Type: " + (contentType || "text/plain; charset=us-ascii"));

	// A single-part body is forwarded byte for byte, so whatever encoded
	// it still describes it. The multipart branch above hands back the
	// part it kept already decoded, and re-announcing the old encoding
	// there would describe the body wrongly.
	if (!multipart && headers["content-transfer-encoding"]) {
		lines.push("Content-Transfer-Encoding: " + normalizeHeaderValue(headers["content-transfer-encoding"].value));
	}

	return lines.join("\r\n") + "\r\n\r\n" + body;
}

// Caps the Subject the game receives at SUBJECT_MAX_CHARS, ellipsis
// included. Counted in characters rather than bytes: a JIS subject is
// multi-byte, and cutting it by byte length would slice a character in
// half and leave the ESC-sequence state dangling, which is exactly the
// kind of malformed header a 2001-era parser has no defence against.
//
// A subject that already fits is returned byte-for-byte untouched -- the
// re-encode path only ever runs on something that had to change anyway.
function truncateSubject(value) {
	let text = decodeSubjectText(value);
	if (text === null) return value;

	// Array.from splits on codepoints, so characters outside the BMP
	// count as one rather than as two UTF-16 halves.
	let chars = Array.from(text);
	if (chars.length <= SUBJECT_MAX_CHARS) return value;

	let short = chars.slice(0, SUBJECT_MAX_CHARS - SUBJECT_ELLIPSIS.length).join("") + SUBJECT_ELLIPSIS;

	// Pure ASCII goes out plain; anything else has to go back into an
	// encoded-word, since a raw 8-bit header is not legal and the game
	// only renders ISO-2022-JP anyway.
	if (/^[\x20-\x7E]*$/.test(short)) return short;

	let jis = encoding.convert(encoding.stringToCode(short), { to: "JIS", from: "UNICODE" });
	return "=?ISO-2022-JP?B?" + Buffer.from(jis).toString("base64") + "?=";
}

// Returns the subject as plain text, or null if it can't be read with
// confidence -- in which case the caller leaves the header alone rather
// than risk emitting something worse than a long title.
function decodeSubjectText(value) {
	if (!/=\?/.test(value)) return value;

	let out = "";
	let lastEnd = 0;
	let re = /=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g;
	let m;
	while ((m = re.exec(value)) !== null) {
		out += value.slice(lastEnd, m.index);
		lastEnd = m.index + m[0].length;

		let charset = m[1].toLowerCase();
		let bytes;
		if (m[2].toLowerCase() === "b") {
			bytes = Buffer.from(m[3], "base64");
		} else {
			// Q encoding: "_" is a space, "=XX" is a literal byte.
			bytes = Buffer.from(
				m[3].replace(/_/g, " ").replace(/=([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))),
				"latin1");
		}

		if (charset === "iso-2022-jp") {
			out += encoding.codeToString(encoding.convert(Array.from(bytes), { to: "UNICODE", from: "JIS" }));
		} else if (charset === "utf-8" || charset === "us-ascii") {
			out += bytes.toString("utf8");
		} else {
			return null;
		}
	}
	return out + value.slice(lastEnd);
}

// RFC 822 header parsing: unfolds continuation lines (they start with
// whitespace), then splits each logical line on its first ":". Keyed by
// lowercase name for lookup, but keeps the original name for output.
function parseHeaders(rawHeaders) {
	let unfolded = [];
	for (let line of rawHeaders.split("\r\n")) {
		if (/^[ \t]/.test(line) && unfolded.length > 0) {
			unfolded[unfolded.length - 1] += line;
		} else {
			unfolded.push(line);
		}
	}
	let headers = {};
	for (let line of unfolded) {
		let idx = line.indexOf(":");
		if (idx === -1) continue;
		let name = line.slice(0, idx).trim();
		headers[name.toLowerCase()] = { name: name, value: line.slice(idx + 1).trim() };
	}
	return headers;
}

// Finds the first text/plain part of a multipart body and decodes its
// Content-Transfer-Encoding (quoted-printable/base64 only carry 7-bit-
// safe ASCII on the wire by spec, so reading them back with charCodeAt
// recovers the original bytes exactly — no charset is touched here,
// only the transfer envelope). Returns null on anything unexpected, so
// _slimMessage's caller falls back to leaving the original body intact
// rather than risk mangling it.
function extractTextPlainPart(body, boundary) {
	let marker = "--" + boundary;
	let parts = body.split(marker).slice(1, -1);
	for (let part of parts) {
		part = part.replace(/^\r\n/, "");
		let sep = part.indexOf("\r\n\r\n");
		if (sep === -1) continue;
		let partHeaders = parseHeaders(part.slice(0, sep));
		let partBody = part.slice(sep + 4).replace(/\r\n$/, "");
		let partType = partHeaders["content-type"] ? partHeaders["content-type"].value : "text/plain";
		if (!/^text\/plain/i.test(partType)) continue;

		let encoding = partHeaders["content-transfer-encoding"] ? partHeaders["content-transfer-encoding"].value.toLowerCase() : "7bit";
		if (encoding === "quoted-printable") {
			partBody = decodeQuotedPrintable(partBody).toString("utf8");
		} else if (encoding === "base64") {
			partBody = Buffer.from(partBody.replace(/\s+/g, ""), "base64").toString("utf8");
		}

		// ISO-2022-JP is left completely alone: it's the one charset a
		// title like Mobile Trainer actually renders (via its own ESC
		// escape sequences into JIS X0208), and stripping to 0x20-0x7E
		// below would eat the ESC (0x1B) bytes those sequences depend
		// on. Anything else (Gmail's default is UTF-8) gets folded down
		// to plain ASCII — confirmed with the MAGB TestSuite ROM
		// project that the font has no latin-accent glyphs at all, so
		// bytes >=0x80 render as a wrong/random glyph, not the intended
		// character, and there's no safe destination to transcode to.
		let charsetMatch = /charset="?([^";]+)"?/i.exec(partType);
		let charset = charsetMatch ? charsetMatch[1].toLowerCase() : "us-ascii";
		if (charset !== "iso-2022-jp") {
			partBody = toAsciiSafe(partBody);
			partType = "text/plain; charset=us-ascii";
		}

		return { body: partBody, contentType: partType };
	}
	return null;
}

function decodeQuotedPrintable(text) {
	let bytes = [];
	for (let i = 0; i < text.length; i++) {
		if (text[i] === "=" && text[i + 1] === "\r" && text[i + 2] === "\n") {
			i += 2;
		} else if (text[i] === "=" && /^[0-9A-Fa-f]{2}$/.test(text.substr(i + 1, 2))) {
			bytes.push(parseInt(text.substr(i + 1, 2), 16));
			i += 2;
		} else {
			bytes.push(text.charCodeAt(i));
		}
	}
	return Buffer.from(bytes);
}

// Folds accented Latin characters to their bare ASCII form (NFD
// decomposition + strip combining marks, e.g. "á" -> "a"+"´" -> "a") and
// replaces anything else outside printable ASCII with "?", since the
// font has no glyph for it at all — confirmed against the actual ROM,
// not just the spec. Backslash and backtick are pulled out separately:
// the game's own 4-page input keyboard (checked against real
// screenshots) covers every other printable ASCII symbol, but neither
// of these two appears on any page -- no confirmed glyph, and fonts of
// this era/origin are known to render 0x5C ("\\") as the yen sign
// instead of a backslash, so it's not safe to assume it displays as typed.
function toAsciiSafe(text) {
	return text
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "")
		.replace(/[\\`]/g, "?")
		.replace(/[^\x20-\x7E\r\n]/g, "?");
}

// Decodes RFC 2047 encoded-words ("=?charset?B|Q?text?=", as used in
// From/To/Subject for anything outside plain ASCII) and applies the same
// accent-folding as the body. ISO-2022-JP encoded-words are left
// completely alone -- that's the one encoding the game itself already
// decodes (see the native message header example this was built from),
// so re-touching it would only risk breaking what already works.
// Adjacent encoded-words separated only by whitespace are RFC 2047
// "folded" together (the whitespace between them is not part of the
// content), so that gap is dropped rather than kept literally.
function normalizeHeaderValue(value) {
	let result = "";
	let lastIndex = 0;
	let lastWasEncoded = false;
	let re = /=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g;
	let match;
	while ((match = re.exec(value)) !== null) {
		let between = value.slice(lastIndex, match.index);
		if (!(lastWasEncoded && /^[ \t]+$/.test(between))) {
			result += toAsciiSafe(between);
		}
		let charset = match[1];
		let encoding = match[2].toUpperCase();
		let text = match[3];
		if (charset.toLowerCase().startsWith("iso-2022-jp")) {
			result += match[0];
		} else {
			let decodedBytes = encoding === "B"
				? Buffer.from(text, "base64")
				: decodeQuotedPrintable(text.replace(/_/g, " "));
			result += toAsciiSafe(decodedBytes.toString("utf8"));
		}
		lastIndex = re.lastIndex;
		lastWasEncoded = true;
	}
	result += toAsciiSafe(value.slice(lastIndex));
	return result;
}

module.exports = { decodeQuotedPrintable, decodeSubjectText, extractTextPlainPart, isInternalSender, normalizeHeaderValue, parseHeaders, rfcDate, slimMessage, stripTransportHeaders, toAsciiSafe, truncateSubject, withDate };
