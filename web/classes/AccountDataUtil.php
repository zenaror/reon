<?php
	require_once(__DIR__ . "/DBUtil.php");
	require_once(__DIR__ . "/MailStoreUtil.php");
	require_once(__DIR__ . "/ConfigUtil.php");
	require_once(__DIR__ . "/RelayUtil.php");

	// Levar embora e apagar: os dois direitos que faltavam.
	//
	// Uma conta do REON não mora num lugar só. Ela tem linha no cadastro,
	// chave de aparelho, contadores por dispositivo, avisos do sino, cópias
	// em Enviados, registro de envio externo, token de relay num SEGUNDO
	// banco, marcas em seis tabelas de jogo -- e correspondência, que nem
	// sequer está no banco: está em Maildir, do lado do Dovecot.
	//
	// Por isso as duas operações moram juntas aqui, e não espalhadas por
	// cada tela. Uma lista só, usada pelas duas: o que a exportação entrega
	// é exatamente o que a exclusão apaga. Quando alguém acrescentar uma
	// tabela e esquecer desta lista, as duas erram do mesmo jeito -- e uma
	// exportação com buraco é bem mais fácil de notar do que uma exclusão
	// com sobra.
	class AccountDataUtil {

		// Tabelas presas à conta pelo id. [tabela, coluna].
		const POR_ID = [
			["sys_device_authorization", "user_id"],
			["sys_device_counter",       "user_id"],
			["sys_notifications",        "user_id"],
			["sys_sent",                 "user_id"],
			["sys_email_change",         "user_id"],
			["sys_password_reset",       "user_id"],
			["sys_web_outbound_log",     "user_id"],
			["amk_user_map",             "user_id"],
			["bxt_battle_tower_honor_roll", "account_id"],
			["bxt_battle_tower_records",    "account_id"],
			["bxt_battle_tower_trainers",   "account_id"],
			["bxt_exchange",             "account_id"],
			["bxt_ranking",              "account_id"],
		];

		// Tabelas de jogo que guardam o endereço em vez do id. O jogo grava o
		// que o cartucho conhece, e o cartucho não conhece id de conta.
		//
		// bxt_exchange aparece nas DUAS listas de propósito: tem as duas
		// colunas, e um depósito antigo pode ter só uma delas preenchida.
		// Apagar por uma chave só deixaria a outra metade para trás.
		const POR_EMAIL = [
			["amc_trades",       ["email"]],
			["amo_ranking",      ["email"]],
			["bxt_exchange",     ["email"]],
			["bxt_exchange_log", ["email_1", "email_2"]],
			["sys_signup",       ["email"]],
		];

		private static $instance;

		public static function getInstance() {
			if (self::$instance === null) self::$instance = new self();
			return self::$instance;
		}

		// A conta, como o resto do sistema a enxerga. Devolve null quando não
		// existe -- o chamador decide o que dizer.
		private function conta($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select * from sys_users where id = ?");
			$id = (int)$userId;
			$stmt->bind_param("i", $id);
			$stmt->execute();
			$linhas = DBUtil::fancy_get_result($stmt);
			return count($linhas) ? $linhas[0] : null;
		}

		// Os endereços pelos quais as tabelas de jogo podem conhecer a pessoa.
		private function enderecos($conta) {
			$cfg = ConfigUtil::getInstance()->getConfig();
			return array_values(array_unique(array_filter([
				$conta["dion_email_local"] . "@" . $cfg["email_domain_dion"],
				$conta["dion_email_local"] . "@" . $cfg["email_domain"],
				$conta["username"] . "@" . $cfg["email_domain"],
				$conta["email"],
			])));
		}

		// Existe a tabela? O esquema mudou bastante, e uma instalação mais
		// nova (ou mais velha) pode não ter todas. Faltar tabela não pode
		// derrubar nem a exportação nem a exclusão.
		private function existe($db, $tabela) {
			$stmt = $db->prepare(
				"select 1 from information_schema.tables
				 where table_schema = database() and table_name = ? limit 1");
			$stmt->bind_param("s", $tabela);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
		}

		// ------------------------------------------------------------------
		// EXPORTAÇÃO
		// ------------------------------------------------------------------

		// Tudo o que o servidor guarda sobre a conta, como estrutura pronta
		// para virar JSON.
		//
		// Campos de segredo saem de fora, e isso não é omissão: a chave de
		// aparelho e o token de relay são credenciais em uso. Entregá-las num
		// arquivo que a pessoa vai guardar no computador, mandar por e-mail
		// ou anexar num chamado é criar uma cópia da chave onde não havia --
		// e o direito é de saber o que existe, não de receber a credencial em
		// claro. O arquivo diz que elas existem, e desde quando.
		public function export($userId) {
			$db = DBUtil::getInstance()->getDB();
			$conta = $this->conta($userId);
			if ($conta === null) return null;

			unset($conta["log_in_password"], $conta["password"], $conta["password_hash"]);

			$saida = [
				"gerado_em" => gmdate("c"),
				"conta" => $conta,
				"tabelas" => [],
				"correio" => [],
			];

			foreach (self::POR_ID as $t) {
				list($tabela, $coluna) = $t;
				if (!$this->existe($db, $tabela)) continue;
				$stmt = $db->prepare("select * from `{$tabela}` where `{$coluna}` = ?");
				$id = (int)$userId;
				$stmt->bind_param("i", $id);
				$stmt->execute();
				$linhas = DBUtil::fancy_get_result($stmt);
				foreach ($linhas as &$linha) {
					// A chave de aparelho vira presença, não valor.
					if (isset($linha["device_auth_key"])) {
						$linha["device_auth_key"] = "<guardada, não exportada>";
					}
				}
				unset($linha);
				if ($linhas) $saida["tabelas"][$tabela] = $linhas;
			}

			$enderecos = $this->enderecos($conta);
			foreach (self::POR_EMAIL as $t) {
				list($tabela, $colunas) = $t;
				if (!$this->existe($db, $tabela)) continue;
				foreach ($colunas as $coluna) {
					$marcas = implode(",", array_fill(0, count($enderecos), "?"));
					$stmt = $db->prepare("select * from `{$tabela}` where `{$coluna}` in ({$marcas})");
					$stmt->bind_param(str_repeat("s", count($enderecos)), ...$enderecos);
					$stmt->execute();
					$linhas = DBUtil::fancy_get_result($stmt);
					if ($linhas) $saida["tabelas"][$tabela . " (" . $coluna . ")"] = $linhas;
				}
			}

			// O relay mora em outro banco. Vazio não entra: uma seção
			// "relay_users: []" no arquivo faria a pessoa procurar o que não
			// existe.
			$relay = $this->linhasRelay($userId);
			if (!empty($relay)) $saida["tabelas"]["relay_users"] = $relay;

			// E a correspondência não está em banco nenhum.
			foreach (["INBOX", MailStoreUtil::TRASH] as $pasta) {
				$msgs = MailStoreUtil::rows($conta["dion_email_local"], $pasta, true);
				if ($msgs) $saida["correio"][$pasta] = $msgs;
			}

			return $saida;
		}

		// Token de relay: banco à parte, e a tabela pode não existir numa
		// instalação sem relay. O token em si não sai -- é credencial viva,
		// como a chave de aparelho.
		private function linhasRelay($userId, $paraApagar = false) {
			// O relay pode não estar configurado nesta instalação; o
			// getRelayDB devolve false nesse caso, em vez de explodir.
			try {
				$db = RelayUtil::getInstance()->getRelayDB();
			} catch (\Throwable $e) {
				return null;
			}
			if (!$db) return null;
			$stmt = @$db->prepare($paraApagar
				? "delete from relay_users where user_id = ?"
				: "select user_id, number from relay_users where user_id = ?");
			if (!$stmt) return null;
			$id = (int)$userId;
			$stmt->bind_param("i", $id);
			if (!$stmt->execute()) return null;
			if ($paraApagar) return $stmt->affected_rows;
			return DBUtil::fancy_get_result($stmt);
		}

		// ------------------------------------------------------------------
		// EXCLUSÃO
		// ------------------------------------------------------------------

		// Apaga a conta e tudo que é dela. Devolve o que foi tirado de cada
		// lugar, para a tela poder dizer o que aconteceu e o registro de
		// administração guardar o número.
		//
		// A correspondência vai PRIMEIRO, e de propósito: ela mora fora do
		// banco, então não entra em transação nenhuma. Se ela falhar, o
		// cadastro ainda está de pé e a pessoa pode tentar de novo. Na ordem
		// inversa, uma falha no Dovecot deixaria correio órfão de uma conta
		// que não existe mais -- sem dono, sem tela que o mostre e sem
		// ninguém para pedir que apague.
		public function erase($userId) {
			$conta = $this->conta($userId);
			if ($conta === null) return null;

			$feito = ["correio" => 0, "tabelas" => [], "conta" => 0];

			foreach (["INBOX", MailStoreUtil::TRASH] as $pasta) {
				$msgs = MailStoreUtil::rows($conta["dion_email_local"], $pasta, false);
				if (!$msgs) continue;
				$ids = array_map(function ($m) { return $m["id"]; }, $msgs);
				$feito["correio"] += MailStoreUtil::purge($conta["dion_email_local"], $ids);
			}

			$db = DBUtil::getInstance()->getDB();
			$enderecos = $this->enderecos($conta);

			foreach (self::POR_EMAIL as $t) {
				list($tabela, $colunas) = $t;
				if (!$this->existe($db, $tabela)) continue;
				foreach ($colunas as $coluna) {
					$marcas = implode(",", array_fill(0, count($enderecos), "?"));
					$stmt = $db->prepare("delete from `{$tabela}` where `{$coluna}` in ({$marcas})");
					$stmt->bind_param(str_repeat("s", count($enderecos)), ...$enderecos);
					$stmt->execute();
					if ($stmt->affected_rows > 0) {
						$chave = $tabela . " (" . $coluna . ")";
						$feito["tabelas"][$chave] = ($feito["tabelas"][$chave] ?? 0) + $stmt->affected_rows;
					}
				}
			}

			foreach (self::POR_ID as $t) {
				list($tabela, $coluna) = $t;
				if (!$this->existe($db, $tabela)) continue;
				$stmt = $db->prepare("delete from `{$tabela}` where `{$coluna}` = ?");
				$id = (int)$userId;
				$stmt->bind_param("i", $id);
				$stmt->execute();
				if ($stmt->affected_rows > 0) {
					$feito["tabelas"][$tabela] = ($feito["tabelas"][$tabela] ?? 0) + $stmt->affected_rows;
				}
			}

			$n = $this->linhasRelay($userId, true);
			if ($n) $feito["tabelas"]["relay_users"] = $n;

			// O cadastro por último: enquanto ele existir, o que sobrou tem
			// dono e pode ser apagado numa segunda tentativa.
			$stmt = $db->prepare("delete from sys_users where id = ?");
			$id = (int)$userId;
			$stmt->bind_param("i", $id);
			$stmt->execute();
			$feito["conta"] = $stmt->affected_rows;

			return $feito;
		}
	}
