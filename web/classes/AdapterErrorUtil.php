<?php
	// Os códigos de erro do Mobile Adapter, prontos para a página.
	//
	// Duas metades, de origens diferentes e por isso em arquivos diferentes:
	//
	//   adapter_errors.json              gerado. Códigos, o texto que cada
	//                                    jogo imprime, e a descrição curta da
	//                                    fonte em inglês. Refeito por
	//                                    maint/build_adapter_errors.php.
	//   adapter_error_notes.<lang>.json  escrito à mão. A explicação amigável,
	//                                    um arquivo por idioma.
	//
	// A junção acontece aqui, na hora de desenhar, e não no gerador: é aqui
	// que se sabe qual idioma a pessoa está lendo. Guardar os sete idiomas
	// dentro do arquivo gerado multiplicaria por sete um dado de 400 KB para
	// entregar um sétimo dele.
	class AdapterErrorUtil {

		const DIR = __DIR__ . "/../data";
		const FALLBACK = "en";

		// As linhas com a explicação do idioma pedido. Campo a campo: uma
		// nota traduzida pela metade cai para o inglês só no que falta, em
		// vez de derrubar a entrada inteira.
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
				// Quando o código tem explicação própria, a da família vira
				// contexto embaixo: é ela que diz que 5xx vem do servidor de
				// e-mail, e não do jogo.
				$row["family"] = isset($own["means"]) ? ($fam["means"] ?? "") : "";
			}
			unset($row);

			return $data;
		}

		// As notas do idioma, com o inglês por baixo. `same_as` evita repetir
		// o mesmo parágrafo em cinco famílias que dizem a mesma coisa.
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
