<?php

require_once '../../Procs_queue.php';

try{
	$Queue = new \Utils\Procs_queue\Procs_queue(\Utils\Procs_queue\Procs_queue::VERBOSE_COLOR);
	
	$list = [
		'php procs/proc.php',
		'php procs/proc.php'
	];
	
	while($list){
		$command = reset($list);
		
		$Queue->put($command);
		break;
	}
}
catch(\Utils\Procs_queue\Error $e){
	echo "\n\tTEST FAILED ON URL: ".$e->getMessage()."\n\n";
}