<?php

require_once '../../Procs_queue.php';

class Test_queue extends \Utils\Procs_queue\Procs_queue {
	protected function task_fetch(){
		$list = [
			'php procs/proc.php',
			'php procs/proc.php'
		];
	}
}

// tmp folder taskname_2020-11-26-0932_id

try{
	$Queue = new Test_queue(Test_queue::VERBOSE_COLOR);
	$Queue->add_worker('root', 'worker.dynaccount.com', '/var/www/worker.dynaccount.com/tmp');
	$Queue->exec();
	
	/*while($list){
		$command = reset($list);
		
		$Queue->put($command);
		break;
	}*/
}
catch(\Utils\Procs_queue\Error $e){
	echo "\n\tTEST FAILED ON URL: ".$e->getMessage()."\n\n";
}