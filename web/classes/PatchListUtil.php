<?php
	require_once("TemplateUtil.php");

	// The list of game patches on the Downloads page.
	//
	// What is listed is whatever the patch build (maint/rom-patches) last
	// published: it writes manifest.json next to the .bps files, and nginx
	// serves that folder under /patches/. This page never reads a ROM and there
	// is none in that folder; the ROMs the patches are made against live in a
	// directory this user cannot open.
	//
	// The page's Markdown holds a <!--reon:patches--> marker where the list
	// goes. It is filled in after the page's rendered HTML comes out of the
	// cache, so the list is always current while the text around it is cached.
	class PatchListUtil {

		const MARKER = "<!--reon:patches-->";
		const URL_PREFIX = "/patches/";
		// A warning that belongs to one game (its family key -> locale key),
		// shown while that game is chosen.
		const NOTES = ["pokecrystal" => "patches-note-pokecrystal"];
		// The one link a note may carry, where its text has a %test% marker.
		const NOTE_LINKS = ["pokecrystal" => "https://github.com/ZoomTen/mbc30test"];

		public static function dir() {
			return getenv("REON_PATCH_PUBLIC") ?: "/var/lib/reon-patches/public";
		}

		public static function fill($html) {
			if (strpos($html, self::MARKER) === false) return $html;
			return str_replace(self::MARKER, self::render(self::load()), $html);
		}

		// The published games that have at least one patch file on disk.
		public static function load() {
			$raw = @file_get_contents(self::dir()."/manifest.json");
			$doc = $raw === false ? null : json_decode($raw, true);
			if (!is_array($doc) || !isset($doc["games"]) || !is_array($doc["games"])) return [];

			$games = [];
			foreach ($doc["games"] as $game) {
				$patches = [];
				foreach (($game["patches"] ?? []) as $patch) {
					// The name ends up in a link: only what the builder can
					// have produced.
					$file = $patch["file"] ?? "";
					if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.bps$/', $file)) continue;
					if (!is_file(self::dir()."/".$file)) continue;
					$patches[] = $patch;
				}
				if ($patches) {
					$game["patches"] = $patches;
					$games[] = $game;
				}
			}
			return $games;
		}

		// One card: a menu of games and, for a game that comes in several
		// regions, radio buttons to pick the region; then the facts about the
		// chosen patch and its download button. Everything is written for the
		// first game here and rewritten by page-sections.js when a choice
		// changes, from the radios' data-* attributes; without scripts the
		// <noscript> list has every link.
		public static function render($games) {
			$t = function($id, $params = []) { return TemplateUtil::translate($id, $params); };
			$e = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); };

			if (!$games) {
				return '<div class="reon-note">'.$e($t("patches-none")).'</div>';
			}

			// Family = what the player thinks of as "the game"; each patch in it
			// is one region. The manifest carries both (a game without a family
			// stands alone).
			$families = [];
			foreach ($games as $game) {
				$key = (string)($game["family"] ?? $game["id"]);
				if (!isset($families[$key])) {
					$families[$key] = ["title" => (string)($game["family_title"] ?? $game["title"] ?? $key), "entries" => []];
				}
				foreach ($game["patches"] as $patch) {
					$base = $patch["base"] ?? [];
					$families[$key]["entries"][] = [
						"region" => (string)($base["region"] ?? $base["label"] ?? ""),
						"href" => self::URL_PREFIX.$patch["file"],
						"base" => (string)($base["label"] ?? ""),
						"base_sha1" => (string)($base["sha1"] ?? ""),
						"result_sha1" => (string)($patch["result"]["sha1"] ?? ""),
						"size" => self::size($patch["size"] ?? 0),
						"date" => substr((string)($game["commit_date"] ?? ""), 0, 10),
					];
				}
			}

			$first = reset($families)["entries"][0];
			$out = '<div class="reon-download reon-download--wide reon-patches" data-patch-picker>';
			$out .= '<label class="reon-patches__label" for="reon-patch-pick">'.$e($t("patches-choose")).'</label>';
			$out .= '<select id="reon-patch-pick" class="reon-patch-pick">';
			$n = 0;
			foreach ($families as $key => $family) {
				$out .= '<option value="'.$e($key).'"'.($n++ === 0 ? ' selected' : '').'>'.$e($family["title"]).'</option>';
			}
			$out .= '</select>';

			// The region choice of every game; only the chosen game's is shown,
			// and a game with a single region has nothing to choose.
			$n = 0;
			foreach ($families as $key => $family) {
				$single = count($family["entries"]) < 2;
				$out .= '<fieldset class="reon-patches__regions" data-family="'.$e($key).'"'
					.($single ? ' data-single="1"' : '').($single || $n > 0 ? ' hidden' : '').'>';
				$out .= '<legend class="reon-patches__label">'.$e($t("patches-region")).'</legend>';
				foreach ($family["entries"] as $m => $x) {
					$out .= '<label class="reon-patches__region"><input type="radio" name="reon-region-'.$e($key).'"'
						.' value="'.$e($x["href"]).'"'
						.' data-base="'.$e($x["base"]).'" data-base-sha1="'.$e($x["base_sha1"]).'"'
						.' data-result-sha1="'.$e($x["result_sha1"]).'" data-size="'.$e($x["size"]).'"'
						.' data-date="'.$e($x["date"]).'"'.($m === 0 ? ' checked' : '').'> '.$e($x["region"]).'</label>';
				}
				$out .= '</fieldset>';
				$n++;
			}

			$n = 0;
			foreach ($families as $key => $family) {
				if (isset(self::NOTES[$key])) {
					// Escaped first, then the marker becomes the link: the text
					// never carries markup of its own.
					$text = $e($t(self::NOTES[$key], ["%test%" => "\x01"]));
					if (isset(self::NOTE_LINKS[$key])) {
						$text = str_replace("\x01", '<a href="'.$e(self::NOTE_LINKS[$key]).'">'
							.$e($t(self::NOTES[$key]."-link")).'</a>', $text);
					}
					$out .= '<div class="reon-note" data-family-note="'.$e($key).'"'.($n > 0 ? ' hidden' : '').'>'
						.$text.'</div>';
				}
				$n++;
			}

			$rows = [
				["patches-row-base", "base", $first["base"], false],
				["patches-row-base-sha1", "base-sha1", $first["base_sha1"], true],
				["patches-row-result-sha1", "result-sha1", $first["result_sha1"], true],
				["patches-row-size", "size", $first["size"], false],
				["patches-row-date", "date", $first["date"], false],
			];
			$out .= '<dl class="reon-patches__facts">';
			foreach ($rows as [$key, $field, $value, $mono]) {
				$out .= '<dt>'.$e($t($key)).'</dt><dd data-field="'.$field.'"'.($mono ? ' class="is-hash"' : '').'>'.$e($value).'</dd>';
			}
			$out .= '</dl>';
			$out .= '<a class="reon-chrome-btn" data-field="button" href="'.$e($first["href"]).'" download>'.$e($t("patches-download")).'</a>';

			$out .= '<noscript><ul>';
			foreach ($families as $family) {
				foreach ($family["entries"] as $x) {
					$out .= '<li><a href="'.$e($x["href"]).'" download>'.$e($family["title"].' — '.$x["region"]).'</a></li>';
				}
			}
			$out .= '</ul></noscript></div>';
			return $out;
		}

		private static function size($bytes) {
			return $bytes >= 1048576 ? sprintf("%.1f MB", $bytes / 1048576) : sprintf("%d KB", max(1, round($bytes / 1024)));
		}
	}
?>
