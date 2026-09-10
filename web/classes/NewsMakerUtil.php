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

		// The minigames an issue can carry.
		public function minigames() {
			$dir = $this->sourceDir();
			if ($dir === false) return [];
			$found = [];
			foreach (glob($dir . "/minigame/*.asm") as $path) {
				$name = basename($path);
				// The debug builds are in the tree but are not something to
				// put in front of a player.
				if (strpos($name, "_debug") !== false) continue;
				$found[] = "minigame/" . $name;
			}
			sort($found);
			return $found;
		}

		// The ranking categories the game knows, read from the toolchain's
		// own constants rather than copied into a list here -- a list here
		// would be right until the day it was not.
		public function rankingCategories() {
			$dir = $this->sourceDir();
			if ($dir === false) return [];

			$found = [];
			$text = (string)@file_get_contents($dir . "/Makefile");
			// The Makefile documents the accepted ids in a comment block,
			// one per line, which is the only place they are enumerated.
			if (preg_match('/accpeted ranking IDs include:(.*?)\n\n/s', $text, $m)
			    || preg_match('/accepted ranking IDs include:(.*?)\n\n/s', $text, $m)) {
				foreach (explode("\n", $m[1]) as $line) {
					$line = trim(ltrim(trim($line), "#"));
					if ($line === "" || !preg_match('/^[A-Z][A-Z0-9_]*$/', $line)) continue;
					$found[] = $line;
				}
			}
			return $found;
		}

		// ------------------------------------------------------------ text

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
		public function buildLanguage($source, $language, $rankings, $minigame) {
			$dir = $this->sourceDir();
			if ($dir === false) return [false, "no-source", ""];
			if (!isset(self::LANGUAGES[$language])) return [false, "bad-language", ""];
			if (!in_array($minigame, $this->minigames(), true)) return [false, "bad-minigame", ""];

			$known = $this->rankingCategories();
			if (count($rankings) !== 3) return [false, "bad-ranking", ""];
			foreach ($rankings as $category) {
				if (!in_array($category, $known, true)) return [false, "bad-ranking", ""];
			}

			$work = sys_get_temp_dir() . "/reon-news-" . bin2hex(random_bytes(6));
			if (!@mkdir($work, 0700)) return [false, "cannot-make-workdir", ""];

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
					"-D", "MINIGAME_FILE=" . $minigame,
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
				@rmdir($work);
			}
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
