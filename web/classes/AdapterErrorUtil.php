<?php
	// Combine generated adapter_errors.json (codes, game screen text and source descriptions)
	// with handwritten adapter_error_notes.<lang>.json explanations at render time. Language
	// selection belongs here, not in the generator; do not duplicate all language notes into
	// the large generated dataset.
	class AdapterErrorUtil {

		const DIR = __DIR__ . "/../data";
		const FALLBACK = "en";

		// Load requested-language explanations with field-level English fallback, preserving
		// translated fields in partially translated entries.
		public static function rows($locale) {
			$data = self::json(self::DIR . "/adapter_errors.json");
			if (!$data) return null;

			$notes = self::notes($locale);
			$families = $notes["families"] ?? [];
			$codes = $notes["codes"] ?? [];

			foreach ($data["rows"] as &$row) {
				$prefix = explode("-", $row["code"])[0];
				$fam = $families[$prefix] ?? [];
				$own = $codes[$row["code"]] ?? [];

				$row["title"] = $own["title"] ?? ($fam["title"] ?? "");
				$row["means"] = $own["means"] ?? ($fam["means"] ?? "");
				$row["todo"] = $own["todo"] ?? ($fam["todo"] ?? "");
				// When a code has its own explanation, retain the family explanation as context below it,
				// for example that 5xx comes from the mail server.
				$row["family"] = isset($own["means"]) ? ($fam["means"] ?? "") : "";
			}
			unset($row);

			// Remove cartridge screen text and its game labels from the page response, while retaining
			// them in the generated source data. The page no longer displays these fields; shipping
			// them accounted for most of the response size.
			foreach ($data["rows"] as &$row) unset($row["messages"]);
			unset($row);
			unset($data["games"]);

			return $data;
		}

		// Overlay language notes on English fallback. same_as avoids repeating identical
		// explanations for multiple families.
		private static function notes($locale) {
			$base = self::json(self::DIR . "/adapter_error_notes." . self::FALLBACK . ".json") ?: [];
			$own = [];
			if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', (string)$locale) && $locale !== self::FALLBACK) {
				$own = self::json(self::DIR . "/adapter_error_notes." . $locale . ".json") ?: [];
			}

			$notes = [
				"families" => self::overlay($base["families"] ?? [], $own["families"] ?? []),
				"codes" => self::overlay($base["codes"] ?? [], $own["codes"] ?? []),
			];

			foreach ($notes["families"] as $key => $fam) {
				$seen = [];
				while (isset($fam["same_as"]) && !isset($seen[$fam["same_as"]])) {
					$seen[$fam["same_as"]] = true;
					$fam = ($notes["families"][$fam["same_as"]] ?? []) + $fam;
					unset($fam["same_as"]);
				}
				$notes["families"][$key] = $fam;
			}
			return $notes;
		}

		private static function overlay($base, $over) {
			foreach ($over as $key => $fields) {
				$base[$key] = is_array($fields) && isset($base[$key]) && is_array($base[$key])
					? $fields + $base[$key]
					: $fields;
			}
			return $base;
		}

		private static function json($path) {
			if (!is_file($path)) return null;
			return json_decode((string)file_get_contents($path), true);
		}
	}
?>
