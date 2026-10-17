<?php
	// Building a Pokémon News issue.
	//
	// A news issue is not a document: it is a program the game interprets,
	// assembled from rgbds source. `third_party/pokecrystal-news-maker` is
	// the real toolchain for it, carried as a submodule rather than
	// reimplemented -- a hand-rolled encoder for a format we have exactly
	// seven real samples of would be a guess dressed as a feature.
	//
	// So an issue here is a **template plus substitutions**, not source
	// generated from nothing. All seven historical issues share one skeleton
	// of ~600 lines -- menus, button scripts, ranking tables, a minigame --
	// and differ in four things:
	//
	//   the headline        per language
	//   the article text    per language
	//   three ranking categories   passed to the assembler with -D
	//   the minigame               likewise
	//
	// Those four are what this class lets someone set. Everything else stays
	// exactly as it is in a known-good issue, which is the whole reason the
	// output is trustworthy.
	//
	// Nothing here needs privilege: the assembler runs as the web user, in a
	// temporary directory, with argument lists rather than shell strings.
	class NewsMakerUtil {

		private static $instance;

		// Languages the toolchain builds, and how they map to REON's region
		// letters. e/p/u all take the English build: the game text is the
		// same and the region letter is REON's, not the assembler's.
		const LANGUAGES = ["j" => "J", "e" => "E", "d" => "D", "f" => "F", "i" => "I", "s" => "S"];
		const REGION_LANGUAGE = [
			"j" => "j", "e" => "e", "p" => "e", "u" => "e",
			"d" => "d", "f" => "f", "i" => "i", "s" => "s",
		];

		// A textbox shows two lines at a time: the first line of a paragraph
		// opens it, the second sits under it, and the rest scroll. Same
		// macros pokecrystal uses everywhere.
		const TEXT_FIRST = "text";
		const TEXT_SECOND = "line";
		const TEXT_MORE = "cont";
		const TEXT_PARAGRAPH = "para";

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new NewsMakerUtil();
			}
			return self::$instance;
		}

		public function sourceDir() {
			return realpath(dirname(__DIR__, 2) . "/third_party/pokecrystal-news-maker");
		}

		// Where auto-schedule looks for custom articles. Built issues land
		// here, so scheduling them needs no further step.
		public function articlesDir() {
			return realpath(dirname(__DIR__, 2) . "/app/auto-schedule/files/bxt_custom");
		}

		public function rgbasm() { return $this->which("rgbasm"); }
		public function rgblink() { return $this->which("rgblink"); }
		public function python() { return $this->which("python3"); }

		private function which($name) {
			foreach (["/usr/bin/", "/usr/local/bin/", "/bin/"] as $dir) {
				if (is_executable($dir . $name)) return $dir . $name;
			}
			return null;
		}

		// Everything that has to be present before the panel offers to build.
		// Reported as a list rather than a boolean: "not available" sends
		// somebody hunting, "rgbasm not found" sends them to apt.
		public function missing() {
			$missing = [];
			if ($this->rgbasm() === null) $missing[] = "rgbasm";
			if ($this->rgblink() === null) $missing[] = "rgblink";
			if ($this->python() === null) $missing[] = "python3";
			if ($this->sourceDir() === false) $missing[] = "third_party/pokecrystal-news-maker";
			if ($this->articlesDir() === false) $missing[] = "app/auto-schedule/files/bxt_custom";
			return $missing;
		}

		public function available() {
			return $this->missing() === [];
		}

		// The assembler's version, shown in the panel so that a build that
		// starts behaving differently after a system upgrade is visible
		// rather than mysterious.
		public function toolVersion() {
			if ($this->rgbasm() === null) return null;
			$out = $this->run([$this->rgbasm(), "--version"]);
			return trim($out["stdout"]) ?: null;
		}

		// The skeletons available to build on: the real issues, which are
		// known to assemble and known to work.
		public function templates() {
			$dir = $this->sourceDir();
			if ($dir === false) return [];
			$found = [];
			foreach (glob($dir . "/*_issue.asm") as $path) {
				$found[] = basename($path);
			}
			sort($found);
			return $found;
		}

		// The minigames an issue can carry, with the name each one calls
		// itself.
		//
		// Every minigame declares its own title through a `minigame_name`
		// macro, per language -- "#RAP IT UP!", "TALL OR SHORT?", "EASY #MON
		// MAZE". Showing `game_cry_memory` instead was showing the file name
		// to somebody writing a magazine.
		public function minigames() {
			$dir = $this->sourceDir();
			if ($dir === false) return [];

			$found = [];
			foreach (glob($dir . "/minigame/*.asm") as $path) {
				$name = basename($path);
				// The debug builds are in the tree but are not something to
				// put in front of a player.
				if (strpos($name, "_debug") !== false) continue;

				$found["minigame/" . $name] = $this->declaredName($path) ?: $name;
			}

			// Three of them call themselves "POKéMON QUIZ!". A list with the
			// same label three times is a list you cannot choose from, so a
			// repeated name gets its file's own word beside it.
			$counts = array_count_values($found);
			foreach ($found as $file => $label) {
				if (($counts[$label] ?? 0) < 2) continue;
				$stem = preg_replace('/^(minigame\/)?(game_|pkmnquiz_|event_)?/', "", $file);
				$stem = str_replace(["_", ".asm"], [" ", ""], $stem);
				$found[$file] = $label . " (" . trim($stem) . ")";
			}

			asort($found);
			return $found;
		}

		// The English title a minigame declares for itself, or null.
		private function declaredName($path) {
			$text = (string)@file_get_contents($path);
			$at = strpos($text, "MACRO minigame_name");
			if ($at === false) return null;

			// The first English line after the macro opens is the title.
			$window = substr($text, $at, 800);
			if (preg_match('/lang E,\s*(?:db|text)\s+"([^"]*)"/', $window, $m)) {
				return $this->readable($m[1]);
			}
			return null;
		}

		// The categories the rankings can be about, with the name the game
		// prints for each -- read from ranking_types.asm, where each carries
		// a RANKING_<KEY>_NAME per language.
		//
		// Worth saying where this is *not*: the cartridge only ever tracked
		// the raw numbers, and the pretty label was composed by the service
		// when it made the article. So there is no name to dig out of a ROM;
		// the toolchain is where it lives.
		//
		// A category whose name is "?" is one of the reserved slots
		// (UNUSED_1/2/3) -- offered nowhere, because a form that lets someone
		// pick one produces an issue with a question mark on the screen.
		public function rankingCategories() {
			$dir = $this->sourceDir();
			if ($dir === false) return [];

			$text = (string)@file_get_contents($dir . "/ranking_types.asm");
			if ($text === "") return [];

			$found = [];
			$language = null;
			foreach (explode("\n", $text) as $line) {
				if (preg_match('/^\s*(?:IF|ELIF)\s+DEF\(_LANG_([A-Z])\)/', $line, $m)) {
					$language = $m[1];
					continue;
				}
				if (preg_match('/^\s*ENDC/', $line)) { $language = null; continue; }
				if ($language !== null && $language !== "E") continue;

				if (preg_match('/^\s*DEF\s+RANKING_([A-Z0-9_]+)_NAME\s+EQUS\s+"([^"]*)"/', $line, $m)) {
					$label = trim($m[2]);
					if ($label === "" || $label === "?") continue;
					$found[$m[1]] = $this->readable($label);
				}
			}
			asort($found);
			return $found;
		}

		// The game writes some things in its own shorthand. Expanded here so
		// a form does not ask somebody to recognise "#MON".
		private function readable($label) {
			return strtr(trim((string)$label), [
				"#MON" => "POKéMON",
				"#RAP" => "RAP",
				"#DEX" => "POKéDEX",
				"#MANIA" => "POKéMANIA",
				"<TRAINER>" => "TRAINER",
			]);
		}


		// ------------------------------------------------------- os prêmios

		// Every item a prize can be, as CONSTANT => label.
		//
		// Read from the toolchain's own item_constants.asm, so the list is
		// exactly what the cartridge knows and cannot drift from it. Three
		// kinds of line define an item there: the plain `const NAME`, and the
		// `add_tm` / `add_hm` macros, which prefix their argument.
		//
		// The `ITEM_xx` entries are dropped: they are the gaps left in the
		// table -- ids that exist and name nothing -- and handing one out
		// gives the player an item the game has no name or sprite for.
		public function items() {
			$dir = $this->sourceDir();
			if ($dir === false) return [];

			$text = (string)@file_get_contents(
				$dir . "/pokecrystal/constants/item_constants.asm");

			$found = [];
			foreach (explode("\n", $text) as $line) {
				// Anchored on purpose: inside the add_tm macro the body line
				// is `const TM_\1`, which an unanchored pattern would read as
				// an item called "TM_".
				if (preg_match('/^\s*const\s+([A-Z0-9_]+)\s*(?:;.*)?$/', $line, $m)) {
					$name = $m[1];
				} elseif (preg_match('/^\s*add_(tm|hm)\s+([A-Z0-9_]+)\s*(?:;.*)?$/', $line, $m)) {
					$name = strtoupper($m[1]) . "_" . $m[2];
				} else {
					continue;
				}

				if ($name === "NO_ITEM") continue;
				if (preg_match('/^ITEM_[0-9A-F]{2}$/', $name)) continue;
				$found[$name] = str_replace("_", " ", $name);
			}
			return $found;
		}

		// Disable known broken upstream minigames only while their exact error line remains in
		// source. Full-line markers expire automatically when fixed. The list is currently empty:
		// the three known assembly errors were repaired in the fork on 2026-09-11. Keep the
		// mechanism to report future incompatible sources before a user builds a complete edition.
		const BROKEN_MINIGAMES = [];

		// Return the source line preventing assembly, or NULL when the minigame builds.
		public function brokenMinigame($minigame) {
			$marker = self::BROKEN_MINIGAMES[$minigame] ?? null;
			if ($marker === null) return null;

			$dir = $this->sourceDir();
			if ($dir === false) return null;

			// Match the entire source line, not a substring. A missing-quote marker remains a prefix
			// after the closing quote is restored and would otherwise keep marking the repaired game
			// as broken.
			$text = (string)@file_get_contents($dir . "/" . $minigame);
			foreach (explode("\n", $text) as $line) {
				if (rtrim($line, "\r") === $marker) return $marker;
			}
			return null;
		}

		// List currently broken minigames so the editor does not offer reward choices for builds
		// that cannot succeed.
		public function brokenMinigames() {
			$found = [];
			foreach (array_keys($this->minigames()) as $minigame) {
				if ($this->brokenMinigame($minigame) !== null) $found[$minigame] = true;
			}
			return $found;
		}

		// Locate reward definitions in script order, including macro arguments rather than only
		// nsc_giveitem lines. game_personality uses quizresult argument 4 for six results;
		// game_cry_memory derives CRYSET1_BIG_PRIZE from def_cryset argument 6. Record the actual
		// source span where the item name is defined so replacements edit the meaningful argument.
		private function prizeSites($minigame) {
			$dir = $this->sourceDir();
			if ($dir === false || !isset($this->minigames()[$minigame])) return [];

			$lines = explode("\n", (string)@file_get_contents($dir . "/" . $minigame));
			$items = $this->items();

			// 1. Identify macro bodies to distinguish definitions from invocations.
			$macros = [];
			$open = null;
			foreach ($lines as $i => $line) {
				if (preg_match('/^\s*MACRO\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
					$open = ["name" => $m[1], "from" => $i];
					continue;
				}
				if ($open !== null && preg_match('/^\s*ENDM\b/', $line)) {
					$macros[$open["name"]] = ["from" => $open["from"], "to" => $i];
					$open = null;
				}
			}
			$inMacro = function ($i) use ($macros) {
				foreach ($macros as $name => $span) {
					if ($i >= $span["from"] && $i <= $span["to"]) return $name;
				}
				return null;
			};

			// 2. Determine whether each macro directly grants an argument item or
			// defines a constant resolved as an item later.
			$gives = [];
			$defines = [];
			foreach ($lines as $i => $line) {
				$owner = $inMacro($i);
				if ($owner === null) continue;
				if (preg_match('/^\s*nsc_giveitem\s+\\\\(\d)\b/', $line, $m)) {
					$gives[$owner] = (int)$m[1] - 1;
				}
				if (preg_match('/^\s*DEF\s+(\S+)\s+EQU\s+\\\\(\d)\s*$/', $line, $m)) {
					$defines[$owner][] = ["name" => $m[1], "arg" => (int)$m[2] - 1];
				}
			}

			// 3. Locate top-level invocations and the exact source span of each argument, preserving
			// surrounding alignment during replacement.
			$calls = [];
			$constants = [];
			foreach ($lines as $i => $line) {
				if ($inMacro($i) !== null) continue;
				if (!preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_]*)\s+(?=\S)/', $line, $m)) continue;
				$name = $m[2];
				if (!isset($macros[$name])) continue;

				$args = self::argumentSpans($line, strlen($m[0]));
				$calls[$name][] = ["line" => $i, "args" => $args];

				foreach ($defines[$name] ?? [] as $def) {
					if (!isset($args[$def["arg"]])) continue;
					// Resolve constant names from arguments: CRYSET\1_BIG_PRIZE with argument 1 becomes
					// CRYSET1_BIG_PRIZE.
					$constant = preg_replace_callback('/\\\\(\d)/',
						function ($c) use ($args) {
							return $args[(int)$c[1] - 1]["text"] ?? $c[0];
						}, $def["name"]);
					$constants[$constant] = [
						"line" => $i, "span" => $args[$def["arg"]],
						"value" => $args[$def["arg"]]["text"],
					];
				}
			}

			// 4. Collect rewards in script execution order.
			$found = [];
			foreach ($lines as $i => $line) {
				if (!preg_match('/^\s*nsc_giveitem\s+(.+)$/', $line, $m)) continue;

				$args = array_map("trim",
					explode(",", (string)preg_replace('/;.*$/', "", $m[1])));
				$first = $args[0];
				$quantity = count($args) >= 4 && ctype_digit($args[1]) ? (int)$args[1] : 1;
				$owner = $inMacro($i);

				if ($owner !== null) {
					// A macro-body reward expands once per invocation. No sound replacement is needed where
					// the minigame derives the sound from the item prefix itself.
					if (!isset($gives[$owner])) continue;
					$arg = $gives[$owner];
					foreach ($calls[$owner] ?? [] as $call) {
						if (!isset($call["args"][$arg])) continue;
						$found[] = self::prizeEntry($call["args"][$arg]["text"], $quantity,
							$call["line"], $call["args"][$arg], null, $items);
					}
					continue;
				}

				if (isset($items[$first])) {
					$spans = self::argumentSpans($line,
						strpos($line, "nsc_giveitem") + strlen("nsc_giveitem"));
					$found[] = self::prizeEntry($first, $quantity, $i, $spans[0],
						self::giftSoundLine($lines, $i), $items);
					continue;
				}

				if (isset($constants[$first])) {
					$c = $constants[$first];
					$found[] = self::prizeEntry($c["value"], $quantity, $c["line"], $c["span"],
						self::giftSoundLine($lines, $i), $items);
					continue;
				}

				// Expose unnamed reward forms rather than hiding them. An unfamiliar future minigame
				// should produce a visible diagnostic instead of silently losing a reward.
				$found[] = self::prizeEntry($first, $quantity, null, null, null, $items);
			}
			return $found;
		}

		private static function prizeEntry($item, $quantity, $line, $span, $sound, $items) {
			return [
				"item" => $item,
				"label" => $items[$item] ?? str_replace("_", " ", $item),
				"quantity" => $quantity,
				"fixed" => $line === null || !isset($items[$item]),
				"line" => $line, "span" => $span, "sound" => $sound,
			];
		}

		// Split comma-separated arguments from $at while retaining exact source spans so one
		// argument can be replaced without changing surrounding whitespace.
		private static function argumentSpans($line, $at) {
			$text = (string)preg_replace('/;.*$/', "", $line);
			$spans = [];
			$start = $at;
			$length = strlen($text);
			for ($i = $at; $i <= $length; $i++) {
				if ($i < $length && $text[$i] !== ",") continue;
				$raw = substr($text, $start, $i - $start);
				$lead = strlen($raw) - strlen(ltrim($raw));
				$spans[] = [
					"text" => trim($raw),
					"at" => $start + $lead,
					"length" => strlen(trim($raw)),
				];
				$start = $i + 1;
			}
			return $spans;
		}

		// Find the gift sound belonging to the reward at $at: the first sound after it and before
		// the next reward.
		private static function giftSoundLine($lines, $at) {
			$last = min($at + 12, count($lines) - 1);
			for ($i = $at + 1; $i <= $last; $i++) {
				if (strpos($lines[$i], "nsc_giveitem") !== false) return null;
				if (preg_match('/^\s*nsc_playsound\s+(?:SFX_GET_TM|SFX_ITEM)\s*$/', $lines[$i])) {
					return $i;
				}
			}
			return null;
		}

		// Present rewards to the editor without internal replacement offsets.
		public function prizes($minigame) {
			if ($this->brokenMinigame($minigame) !== null) return [];
			$shown = [];
			foreach ($this->prizeSites($minigame) as $one) {
				unset($one["line"], $one["span"], $one["sound"]);
				$shown[] = $one;
			}
			return $shown;
		}

		// Return minigame source with this edition rewards, or NULL to include the original tool
		// file without copying it. Replace gift sound with the item where needed; the upstream TM_
		// prefix rule distinguishes TM fanfare from ordinary item rewards.
		public function minigameWithPrizes($minigame, $chosen) {
			$dir = $this->sourceDir();
			if ($dir === false) return null;

			$sites = $this->prizeSites($minigame);
			if (!$sites) return null;

			$items = $this->items();
			$lines = explode("\n", (string)@file_get_contents($dir . "/" . $minigame));

			// Group edits by line: one def_cryset invocation can define both major rewards, and each
			// substitution shifts later offsets.
			$edits = [];
			foreach ($sites as $i => $site) {
				if ($site["fixed"]) continue;

				$want = trim((string)($chosen[$i] ?? ""));
				if ($want === "" || $want === $site["item"] || !isset($items[$want])) continue;

				$edits[$site["line"]][] = ["span" => $site["span"], "to" => $want];

				if ($site["sound"] !== null) {
					$lines[$site["sound"]] = (string)preg_replace(
						'/(nsc_playsound\s+)(?:SFX_GET_TM|SFX_ITEM)/',
						'${1}' . (preg_match('/^(?:TM|HM)_/', $want) ? "SFX_GET_TM" : "SFX_ITEM"),
						$lines[$site["sound"]]);
				}
			}
			if (!$edits) return null;

			foreach ($edits as $at => $list) {
				// Apply edits right to left so replacements of different lengths do not invalidate
				// remaining spans on the same line.
				usort($list, function ($a, $b) { return $b["span"]["at"] - $a["span"]["at"]; });
				foreach ($list as $edit) {
					$lines[$at] = substr_replace($lines[$at], $edit["to"],
						$edit["span"]["at"], $edit["span"]["length"]);
				}
			}

			return implode("\n", $lines);
		}

		// Every minigame's prize slots, for the panel: choosing a different
		// minigame changes which prizes exist, and the screen has to redraw
		// them without a round trip.
		public function prizesByMinigame() {
			$found = [];
			foreach (array_keys($this->minigames()) as $minigame) {
				$found[$minigame] = $this->prizes($minigame);
			}
			return $found;
		}

		// The prizes this issue asks for that no item answers to, as readable
		// "slot: value" pairs. Empty when everything checks out.
		//
		// A slot the minigame does not offer is refused too: `game_personality`
		// builds its prize from a macro parameter and `game_cry_memory` names
		// constants, and accepting a choice for one of those would save a
		// setting the build is going to ignore.
		public function checkPrizes($issue) {
			$chosen = array_values((array)($issue["prizes"] ?? []));
			if (!$chosen) return [];

			$slots = $this->prizes((string)($issue["minigame"] ?? ""));
			$items = $this->items();

			$bad = [];
			foreach ($chosen as $i => $item) {
				$item = trim((string)$item);
				if ($item === "") continue;
				if (!isset($slots[$i]) || $slots[$i]["fixed"] || !isset($items[$item])) {
					$bad[] = ($i + 1) . ": " . $item;
				}
			}
			return $bad;
		}

		// ------------------------------------------------------------ text		// ------------------------------------------------------------ text

		// Plain text, as a person types it, into the macros a textbox wants.
		// Blank lines separate paragraphs; within one, the first line opens
		// the box, the second sits beneath it, the rest scroll.
		public function textMacros($body) {
			$body = str_replace("\r\n", "\n", (string)$body);
			$lines = [];
			$first = true;
			$position = 0;

			foreach (explode("\n", $body) as $raw) {
				$line = rtrim($raw);
				if (trim($line) === "") {
					// A run of blank lines is still one paragraph break.
					if ($position !== 0) $position = 0;
					continue;
				}

				if ($position === 0) {
					$macro = $first ? self::TEXT_FIRST : self::TEXT_PARAGRAPH;
					$first = false;
				} elseif ($position === 1) {
					$macro = self::TEXT_SECOND;
				} else {
					$macro = self::TEXT_MORE;
				}
				$position++;
				$lines[] = [$macro, $line];
			}
			return $lines;
		}

		// --------------------------------------------------------- rendering

		// Produces the issue source: a known-good template with three regions
		// replaced. Nothing else is touched, which is why the result can be
		// trusted -- the menus, button scripts and ranking tables are the
		// ones that shipped.
		//
		// $issue keys:
		//   template   which real issue to build on
		//   headline   [language => string]
		//   body       [language => plain text]
		//
		// Returns [ok, source-or-reason].
		public function render($issue) {
			$dir = $this->sourceDir();
			if ($dir === false) return [false, "no-source"];

			$template = basename((string)($issue["template"] ?? ""));
			if (!in_array($template, $this->templates(), true)) return [false, "bad-template"];

			$text = @file_get_contents($dir . "/" . $template);
			if ($text === false) return [false, "unreadable-template"];

			$headline = (array)($issue["headline"] ?? []);
			$body = (array)($issue["body"] ?? []);

			// The headline appears exactly twice: in the download banner and
			// as the page title. Both are anchored precisely, because the
			// template holds thirty `lang X, db` lines and only these six
			// belong to the title -- the rest are menu item names and
			// descriptions. A first version replaced every run of them and
			// silently overwrote `.menuDesc`, which the linker caught as an
			// undefined symbol; a looser match here would have shipped a
			// broken issue instead of failing.
			$titleLines = [];
			foreach (self::LANGUAGES as $key => $letter) {
				$value = trim((string)($headline[$key] ?? ""));
				if ($value === "") continue;
				$titleLines[] = "\tlang " . $letter . ", db \"" . $this->asmString($value) . "\"";
			}
			if ($titleLines === []) return [false, "no-headline"];
			$title = implode("\n", $titleLines) . "\n";

			// (?:[ \t]*\n)* between anchor and run: the template separates them
			// with a whitespace-only line, and requiring them to be adjacent
			// matched nothing at all.
			$anchors = [
				// The download banner, right after the persistent-data size.
				'/(SECTION "Download Text".*?\n[ \t]*db PERSISTENT_MINIGAME_DATA_SIZE[^\n]*\n(?:[ \t]*\n)*)((?:[ \t]*lang [A-Z], db [^\n]*\n)+)/s',
				// The page title, right after the string that positions it.
				'/(news_string 1, 2,[^\n]*\n(?:[ \t]*\n)*)((?:[ \t]*lang [A-Z], db [^\n]*\n)+)/',
			];
			foreach ($anchors as $anchor) {
				$count = 0;
				$text = preg_replace_callback($anchor, function ($m) use ($title) {
					return $m[1] . $title;
				}, $text, 1, $count);
				if ($count !== 1) return [false, "headline-not-found"];
			}

			// The article text, under its own label, up to the next label.
			$bodyLines = [];
			foreach (self::LANGUAGES as $key => $letter) {
				$plain = (string)($body[$key] ?? "");
				if (trim($plain) === "") continue;
				foreach ($this->textMacros($plain) as [$macro, $line]) {
					$bodyLines[] = "\tlang " . $letter . ", " . $macro . " \"" . $this->asmString($line) . "\"";
				}
				$bodyLines[] = "";
			}

			// Bounded by the `done` that closes the block, not by the next
			// `db "@"`. The first version reached for that quote and found one
			// 280 lines later, past `.menuDesc`, swallowing every label in
			// between -- again caught by the linker rather than by the eye.
			// Commented-out `done`s further down cannot match: they begin
			// with a semicolon.
			if ($bodyLines !== []) {
				$found = 0;
				$text = preg_replace_callback(
					'/(^\.newsGuideText[ \t]*\n)(.*?)(^[ \t]*done[ \t]*$)/ms',
					function ($m) use ($bodyLines, &$found) {
						$found++;
						return $m[1] . implode("\n", $bodyLines) . "\n" . $m[3];
					},
					$text,
					1
				);
				if ($found !== 1) return [false, "body-not-found"];
			}

			return [true, $text];
		}

		// What may sit inside a quoted assembler string. A quote or a newline
		// would end it and turn the rest of the line into code, so neither is
		// allowed through; the rest is the author's to write.
		private function asmString($value) {
			$value = str_replace(["\r", "\n"], " ", (string)$value);
			$value = str_replace('"', "'", $value);
			return $value;
		}

		// ---------------------------------------------------------- building

		// Assembles one language. Returns [ok, bytes-or-reason, log].
		//
		// The build happens in a temporary directory of its own, and the
		// toolchain directory is reached only through the assembler's include
		// path. The first version wrote the generated source *into* the
		// toolchain checkout, which meant the web user needed write access to
		// somebody else's source tree to render a news page -- a permission
		// worth refusing, and refusing it is what caught this.
		public function buildLanguage($source, $language, $rankings, $minigame, $prizes = []) {
			$dir = $this->sourceDir();
			if ($dir === false) return [false, "no-source", ""];
			if (!isset(self::LANGUAGES[$language])) return [false, "bad-language", ""];
			if (!isset($this->minigames()[$minigame])) return [false, "bad-minigame", ""];

			$known = $this->rankingCategories();
			if (count($rankings) !== 3) return [false, "bad-ranking", ""];
			foreach ($rankings as $category) {
				if (!isset($known[$category])) return [false, "bad-ranking", ""];
			}

			$work = sys_get_temp_dir() . "/reon-news-" . bin2hex(random_bytes(6));
			if (!@mkdir($work, 0700)) return [false, "cannot-make-workdir", ""];

			// An issue that changed a prize builds against its own copy of the
			// minigame, written here beside the generated source -- never into
			// the toolchain, for the same reason the issue itself is not built
			// there. When nothing was changed there is no copy and the build
			// includes the toolchain's file unchanged.
			$include = $minigame;
			$custom = $this->minigameWithPrizes($minigame, $prizes);
			if ($custom !== null) {
				// Absolute, so `INCLUDE "{MINIGAME_FILE}"` resolves to this
				// copy whatever order the assembler searches in.
				$include = $work . "/" . $minigame;
				if (!@mkdir(dirname($include), 0700, true)
				 || @file_put_contents($include, $custom) === false) {
					@rmdir($work);
					return [false, "cannot-write-minigame", ""];
				}
			}

			$log = "";
			try {
				if (@file_put_contents($work . "/issue.asm", $source) === false) {
					return [false, "cannot-write-source", ""];
				}

				$assemble = $this->run([
					$this->rgbasm(), "issue.asm", "-o", "issue.o",
					// Every include in the source is relative to the
					// toolchain; this is how they resolve without the build
					// having to happen inside it.
					"-i", $dir . "/",
					"-D", "RANKING_1=" . $rankings[0],
					"-D", "RANKING_2=" . $rankings[1],
					"-D", "RANKING_3=" . $rankings[2],
					"-D", "MINIGAME_FILE=" . $include,
					"-D", "_LANG_" . self::LANGUAGES[$language],
				], $work);
				$log .= $assemble["stderr"];
				if ($assemble["code"] !== 0) return [false, "assemble-failed", $log];

				$link = $this->run([
					$this->rgblink(), "issue.o",
					"-l", $dir . "/pokecrystal/layout.link",
					"-o", "issue.bin",
				], $work);
				$log .= $link["stderr"];
				if ($link["code"] !== 0) return [false, "link-failed", $log];

				// This also trims the image to its real length: without it
				// the file is a padded 16K that the game will not take.
				$sum = $this->run([
					$this->python(), $dir . "/newschecksum.py", "issue.bin",
				], $work);
				$log .= $sum["stderr"];
				if ($sum["code"] !== 0) return [false, "checksum-failed", $log];

				$bytes = @file_get_contents($work . "/issue.bin");
				if ($bytes === false || $bytes === "") return [false, "no-output", $log];
				return [true, $bytes, $log];
			} finally {
				foreach (["issue.asm", "issue.o", "issue.bin"] as $name) {
					@unlink($work . "/" . $name);
				}
				if ($include !== $minigame) {
					@unlink($include);
					@rmdir(dirname($include));
				}
				@rmdir($work);
			}
		}

		// ------------------------------------------------- the mailbox line

		// Which character table a region reads. Copied from
		// resolveMessageEncodingTableForRegion in app/auto-schedule, because
		// the two have to agree: this writes the bytes that one decodes.
		const MESSAGE_TABLE = [
			"j" => "jp", "e" => "en", "p" => "en", "u" => "en",
			"f" => "fr_de", "d" => "fr_de", "s" => "es_it", "i" => "es_it",
		];

		private $encoding = null;

		private function encodingTable($region) {
			if ($this->encoding === null) {
				$path = dirname(__DIR__, 2) . "/web/scripts/bxt_encoding.json";
				$raw = @file_get_contents($path);
				$this->encoding = $raw === false ? [] : (json_decode($raw, true) ?: []);
			}
			$name = self::MESSAGE_TABLE[strtolower((string)$region)] ?? "en";
			return $this->encoding[$name] ?? [];
		}

		// The one line the mailbox shows for an issue, in the game's own
		// character bytes rather than ASCII: "POKéMON NEWS No.8" is fourteen
		// bytes, not seventeen, because some of them stand for more than one
		// character.
		//
		// Returns [ok, bytes-or-reason]. Longest match first, so a compound
		// like "POKé" becomes its single byte instead of four separate ones.
		public function encodeMessage($text, $region) {
			$table = $this->encodingTable($region);
			if ($table === []) return [false, "no-encoding-table"];

			// Inverted once per call, longest first. Where two bytes decode
			// to the same character the lower one wins, which is the one the
			// real files use.
			$reverse = [];
			foreach ($table as $hex => $char) {
				if ($char === "" || $char === null) continue;
				if (!isset($reverse[$char]) || strcmp($hex, $reverse[$char]) < 0) {
					$reverse[$char] = $hex;
				}
			}
			// Cast to string on the way out: PHP turns an array key that looks
			// like a number into an integer, so the character "1" came back
			// as int 1 and the strict comparison below never matched it --
			// every digit was reported unencodable while sitting right there
			// in the table.
			$sequences = array_map("strval", array_keys($reverse));
			usort($sequences, function ($a, $b) {
				return mb_strlen($b, "UTF-8") <=> mb_strlen($a, "UTF-8");
			});

			$text = trim((string)$text);
			$bytes = "";
			$position = 0;
			$length = mb_strlen($text, "UTF-8");

			while ($position < $length) {
				$matched = null;
				foreach ($sequences as $sequence) {
					$width = mb_strlen($sequence, "UTF-8");
					if ($width === 0 || $position + $width > $length) continue;
					if (mb_substr($text, $position, $width, "UTF-8") === $sequence) {
						$matched = [$sequence, $width];
						break;
					}
				}
				// A character the game cannot draw is refused rather than
				// swapped for something else: silently turning it into "?"
				// would put a mystery on a Game Boy screen.
				if ($matched === null) {
					return [false, "unencodable:" . mb_substr($text, $position, 1, "UTF-8")];
				}
				$bytes .= hex2bin($reverse[$matched[0]]);
				$position += $matched[1];
			}

			if ($bytes === "") return [false, "empty-message"];
			// 0x50 is the terminator the decoder stops at.
			return [true, $bytes . chr(0x50)];
		}

		// ------------------------------------------------------- publishing

		// Where an issue's own definition is kept, so it can be edited again.
		// A leading underscore keeps it out of the way of auto-schedule,
		// which only ever looks inside single-letter region folders.
		public function issuesDir() {
			$base = $this->articlesDir();
			if ($base === false) return false;
			$dir = $base . "/_issues";
			if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
			return $dir;
		}

		public function slug($name) {
			$slug = strtolower(trim((string)$name));
			$slug = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $slug);
			$slug = preg_replace("/[^a-z0-9]+/", "_", (string)$slug);
			$slug = trim($slug, "_");
			return substr($slug, 0, 40);
		}

		public function issues() {
			$dir = $this->issuesDir();
			if ($dir === false) return [];
			$out = [];
			foreach (glob($dir . "/*.json") as $path) {
				$data = json_decode((string)@file_get_contents($path), true);
				if (!is_array($data)) continue;
				$data["slug"] = basename($path, ".json");
				$data["saved_at"] = filemtime($path);
				$data["built"] = $this->builtRegions($data["slug"]);
				$data["scheduled"] = $this->scheduledRegions($data["slug"]);
				$data["locked"] = $this->lockedFrom($data);
				$out[] = $data;
			}
			usort($out, function ($a, $b) { return $b["saved_at"] <=> $a["saved_at"]; });
			return $out;
		}

		public function issue($slug) {
			$dir = $this->issuesDir();
			$slug = $this->slug($slug);
			if ($dir === false || $slug === "") return null;
			$path = $dir . "/" . $slug . ".json";
			if (!is_file($path)) return null;
			$data = json_decode((string)@file_get_contents($path), true);
			if (!is_array($data)) return null;
			$data["slug"] = $slug;
			$data["built"] = $this->builtRegions($slug);
			$data["scheduled"] = $this->scheduledRegions($slug);
			if (trim((string)($data["date"] ?? "")) === "") {
				$data["date"] = $this->scheduledDate($slug);
			}
			$data["locked"] = $this->lockedFrom($data);
			// A definition loaded from disk exists. Do not offer deletion for an unsaved form that
			// only has a typed name.
			$data["exists"] = true;
			return $data;
		}

		public function saveIssue($slug, $issue) {
			$dir = $this->issuesDir();
			$slug = $this->slug($slug);
			if ($dir === false || $slug === "") return [false, "bad-name"];
			$issue["slug"] = $slug;
			$written = @file_put_contents($dir . "/" . $slug . ".json",
				json_encode($issue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return $written === false ? [false, "write-failed"] : [true, $slug];
		}

		// Which regions already carry a built copy of this issue.
		public function builtRegions($slug) {
			$base = $this->articlesDir();
			$slug = $this->slug($slug);
			if ($base === false || $slug === "") return [];
			$found = [];
			foreach (self::REGION_LANGUAGE as $region => $language) {
				if (is_file($base . "/" . $region . "/" . $slug . ".bin")) $found[] = $region;
			}
			return $found;
		}

		// Builds every region asked for and writes the pair auto-schedule
		// consumes: <slug>.bin and <slug>.bin.message. Returns
		// [results-per-region, log].
		//
		// Each region is built and written on its own: one region failing to
		// encode its mailbox line should not cost the others their issue.
		public function publish($issue, $regions) {
			$base = $this->articlesDir();
			if ($base === false) return [[], "no-articles-dir"];

			$slug = $this->slug($issue["slug"] ?? "");
			if ($slug === "") return [[], "bad-name"];

			[$ok, $source] = $this->render($issue);
			if (!$ok) return [[], $source];

			$rankings = array_values((array)($issue["rankings"] ?? []));
			$minigame = (string)($issue["minigame"] ?? "");
			$prizes = array_values((array)($issue["prizes"] ?? []));
			$results = [];
			$log = "";

			foreach ($regions as $region) {
				$region = strtolower((string)$region);
				if (!isset(self::REGION_LANGUAGE[$region])) {
					$results[$region] = [false, "bad-region"];
					continue;
				}

				$language = self::REGION_LANGUAGE[$region];
				// The mailbox line is per language, not one string for every
				// region: the Japanese table has no Latin letters at all, so
				// an English line handed to the Japanese build is refused
				// character by character -- correctly. Falls back to that
				// language's own headline when no separate line was written.
				$line = (string)(($issue["message"] ?? [])[$language] ?? "");
				if (trim($line) === "") {
					$line = (string)(($issue["headline"] ?? [])[$language] ?? "");
				}
				[$encoded, $message] = $this->encodeMessage($line, $region);
				if (!$encoded) {
					$results[$region] = [false, $message];
					continue;
				}

				[$built, $bytes, $buildLog] = $this->buildLanguage(
					$source, $language, $rankings, $minigame, $prizes);
				$log .= $buildLog;
				if (!$built) {
					$results[$region] = [false, $bytes];
					continue;
				}

				$dir = $base . "/" . $region;
				if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
					$results[$region] = [false, "mkdir-failed"];
					continue;
				}
				if (@file_put_contents($dir . "/" . $slug . ".bin", $bytes) === false
				 || @file_put_contents($dir . "/" . $slug . ".bin.message", $message) === false) {
					$results[$region] = [false, "write-failed"];
					continue;
				}
				$results[$region] = [true, strlen($bytes)];
			}

			return [$results, $log];
		}

		// Resolve ranking_category_N using authoritative bxt_encoding.json names, the same mapping
		// used by decodeRankingCategory. Do not use ranking_types.asm list positions because
		// filtering its three reserved entries shifts indices. Return NULL on mismatch; callers
		// reject rather than invent a cartridge category ID.
		public function rankingNumber($constant) {
			$labels = $this->rankingCategories();
			if (!isset($labels[$constant])) return null;

			$path = dirname(__DIR__, 2) . "/web/scripts/bxt_encoding.json";
			$raw = @file_get_contents($path);
			$data = $raw === false ? [] : (json_decode($raw, true) ?: []);
			// Ranking identifiers are region-independent; use the English table as the shared
			// reference.
			$table = $data["btxe_btxp_btxu_ranking_category"] ?? [];

			foreach ($table as $number => $label) {
				if ($this->readable($label) === $labels[$constant]) return (int)$number;
			}
			return null;
		}

		// Widths measured from the seven original editions: article nsc_textbox is 18 characters
		// wide and the longest of 245 lines is 18; title limits cover the longest real localized
		// titles; the eight mailbox messages range from 14 to 17 characters. Articles scroll with
		// cont, so constrain line width rather than total line count.
		const HEADLINE_MAX = 18;
		const BODY_LINE_MAX = 18;
		const MESSAGE_MAX = 17;

		// Validate text widths and return diagnostics identifying language, field and offending
		// line instead of an unlocated overflow message.
		public function checkText($issue) {
			$problems = [];

			foreach (self::LANGUAGES as $key => $letter) {
				$headline = trim((string)(($issue["headline"] ?? [])[$key] ?? ""));
				if (mb_strlen($headline, "UTF-8") > self::HEADLINE_MAX) {
					$problems[] = [
						"language" => $key, "field" => "headline",
						"limit" => self::HEADLINE_MAX,
						"length" => mb_strlen($headline, "UTF-8"), "text" => $headline,
					];
				}

				$message = trim((string)(($issue["message"] ?? [])[$key] ?? ""));
				if (mb_strlen($message, "UTF-8") > self::MESSAGE_MAX) {
					$problems[] = [
						"language" => $key, "field" => "message",
						"limit" => self::MESSAGE_MAX,
						"length" => mb_strlen($message, "UTF-8"), "text" => $message,
					];
				}

				// Measure after conversion to line macros, matching the text actually shown on screen
				// rather than the raw form text.
				foreach ($this->textMacros((string)(($issue["body"] ?? [])[$key] ?? "")) as $i => [$macro, $line]) {
					if (mb_strlen($line, "UTF-8") <= self::BODY_LINE_MAX) continue;
					$problems[] = [
						"language" => $key, "field" => "body", "line" => $i + 1,
						"limit" => self::BODY_LINE_MAX,
						"length" => mb_strlen($line, "UTF-8"), "text" => $line,
					];
				}
			}

			return $problems;
		}

		// Group build destinations by consumed language. Regions e, p and u use English and
		// produce byte-identical binaries; build once for all three. Keep French, German, Italian,
		// Spanish and Japanese content separate even where cartridge execution flags are shared.
		public function buildTargets() {
			$targets = [];
			foreach (self::REGION_LANGUAGE as $region => $language) {
				$targets[$language][] = $region;
			}
			return $targets;
		}

		// Require title and article in every language consumed by a selected region. The mailbox
		// line intentionally falls back to title. Regions e/p/u share English. A missing article
		// would retain the original 2002 template rather than produce a harmless empty page.
		public function checkBuildable($issue, $regions) {
			$missing = [];
			foreach ($regions as $region) {
				$region = strtolower((string)$region);
				if (!isset(self::REGION_LANGUAGE[$region])) continue;

				$language = self::REGION_LANGUAGE[$region];
				$gaps = [];
				if (trim((string)(($issue["headline"] ?? [])[$language] ?? "")) === "") $gaps[] = "headline";
				if (trim((string)(($issue["body"] ?? [])[$language] ?? "")) === "") $gaps[] = "body";
				if ($gaps === []) continue;

				$missing[] = ["region" => $region, "language" => $language, "fields" => $gaps];
			}
			return $missing;
		}

		// Determine which languages have enough text to build, so the UI does not offer an
		// incomplete region.
		public function filledLanguages($issue) {
			$filled = [];
			foreach (array_keys(self::LANGUAGES) as $language) {
				if (trim((string)(($issue["headline"] ?? [])[$language] ?? "")) === "") continue;
				if (trim((string)(($issue["body"] ?? [])[$language] ?? "")) === "") continue;
				$filled[] = $language;
			}
			return $filled;
		}

		// Month lengths for a yearless annual schedule; February is always 28 days. Reject
		// February 29 because it would skip publication in three out of four years.
		const DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

		// Build MM-DD from month/day, or return NULL for an invalid date.
		public static function composeDate($month, $day) {
			$month = (int)$month;
			$day = (int)$day;
			if ($month < 1 || $month > 12) return null;
			if ($day < 1 || $day > self::DAYS_IN_MONTH[$month - 1]) return null;
			return sprintf("%02d-%02d", $month, $day);
		}

		// Published editions are read-only: delete and republish to change their content. Lock if
		// either published_at was recorded by the scheduler or the scheduled date has arrived. The
		// marker survives calendar removal/replacement; the date also covers scheduler delay or a
		// missing marker and intentionally errs toward locking.
		public function isPublished($slug) {
			$issue = $this->issue($slug);
			return is_array($issue) ? $this->lockedFrom($issue) : false;
		}

		// Accept an already loaded definition so issue() can annotate publication state without
		// recursively reloading itself.
		private function lockedFrom($data) {
			if (!is_array($data)) return false;
			if (trim((string)($data["published_at"] ?? "")) !== "") return true;

			$date = $this->scheduledDate((string)($data["slug"] ?? ""));
			if ($date === "") return false;
			$parts = explode("-", $date);
			if (count($parts) !== 2) return false;
			$when = mktime(0, 0, 0, (int)$parts[0], (int)$parts[1], (int)date("Y"));
			return $when !== false && $when <= mktime(0, 0, 0);
		}

		// Convert MM-DD to YYYY-MM-DD in the current year. A past date means publish now, not wait
		// until next year.
		public static function withYear($date) {
			$parts = explode("-", (string)$date);
			if (count($parts) !== 2) return (string)$date;
			return sprintf("%04d-%02d-%02d", (int)date("Y"), (int)$parts[0], (int)$parts[1]);
		}

		// A volta: o calendário guarda a data com ano, o formulário mostra
		// mês e dia.
		public static function withoutYear($date) {
			$parts = explode("-", (string)$date);
			if (count($parts) !== 3) return (string)$date;
			return sprintf("%02d-%02d", (int)$parts[1], (int)$parts[2]);
		}

		// Form value representing a randomized selection.
		const RANKING_RANDOM = "__random__";

		// All original Pokemon News messages fit this width. This is a warning, not a rejection;
		// the display limit is more relevant than the generous varbinary(100) column.
		const MESSAGE_SOFT_LIMIT = 17;

		// Resolve three distinct categories, honoring manual choices first and sampling random
		// categories from those remaining. Duplicate categories waste a third of the ranking
		// screen. Return [ok, categories-or-reason].
		public function resolveRankings($chosen) {
			$pool = array_keys($this->rankingCategories());
			if (count($pool) < 3) return [false, "no-categories"];

			$chosen = array_values((array)$chosen);
			while (count($chosen) < 3) $chosen[] = self::RANKING_RANDOM;
			$chosen = array_slice($chosen, 0, 3);

			// Reserve manually selected categories first so randomized choices cannot repeat them.
			$out = [null, null, null];
			$taken = [];
			foreach ($chosen as $slot => $value) {
				$value = (string)$value;
				if ($value === self::RANKING_RANDOM || $value === "") continue;
				if (!in_array($value, $pool, true)) return [false, "unknown-ranking:" . $value];
				if (in_array($value, $taken, true)) return [false, "duplicate-ranking"];
				$out[$slot] = $value;
				$taken[] = $value;
			}

			$available = array_values(array_diff($pool, $taken));
			foreach ($out as $slot => $value) {
				if ($value !== null) continue;
				if ($available === []) return [false, "no-categories"];
				$pick = random_int(0, count($available) - 1);
				$out[$slot] = $available[$pick];
				array_splice($available, $pick, 1);
			}

			return [true, $out];
		}

		// -------------------------------------------------------- scheduling

		// The overlay auto-schedule merges over the custom cycle's schedule.
		// Its own file, not the shared config: that one also carries the
		// vanilla schedule, and a web application must not be one bad save
		// away from breaking the ordinary news.
		public function schedulePath() {
			return dirname(__DIR__, 2) . "/app/auto-schedule/bxt_news_custom.schedule.json";
		}

		public function scheduleWritable() {
			$path = $this->schedulePath();
			return is_file($path) ? is_writable($path) : is_writable(dirname($path));
		}

		public function schedule() {
			$raw = @file_get_contents($this->schedulePath());
			if ($raw === false) return [];
			$data = json_decode($raw, true);
			return is_array($data) && isset($data["schedule"]) && is_array($data["schedule"])
				? $data["schedule"] : [];
		}

		// Written to a neighbour and moved into place, and only after the
		// bytes have been read back as JSON: a half-written schedule is a
		// news cycle that stops.
		private function saveSchedule($schedule) {
			$path = $this->schedulePath();
			$body = json_encode(
				["schedule" => $schedule],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			if ($body === false || json_decode($body, true) === null) return false;

			// Write the allowed overlay file in place: atomic replacement would require directory
			// write permission, which is deliberately denied because the official schedule
			// configuration is adjacent. A partial write can truncate JSON; the scheduler tolerates an
			// unreadable overlay by warning and skipping the custom track for that run. Read back the
			// result so the admin receives an error instead of silent failure.
			if (@file_put_contents($path, $body) === false) return false;

			$back = @file_get_contents($path);
			if ($back === false || json_decode($back, true) === null) return false;
			return true;
		}

		// Puts an issue into the calendar for the regions given, and takes it
		// out of every region not given -- so unticking a region is how you
		// stop the game using it there.
		//
		// `ranking_categories` is deliberately not written: auto-schedule
		// reads the categories out of the binary when the entry does not name
		// them, and the binary is where they actually are -- they were
		// compiled into it. Writing them here would create a second copy that
		// can disagree with the first.
		public function setScheduled($slug, $date, $regions, $rankings = []) {
			$slug = $this->slug($slug);
			if ($slug === "") return [false, "bad-name"];
			// Validate actual month lengths rather than accepting shape-only dates such as 02-30 and
			// 04-31.
			$parts = explode("-", (string)$date);
			if (count($parts) !== 2 || self::composeDate($parts[0], $parts[1]) === null) {
				return [false, "bad-date"];
			}
			$date = self::composeDate($parts[0], $parts[1]);
			if (!$this->scheduleWritable()) return [false, "schedule-not-writable"];

			$schedule = $this->schedule();
			$id = $slug . ".bin";

			// Store explicit ranking categories. The scheduler binary-inference path returns
			// structured slots unsuitable for an integer column; real schedule entries therefore
			// provide the list.
			$numbers = [];
			foreach ($rankings as $constant) {
				$n = $this->rankingNumber($constant);
				if ($n === null) return [false, "unknown-ranking:" . $constant];
				$numbers[] = $n;
			}

			foreach (array_keys(self::REGION_LANGUAGE) as $region) {
				$entries = isset($schedule[$region]) && is_array($schedule[$region])
					? $schedule[$region] : [];

				if (in_array($region, $regions, true)) {
					// Deliberately no `slot`.
					//
					// A slot puts the region into the scheduler's slot-based
					// cycle, and every entry written here would carry the
					// same one. That makes maxSlot 0, so cycleLength becomes
					// 1, so `for (step = 1; step < 1)` never runs and the
					// selector returns nothing -- the region is skipped. The
					// first run would still publish (no lastSlot yet) and
					// then record lastSlot 0, after which the custom track
					// would never move again. It would have looked like it
					// worked, once.
					//
					// Without a slot the region stays in the plain date mode,
					// where the entry whose date most recently came round is
					// the one that goes out -- which is what "goes live on
					// this day" is supposed to mean.
					$entries[$id] = [
						// Store YYYY-MM-DD, not MM-DD. The scheduler interprets a future yearless date as the
						// previous annual occurrence and could publish a December edition immediately instead of
						// waiting for its intended date.
						"date" => self::withYear($date),
						"file" => "bxt_custom/" . $region . "/" . $id,
						"message_file" => "bxt_custom/" . $region . "/" . $id . ".message",
					];
					if ($numbers !== []) $entries[$id]["ranking_categories"] = $numbers;
				} else {
					unset($entries[$id]);
				}

				if ($entries === []) {
					unset($schedule[$region]);
				} else {
					$schedule[$region] = $entries;
				}
			}

			return $this->saveSchedule($schedule) ? [true, "ok"] : [false, "write-failed"];
		}

		// Which regions currently have this issue in the calendar.
		public function scheduledRegions($slug) {
			$slug = $this->slug($slug);
			$id = $slug . ".bin";
			$found = [];
			foreach ($this->schedule() as $region => $entries) {
				if (is_array($entries) && isset($entries[$id])) $found[] = $region;
			}
			sort($found);
			return $found;
		}

		public function scheduledDate($slug) {
			$id = $this->slug($slug) . ".bin";
			foreach ($this->schedule() as $entries) {
				if (is_array($entries) && isset($entries[$id]["date"])) {
					return self::withoutYear($entries[$id]["date"]);
				}
			}
			return "";
		}

		// Takes an issue out of the game entirely: out of the calendar first,
		// then the built files. That order matters -- a scheduled entry whose
		// file is gone is a run that warns and skips, while a file nothing
		// points at is simply unused.
		public function withdraw($slug) {
			$slug = $this->slug($slug);
			if ($slug === "") return [false, "bad-name"];

			$scheduleOk = true;
			if ($this->scheduledRegions($slug) !== []) {
				[$scheduleOk] = $this->setScheduled($slug, "01-01", []);
			}

			$base = $this->articlesDir();
			if ($base !== false) {
				foreach (array_keys(self::REGION_LANGUAGE) as $region) {
					@unlink($base . "/" . $region . "/" . $slug . ".bin");
					@unlink($base . "/" . $region . "/" . $slug . ".bin.message");
				}
			}
			return [$scheduleOk, $scheduleOk ? "ok" : "schedule-not-writable"];
		}

		// Always an argument list, never a shell string.
		private function run(array $cmd, $cwd = null) {
			$spec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes, $cwd);
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
