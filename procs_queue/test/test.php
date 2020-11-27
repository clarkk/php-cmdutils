<?php

require_once '../../Procs_queue.php';

class Test_queue extends \Utils\Procs_queue\Procs_queue {
	private $tasks = [
		
	];
	
	protected function task_fetch(){
		return array_pop($this->tasks);
	}
}

// tmp folder taskname_2020-11-26-0932_id

try{
	$Queue = new Test_queue(Test_queue::VERBOSE_COLOR);
	$Queue->add_worker('root', 'worker.dynaccount.com', '/var/www/worker.dynaccount.com/', 'tmp/test_proc.php', 'tmp');
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