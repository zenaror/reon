<?php
require_once __DIR__.'/SettingsUtil.php';
/** One registry for routing, administration and worker dependencies. */
class GameSupportUtil {
    const MODULES = [
        'trainer'=>['label'=>'Mobile Trainer','ids'=>[],'dependencies'=>['web','dns','mail'],'required'=>true],
        'crystal'=>['label'=>'Pokémon Crystal','ids'=>['CGB-BXTJ','CGB-BXTE','CGB-BXTD','CGB-BXTF','CGB-BXTS','CGB-BXTI','CGB-BXTP','CGB-BXTU'],'dependencies'=>['web','dns','mail','pokemon-legality'],'jobs'=>['reon-pokemon-exchange','reon-pokemon-battle','reon-auto-schedule','reon-auto-schedule-refresh']],
        'gbwars'=>['label'=>'Game Boy Wars 3','ids'=>['CGB-B9AJ','CGB-BWWJ','CGB-BWWE'],'dependencies'=>['web','dns','mail']],
        'mariokart'=>['label'=>'Mario Kart','ids'=>['AGB-AMKJ','AGB-AMKE'],'dependencies'=>['web','dns']],
        'netdeget'=>['label'=>'Net de Get','ids'=>['CGB-BMVJ'],'dependencies'=>['web','dns']],
        'monopoly'=>['label'=>'EX Monopoly','ids'=>['AGB-AMOJ'],'dependencies'=>['web','dns']],
        'mailbottle'=>['label'=>'Mail de Cute','ids'=>['AGB-AMCJ'],'dependencies'=>['web','dns','mail'],'jobs'=>['reon-mail-bottle']],
        'sarukoro'=>['label'=>'Sutakomi: Star Communicator','ids'=>['AGB-ASKJ'],'dependencies'=>['web','dns','mail']],
        'puzzle'=>['label'=>'Morita Shougi Advance','ids'=>['AGB-AMSJ'],'dependencies'=>['web','dns']],
        // Keep the previous setting key for Napoleon; copy its state to the new
        // Monster Guardians switch in migration 20261011130000.
        'football'=>['label'=>'Napoleon','ids'=>['AGB-ANPJ'],'dependencies'=>['web','dns']],
        'monster_guardians'=>['label'=>'Monster Guardians','ids'=>['AGB-AMNJ'],'dependencies'=>['web','dns']],
        'agb_adrj'=>['label'=>'Doraemon: Midori no Wakusei Doki Doki Daikyuushutsu!','ids'=>['AGB-ADRJ'],'dependencies'=>['web','dns']],
        'agb_agtj'=>['label'=>'Zen Nihon GT Senshuken','ids'=>['AGB-AGTJ'],'dependencies'=>['web','dns']],
        'agb_ambj'=>['label'=>'Mobile Pro Yakyuu: Kantoku no Saihai','ids'=>['AGB-AMBJ'],'dependencies'=>['web','dns']],
        'agb_amgj'=>['label'=>'Exciting Bass','ids'=>['AGB-AMGJ'],'dependencies'=>['web','dns']],
        'agb_ay5j'=>['label'=>'Yu-Gi-Oh! Duel Monsters 5: Expert 1','ids'=>['AGB-AY5J'],'dependencies'=>['web','dns']],
    ];
    public static function enabled($module) {
        if (!isset(self::MODULES[$module])) return true;
        if (!empty(self::MODULES[$module]['required'])) return true;
        return SettingsUtil::getInstance()->isOn('game_support_'.$module);
    }
    public static function moduleForPath($path) {
        if (!preg_match('#(?:AGB|CGB|SGB)-[A-Z0-9]{4}#i', (string)$path, $m)) return null;
        foreach (self::MODULES as $key=>$meta) if (in_array(strtoupper($m[0]), $meta['ids'], true)) return $key;
        return null;
    }
    public static function guardPath($path) {
        $module = self::moduleForPath($path);
        if ($module !== null && !self::enabled($module)) {
            require_once __DIR__.'/ActivityLog.php';
            ActivityLog::record('game-disabled', ['game_id'=>self::gameId($path),'module'=>$module], 'warn');
            http_response_code(503); exit;
        }
    }
    public static function gameId($path) { return preg_match('#(?:AGB|CGB|SGB)-[A-Z0-9]{4}#i', (string)$path, $m) ? strtoupper($m[0]) : ''; }
    public static function dependencies() {
        $needed=[];
        foreach (self::MODULES as $key=>$m) if (self::enabled($key)) foreach ($m['dependencies'] as $d) $needed[$d]=true;
        return array_keys($needed);
    }
    public static function mailRequired() { return in_array('mail', self::dependencies(), true); }
}
