<?php
	// SPDX-License-Identifier: MIT
	//
	// Ponte fina entre os arquivos por-caminho (menu.php e o handler de
	// payload, um por região) e o StadiumUtil, que tem a lógica de verdade.
	// Mesmo papel que web/cgb/pokemon/news.php tem para as News.
	require_once(dirname(dirname(__DIR__))."/classes/StadiumUtil.php");

	// Bytes do menu.cgb para a região, ou null se não houver distribuição
	// ativa (o chamador deve devolver 404, não um corpo vazio -- um menu.cgb
	// de tamanho zero seria N=0, que o Crystal lê como 256 entradas de lixo,
	// spec.md §1.3).
	function get_stadium_menu($region) {
		return StadiumUtil::buildMenu($region);
	}

	// Bytes do payload para o slug, ou null.
	function get_stadium_payload($region, $slug) {
		return StadiumUtil::payloadForSlug($region, $slug);
	}
?>
