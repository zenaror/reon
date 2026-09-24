<?php
	require_once(__DIR__ . "/SettingsUtil.php");
	require_once(__DIR__ . "/DBUtil.php");

	// As gravações do modo torneio, do lado do site.
	//
	// Quem grava é o mobile-relay, num diretório dele. O site só lista e
	// entrega -- não grava, não converte, não interpreta. A conversão para
	// replay é de outra pessoa, e o que ela precisa é exatamente o arquivo
	// como o relay o escreveu.
	//
	// Dá para ler porque o relay roda como o MESMO usuário do PHP (reon, ver
	// a unit reon-mobile-relay). Se um dia isso mudar, esta classe passa a
	// enxergar um diretório vazio em vez de falhar -- e a tela diz isso, em
	// vez de mentir que não há partida gravada.
	class CaptureStoreUtil {

		// Onde o relay escreve: o `directory` do [capture] dele.
		//
		// Fora do checkout, de propósito. A primeira tentativa foi gravar em
		// /opt/mobile-relay/captures, e não funcionou: o relay roda como
		// `reon` e aquele diretório é do `ubuntu`, então a gravação falhava
		// com Permission denied -- e o modo torneio pareceria ligado sem
		// gravar nada, provavelmente descoberto no dia do torneio. Agora é
		// um StateDirectory= do systemd, criado já com o dono certo.
		const DIRECTORY = "/var/lib/reon-captures";

		// O relay nomeia cada arquivo <hora>-<meu número>-<par>-<papel>.jsonl
		// e nada mais entra aqui: um nome que não casa com isto não é
		// gravação nossa, e servir arquivo arbitrário de um diretório é como
		// se transforma "baixar log" em "ler qualquer coisa do disco".
		const NAME = '/^[0-9]{8}T[0-9]{6}-[A-Za-z0-9]+-[A-Za-z0-9]+-(caller|receiver|peer)\.jsonl$/';

		private static $instance;

		public static function getInstance() {
			if (self::$instance === null) self::$instance = new self();
			return self::$instance;
		}

		public function isOn() {
			return SettingsUtil::getInstance()->getValid("relay_capture") === "1";
		}

		// O diretório existe e dá para ler? A tela precisa separar "ninguém
		// jogou ainda" de "não consigo ver onde o relay grava".
		public function readable() {
			return is_dir(self::DIRECTORY) && is_readable(self::DIRECTORY);
		}

		// Uma linha por arquivo, mais recente primeiro. O par e o papel saem
		// do próprio nome; o resto vem do sistema de arquivos, para listar
		// sem abrir e ler cada arquivo.
		public function sessions() {
			if (!$this->readable()) return [];
			$saida = [];
			foreach ((array)scandir(self::DIRECTORY) as $nome) {
				if (!preg_match(self::NAME, $nome)) continue;
				$caminho = self::DIRECTORY . "/" . $nome;
				if (!is_file($caminho)) continue;
				$partes = explode("-", substr($nome, 0, -6));
				$cabecalho = $this->header($caminho);
				$saida[] = [
					"name" => $nome,
					"when" => $partes[0] ?? "",
					// A hora em forma de gente, para a lista poder ser lida
					// sem decifrar 20260924T142147.
					"when_text" => $this->whenText($partes[0] ?? "", $cabecalho),
					"number" => $partes[1] ?? "",
					"pair" => $partes[2] ?? "",
					"role" => $partes[3] ?? "",
					"user_id" => $cabecalho["user_id"] ?? null,
					"size" => (int)filesize($caminho),
					"mtime" => (int)filemtime($caminho),
				];
			}
			usort($saida, function ($a, $b) { return $b["mtime"] - $a["mtime"]; });
			return $saida;
		}

		// A primeira linha do arquivo, que é o registro de início. Só ela: o
		// resto é a partida, e a lista não precisa dela para se montar.
		private function header($caminho) {
			$f = @fopen($caminho, "r");
			if ($f === false) return [];
			// Teto na leitura: a primeira linha de um arquivo nosso tem
			// algumas centenas de bytes, e um arquivo que não seja nosso não
			// vai puxar megabytes para a memória por causa disto.
			$linha = fgets($f, 8192);
			fclose($f);
			if ($linha === false) return [];
			$dados = json_decode($linha, true);
			return (is_array($dados) && ($dados["type"] ?? "") === "start")
				? $dados : [];
		}

		// Nomes de conta para os ids que apareceram na lista, numa consulta
		// só. Resolver aqui e não no arquivo é o que faz um nome trocado
		// aparecer certo na lista, e uma conta apagada aparecer como ausente
		// em vez de mostrar um nome que já não existe.
		public function usernames($sessions) {
			$ids = [];
			foreach ($sessions as $s) {
				$id = $s["user_id"] ?? null;
				if ($id !== null && (int)$id > 0) $ids[(int)$id] = true;
			}
			if (!$ids) return [];
			$ids = array_keys($ids);
			$marcas = implode(",", array_fill(0, count($ids), "?"));
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select id, username from sys_users where id in ({$marcas})");
			$stmt->bind_param(str_repeat("i", count($ids)), ...$ids);
			$stmt->execute();
			$mapa = [];
			foreach (DBUtil::fancy_get_result($stmt) as $linha) {
				$mapa[(int)$linha["id"]] = $linha["username"];
			}
			return $mapa;
		}

		// "2026-09-24 14:21 UTC". Prefere o que o relay escreveu; o nome do
		// arquivo é a rede de baixo, para um arquivo sem cabeçalho legível
		// ainda aparecer com data.
		private function whenText($compacto, $cabecalho) {
			if (!empty($cabecalho["started_utc"])) {
				return substr((string)$cabecalho["started_utc"], 0, 16) . " UTC";
			}
			if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', (string)$compacto, $m)) {
				return sprintf("%s-%s-%s %s:%s UTC", $m[1], $m[2], $m[3], $m[4], $m[5]);
			}
			return (string)$compacto;
		}

		// O caminho de um arquivo que EXISTE e cujo nome passa no padrão, ou
		// null. Nunca concatena o que veio do pedido sem essa peneira, e a
		// checagem de basename fecha o caminho de ../ antes de tocar o disco.
		public function path($nome) {
			$nome = (string)$nome;
			if ($nome !== basename($nome)) return null;
			if (!preg_match(self::NAME, $nome)) return null;
			$caminho = self::DIRECTORY . "/" . $nome;
			return is_file($caminho) && is_readable($caminho) ? $caminho : null;
		}

		// As duas metades de uma partida, para a tela poder oferecer o par.
		// Mesma hora de início e números trocados: é isso que faz duas
		// gravações serem uma conversa, e não dois arquivos parecidos.
		public function peerOf($sessao) {
			$alvo = sprintf("%s-%s-%s-", $sessao["when"], $sessao["pair"], $sessao["number"]);
			foreach ($this->sessions() as $outra) {
				if (strpos($outra["name"], $alvo) === 0) return $outra["name"];
			}
			return null;
		}
	}
