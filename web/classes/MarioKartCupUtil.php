<?php
require_once __DIR__.'/DBUtil.php';
/** Publishes the existing amk_rule wire format; no new cup file format. */
class MarioKartCupUtil {
    const DATES = ['start_date','end_date','next_start_date','next_end_date','entry_start_date','entry_end_date','ranking_start_date','ranking_end_date'];
    const FLAGS = ['coins_enabled','items_enabled','start_item_triple_shroom_enabled','shrooms_only_enabled','cpu_enabled','five_laps_enabled'];
    const NIBBLES = ['character','start_coins'];
    public static function all() { return DBUtil::getInstance()->getDB()->query('SELECT * FROM amk_rule ORDER BY start_date DESC,id DESC')->fetch_all(MYSQLI_ASSOC); }
    public static function find($id) { $s=DBUtil::getInstance()->getDB()->prepare('SELECT * FROM amk_rule WHERE id=?');$s->bind_param('i',$id);$s->execute();return $s->get_result()->fetch_assoc(); }
    public static function save($input, $id = 0) {
        $v=[]; $region=(string)($input['game_region']??'');
        if (!in_array($region,['j','e'],true)) return 'region'; $v['game_region']=$region;
        $name=trim((string)($input['file_name']??''));
        // Also tolerate an existing suffix from an older form; append once.
        $name=preg_replace('/\.cgb$/i','',$name);
        if (!preg_match('/^[A-Za-z0-9_-]{1,48}$/D',$name)) return 'filename'; $v['file_name']=$name.'.cgb';
        foreach(self::DATES as $key) {
            $value=(string)($input[$key]??''); $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value) return 'dates'; $v[$key]=$value;
        }
        foreach([['start_date','end_date'],['next_start_date','next_end_date'],['entry_start_date','entry_end_date'],['ranking_start_date','ranking_end_date']] as [$a,$b]) if($v[$a]>$v[$b])return 'dates';
        foreach(self::FLAGS as $k)$v[$k]=empty($input[$k])?0:1;
        foreach(self::NIBBLES as $k){$n=(string)($input[$k]??'');if(!ctype_digit($n)||(int)$n>15)return 'rules';$v[$k]=(int)$n;}
        foreach(['course'=>19,'num_attempts'=>99] as $k=>$max){$n=(string)($input[$k]??'');if(!ctype_digit($n)||(int)$n>$max)return 'rules';$v[$k]=(int)$n;}
        if($v['num_attempts']<1)return 'rules';
        $message=str_replace(["\r\n","\r"],"\n",(string)($input['message']??''));
        if(strlen($message)>4096||substr_count($message,"\n")>=99||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$message))return 'message';
        $v['message']=str_replace("\n","\r\n",$message);
        $db=DBUtil::getInstance()->getDB();
        if($id && !self::find($id))return 'missing';
        $columns=array_keys($v);$values=array_values($v);
        $sql=$id?'UPDATE amk_rule SET '.implode(',',array_map(fn($k)=>'`'.$k.'`=?',$columns)).' WHERE id=?':'INSERT INTO amk_rule ('.implode(',',array_map(fn($k)=>'`'.$k.'`',$columns)).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        if($id)$values[]=$id;$s=$db->prepare($sql);$s->bind_param(str_repeat('s',count($values)),...$values);$s->execute();return '';
    }
    public static function delete($id,$confirmation) {
        if((string)$id!==$confirmation)return 'confirmation';
        $db=DBUtil::getInstance()->getDB();$s=$db->prepare('SELECT COUNT(*) AS n FROM amk_ghosts_mobilegp WHERE gp_id=?');$s->bind_param('i',$id);$s->execute();if($s->get_result()->fetch_assoc()['n'])return 'has-results';
        $s=$db->prepare('DELETE FROM amk_rule WHERE id=?');$s->bind_param('i',$id);$s->execute();return '';
    }
}
