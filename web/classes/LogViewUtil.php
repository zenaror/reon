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
	class LogViewUtil {

		const RANK = ["debug" => 0, "info" => 1, "warn" => 2, "error" => 3];

		// The file the "Site (PHP)" source reads. World-readable, owned by the
		// PHP user, rotated by /etc/logrotate.d/reon.
		const PHP_LOG = "/var/log/reon/php-error.log";

		// Last $entries entries of the PHP log at or above $minLevel. Reads
		// only the tail of the file (it can be megabytes), so a very old
		// entry is out of reach by design; null when the file is unreadable.
		public static function phpLogText($bytes = 1048576) {
			$f = @fopen(self::PHP_LOG, "rb");
			if (!$f) return null;
			$size = (int)@filesize(self::PHP_LOG);
			if ($size > $bytes) {
				fseek($f, $size - $bytes);
				fgets($f); // drop the line cut in half
			}
			$text = stream_get_contents($f);
			fclose($f);
			return (string)$text;
		}

		// $kind: "journal" or "file". $minLevel: "" (all), "warn" or "error".
		// Returns at most $limit of the newest entries, oldest first.
		public static function parse($text, $kind, $minLevel = "", $limit = 200) {
			$entries = [];
			foreach (preg_split('/\R/', (string)$text) as $line) {
				if ($line === "" || strpos($line, "-- No entries --") === 0 || strpos($line, "-- Boot ") === 0) continue;

				$time = $source = null;
				$rest = $line;
				if ($kind === "journal" && preg_match('/^(\d{4}-\d\d-\d\dT[\d:]+[+-]\d\d:?\d\d)\s+\S+\s+([^\s\[:]+)(?:\[\d+\])?:\s?(.*)$/', $line, $m)) {
					[$time, $source, $rest] = [str_replace('T', ' ', $m[1]), $m[2], $m[3]];
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

			if ($minLevel !== "" && isset(self::RANK[$minLevel])) {
				$min = self::RANK[$minLevel];
				$entries = array_values(array_filter($entries, function ($e) use ($min, $kind) {
					// A journal line with no level of its own was already
					// filtered by journalctl -p; keep it.
					if ($e["level"] === "") return $kind === "journal";
					return self::RANK[$e["level"]] >= $min;
				}));
			}
			return array_slice($entries, -$limit);
		}

		private static function fromMessage($time, $source, $msg) {
			if ($msg !== "" && $msg[0] === "{") {
				$j = json_decode($msg, true);
				if (is_array($j) && isset($j["level"], $j["msg"])) {
					$extra = "";
					if (isset($j["error"]["stack"])) $extra = (string)$j["error"]["stack"];
					elseif (isset($j["error"]["message"])) $extra = (string)$j["error"]["message"];
					$level = isset(self::RANK[$j["level"]]) ? $j["level"] : "";
					return self::entry($time, $source, $level, (string)($j["component"] ?? ""), (string)$j["msg"], $extra);
				}
			}
			$level = "";
			if (preg_match('/^PHP (Fatal error|Parse error|Recoverable fatal error)/', $msg)) $level = "error";
			elseif (preg_match('/^PHP (Warning|Deprecated|Notice)/', $msg)) $level = "warn";
			return self::entry($time, $source, $level, "", $msg, "");
		}

		private static function entry($time, $source, $level, $component, $msg, $extra) {
			return ["time" => $time, "source" => $source, "level" => $level,
			        "component" => $component, "msg" => $msg, "extra" => $extra];
		}

		// journalctl -p value for a level filter, or null for "all".
		public static function journalPriority($minLevel) {
			return ["warn" => "4", "error" => "3"][$minLevel] ?? null;
		}
	}
?>
