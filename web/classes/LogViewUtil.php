<?php
	// Turns the text of a log into rows the admin Logs page can draw: time,
	// where it came from, a level, a component and the message, with a stack
	// trace folded away. Two sources, both written by this project or by PHP:
	//
	//   journal  `journalctl --output short-iso`:  2026-09-29T10:00:00+0000 host proc[12]: {json}
	//   file     php-error.log:                     [29-Sep-2026 10:00:00 UTC] {json}
	//
	// The JSON is what lib/log.js and LogUtil write. Anything else is kept as
	// it came: a line that is not JSON is shown as plain text, with a level
	// only when PHP itself says one ("PHP Fatal error: ..."). A line that
	// does not start with a timestamp at all (a stack trace) belongs to the
	// entry above it.
	require_once(__DIR__."/DisplayTimeUtil.php");

	class LogViewUtil {

		const RANK = ["debug" => 0, "info" => 1, "warn" => 2, "error" => 3];

		// The file the "Site (PHP)" source reads. World-readable, owned by the
		// PHP user, rotated by /etc/logrotate.d/reon.
		const PHP_LOG = "/var/log/reon/php-error.log";

		// What people do (ActivityLog.php / lib/activity.js). Lines carry their
		// own "ts", and an "event" that the Logs page can filter by group.
		const ACTIVITY_LOG = "/var/log/reon/activity.log";
		const EVENT_GROUPS = [
			"accounts" => ["signup", "signup-requested", "login", "login-failed", "password-changed",
			               "password-reset-requested", "password-reset", "game-password-rerolled",
			               "email-changed", "account-deleted"],
			"game"     => ["game-login", "game-login-failed", "download", "upload", "ranking", "utility", "game-disabled"],
			"trades"   => ["trade"],
		];

		// Last $entries entries of the PHP log at or above $minLevel. Reads
		// only the tail of the file (it can be megabytes), so a very old
		// entry is out of reach by design; null when the file is unreadable.
		public static function phpLogText($bytes = 1048576, $path = self::PHP_LOG) {
			$f = @fopen($path, "rb");
			if (!$f) return null;
			$size = (int)@filesize($path);
			if ($size > $bytes) {
				fseek($f, $size - $bytes);
				fgets($f); // drop the line cut in half
			}
			$text = stream_get_contents($f);
			fclose($f);
			return (string)$text;
		}

        // Read recent rotations too: the current file can be empty after logrotate.
        // Bounded per-file reads; gzip rotations are streamed with a bounded tail.
        public static function historyText($path, $bytes = 1048576) {
            $parts = []; $readable = false;
            for ($n = 14; $n >= 0; $n--) {
                $file = $n ? $path.'.'.$n : $path;
                if (!is_file($file) && is_file($file.'.gz')) $file .= '.gz';
                if (!is_readable($file)) continue;
                $readable = true;
                if (str_ends_with($file, '.gz')) {
                    $f = @gzopen($file, 'rb'); if (!$f) continue;
                    $tail = '';
                    while (!gzeof($f)) {
                        $tail .= gzread($f, 65536);
                        if (strlen($tail) > $bytes) { $tail = substr($tail, -$bytes); $cut = strpos($tail, "\n"); if ($cut !== false) $tail = substr($tail, $cut + 1); }
                    }
                    gzclose($f); $parts[] = $tail;
                } else $parts[] = self::phpLogText($bytes, $file) ?? '';
            }
            return $readable ? implode("\n", $parts) : null;
        }

		// $kind: "journal" or "file". $minLevel: "" (all), "warn" or "error".
		// Returns at most $limit matching entries, newest first.
		public static function parse($text, $kind, $minLevel = "", $limit = 200, $group = "", $game = "", $from = "", $to = "") {
			$entries = [];
			foreach (preg_split('/\R/', (string)$text) as $line) {
				if ($line === "" || strpos($line, "-- No entries --") === 0 || strpos($line, "-- Boot ") === 0) continue;

				$time = $source = null;
				$rest = $line;
				if ($kind === "journal" && preg_match('/^(\d{4}-\d\d-\d\dT[\d:]+[+-]\d\d:?\d\d)\s+\S+\s+([^\s\[:]+)(?:\[\d+\])?:\s?(.*)$/', $line, $m)) {
					[$time, $source, $rest] = [str_replace('T', ' ', $m[1]), $m[2], $m[3]];
				} elseif ($kind === "file" && $line[0] === "{") {
					// The activity log has no PHP prefix; its lines carry "ts".
					$j = json_decode($line, true);
					if (is_array($j) && isset($j["ts"])) {
						$entries[] = self::fromMessage(str_replace(["T", "Z"], [" ", " UTC"], (string)$j["ts"]), null, $line);
						continue;
					}
					$entries[] = self::fromMessage(null, null, $line);
					continue;
				} elseif ($kind === "file" && preg_match('/^\[(\d\d-\w{3}-\d{4} [\d:]+ \w+)\]\s?(.*)$/', $line, $m)) {
					[$time, $rest] = [$m[1], $m[2]];
				} else {
					// No timestamp: a continuation of the previous entry.
					if ($entries) $entries[count($entries) - 1]["extra"] .= ($entries[count($entries) - 1]["extra"] === "" ? "" : "\n") . $line;
					else $entries[] = self::entry("", null, "", "", $line, "");
					continue;
				}
				$entries[] = self::fromMessage($time, $source, $rest);
			}

			if ($group !== "" && isset(self::EVENT_GROUPS[$group])) {
				$want = self::EVENT_GROUPS[$group];
				$entries = array_values(array_filter($entries, function ($e) use ($want) {
					return in_array($e["event"] ?? "", $want, true);
				}));
			}

			if ($minLevel !== "" && isset(self::RANK[$minLevel])) {
				$min = self::RANK[$minLevel];
				$entries = array_values(array_filter($entries, function ($e) use ($min, $kind) {
					// A journal line with no level of its own was already
					// filtered by journalctl -p; keep it.
					if ($e["level"] === "") return $kind === "journal";
					return self::RANK[$e["level"]] >= $min;
				}));
			}
			// Filter before limiting, including raw view. Dates are operator-local days.
            $entries = array_values(array_filter($entries, function ($e) use ($game, $from, $to) {
                if ($game !== '' && ($e['game_id'] ?? '') !== strtoupper($game)) return false;
                $stamp = strtotime($e['time'] ?? '');
                if ($from !== '' && ($stamp === false || $stamp < (new DateTimeImmutable($from.' 00:00:00', new DateTimeZone('America/Sao_Paulo')))->getTimestamp())) return false;
                if ($to !== '' && ($stamp === false || $stamp >= (new DateTimeImmutable($to.' 00:00:00', new DateTimeZone('America/Sao_Paulo')))->modify('+1 day')->getTimestamp())) return false;
                return true;
            }));
            // Equal timestamps retain reverse append order.
            $entries = array_reverse($entries);
            usort($entries, fn($a, $b) => (strtotime($b['time'] ?? '') ?: 0) <=> (strtotime($a['time'] ?? '') ?: 0));
            return array_slice($entries, 0, max(1, (int)$limit));
		}

		private static function fromMessage($time, $source, $msg) {
			if ($msg !== "" && $msg[0] === "{") {
				$j = json_decode($msg, true);
				if (is_array($j) && isset($j["level"], $j["msg"])) {
					$extra = "";
					if (isset($j["error"]["stack"])) $extra = (string)$j["error"]["stack"];
					elseif (isset($j["error"]["message"])) $extra = (string)$j["error"]["message"];
					$level = isset(self::RANK[$j["level"]]) ? $j["level"] : "";
					// Any other field (an activity event's account, path, status...)
					// is shown after the message as key=value.
					$msgText = (string)$j["msg"];
					$kv = [];
					foreach ($j as $k => $v) {
						if (in_array($k, ["ts", "level", "component", "msg", "error", "event"], true) || is_array($v)) continue;
						$kv[] = $k."=".$v;
					}
					if ($kv) $msgText .= "  ".implode(" ", $kv);
					$e = self::entry($time, $source, $level, (string)($j["component"] ?? ""), $msgText, $extra);
					if (isset($j["event"])) $e["event"] = (string)$j["event"];
                    $game = strtoupper((string)($j['game_id'] ?? ''));
                    if ($game === '' && preg_match('#(?:AGB|CGB|SGB)-[A-Z0-9]{4}#i', (string)($j['path'] ?? ''), $m)) $game = strtoupper($m[0]);
                    $e['game_id'] = $game;
					return $e;
				}
			}
			$level = "";
			if (preg_match('/^PHP (Fatal error|Parse error|Recoverable fatal error)/', $msg)) $level = "error";
			elseif (preg_match('/^PHP (Warning|Deprecated|Notice)/', $msg)) $level = "warn";
			return self::entry($time, $source, $level, "", $msg, "");
		}

		private static function entry($time, $source, $level, $component, $msg, $extra) {
			// "time" is what the log said (UTC); the page shows the local form and
			// keeps the original in the tooltip.
			return ["time" => $time, "time_local" => DisplayTimeUtil::local($time), "time_utc" => DisplayTimeUtil::utc($time),
			        "source" => $source, "level" => $level,
			        "component" => $component, "msg" => $msg, "extra" => $extra];
		}

		// journalctl -p value for a level filter, or null for "all".
		public static function journalPriority($minLevel) {
			return ["warn" => "4", "error" => "3"][$minLevel] ?? null;
		}
	}
?>
