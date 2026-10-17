<?php
require_once(__DIR__."/LogUtil.php");
	// Dovecot-backed webmail storage. MySQL retains account registration. Return the former
	// sys_inbox row shape (id, sender, timestamp, message, deleted_at) so MailUtil
	// conversation/filter/pagination behavior stays independent of storage. Use the privileged
	// doveadm-server socket instead of granting the web service direct access to other users
	// mailboxes.
	class MailStoreUtil {

		const SOCKET = "/run/dovecot/doveadm-server";
		const TRASH = "Trash";
		// Application IMAP keywords replace former table columns; the conventional $ prefix and
		// values survive folder moves.
		const RETRIEVED = '$Retrieved';
		// Web reading and cartridge retrieval are separate states. Dovecot POP3 sets
		// \Seen on retrieval, so webmail requires its own $WebRead keyword.
		const WEB_READ = '$WebRead';
		const BY_GAME = '$DeletedByGame';

		// A webmail URL ID includes folder and UID, such as INBOX:12. UIDs are folder-local, so
		// Trash:12 is a different message. List the mailbox before acting on its IDs.
		public static function makeId($mailbox, $uid) {
			return $mailbox . ":" . (int)$uid;
		}

		public static function splitId($id) {
			$parts = explode(":", (string)$id, 2);
			if (count($parts) !== 2 || !ctype_digit($parts[1])) return null;
			if (!preg_match('/^[A-Za-z0-9_.-]+$/', $parts[0])) return null;
			return [$parts[0], (int)$parts[1]];
		}

		private static function run($words, $mailboxUser, $args, $calado = false) {
			$cmd = array_merge(["/usr/bin/doveadm"], $words,
				["-u", $mailboxUser, "-S", self::SOCKET], $args);
			$spec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) return null;
			$out = stream_get_contents($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]); fclose($pipes[2]);
			$status = proc_close($proc);
			if ($status !== 0) {
				if (!$calado) LogUtil::error("mail-store", "MailStoreUtil: doveadm exited {$status}: " . trim((string)$err));
				return null;
			}
			return $out;
		}

		// Read a folder in arrival order; MailUtil paginates the small mailbox in memory rather
		// than adding per-page doveadm overhead.
		public static function rows($mailboxUser, $folder = "INBOX", $comCorpo = false) {
			// Fetch all listing fields and bodies in one call rather than spawning one process per
			// message. Put multiline text last so preceding fields can be split unambiguously by line.
			$campos = "uid flags hdr.return-path hdr.from hdr.date date.received size.physical"
				. ($comCorpo ? " text" : "");
			$out = self::run(["fetch"], $mailboxUser, [$campos, "mailbox", $folder]);
			if ($out === null) return [];

			$rows = [];
			foreach (explode("\f", $out) as $bloco) {
				if (!preg_match('/^uid:\s*(\d+)/m', $bloco, $u)) continue;
				$flags = self::header($bloco, "flags");
				$row = [
					"id" => self::makeId($folder, $u[1]),
					"uid" => (int)$u[1],
					"sender" => self::remetente($bloco),
					"timestamp" => self::when($bloco),
					// Both deletion mechanisms count as trash: game DELE sets $DeletedByGame while the message
					// remains in INBOX but is hidden from later POP3 sessions; webmail moves it to Trash,
					// outside POP3. deleted_by preserves which actor deleted it.
					"deleted_at" => ($folder === self::TRASH
						|| stripos($flags, self::BY_GAME) !== false)
						? self::when($bloco) : null,
					"size" => preg_match('/^size\.physical:\s*(\d+)/m', $bloco, $s) ? (int)$s[1] : 0,
					// Represent the previous table state with IMAP application keywords.
					"read_at" => stripos($flags, self::WEB_READ) !== false ? self::when($bloco) : null,
					// Dovecot sets \Seen on RETR; accept legacy $Retrieved markers too.
					"retrieved_at" => (stripos($flags, self::RETRIEVED) !== false
						|| stripos($flags, "\\Seen") !== false) ? self::when($bloco) : null,
					"deleted_by" => stripos($flags, self::BY_GAME) !== false ? "game" : ($folder === self::TRASH ? "web" : null),
				];
				if ($comCorpo) $row["message"] = self::afterTextMarker($bloco);
				$rows[] = $row;
			}
			usort($rows, function ($a, $b) { return $a["uid"] - $b["uid"]; });
			return $rows;
		}

		// Return exact message contents, converting Maildir LF to the CRLF expected by callers.
		public static function message($mailboxUser, $id) {
			$parts = self::splitId($id);
			if ($parts === null) return null;
			$out = self::run(["fetch"], $mailboxUser,
				["text", "mailbox", $parts[0], "uid", (string)$parts[1]]);
			if ($out === null) return null;
			return self::afterTextMarker($out);
		}

		// Return one record in the same shape as folder listings, including its message.
		public static function row($mailboxUser, $id) {
			$parts = self::splitId($id);
			if ($parts === null) return null;
			foreach ([$parts[0]] as $folder) {
				foreach (self::rows($mailboxUser, $folder) as $r) {
					if ($r["id"] !== $id) continue;
					$r["message"] = self::message($mailboxUser, $id);
					return $r["message"] === null ? null : $r;
				}
			}
			return null;
		}

		// Web deletion moves mail out of INBOX so POP3 cannot serve it; using the game deletion
		// flag would misattribute the actor. Count all messages and recent arrivals in a single
		// call per mailbox, using the supplied mailbox list because static userdb does not support
		// doveadm -A enumeration.
		public static function stats($mailboxUser, $horas = 24) {
			$out = self::run(["fetch"], $mailboxUser,
				["uid date.saved", "mailbox", "INBOX"]);
			if ($out === null) return ["total" => 0, "recentes" => 0];

			$total = 0;
			$recentes = 0;
			$corte = time() - ($horas * 3600);
			foreach (explode("\f", $out) as $bloco) {
				if (!preg_match('/^uid:\s*\d+/m', $bloco)) continue;
				$total++;
				$d = self::header($bloco, "date.saved");
				if ($d !== "" && ($t = strtotime($d)) && $t >= $corte) $recentes++;
			}
			return ["total" => $total, "recentes" => $recentes];
		}

		public static function moveToTrash($mailboxUser, $ids) {
			return self::moveMany($mailboxUser, $ids, self::TRASH);
		}

		// Restore reverses the relevant actor operation: clear a game marker in INBOX or move the
		// message back from Trash.
		public static function restore($mailboxUser, $ids) {
			$naInbox = [];
			$naLixeira = [];
			foreach ((array)$ids as $id) {
				$p = self::splitId($id);
				if ($p === null) continue;
				if ($p[0] === "INBOX") $naInbox[] = $p[1]; else $naLixeira[] = $id;
			}
			$n = 0;
			if (!empty($naInbox)) {
				$r = self::run(["flags", "remove"], $mailboxUser,
					[self::BY_GAME, "mailbox", "INBOX", "uid", implode(",", $naInbox)]);
				if ($r !== null) $n += count($naInbox);
			}
			if (!empty($naLixeira)) $n += self::moveMany($mailboxUser, $naLixeira, "INBOX");
			return $n;
		}

		private static function moveMany($mailboxUser, $ids, $destino) {
			// INBOX always exists. Trash is created on first deletion; already-existing is a normal
			// mailbox-create outcome, not a reportable failure.
			if ($destino !== "INBOX") {
				self::run(["mailbox", "create"], $mailboxUser, [$destino], true);
			}
			$porPasta = [];
			foreach ((array)$ids as $id) {
				$p = self::splitId($id);
				if ($p === null || $p[0] === $destino) continue;
				$porPasta[$p[0]][] = $p[1];
			}
			$n = 0;
			foreach ($porPasta as $folder => $uids) {
				$r = self::run(["move"], $mailboxUser,
					[$destino, "mailbox", $folder, "uid", implode(",", $uids)]);
				if ($r !== null) $n += count($uids);
			}
			return $n;
		}

		// Expire Trash messages by their folder save timestamp, as doveadm savedbefore does. Move
		// game-marked messages here before measuring the retention window.
		public static function purgeOlderThan($mailboxUser, $dias) {
			$antes = self::run(["search"], $mailboxUser,
				["mailbox", self::TRASH, "savedbefore", ((int)$dias) . "d"]);
			if ($antes === null || trim($antes) === "") return 0;

			$uids = [];
			foreach (preg_split('/\r?\n/', trim($antes)) as $linha) {
				$partes = preg_split('/\s+/', trim($linha));
				$ultimo = end($partes);
				if (ctype_digit((string)$ultimo)) $uids[] = (int)$ultimo;
			}
			if (empty($uids)) return 0;

			$r = self::run(["expunge"], $mailboxUser,
				["mailbox", self::TRASH, "uid", implode(",", $uids)]);
			return $r === null ? 0 : count($uids);
		}

		public static function purge($mailboxUser, $ids) {
			$porPasta = [];
			foreach ((array)$ids as $id) {
				$p = self::splitId($id);
				if ($p !== null) $porPasta[$p[0]][] = $p[1];
			}
			$n = 0;
			foreach ($porPasta as $folder => $uids) {
				$r = self::run(["expunge"], $mailboxUser,
					["mailbox", $folder, "uid", implode(",", $uids)]);
				if ($r !== null) $n += count($uids);
			}
			return $n;
		}

		public static function markRead($mailboxUser, $id, $lida = true) {
			$p = self::splitId($id);
			if ($p === null) return false;
			return self::run(["flags", $lida ? "add" : "remove"], $mailboxUser,
				[self::WEB_READ, "mailbox", $p[0], "uid", (string)$p[1]]) !== null;
		}

		public static function isRead($mailboxUser, $id) {
			$p = self::splitId($id);
			if ($p === null) return false;
			$out = self::run(["fetch"], $mailboxUser,
				["flags", "mailbox", $p[0], "uid", (string)$p[1]]);
			return $out !== null && stripos($out, self::WEB_READ) !== false;
		}

		// doveadm emits text: followed by the first message line on that same line. Skipping to
		// the next newline would discard a real header when the first line is not a disposable
		// Return-Path.
		private static function afterTextMarker($texto) {
			$marca = "text: ";
			$pos = strpos($texto, "\n" . $marca);
			if ($pos !== false) {
				$raw = substr($texto, $pos + 1 + strlen($marca));
			} elseif (strncmp($texto, $marca, strlen($marca)) === 0) {
				$raw = substr($texto, strlen($marca));
			} else {
				return null;
			}
			// Strip only the extra newline added by doveadm fetch, not the message own final newline;
			// otherwise every message gains a blank line.
			if ($raw !== "" && substr($raw, -1) === "\n") $raw = substr($raw, 0, -1);
			return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
		}

		// Use [ \t]* instead of \s*: in multiline mode the latter crosses line boundaries and
		// assigns the next header value to an empty field.
		private static function header($bloco, $campo) {
			if (!preg_match('/^' . preg_quote($campo, "/") . ':[ \t]*(.*)$/mi', $bloco, $m)) return "";
			return trim($m[1], " <>\r\n\t");
		}

		// For webmail display, use From when delivery shaping has removed Return-Path. The latter
		// is normally the stronger envelope identity, but must not be sent to the Game Boy.
		private static function remetente($bloco) {
			$rp = self::header($bloco, "hdr.return-path");
			if ($rp !== "") return $rp;

			// Do not trim angle brackets here. header() trims them for Return-Path, but doing so for
			// From removes its closing > and prevents address extraction.
			if (!preg_match('/^hdr\.from:[ \t]*(.*)$/mi', $bloco, $m)) return "";
			$from = trim($m[1]);

			// Modern client form: Display Name <a@b>.
			if (preg_match('/<([^>]+)>/', $from, $e)) return trim($e[1]);
			// Mobile Adapter form: a@b (Display Name). Extract the address before the comment.
			$from = trim(preg_replace('/\s*\(.*$/s', "", $from));
			return $from;
		}

		// Prefer the original message Date over delivery time, especially for older messages
		// delivered together during migration.
		private static function when($bloco) {
			$d = self::header($bloco, "hdr.date");
			if ($d === "") $d = self::header($bloco, "date.received");
			$t = strtotime($d);
			return $t ? date("Y-m-d H:i:s", $t) : date("Y-m-d H:i:s");
		}
	}
?>
