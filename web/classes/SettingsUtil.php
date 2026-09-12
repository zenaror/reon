<?php
	require_once("DBUtil.php");

	// Configurações do servidor que quem administra muda pelo painel, sem
	// editar arquivo e sem reiniciar serviço.
	//
	// Ficam no banco e não em arquivo de configuração por um motivo prático: o
	// Dovecot lê a mesma linha dentro da própria consulta de autenticação, e o
	// nosso POP3 lê na hora do login. Um interruptor no painel vale na próxima
	// conexão, nos dois, sem ninguém recarregar nada.
	class SettingsUtil {

		// Aceita só o que existe. A chave vem de formulário, e uma lista
		// fechada é o que impede alguém de inventar nome e gravar linha solta.
		const KNOWN = [
			// Se o servidor ainda aceita USER/PASS com a senha de oito
			// caracteres, ou se exige APOP. Ver examples/dovecot/.
			"pop3_password_fallback" => "1",

			// O que o gerador do mobile_config.bin escreve. Só entra aqui o
			// que NÃO depende da conta: endereço do DNS, do relay, portas,
			// modelo do adaptador e a marca de não-tarifado. Endereço de
			// e-mail, gID, chave de aparelho e token de relay saem do
			// cadastro de cada pessoa e não têm o que configurar.
			//
			// Os padrões abaixo são exatamente o que o gerador escrevia
			// fixo no código antes de virem para cá, então uma tabela vazia
			// produz a mesma bin de sempre.
			"bin_dns1_host" => "152.67.55.127",
			"bin_dns1_port" => "53",
			// Vazio de propósito: o REON tem um servidor de DNS só, e
			// repetir o endereço não dá redundância -- o segundo falharia
			// pelo mesmo motivo que o primeiro. Vazio vira
			// MOBILE_ADDRTYPE_NONE e o core nem lê o campo.
			"bin_dns2_host" => "",
			"bin_dns2_port" => "53",
			"bin_relay_host" => "152.67.55.127",
			// MOBILE_DEFAULT_RELAY_PORT da libmobile.
			"bin_relay_port" => "31227",
			"bin_p2p_port" => "1027",
			// enum mobile_adapter_device: 8 azul, 9 amarelo, 10 verde,
			// 11 vermelho. O byte guardado no arquivo é este OU 0x80
			// quando não-tarifado.
			"bin_adapter_device" => "8",
			"bin_unmetered" => "0",
		];

		// O que cada chave aceita. Isto não é zelo: o valor vai para dentro
		// de um arquivo binário que um cartucho de 2001 interpreta, e um
		// endereço mal digitado no formulário quebraria o download de TODO
		// mundo, sem mensagem de erro em lugar nenhum. Validado ao gravar e
		// conferido de novo ao gerar.
		const REGRAS = [
			"pop3_password_fallback" => "bool",
			"bin_dns1_host" => "ipv4",
			"bin_dns2_host" => "ipv4_ou_vazio",
			"bin_relay_host" => "ipv4",
			"bin_dns1_port" => "porta",
			"bin_dns2_port" => "porta",
			"bin_relay_port" => "porta",
			"bin_p2p_port" => "porta",
			"bin_adapter_device" => "modelo",
			"bin_unmetered" => "bool",
		];

		// Devolve true quando o valor serve para a chave.
		public static function isValid($name, $value) {
			$regra = self::REGRAS[(string)$name] ?? null;
			if ($regra === null) return false;
			$value = (string)$value;
			switch ($regra) {
				case "bool":
					return $value === "0" || $value === "1";
				case "ipv4":
					return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
				case "ipv4_ou_vazio":
					return $value === "" ||
						filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
				case "porta":
					// 0 não é porta válida para escutar, mas é o valor certo
					// para um campo desligado, então entra.
					return preg_match('/^\d{1,5}$/', $value) === 1 && (int)$value <= 65535;
				case "modelo":
					return in_array($value, ["8", "9", "10", "11"], true);
			}
			return false;
		}

		// O valor guardado, ou o padrão quando o que está no banco não passa
		// na regra. É a segunda rede: se uma linha ruim entrar por outro
		// caminho que não o painel, o gerador continua produzindo arquivo
		// válido em vez de um que o cartucho não entende.
		public function getValid($name) {
			$v = $this->get($name);
			return self::isValid($name, $v) ? $v : (self::KNOWN[$name] ?? null);
		}

		private static $instance = null;
		public static function getInstance() {
			if (self::$instance === null) self::$instance = new SettingsUtil();
			return self::$instance;
		}

		private $cache = [];

		public static function isKnown($name) {
			return array_key_exists((string)$name, self::KNOWN);
		}

		public function get($name) {
			if (!self::isKnown($name)) return null;
			if (array_key_exists($name, $this->cache)) return $this->cache[$name];

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select value from sys_settings where name = ? limit 1");
			$stmt->bind_param("s", $name);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			// Sem linha vale o padrão: uma tabela vazia não pode significar
			// "tudo desligado", que aqui seria deixar gente sem correio.
			return $this->cache[$name] = ($row ? $row["value"] : self::KNOWN[$name]);
		}

		public function isOn($name) {
			return $this->get($name) === "1";
		}

		public function set($name, $value) {
			if (!self::isKnown($name)) return false;
			$value = (string)$value;
			// Recusa na porta de entrada. Ver REGRAS.
			if (!self::isValid($name, $value)) return false;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"insert into sys_settings (name, value) values (?, ?)
				 on duplicate key update value = values(value)"
			);
			$stmt->bind_param("ss", $name, $value);
			$ok = $stmt->execute();
			if ($ok) $this->cache[$name] = $value;
			return $ok;
		}
	}
?>
