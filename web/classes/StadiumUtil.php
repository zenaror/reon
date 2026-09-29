<?php
	require_once("DBUtil.php");
	require_once(__DIR__."/LogUtil.php");

	// Mobile Stadium distributions, assembled from the database -- same
	// design as the Pokémon News, and born from the same request from the
	// owner (2026-09-27).
	//
	// What this file deliberately does NOT do: generate the 0xFFE-byte
	// payload. That is the job of whoever knows how to assemble a Crystal
	// battle block (the PKHeX plugin), and it arrives here already built, via
	// a direct INSERT or an import script reading the <slug>.bin/<slug>.json
	// pair described in the conversation with that session. This file serves
	// what already exists and validates its shape -- it does not invent
	// content.
	//
	// Specification in docs/mobile-stadium/spec.md (read out of the
	// disassembly by the "PKHeX Linux Port" session, not written here). Every
	// offset cited in the comments comes from there.
	class StadiumUtil {

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) self::$instance = new StadiumUtil();
			return self::$instance;
		}

		const PAYLOAD_SIZE = 4094; // 0xFFE

		// Replay-record and rule-record geometry (spec.md §5.2). A replay
		// record's own trailer is self-contained -- marker at stride-4, LE
		// sum16 at stride-2, covering [0, stride-2) of THAT record alone --
		// so a validated record can be dropped into any of the 3 slots
		// unchanged. Verified by round-trip: splitting a real, live payload
		// into 3 records + 5 rules + message/flags/File ID and feeding them
		// back through composePayload() reproduces the original payload
		// byte for byte (see maint/ scratch test run on 2026-09-28, not
		// kept -- the assertion is what matters, not the script).
		const REPLAY_STRIDE = ["j" => 0x480, "w" => 0x490];
		const REPLAY_SLOTS = 3;
		const RULE_RECORD_SIZE = 0x48;
		const RULE_SLOTS = 5;

		// A record's own format: JP is 'j', every western code is 'w' --
		// same grouping as storeWestern(), because the byte layout (not the
		// menu URL) is what a replay/rule record has to match.
		public static function formatFor($region) {
			return strtolower((string)$region) === "j" ? "j" : "w";
		}

		// Default rule bank, used by composePayload() when the caller does
		// not supply rule records of its own. Five 0x48-byte records,
		// concatenated, each with its own valid P3 trailer.
		//
		// 'j': the REAL 5 rule records from the live "stadium-20260927-trailers1"
		// distribution (region j, id fetched 2026-09-28) -- read out of
		// production, not invented. Its text bytes are EUC-JP (Japanese
		// rule names); the fixed parameter bytes after the name are
		// IDENTICAL across all 5 records in that real block, which is why a
		// single reused bank is plausible at all.
		//
		// 'w': the REAL 5 rule records from block_us.bin, the western
		// production block with the three actual battles (header 03 05 00
		// 00, 5 active rule records at 0xDB4) -- sent by the "PKHeX Linux
		// Port" session on 2026-09-28, same status as the JP bank: real
		// bytes off a real block, not invented. Same shape confirmed: all
		// 5 share identical parameter bytes and differ only in an ASCII
		// name (western text is ASCII, spec.md §3.2).
		const DEFAULT_RULE_BANK = [
			"j" => "pLyk86SzpK+ksaTDpLek56SmAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQM0AupLik5aTzpLGkxKGhpMCkpKOyAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQMy8upd6k6qWqpbmlr6G8peuhoaSxAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQM0kupNukw6SrpKSkyaSmoaGkuKTlAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQMw4upLek3qTNpL+kpKSrpKShoaSxAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQM9ot",
			"w" => "TmludGVuZG8gQ3VwIEZpbmFsAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQM0QoTmludGVuZG8gQ3VwIFNlbWlmaQD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQM7coTWFyaW8gU2Nob29sIEZpbmFsAAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQMz0oSG9ra2FpZG8gVG91cm5hbWVudAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQMyopU2hpbWFuZSBUb3VybmFtZW50IAD////////////////////////5////////////////AAAKMjcAmwAJAAMALQ//AABQM+Uo",
		];

		// The trailer for an EMPTY slot -- content zeroed, marker "XX", and
		// a stored sum that is the ONE'S COMPLEMENT of sum16(zero content +
		// marker), not the sum itself (confirmed against real hardware
		// behaviour by the PKHeX session on 2026-09-28: the Stadium's own
		// "clear slot" menu writes marker 58 58 with stored sum 0x41C0
		// against a direct content sum of 0xBE3F -- exactly the bitwise
		// complement). Because the content is zero, only the marker enters
		// the sum, so the complement comes out IDENTICAL for a replay slot
		// or a rule slot, any size: sum16("XX") = 0x0058+0x0058 = 0x00B0,
		// complement (~0x00B0 & 0xFFFF) = 0xFF4F, little-endian = bytes
		// 4F FF. Never write an unused slot as plain zero -- that was this
		// class's own bug until this constant existed.
		const EMPTY_SLOT_TRAILER = "\x58\x58\x4F\xFF";

		// The 8 game regions, and which of them share the western payload.
		// The 7 non-Japanese ones use IDENTICAL bytes (spec.md §5.2): "the
		// western payload bytes are identical for all seven western codes".
		// A western distribution writes 7 rows (one per region, each with
		// its own File ID -- see why in storeWestern()), not just one.
		const REGIONS = ["j", "e", "p", "u", "d", "f", "i", "s"];
		const WESTERN_REGIONS = ["e", "p", "u", "d", "f", "i", "s"];

		// The 4-letter code from the region letter, the same way
		// app/auto-schedule uses it (BXTJ, BXTE, ...). One source of truth:
		// the path is never typed by hand in two places.
		public static function pathCode($region) {
			$region = strtolower((string)$region);
			$mapa = [
				"j" => "BXTJ", "e" => "BXTE", "p" => "BXTP", "u" => "BXTU",
				"d" => "BXTD", "f" => "BXTF", "i" => "BXTI", "s" => "BXTS",
			];
			return $mapa[$region] ?? null;
		}

		// Validates the SHAPE of a payload, without trusting whoever
		// generated it. The game only checks size and File ID (spec.md
		// §1.4) -- beyond that, it is us or nobody, and a malformed block
		// published today would only be noticed when someone opened the
		// Stadium and saw an empty list, exactly like the 2023 stubs.
		//
		// Returns "" when valid, or the reason for the refusal.
		public static function validatePayload($payload, $fileId) {
			if (!is_string($payload) || strlen($payload) !== self::PAYLOAD_SIZE) {
				return "payload must be exactly " . self::PAYLOAD_SIZE . " bytes (0xFFE), got " . strlen((string)$payload);
			}
			if (!is_string($fileId) || strlen($fileId) !== 16) {
				return "file_id must be exactly 16 bytes";
			}
			// offset 0xFEA..0xFF9 inside the 0xFFE-byte payload.
			$noPayload = substr($payload, 0xFEA, 16);
			if ($noPayload !== $fileId) {
				return "File ID in the payload (offset 0xFEA) does not match the row's file_id";
			}
			// 0xFFA-0xFFD: "P3" + LE sum of 0x000..0xFFB. The game does not
			// check this (spec.md §1.4: "the marker and sum... are not
			// checked by Crystal"), but WITHOUT it the Stadium lists
			// nothing -- that was exactly the 2023 stubs' defect. Checking
			// it here is the only safety net that exists.
			$marcador = substr($payload, 0xFFA, 2);
			if ($marcador !== "P3") {
				return "missing P3 frame at 0xFFA -- Crystal would accept it, but the Stadium would list nothing (this was the old stubs' defect)";
			}
			$somaGravada = unpack("v", substr($payload, 0xFFC, 2))[1];
			$somaCalculada = self::sum16(substr($payload, 0, 0xFFC));
			if ($somaGravada !== $somaCalculada) {
				return sprintf("sum at 0xFFC (%04X) does not match the calculated sum16(0x000..0xFFB) (%04X)", $somaGravada, $somaCalculada);
			}
			return "";
		}

		// The game's sum16: a 16-bit sum with no carry beyond 16 bits -- it
		// is literally a sum modulo 0x10000, not a CRC. spec.md §5.2
		// confirms: "LE sum16(payload[0x000..0xFFB])".
		private static function sum16($bytes) {
			$soma = 0;
			foreach (unpack("C*", $bytes) as $b) {
				$soma = ($soma + $b) & 0xFFFF;
			}
			return $soma;
		}

		// Builds the 4 menu bytes that replicate the payload's frame: "P3" +
		// LE sum. spec.md §5.3: "50 33 <sum lo> <sum hi> = payload[0xFFA..0xFFD]".
		// Never type these bytes separately from the payload -- they come
		// from it, and a divergence between the two produced a bug before
		// (see this table's migration).
		public static function frameFromPayload($payload) {
			return substr($payload, 0xFFA, 4);
		}

		// A record's own trailer, marker at length-4 and LE sum16 at
		// length-2, the sum covering everything up to and including the
		// marker (spec.md §5.2: "sum over 0x47E bytes, marker included" for
		// a 0x480 JP replay record -- generalised here to any record length,
		// and confirmed against a real rule record too: stride 0x48, sum
		// over [0,0x46), matches the live bytes exactly). Returns "" when
		// valid, or the reason for the refusal.
		private static function validateRecordTrailer($record, $length, $what) {
			if (!is_string($record) || strlen($record) !== $length) {
				return "$what must be exactly $length bytes, got " . strlen((string)$record);
			}
			$marcador = substr($record, $length - 4, 2);
			if ($marcador !== "P3") {
				return "$what is missing its own P3 trailer at +" . ($length - 4);
			}
			$somaGravada = unpack("v", substr($record, $length - 2, 2))[1];
			$somaCalculada = self::sum16(substr($record, 0, $length - 2));
			if ($somaGravada !== $somaCalculada) {
				return sprintf("%s trailer sum (%04X) does not match the calculated sum16 (%04X)", $what, $somaGravada, $somaCalculada);
			}
			return "";
		}

		// Validates a single replay record on its own, before it ever
		// enters the library -- so a broken upload is refused at import
		// time, not discovered later inside a composed payload.
		public static function validateReplayRecord($format, $record) {
			$stride = self::REPLAY_STRIDE[$format] ?? null;
			if ($stride === null) return "unknown format: '$format' (must be 'j' or 'w')";
			return self::validateRecordTrailer($record, $stride, "replay record");
		}

		public static function validateRuleRecord($record) {
			return self::validateRecordTrailer($record, self::RULE_RECORD_SIZE, "rule record");
		}

		// Stores one uploaded replay clip in the library, after validating
		// its own trailer. Does not touch bxt_stadium_distributions --
		// composePayload() is what turns library entries into a servable
		// payload, and that is a separate, later step (the admin panel's
		// compose form).
		//
		// $isCustom is a required editorial call by whoever uploads, not a
		// default to leave implicit the way bxt_stadium_distributions'
		// does: the same "Export battle" can capture either a real
		// historical save or a fan-made one, and composing an OFFICIAL
		// distribution out of a CUSTOM replay would misrepresent it
		// (owner's point, 2026-09-28) -- checked in composeIsAllowed()
		// below before a compose is allowed to claim "official".
		public static function storeReplay($format, $record, $label, $sourceNote = null, $isCustom = false) {
			$erro = self::validateReplayRecord($format, $record);
			if ($erro !== "") return $erro;
			if ($label === null || trim((string)$label) === "") {
				return "label must not be empty";
			}

			$db = DBUtil::getInstance()->getDB();
			$isCustomInt = $isCustom ? 1 : 0;
			$stmt = $db->prepare(
				"insert into bxt_stadium_replays (format, is_custom, record, label, source_note)
				 values (?, ?, ?, ?, ?)");
			$stmt->bind_param("sisss", $format, $isCustomInt, $record, $label, $sourceNote);
			try {
				$stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				return "database error: " . $e->getMessage();
			}
			return "";
		}

		// For the admin panel's picker and manage list: every uploaded
		// clip for a format, newest first.
		public static function replaysFor($format) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, is_custom, label, source_note, created_at
				   from bxt_stadium_replays
				  where format = ?
				  order by id desc");
			$stmt->bind_param("s", $format);
			$stmt->execute();
			return DBUtil::fancy_get_result($stmt);
		}

		public static function replayById($id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select format, is_custom, record, label, source_note from bxt_stadium_replays where id = ?");
			$id = (int)$id;
			$stmt->bind_param("i", $id);
			$stmt->execute();
			return $stmt->get_result()->fetch_assoc();
		}

		// Renames a library entry -- label and source note only, never the
		// record bytes or the format (uploading again is how you replace
		// the actual data). Returns "" on success, or the reason for the
		// refusal.
		public static function renameReplay($id, $label, $sourceNote, $isCustom) {
			if ($label === null || trim((string)$label) === "") {
				return "label must not be empty";
			}
			$db = DBUtil::getInstance()->getDB();
			$id = (int)$id;
			$isCustomInt = $isCustom ? 1 : 0;
			$stmt = $db->prepare(
				"update bxt_stadium_replays set label = ?, source_note = ?, is_custom = ? where id = ?");
			$stmt->bind_param("ssii", $label, $sourceNote, $isCustomInt, $id);
			try {
				$stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				return "database error: " . $e->getMessage();
			}
			return "";
		}

		// Removes a library entry. Does not touch any distribution that
		// was already composed from it -- composePayload() copies the
		// record bytes into the payload at compose time, so an already-
		// stored distribution keeps working after its source replay is
		// deleted from the library.
		public static function deleteReplay($id) {
			$db = DBUtil::getInstance()->getDB();
			$id = (int)$id;
			$stmt = $db->prepare("delete from bxt_stadium_replays where id = ?");
			$stmt->bind_param("i", $id);
			try {
				return $stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				LogUtil::error("stadium", "StadiumUtil::deleteReplay($id) failed: " . $e->getMessage());
				return false;
			}
		}

		// The check the owner asked for, 2026-09-28: composing something
		// claimed as OFFICIAL out of a CUSTOM replay would misrepresent
		// it, so refuse instead of allowing the combination silently. A
		// custom compose has no such restriction -- mixing official
		// replays into a custom compose is fine, it just cannot claim to
		// BE official. $replayRows is the array of rows replayById()
		// returned for each picked slot.
		public static function composeIsAllowed($isCustom, array $replayRows) {
			if ($isCustom) return "";
			foreach ($replayRows as $i => $row) {
				if ((int)($row["is_custom"] ?? 0) === 1) {
					return "slot " . ($i + 1) . " (\"" . $row["label"] . "\") is a custom replay -- an official distribution cannot be composed from it";
				}
			}
			return "";
		}

		// Assembles a full 0xFFE payload out of up to 3 replay records (in
		// slot order -- slot order is menu/save display order, not chosen by
		// this function) plus 5 rule records, a message of the day, the
		// Delibird flags byte and a File ID. Every field is placed at its
		// absolute offset (spec.md §5.2) rather than concatenated in
		// sequence, on purpose: an off-by-one in one field would silently
		// shift every field after it if this were built by concatenation,
		// and placing by absolute offset makes that class of bug
		// impossible.
		//
		// $replayRecords: 0-3 elements, each already exactly stride bytes
		// with its own valid trailer (validateReplayRecord). Missing slots
		// are zero-filled -- the same "not P3" convention spec.md documents
		// for an empty slot, and the same state a fresh plugin-created block
		// already uses for an unset File ID (spec.md §5.1).
		// $ruleRecords: null to use DEFAULT_RULE_BANK for the format (and 0
		// active rules where no bank exists yet -- see the 'w' comment on
		// that constant), or 0-5 elements of 0x48 bytes each, own trailer
		// valid.
		// $message: plain text, UTF-8. Converted to EUC-JP for 'j', required
		// ASCII for 'w' (spec.md §3.2: "titles/display names ... ASCII").
		// $flags: Delibird flags byte, default 0 (spec.md §5.1: bits 0x01/
		// 0x02 change the player's platform PERMANENTLY -- callers should
		// default to 0 unless that is explicitly wanted).
		//
		// Returns [payload, ""] or [null, reason].
		public static function composePayload($format, array $replayRecords, $ruleRecords, $message, $flags, $fileId) {
			$stride = self::REPLAY_STRIDE[$format] ?? null;
			if ($stride === null) return [null, "unknown format: '$format' (must be 'j' or 'w')"];
			if (count($replayRecords) > self::REPLAY_SLOTS) {
				return [null, "at most " . self::REPLAY_SLOTS . " replay records"];
			}
			foreach ($replayRecords as $i => $rec) {
				$erro = self::validateReplayRecord($format, $rec);
				if ($erro !== "") return [null, "replay slot $i: $erro"];
			}

			if ($ruleRecords === null) {
				$banco = self::DEFAULT_RULE_BANK[$format] ?? null;
				if ($banco === null) {
					$ruleRecords = []; // no verified bank for this format yet -- 0 active rules
				} else {
					$bytes = base64_decode($banco);
					$ruleRecords = [];
					for ($i = 0; $i < self::RULE_SLOTS; $i++) {
						$ruleRecords[] = substr($bytes, $i * self::RULE_RECORD_SIZE, self::RULE_RECORD_SIZE);
					}
				}
			}
			if (count($ruleRecords) > self::RULE_SLOTS) {
				return [null, "at most " . self::RULE_SLOTS . " rule records"];
			}
			foreach ($ruleRecords as $i => $rec) {
				$erro = self::validateRuleRecord($rec);
				if ($erro !== "") return [null, "rule slot $i: $erro"];
			}

			if (!is_string($fileId) || strlen($fileId) !== 16) {
				return [null, "file_id must be exactly 16 bytes"];
			}
			$flags = (int)$flags & 0xFF;

			$payload = str_repeat("\x00", self::PAYLOAD_SIZE);

			// 0x000/0x001: active counts. 0x002-0x003 stay 00 00.
			$payload = substr_replace($payload, chr(count($replayRecords)) . chr(count($ruleRecords)), 0x000, 2);

			// 0x004 + i*stride: the 3 replay slots. Past the supplied count,
			// a slot is EMPTY, not zero: content zeroed, marker "XX",
			// stored sum = complement (EMPTY_SLOT_TRAILER's own comment
			// explains why the same 4 bytes work at any record length).
			// Plain zero-fill (marker 00 00) was this method's own bug
			// until a real console's "clear slot" behaviour was checked
			// (PKHeX session, 2026-09-28) -- Crystal never reads a slot's
			// trailer, but nothing here had confirmed Stadium tolerates it.
			for ($i = 0; $i < self::REPLAY_SLOTS; $i++) {
				$conteudo = $replayRecords[$i] ?? (str_repeat("\x00", $stride - 4) . self::EMPTY_SLOT_TRAILER);
				$payload = substr_replace($payload, $conteudo, 0x004 + $i * $stride, $stride);
			}

			// The 5 rule slots follow immediately, same empty-slot rule.
			$rulesOffset = 0x004 + self::REPLAY_SLOTS * $stride;
			for ($i = 0; $i < self::RULE_SLOTS; $i++) {
				$conteudo = $ruleRecords[$i] ?? (str_repeat("\x00", self::RULE_RECORD_SIZE - 4) . self::EMPTY_SLOT_TRAILER);
				$payload = substr_replace($payload, $conteudo, $rulesOffset + $i * self::RULE_RECORD_SIZE, self::RULE_RECORD_SIZE);
			}

			// Message of the day: fills up to the flags byte at 0xFE1
			// (spec.md §5.2 -- 0xF5 bytes JP, 0xC5 western, terminator
			// included), 00-terminated and zero-padded.
			$messageOffset = $rulesOffset + self::RULE_SLOTS * self::RULE_RECORD_SIZE;
			$messageMaxLen = 0xFE1 - $messageOffset;
			if ($format === "j") {
				$codificada = @mb_convert_encoding((string)$message, "EUC-JP", "UTF-8");
			} else {
				if ((string)$message !== "" && !mb_check_encoding((string)$message, "ASCII")) {
					return [null, "message of the day must be ASCII for a western payload (spec.md §3.2)"];
				}
				$codificada = (string)$message;
			}
			if (strlen($codificada) + 1 > $messageMaxLen) {
				return [null, "message of the day is too long: " . (strlen($codificada) + 1) . " bytes encoded, $messageMaxLen available"];
			}
			$codificada = str_pad($codificada . "\x00", $messageMaxLen, "\x00");
			$payload = substr_replace($payload, $codificada, $messageOffset, $messageMaxLen);

			// 0xFE1 flags, 0xFE2-0xFE9 stay 00, 0xFEA-0xFF9 File ID.
			$payload = substr_replace($payload, chr($flags), 0xFE1, 1);
			$payload = substr_replace($payload, $fileId, 0xFEA, 16);

			// 0xFFA-0xFFD: the frame. The marker at 0xFFA-0xFFB is INSIDE
			// the summed range (0x000-0xFFB) -- the same "marker included"
			// rule as a record's own trailer -- so it has to be written
			// before the sum is computed, not after. Writing both together
			// afterwards silently sums two 00 bytes instead of "P3" and
			// produces a frame that is wrong by exactly ord('P')+ord('3')
			// (found by round-tripping a real payload through this
			// function: the composed sum came out 0x0083 short).
			$payload = substr_replace($payload, "P3", 0xFFA, 2);
			$soma = self::sum16(substr($payload, 0, 0xFFC));
			$payload = substr_replace($payload, pack("v", $soma), 0xFFC, 2);

			return [$payload, ""];
		}

		// Stores a Japanese distribution. One row; region 'j' is the only
		// one that shares its payload with nobody.
		//
		// $isCustom: false (default) = official, a faithful reconstruction
		// of a real historical Nintendo block -- what
		// import_stadium_distribution.php has always produced. true =
		// custom, an admin-curated combination (StadiumUtil::composePayload());
		// gated by sys_users.custom_mobile_stadium_opt_in the same way
		// bxt_news.is_custom gates the custom Pokémon News track (owner's
		// correction, 2026-09-28: a user who does NOT opt in must still
		// get official content, not nothing).
		public static function storeJapanese($fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom = false) {
			return self::storeOne("j", $fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom);
		}

		// Generic entry point, for callers that already know the region --
		// the importer (maint/import_stadium_distribution.php) uses this,
		// because the <slug>.bin/<slug>.json pair arrives already marked
		// with its region letter.
		public static function store($region, $fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom = false) {
			return self::storeOne($region, $fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom);
		}

		// Stores a western distribution. ONE row per region (7 rows), with
		// the SAME payload -- because the western payload is byte-identical
		// across the 7 (spec.md §5.2) -- but the File ID can be the same or
		// different per region, depending on how the plugin numbers them.
		// Accepts a single File ID (applied to all 7) or one per region, so
		// as not to force a decision that belongs to the plugin, not to us.
		public static function storeWestern($fileIdOuMapa, $payload, $cost, $slug, $title, $specVersion, $isCustom = false) {
			$falhas = [];
			foreach (self::WESTERN_REGIONS as $regiao) {
				$fileId = is_array($fileIdOuMapa) ? ($fileIdOuMapa[$regiao] ?? null) : $fileIdOuMapa;
				if ($fileId === null) {
					$falhas[$regiao] = "no file_id for this region";
					continue;
				}
				$erro = self::storeOne($regiao, $fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom);
				if ($erro !== "") $falhas[$regiao] = $erro;
			}
			return $falhas; // empty = all good
		}

		private static function storeOne($region, $fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom = false) {
			if (self::pathCode($region) === null) return "unknown region: '$region'";

			$erro = self::validatePayload($payload, $fileId);
			if ($erro !== "") return $erro;

			if (!preg_match('/^[a-z0-9-]{1,40}$/', (string)$slug)) {
				return "slug must be [a-z0-9-], up to 40 characters";
			}
			if ($cost !== null && (!is_int($cost) || $cost < 0 || $cost > 999)) {
				// 4+ digits gives D3 in the game (spec.md §5.4); 999 is the
				// largest 3-digit value.
				return "cost must be null, or an integer from 0 to 999";
			}

			$db = DBUtil::getInstance()->getDB();
			// 's' for the binary file_id and payload, not 'b' -- tested,
			// and 'b' fails silently: without a call to send_long_data(),
			// mysqli writes 0 bytes for a 'b'-typed parameter and the
			// INSERT "succeeds" with no error at all. web/classes/AdminUtil.php
			// already says this about news_binary: "PHP's string is
			// binary-safe and mysqli sends the length, so a null byte in
			// the middle of the binary does not terminate the value" --
			// that holds for 's', not for 'b'.
			$isCustomInt = $isCustom ? 1 : 0;
			$stmt = $db->prepare(
				"insert into bxt_stadium_distributions
				   (game_region, is_custom, file_id, cost, slug, payload, title, spec_version)
				 values (?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt->bind_param("sissssss", $region, $isCustomInt, $fileId, $cost, $slug, $payload, $title, $specVersion);
			try {
				$stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				// mysqli THROWS on error by default since PHP 8.1 --
				// execute() does not return false the way it did on older
				// PHP. Found while testing the re-import path (the same
				// File ID twice): without this catch, an expected error
				// (the per-region uniqueness this migration created on
				// purpose) crashed the whole import script instead of
				// turning into a clean "FAILED" line.
				if ($e->getCode() === 1062) { // ER_DUP_ENTRY
					return "a distribution with this File ID already exists for this region (a file_id cannot be reused -- see the migration)";
				}
				return "database error: " . $e->getMessage();
			}

			$insertId = $db->insert_id;
			$erroStub = self::writeStub($region, $cost, $slug);
			if ($erroStub !== "") {
				// Undo the row: without the physical file the download
				// router looks for, it is unreachable -- and an "active"
				// row in the panel that serves nothing is exactly the 2023
				// stubs' defect, just hidden one level deeper.
				$db->query("delete from bxt_stadium_distributions where id = " . (int)$insertId);
				return "row rolled back (could not write the physical file): " . $erroStub;
			}
			return "";
		}

		// Where a region's physical files live. web/classes/ -> web/ ->
		// cgb/download/01/CGB-<code>/POKESTA.
		private static function downloadDir($region) {
			$codigo = self::pathCode($region);
			if ($codigo === null) return null;
			return dirname(__DIR__) . "/cgb/download/01/CGB-" . $codigo . "/POKESTA";
		}

		// The file name the GAME requests, with the cost prefix baked in --
		// that is how getCost() in auth.php:248 reads it, straight off the
		// name, no extra parameter. Not to be confused with the plain
		// "slug", which is only the part StadiumUtil uses to find the row
		// in the database.
		public static function stubFileName($cost, $slug) {
			return ($cost === null ? "" : ((int)$cost . ".")) . $slug . ".php";
		}

		// Generates the physical router for a payload, in the same pattern
		// download/28/AGB-AGTJ/0.ghost.php and 200.ghost.php already use for
		// costed content: one file per (cost, content) combination, small,
		// calling a shared function with the specific data as a literal
		// argument.
		//
		// The alternative we did NOT use was rewriting the URL in nginx (the
		// way the Battle Tower does for "room0001.cgb" -> "room.php?room=0001").
		// It does not work here: getCost() and the file resolution in
		// serveFileOrExecScript() read the SAME variable ($_GET['name']) in
		// download.php, so rewriting the name to point at a fixed script
		// would erase the cost prefix before getCost() ever sees it -- the
		// download would stop requiring authentication, the opposite of
		// what is intended. One physical file per (cost, slug) avoids the
		// problem because the file name itself, with no rewriting at all,
		// is already what the game asked for.
		//
		// Idempotent by design: the stub does NOT write content, it only
		// calls get_stadium_payload($region, $slug), which reads from the
		// database on every request. So two INSERTs with the same (region,
		// slug) -- which the table allows, since the only unique index is
		// on file_id -- end up served by the SAME physical file with no
		// conflict: payloadForSlug() always looks up that slug's most
		// recent active row. That is why an existing stub is never
		// overwritten.
		private static function writeStub($region, $cost, $slug) {
			$dir = self::downloadDir($region);
			if ($dir === null) return "unknown region";
			if (!is_dir($dir)) {
				if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
					return "could not create directory $dir";
				}
			}
			$arquivo = $dir . "/" . self::stubFileName($cost, $slug);
			if (file_exists($arquivo)) return ""; // already serves this (cost, slug)

			// Nowdoc (single-quoted identifier): NO interpolation -- the
			// "$payload" and "CORE_PATH" in the text below are literal PHP
			// for the generated file, not variables of this method. The two
			// points that vary (region and slug) come in as text markers,
			// substituted by str_replace afterwards -- var_export() already
			// produces valid PHP (quotes and escaping included), so the
			// marker becomes real PHP code, not a string inside a string.
			$modelo = <<<'PHPEOF'
<?php
	// SPDX-License-Identifier: MIT
	// Generated by StadiumUtil::writeStub() -- do not edit by hand.
	// The distribution itself lives in the database (bxt_stadium_distributions);
	// this exists only because the download router needs to find a
	// physical file with this exact name -- the same pattern
	// download/28/AGB-AGTJ/0.ghost.php already uses for costed content.
	require_once(CORE_PATH."/pokemon/stadium.php");

	$payload = get_stadium_payload(%%REGION%%, %%SLUG%%);
	if ($payload === null) {
		http_response_code(404);
	} else {
		header("Content-Type: application/octet-stream");
		print $payload;
	}
?>
PHPEOF;
			$conteudo = str_replace(
				["%%REGION%%", "%%SLUG%%"],
				[var_export($region, true), var_export($slug, true)],
				$modelo
			) . "\n";

			$ok = @file_put_contents($arquivo, $conteudo);
			return $ok === false ? "could not write $arquivo" : "";
		}

		// What a region's menu.php needs: that region's ACTIVE distributions
		// on ONE track (official or custom -- see is_custom's own comment
		// on the migration), in insertion order -- which is the order
		// Crystal reads them in (spec.md §5.3: "never list older
		// distributions after the current one with overlapping windows").
		// Whoever activates decides the order by activating in the right
		// order; we do not reorder here.
		public static function activeFor($region, $isCustom = false) {
			try {
				$db = DBUtil::getInstance()->getDB();
				$isCustomInt = $isCustom ? 1 : 0;
				$stmt = $db->prepare(
					"select file_id, schedule, cost, slug, payload
					   from bxt_stadium_distributions
					  where game_region = ? and is_custom = ? and active = 1
					  order by id asc");
				$stmt->bind_param("si", $region, $isCustomInt);
				$stmt->execute();
				return DBUtil::fancy_get_result($stmt);
			} catch (\mysqli_sql_exception $e) {
				// This method feeds menu.php, which a CARTRIDGE calls --
				// not a browser. An exception here (table not migrated yet,
				// for example) cannot turn into a PHP error page in the
				// middle of a game request: better to return "no
				// distribution" (empty list -> buildMenu() returns null ->
				// 404, the same as the other six regions always had) than
				// to break the download.
				LogUtil::error("stadium", "StadiumUtil::activeFor($region) failed: " . $e->getMessage());
				return [];
			}
		}

		// Per-account preference, same shape as
		// bxt_pokemon_news_user_opted_in_custom() in news.php. Fails
		// closed (false) on a database error or a missing/invalid user id.
		public static function userOptedInCustom($userId) {
			$userId = (int)$userId;
			if ($userId <= 0) return false;
			try {
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare("select custom_mobile_stadium_opt_in from sys_users where id = ? limit 1");
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				$linha = $stmt->get_result()->fetch_assoc();
				return $linha !== null && (int)$linha["custom_mobile_stadium_opt_in"] === 1;
			} catch (\mysqli_sql_exception $e) {
				LogUtil::error("stadium", "StadiumUtil::userOptedInCustom($userId) failed: " . $e->getMessage());
				return false;
			}
		}

		// Whether an ACTIVE custom distribution exists for a region --
		// same shape as bxt_pokemon_news_custom_row_exists() in news.php.
		// Checked before switching an opted-in user to the custom track:
		// opting in with nothing custom active must still show official
		// content, not an empty menu.
		public static function customRowExists($region) {
			try {
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare(
					"select id from bxt_stadium_distributions
					  where game_region = ? and is_custom = 1 and active = 1
					  order by id desc limit 1");
				$stmt->bind_param("s", $region);
				$stmt->execute();
				return $stmt->get_result()->fetch_assoc() !== null;
			} catch (\mysqli_sql_exception $e) {
				LogUtil::error("stadium", "StadiumUtil::customRowExists($region) failed: " . $e->getMessage());
				return false;
			}
		}

		// The single decision every content-serving call re-derives (menu
		// AND payload, the same defense-in-depth shape news.php uses at
		// every one of its own serving points, not just once at an
		// "index"): official unless the account opted in AND a custom
		// distribution is actually active for this region. Owner's
		// correction, 2026-09-28: NOT opting in must still return official
		// content -- this replaced an earlier version of this class that
		// treated the opt-in as a hard gate (opt out = nothing at all),
		// which was wrong and never matched how the Pokémon News opt-in
		// behaves.
		public static function isCustomForUser($region, $userId) {
			if (!self::userOptedInCustom($userId)) return false;
			return self::customRowExists($region);
		}

		// Builds the menu.cgb bytes for a region: N + entries. spec.md §1.3
		// and §5.3. Host is fixed on purpose -- see the comment on the
		// payload function for why it cannot be anything else.
		const HOST = "gameboy.datacenter.ne.jp";

		public static function buildMenu($region, $isCustom = false) {
			$linhas = self::activeFor($region, $isCustom);
			if (count($linhas) === 0) return null; // no distribution: 404, not a menu with N=0

			$codigo = self::pathCode($region);
			$corpo = "";
			foreach ($linhas as $linha) {
				$schedule = $linha["schedule"];
				if (strlen($schedule) !== 6) $schedule = "\xFF\xFF\xFF\xFF\xFF\xFF";
				$fileId = $linha["file_id"];
				$moldura = self::frameFromPayload($linha["payload"]);
				$nome = ($linha["cost"] === null ? "" : ((int)$linha["cost"] . "."))
					. $linha["slug"] . ".cgb";
				$url = "http://" . self::HOST . "/cgb/download?name=/01/CGB-" . $codigo . "/POKESTA/" . $nome;
				if (strlen($url) > 0xA5) {
					// spec.md §1.3: L > 0xA5 gives error D8 in the game. We
					// do not let a broken entry into the menu; better
					// missing than crashing whoever tries to download.
					continue;
				}
				$corpo .= $schedule . $fileId . $moldura . pack("v", strlen($url)) . $url;
			}
			if ($corpo === "") return null;
			$n = count($linhas);
			if ($n > 255) $n = 255; // N is 1 byte; should never reach here
			$menu = chr($n) . $corpo;
			if (strlen($menu) > self::PAYLOAD_SIZE) {
				// spec.md §1.3: "the whole menu must be <= 0xFFE bytes".
				// This is a symptom of someone activating too many
				// distributions; we do not trim on our own because we do
				// not know which one to drop.
				LogUtil::warn("stadium", "StadiumUtil::buildMenu($region): menu exceeds 0xFFE bytes with " . count($linhas) . " active distributions");
				return null;
			}
			return $menu;
		}

		// A distribution's payload, by the served slug (no cost prefix, no
		// extension) -- this is what the nginx handler passes through after
		// matching the name pattern. null if not found or not active: a
		// deactivated slug should no longer be served, even if someone
		// still has the link.
		// $isCustom gates this the same way it gates buildMenu(): a
		// non-opted-in (or opted-in-but-nothing-custom-active) account
		// must not be able to fetch a custom payload just by knowing its
		// slug, even though it was never listed in their menu.
		public static function payloadForSlug($region, $slug, $isCustom = false) {
			// Same reasoning as activeFor(): the caller here is the
			// physical stub that Crystal downloads, not a browser. A
			// database error here returns null (-> 404), not an uncaught
			// exception.
			try {
				$db = DBUtil::getInstance()->getDB();
				$isCustomInt = $isCustom ? 1 : 0;
				$stmt = $db->prepare(
					"select payload from bxt_stadium_distributions
					  where game_region = ? and slug = ? and is_custom = ? and active = 1
					  order by id desc limit 1");
				$stmt->bind_param("ssi", $region, $slug, $isCustomInt);
				$stmt->execute();
				$linha = $stmt->get_result()->fetch_assoc();
				return $linha ? $linha["payload"] : null;
			} catch (\mysqli_sql_exception $e) {
				LogUtil::error("stadium", "StadiumUtil::payloadForSlug($region, $slug) failed: " . $e->getMessage());
				return null;
			}
		}

		// Removes a built distribution -- owner's request, 2026-09-28, a
		// cleanup button for the panel. Refuses an ACTIVE row rather than
		// silently deactivating it first: deleting something currently
		// served should be a deliberate two-step (deactivate, then
		// delete), not one click that also changes what the game sees
		// right now. Also removes the physical stub file writeStub()
		// generated, but only if no OTHER row still shares the same
		// (region, cost, slug) -- the stub is shared by design (its own
		// comment: "two INSERTs with the same (region, slug)... end up
		// served by the SAME physical file"), so removing it out from
		// under a sibling row would 404 something still meant to work.
		// Returns "" or the reason for the refusal.
		public static function deleteDistribution($id) {
			$db = DBUtil::getInstance()->getDB();
			$id = (int)$id;
			$stmt = $db->prepare("select game_region, cost, slug, active from bxt_stadium_distributions where id = ?");
			$stmt->bind_param("i", $id);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if ($row === null) return "not found";
			if ((int)$row["active"] === 1) return "active distributions cannot be deleted -- deactivate it first";

			try {
				$del = $db->prepare("delete from bxt_stadium_distributions where id = ?");
				$del->bind_param("i", $id);
				$del->execute();
			} catch (\mysqli_sql_exception $e) {
				return "database error: " . $e->getMessage();
			}

			$stillNeeded = $db->prepare(
				"select count(*) as c from bxt_stadium_distributions
				  where game_region = ? and slug = ? and (cost <=> ?)");
			$stillNeeded->bind_param("ssi", $row["game_region"], $row["slug"], $row["cost"]);
			$stillNeeded->execute();
			$count = $stillNeeded->get_result()->fetch_assoc()["c"];
			if ((int)$count === 0) {
				$dir = self::downloadDir($row["game_region"]);
				if ($dir !== null) {
					$stub = $dir . "/" . self::stubFileName($row["cost"], $row["slug"]);
					if (file_exists($stub)) @unlink($stub);
				}
			}
			return "";
		}

		// For the panel: lists everything, active and inactive, most
		// recent first.
		public static function allFor($region) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, file_id, is_custom, cost, slug, title, active, spec_version, created_at,
				        length(payload) as payload_size
				   from bxt_stadium_distributions
				  where game_region = ?
				  order by id desc");
			$stmt->bind_param("s", $region);
			$stmt->execute();
			return DBUtil::fancy_get_result($stmt);
		}

		// For the panel's "view details" page: one full row, payload
		// included. Never sent to the game -- this is the admin reading
		// what a distribution actually contains, the payloadForSlug()/
		// activeFor() paths a cartridge calls are separate and unaffected.
		public static function getById($id) {
			$db = DBUtil::getInstance()->getDB();
			$id = (int)$id;
			$stmt = $db->prepare(
				"select id, game_region, is_custom, file_id, cost, slug, title, active, spec_version, created_at, payload
				   from bxt_stadium_distributions
				  where id = ?");
			$stmt->bind_param("i", $id);
			$stmt->execute();
			return $stmt->get_result()->fetch_assoc();
		}

		// Decodes a stored payload back into human-readable fields, for
		// the admin panel's "view details" page. Owner's request,
		// 2026-09-28: seeing a REAL decoded message (tags and all) is
		// what actually explains the EUC-JP/markup hint on the compose
		// form, better than more prose would. Read-only -- never used on
		// the game-facing serving path, only for display.
		public static function describePayload($region, $payload) {
			$format = self::formatFor($region);
			$stride = self::REPLAY_STRIDE[$format];

			$replayCount = ord($payload[0x000]);
			$ruleCount = ord($payload[0x001]);

			$replaySlots = [];
			for ($i = 0; $i < self::REPLAY_SLOTS; $i++) {
				$offset = 0x004 + $i * $stride;
				$marker = substr($payload, $offset + $stride - 4, 2);
				$replaySlots[] = ($marker === "P3") ? "filled" : "empty";
			}

			$rulesOffset = 0x004 + self::REPLAY_SLOTS * $stride;
			$ruleSlots = [];
			for ($i = 0; $i < self::RULE_SLOTS; $i++) {
				$offset = $rulesOffset + $i * self::RULE_RECORD_SIZE;
				$marker = substr($payload, $offset + self::RULE_RECORD_SIZE - 4, 2);
				$ruleSlots[] = ($marker === "P3") ? "filled" : "empty";
			}

			$messageOffset = $rulesOffset + self::RULE_SLOTS * self::RULE_RECORD_SIZE;
			$messageMaxLen = 0xFE1 - $messageOffset;
			$rawMessage = substr($payload, $messageOffset, $messageMaxLen);
			$terminator = strpos($rawMessage, "\x00");
			if ($terminator !== false) $rawMessage = substr($rawMessage, 0, $terminator);
			if ($format === "j") {
				$messageText = @mb_convert_encoding($rawMessage, "UTF-8", "EUC-JP");
			} else {
				$messageText = $rawMessage;
			}

			$flags = ord($payload[0xFE1]);
			$flagNames = [];
			if ($flags & 0x01) $flagNames[] = "Game Boy -> Game Boy Advance";
			if ($flags & 0x02) $flagNames[] = "Nintendo 64 -> GameCube";

			$fileId = substr($payload, 0xFEA, 16);
			$fileIdText = ctype_print($fileId) ? $fileId : bin2hex($fileId);

			$frameMarker = substr($payload, 0xFFA, 2);
			$frameSum = unpack("v", substr($payload, 0xFFC, 2))[1];

			return [
				"format" => $format,
				"replay_count" => $replayCount,
				"rule_count" => $ruleCount,
				"replay_slots" => $replaySlots,
				"rule_slots" => $ruleSlots,
				"message_raw_hex" => bin2hex($rawMessage),
				"message_text" => $messageText,
				"flags" => $flags,
				"flag_names" => $flagNames,
				"file_id" => $fileIdText,
				"frame_valid" => $frameMarker === "P3",
				"frame_sum" => $frameSum,
			];
		}

		// Returns bool -- the panel uses this to decide the success/failure
		// message, and a database error here must turn into "did not
		// save", not a PHP error page in the admin panel.
		// Activating a row now deactivates every OTHER row on the same
		// (region, track) first -- at most one active distribution per
		// region, owner's request 2026-09-28. This is the exact bug
		// spec.md §5.3 already warned about (two active entries with
		// overlapping windows make the game alternate between "new data"
		// and "you already have this"): the schedule-window use case that
		// justified allowing several active rows was never exercised
		// (spec.md §7), so exclusivity is the safer default. Deactivating
		// never touches other rows.
		public static function setActive($id, $active) {
			try {
				$db = DBUtil::getInstance()->getDB();
				$id = (int)$id;
				$a = $active ? 1 : 0;

				if ($active) {
					$linha = $db->query("select game_region, is_custom from bxt_stadium_distributions where id = $id")->fetch_assoc();
					if ($linha === null) return false;
					$stmt = $db->prepare(
						"update bxt_stadium_distributions set active = 0
						  where game_region = ? and is_custom = ? and id != ? and active = 1");
					$stmt->bind_param("sii", $linha["game_region"], $linha["is_custom"], $id);
					$stmt->execute();
				}

				$stmt = $db->prepare("update bxt_stadium_distributions set active = ? where id = ?");
				$stmt->bind_param("ii", $a, $id);
				return $stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				LogUtil::error("stadium", "StadiumUtil::setActive($id) failed: " . $e->getMessage());
				return false;
			}
		}
	}
