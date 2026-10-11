<?php
require_once '../../classes/TemplateUtil.php';require_once '../../classes/SessionUtil.php';require_once '../../classes/AdminUtil.php';require_once '../../classes/CsrfUtil.php';require_once '../../classes/MarioKartCupUtil.php';
session_start();AdminUtil::guard();
$id=filter_var($_GET['edit']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
$today=new DateTimeImmutable('today',new DateTimeZone('Asia/Tokyo'));$last=$today->modify('+6 days')->format('Y-m-d');$next=$today->modify('+7 days')->format('Y-m-d');
$form=['game_region'=>'j','file_name'=>'reon-cup','start_date'=>$today->format('Y-m-d'),'end_date'=>$last,'entry_start_date'=>$today->format('Y-m-d'),'entry_end_date'=>$last,'ranking_start_date'=>$today->format('Y-m-d'),'ranking_end_date'=>$last,'next_start_date'=>$next,'next_end_date'=>$today->modify('+13 days')->format('Y-m-d'),'course'=>0,'num_attempts'=>3,'character'=>0,'start_coins'=>0,'message'=>''];
if($id){$form=MarioKartCupUtil::find($id);if(!$form){http_response_code(404);exit;}}
$notice=null;$kind='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
 CsrfUtil::check();$action=(string)($_POST['action']??'');
 $error='invalid';
 if($action==='save'){$form=$_POST;$error=MarioKartCupUtil::save($form,$id);}
 elseif($action==='delete')$error=MarioKartCupUtil::delete((int)($_POST['id']??0),(string)($_POST['confirmation']??''));
 AdminUtil::getInstance()->log('mariokart-cup.'.($error===''?$action:'failed'),(string)$id,$error);
 if($error===''){header('Location: /admin/mariokart_cups.php?saved=1');exit;}
 $notice=TemplateUtil::translate('mariokart-admin.error').': '.$error;$kind='bad';
}
if(isset($_GET['saved']))$notice=TemplateUtil::translate('mariokart-admin.saved');
$form['file_name']=preg_replace('/\.cgb$/i','',(string)($form['file_name']??''));
echo TemplateUtil::render('admin/mariokart_cups',['cups'=>MarioKartCupUtil::all(),'form'=>$form,'editing'=>$id,'dates'=>MarioKartCupUtil::DATES,'flags'=>MarioKartCupUtil::FLAGS,'notice'=>$notice,'notice_kind'=>$kind]);
