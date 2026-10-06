<?php
require_once '../../classes/TemplateUtil.php';
require_once '../../classes/CsrfUtil.php';
require_once '../../classes/SessionUtil.php';
require_once '../../classes/AdminUtil.php';
require_once '../../classes/BmvjAdminUtil.php';
session_start();
AdminUtil::guard();
$notice = null; $kind = 'ok';
$id = (string)($_GET['edit'] ?? '');
$editing = $id !== '';
$form = ['game_id'=>'','blocks_needed'=>1,'category_icon'=>6,'minigame_type'=>1,'price_yen'=>0,
    'min_level_react'=>0,'min_level_smart'=>0,'min_level_sense'=>0,'min_hidden_level_a'=>0,'min_hidden_level_b'=>0,'is_custom'=>1,'is_active'=>0];
if ($editing) {
    $row = BmvjAdminUtil::find($id);
    if ($row === null) { http_response_code(404); exit(); }
    $form = BmvjAdminUtil::form($row);
}
if (isset($_GET['download'])) {
    $row = BmvjAdminUtil::find((string)$_GET['download']);
    if ($row === null) { http_response_code(404); exit(); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $row['download_filename'] . '"');
    echo $row['game_binary']; exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfUtil::check();
    $action = (string)($_POST['form_action'] ?? ''); $error = 'invalid';
    $target = (string)($_POST['game_id'] ?? '');
    if ($action === 'save') {
        $form = $_POST;
        if ($editing) $form['game_id'] = $id;
        $body = null; $upload = $_FILES['body'] ?? null;
        if ($upload !== null && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > BmvjAdminUtil::MAX_BODY_BYTES || !str_ends_with(strtolower((string)$upload['name']), '.cgb') || !is_uploaded_file($upload['tmp_name'])) $error = 'upload-error';
            else { $body = file_get_contents($upload['tmp_name']); $error = $body === false ? 'upload-error' : ''; if ($body === false) $body = null; }
        } else $error = '';
        if ($error === '') $error = BmvjAdminUtil::save($form, $body, $editing);
        $target = (string)$form['game_id'];
    } elseif ($action === 'toggle') {
        $row = BmvjAdminUtil::find($target);
        if ($row !== null) {
            $input = BmvjAdminUtil::form($row);
            if (!$row['is_custom']) unset($input['is_custom']);
            if ($row['is_active']) unset($input['is_active']); else $input['is_active'] = '1';
            $error = BmvjAdminUtil::save($input, null, true);
        }
    } elseif ($action === 'delete') {
        $error = BmvjAdminUtil::delete($target, (string)($_POST['confirmation'] ?? ''));
    }
    if (!preg_match('/^G[0-9]{3}$/D', $target)) $target = '';
    AdminUtil::getInstance()->log('bmvj.' . ($error === '' ? $action : 'failed'), $target, $error);
    if ($error === '') { header('Location: /admin/bmvj.php?saved=1'); exit(); }
    $notice = TemplateUtil::translate('bmvj-admin.' . $error); $kind = 'bad';
}
if (isset($_GET['saved'])) $notice = TemplateUtil::translate('bmvj-admin.saved');
$games = [];
foreach (BmvjAdminUtil::all() as $row) $games[] = BmvjAdminUtil::form($row);
echo TemplateUtil::render('admin/bmvj', ['games'=>$games,'form'=>$form,'editing'=>$editing,'current_tab'=>($editing || ($_GET['tab'] ?? '') === 'upload' || (($_POST['form_action'] ?? '') === 'save')) ? 'upload' : 'library','notice'=>$notice,'notice_kind'=>$kind]);
