<?php
// SPDX-License-Identifier: MIT
use Phinx\Migration\AbstractMigration;

/** Bring historical test entries into the same library as published content. */
final class ManageBmvjLegacyCatalog extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('bmvj_custom_games');
        if (!$table->hasColumn('legacy_catalog_record')) {
            $table->addColumn('legacy_catalog_record', 'blob', ['null'=>true, 'comment'=>'Original historical fixture record; inactive until replaced with a valid upload'])->update();
        }
        $path = dirname(__DIR__, 2).'/web/cgb/download/A4/CGB-BMVJ/RomList.cgb';
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes)<3) throw new RuntimeException('Missing historical catalog');
        $count=ord($bytes[0]);
        if ($count<1 || $count>78 || strlen($bytes)<1+2*$count) throw new RuntimeException('Invalid historical catalog');
        $offsets=array_values(unpack('v'.$count, substr($bytes,1,2*$count)));
        if (count(array_unique($offsets))!==$count) throw new RuntimeException('Duplicate historical offsets');
        $sorted=$offsets;sort($sorted,SORT_NUMERIC);
        foreach ($sorted as $i=>$offset) {
            $end=$sorted[$i+1]??strlen($bytes);
            if ($offset<1+2*$count || $end<=$offset || $end>strlen($bytes)) throw new RuntimeException('Invalid historical bounds');
            $record=substr($bytes,$offset,$end-$offset);
            if (strlen($record)<23) throw new RuntimeException('Truncated historical record');
            $pos=20;$strings=[];
            foreach (['title','description'] as $key) {
                if ($pos>=strlen($record)) throw new RuntimeException('Missing historical text');
                $length=ord($record[$pos++]);
                if (!$length || $pos+$length>strlen($record)) throw new RuntimeException('Invalid historical text');
                $strings[$key]=substr($record,$pos,$length);$pos+=$length;
            }
            // These old fixtures use NUL-prefixed, NUL-terminated filenames,
            // zero game IDs and invalid block/type fields. Preserve raw bytes
            // as provenance; do not pretend that they are publishable content.
            if (!preg_match('/(?:^|\x00)([0-9]{4}\.?(G[0-9]{3})\.cgb)\x00/', substr($record,$pos),$m)) throw new RuntimeException('Unknown historical filename');
            $id=$m[2];$quoted=$this->getAdapter()->getConnection()->quote($id);
            if ($this->fetchRow('SELECT game_id FROM bmvj_custom_games WHERE game_id='.$quoted)) continue;
            $this->table('bmvj_custom_games')->insert([
                'game_id'=>$id,'blocks_needed'=>ord($record[4]),'category_icon'=>ord($record[5]),
                'min_level_react'=>ord($record[12]),'min_level_smart'=>ord($record[13]),'min_level_sense'=>ord($record[14]),
                'min_hidden_level_a'=>unpack('v',substr($record,16,2))[1],'min_hidden_level_b'=>unpack('v',substr($record,18,2))[1],
                'title'=>$strings['title'],'description'=>$strings['description'],'download_filename'=>$m[1],
                'minigame_type'=>0,'price_yen'=>(int)substr($m[1],0,4),'game_binary'=>'',
                'is_custom'=>1,'is_active'=>0,'legacy_catalog_record'=>$record,
            ])->saveData();
        }
    }
    public function down(): void
    {
        // Removing the provenance marker would turn incomplete drafts into
        // apparently normal entries. Restore the pre-migration backup instead.
        throw new RuntimeException('Restore the pre-migration backup to roll back historical catalog import');
    }
}
