<?php

require_once 'cmdutils/Cmd.php';

$cmd = new \Cmdutils\Cmd;
$err = $cmd->exec('hostname');

echo "err: $err\n";
echo 'out: '.$cmd->output()."\n";

echo "done!\n";