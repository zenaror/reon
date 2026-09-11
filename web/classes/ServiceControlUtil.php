<?php
	// Starting, stopping, restarting, rescheduling and reading the log of the
	// server's own units, from the admin panel.
	//
	// The web server does not get to run systemctl. Everything privileged
	// goes through one helper, `/usr/local/sbin/reon-admin-ctl`, which the
	// server has to be told to allow (see setup-script/5-admin-control.sh):
	// a single fixed sudoers entry for a single script that accepts a verb
	// and a unit name from a list it holds itself. The web app therefore
	// cannot ask for anything the helper does not already offer, and the
	// list of what can be touched lives on the server rather than in a form
	// field.
	//
	// When the helper is not installed, nothing is attempted and the panel
	// says so. A control that silently does nothing is worse than one that
	// is plainly not available yet.
	class ServiceControlUtil {

		private static $instance;

		const HELPER = "/usr/local/sbin/reon-admin-ctl";

		// What the panel offers.
		//   daemon   runs continuously
		//   job      a one-shot run behind a timer: it can be run now,
		//            switched off, and rescheduled
		//
		// The label here is what we call the thing; the one-line description
		// shown beside it is read from the unit itself at display time, so it
		// cannot drift from what systemd actually has.
		const UNITS = [
			"reon-mail"             => ["kind" => "daemon", "label" => "REON mail"],
			"reon-mobile-relay"     => ["kind" => "daemon", "label" => "Mobile relay"],
			"reon-relay-policy"     => ["kind" => "daemon", "label" => "External mail policy"],
			"nginx"                 => ["kind" => "daemon", "label" => "Web server"],
			"postfix"               => ["kind" => "daemon", "label" => "Postfix"],
			"reon-pokemon-exchange" => ["kind" => "job",    "label" => "Trade Corner"],
			"reon-pokemon-battle"   => ["kind" => "job",    "label" => "Battle Tower"],
			"reon-auto-schedule"    => ["kind" => "job",    "label" => "Auto schedule"],
			"reon-mail-bottle"      => ["kind" => "job",    "label" => "Mail de Cute"],
			"reon-mail-trash-purge" => ["kind" => "job",    "label" => "Mail trash purge"],
			"reon-service-status"   => ["kind" => "job",    "label" => "Service status probe"],
		];

		// Stopping nginx from a page nginx is serving is a one-way door: the
		// panel that would start it again goes down with it. The helper
		// refuses it too -- this is only so the button is not offered.
		const NEVER_STOP = ["nginx"];

		// The verbs a request may name. Anything else never reaches the
		// helper.
		const VERBS = ["start", "stop", "restart", "run", "enable", "disable", "timer-set", "timer-reset"];

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new ServiceControlUtil();
			}
			return self::$instance;
		}

		public function available() {
			return is_file(self::HELPER) && is_executable(self::HELPER);
		}

		public static function isKnown($unit) {
			return isset(self::UNITS[$unit]);
		}

		public static function isJob($unit) {
			return self::isKnown($unit) && self::UNITS[$unit]["kind"] === "job";
		}

		public static function canStop($unit) {
			return self::isKnown($unit) && !in_array($unit, self::NEVER_STOP, true);
		}

		// Everything the Services page needs, in as few processes as it can
		// be had. Reading state needs no privileges, so it is asked of
		// systemctl directly -- one call for every service, and one more for
		// every timer, instead of two calls per row.
		public function overview() {
			$units = array_keys(self::UNITS);

			$services = $this->show(array_map(function ($u) { return $u . ".service"; }, $units));
			$jobs = array_values(array_filter($units, function ($u) { return self::isJob($u); }));
			$timers = $this->show(array_map(function ($u) { return $u . ".timer"; }, $jobs));

			$out = [];
			foreach (self::UNITS as $name => $meta) {
				$service = $services[$name . ".service"] ?? [];
				$timer = $timers[$name . ".timer"] ?? [];

				$active = $service["ActiveState"] ?? "";
				$state = "unknown";
				if ($active === "active") $state = "up";
				// A one-shot job sits "inactive" between runs, which is its
				// healthy state, not a fault. Calling that "down" would put a
				// red pill beside every cron on the panel.
				elseif ($active === "inactive") $state = self::isJob($name) ? "idle" : "down";
				elseif ($active !== "") $state = "down";

				$row = [
					"name" => $name,
					"label" => $meta["label"],
					"kind" => $meta["kind"],
					"description" => $service["Description"] ?? "",
					"state" => $state,
					"can_stop" => self::canStop($name),
					"timer" => null,
				];

				if (self::isJob($name)) {
					$row["timer"] = [
						// A timer that was never installed reports nothing at
						// all, which is not the same as one that is switched
						// off -- the page says so rather than showing "off".
						"present" => isset($timer["Id"]),
						"enabled" => ($timer["UnitFileState"] ?? "") === "enabled",
						"active" => ($timer["ActiveState"] ?? "") === "active",
						"schedule" => $this->calendarOf($timer["TimersCalendar"] ?? ""),
						"next" => $timer["NextElapseUSecRealtime"] ?? "",
					];
				}

				$out[] = $row;
			}
			return $out;
		}

		// TimersCalendar reads "{ OnCalendar=*-*-* *:00/15:00 ; next_elapse=... }".
		private function calendarOf($value) {
			if (preg_match('/OnCalendar=(.*?)\s*;\s*next_elapse=/', (string)$value, $m)) {
				return trim($m[1]);
			}
			return "";
		}

		// systemctl show over many units at once: property blocks in order,
		// separated by blank lines, each carrying its own Id.
		private function show(array $units) {
			if ($units === []) return [];

			$cmd = array_merge(
				["/usr/bin/systemctl", "show"],
				$units,
				["-p", "Id", "-p", "ActiveState", "-p", "UnitFileState",
				 "-p", "Description", "-p", "TimersCalendar", "-p", "NextElapseUSecRealtime"]
			);
			$out = $this->run($cmd);

			$found = [];
			foreach (explode("\n\n", $out["stdout"]) as $block) {
				$props = [];
				foreach (explode("\n", trim($block)) as $line) {
					$at = strpos($line, "=");
					if ($at === false) continue;
					$props[substr($line, 0, $at)] = substr($line, $at + 1);
				}
				if (isset($props["Id"])) $found[$props["Id"]] = $props;
			}
			return $found;
		}

		// Runs one verb against one unit. Returns [ok, detail].
		//
		// $argument is only ever the schedule for "timer-set"; every other
		// verb takes none, and the helper ignores what it is not expecting.
		public function act($unit, $verb, $argument = "") {
			if (!self::isKnown($unit)) return [false, "unknown unit"];
			if (!in_array($verb, self::VERBS, true)) return [false, "unknown action"];
			if ($verb === "stop" && !self::canStop($unit)) return [false, "refused"];
			if (in_array($verb, ["run", "enable", "disable", "timer-set", "timer-reset"], true) && !self::isJob($unit)) {
				return [false, "not a scheduled job"];
			}
			if (!$this->available()) return [false, "helper-missing"];

			$cmd = ["/usr/bin/sudo", "-n", self::HELPER, $verb, $unit];
			if ($verb === "timer-set") $cmd[] = (string)$argument;

			$out = $this->run($cmd);
			$detail = trim($out["stdout"] . " " . $out["stderr"]);
			return [$out["code"] === 0, $detail === "" ? "ok" : $detail];
		}

		public function journal($unit, $lines = 200) {
			if (!self::isKnown($unit)) return "";
			if (!$this->available()) return null;

			$lines = max(10, min(1000, (int)$lines));
			$out = $this->run(["/usr/bin/sudo", "-n", self::HELPER, "log", $unit, (string)$lines]);
			return $out["stdout"] . $out["stderr"];
		}

		// Always an argument list, never a shell string: nothing here can
		// become part of a command, however a unit name or a schedule got
		// here.
		private function run(array $cmd) {
			$spec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) return ["code" => 127, "stdout" => "", "stderr" => "could not run"];

			fclose($pipes[0]);
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$code = proc_close($proc);

			return ["code" => $code, "stdout" => (string)$stdout, "stderr" => (string)$stderr];
		}
	}
?>
