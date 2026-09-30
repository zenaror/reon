<?php
	// Times for people to read in the admin panel.
	//
	// The server, PHP, MySQL, the logs and every timer run in UTC, on purpose:
	// the game's day boundaries, the retention windows and the nightly jobs all
	// hang on it, and changing the machine's zone would move them by hours. So
	// nothing is stored or scheduled in local time; it is only *shown* in it,
	// with the UTC value on hover.
	class DisplayTimeUtil {

		// No daylight saving in this zone since 2019, so a fixed -03:00.
		const ZONE = "America/Sao_Paulo";

		// Any of the shapes the panel meets: journalctl's "2026-09-29 10:00:00+0000",
		// PHP's "29-Sep-2026 10:00:00 UTC", the activity log's "... UTC", and
		// systemd's "Thu 2026-10-01 05:10:00 UTC". Null when it is not a time
		// (systemd's "n/a", an empty string, a log line that has none).
		public static function parse($raw) {
			$raw = trim((string)$raw);
			if ($raw === "" || !preg_match('/\d/', $raw)) return null;
			try {
				return new DateTimeImmutable($raw);
			} catch (Exception $e) {
				return null;
			}
		}

		// "2026-09-30 14:11:43" in the display zone, or null.
		public static function local($raw) {
			$t = self::parse($raw);
			return $t ? $t->setTimezone(new DateTimeZone(self::ZONE))->format("Y-m-d H:i:s") : null;
		}

		// "2026-09-30 17:11:43 UTC", or null.
		public static function utc($raw) {
			$t = self::parse($raw);
			return $t ? $t->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s")." UTC" : null;
		}
	}
?>
