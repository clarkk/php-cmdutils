<?php

require_once '../Cmd.php';
require_once 'unit_test/Test.php';

try{
	$Test = new \Cmdutils\Test\Unit;
	$Test->proc('echo this-test', '', 'this-test');
	$Test->proc_stream('php procs/proc.php', '', '');
	$Test->proc_stream('php procs/proc_term.php', '', '', 1);
}
catch(\Cmdutils\Test\Error $e){
	echo "Test failed on command: ".$e->getMessage()."\n";
}