<?php
	// SPDX-License-Identifier: MIT
	//
	// Thin bridge between the per-path files (menu.php and the payload
	// handler, one per region) and StadiumUtil, which holds the actual
	// logic. Same role web/cgb/pokemon/news.php plays for the News.
	require_once(dirname(dirname(__DIR__))."/classes/StadiumUtil.php");

	// Same requirement as Pokémon News (news.php's
	// bxt_pokemon_news_require_authenticated_user_id()): menu.cgb has no
	// cost prefix in the URL (spec.md §1.3 -- its name is fixed in ROM),
	// so nothing here would normally trigger the 401/WWW-Authenticate
	// challenge that tells the server which account is asking. Type 2
	// forces that challenge anyway, without charging anything, purely to
	// learn the account id for the opt-in check below.
	function bxt_stadium_require_authenticated_user_id() {
		if (defined('CORE_PATH')) {
			require_once(CORE_PATH . "/auth.php");
		}
		if (function_exists('doAuth')) {
			$uid = doAuth(2); // type=2 => always requires auth, never charges
			if (isset($uid) && (int)$uid > 0) {
				return (int)$uid;
			}
		}
		if (isset($_SESSION) && isset($_SESSION['userId']) && isset($_SESSION['type']) && $_SESSION['type'] === 'cgb') {
			return (int)$_SESSION['userId'];
		}
		return 0;
	}

	// menu.cgb bytes for the region, or null if there is no active
	// distribution on the account's track. Official unless the account
	// opted in to custom Mobile Stadium content AND a custom distribution
	// is actually active for the region (StadiumUtil::isCustomForUser()) --
	// same shape as the Pokémon News custom track: NOT opting in still
	// gets official content, it never means nothing (owner's correction,
	// 2026-09-28, to an earlier version of this file that made the opt-in
	// a hard gate). The caller must return 404, not an empty body -- a
	// zero-length menu.cgb would be N=0, which Crystal reads as 256
	// garbage entries, spec.md §1.3.
	function get_stadium_menu($region) {
		$userId = bxt_stadium_require_authenticated_user_id();
		$isCustom = StadiumUtil::isCustomForUser($region, $userId);
		return StadiumUtil::buildMenu($region, $isCustom);
	}

	// Payload bytes for the slug, or null. Re-derives the same track
	// decision as the menu (defense in depth, same shape as news.php
	// checking the opt-in again at every content-serving call, not only
	// once at the index): a cost-prefixed stub already forces
	// authentication through the normal download.php/auth.php path, so
	// this call is typically a cache hit, not a fresh challenge.
	function get_stadium_payload($region, $slug) {
		$userId = bxt_stadium_require_authenticated_user_id();
		$isCustom = StadiumUtil::isCustomForUser($region, $userId);
		return StadiumUtil::payloadForSlug($region, $slug, $isCustom);
	}
?>
