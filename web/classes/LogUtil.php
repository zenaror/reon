<?php
	// Leveled log line for the site, the PHP twin of lib/log.js: one JSON
	// object per line, {"level","component","msg"}, written with error_log()
	// so it lands in /var/log/reon/php-error.log with the timestamp PHP-FPM
	// already prepends. Only the shape changes; where it goes and how long it
	// is kept do not.
	//
	//   LogUtil::error("mail", "sendmail exited 75");
	//   LogUtil::warn("trade_corner", "banned player name account_id=3");
	//
	// error/warn always go out. info is on by default; debug only when the
	// LOG_LEVEL environment variable says "debug" (PHP-FPM keeps the pool's
	// environment, so set it with `env[LOG_LEVEL] = debug` in the pool).
	class LogUtil {

		const RANK = ["debug" => 0, "info" => 1, "warn" => 2, "error" => 3];

		public static function debug($component, $msg) { self::write("debug", $component, $msg); }
		public static function info($component, $msg)  { self::write("info", $component, $msg); }
		public static function warn($component, $msg)  { self::write("warn", $component, $msg); }
		public static function error($component, $msg) { self::write("error", $component, $msg); }

		// Writes at any level, ignoring LOG_LEVEL. For a caller that has its
		// own switch (bxt_debug_log: the runtime "debug_log_enabled" flag).
		public static function emit($level, $component, $msg) {
			$line = json_encode(
				["level" => $level, "component" => (string)$component, "msg" => (string)$msg],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
			);
			error_log($line === false ? "{\"level\":\"$level\",\"msg\":\"(unencodable)\"}" : $line);
		}

		private static function write($level, $component, $msg) {
			if (self::RANK[$level] < self::threshold()) return;
			self::emit($level, $component, $msg);
		}

		private static function threshold() {
			static $t = null;
			if ($t === null) {
				$env = strtolower((string)getenv("LOG_LEVEL"));
				$t = self::RANK[$env] ?? self::RANK["info"];
			}
			return $t;
		}
	}
