<?php

require_once '../../Procs_queue.php';

class Test_queue extends \Utils\Procs_queue\Procs_queue {
	private $tasks = [
		['id' => 1],
		['id' => 2],
		['id' => 3],
		['id' => 4],
		['id' => 5],
		['id' => 6]
	];
	
	protected function task_fetch(): array{
		if($data = array_pop($this->tasks)){
			return [
				'data'	=> $data,
				'file'	=> '/root/test.pdf'
			];
		}
		else{
			return [];
		}
	}
	
	protected function task_success(int $id, array $data){
		
	}
	
	protected function task_failed(int $id, array $data){
		
	}
}

try{
	$Queue = new Test_queue('task_name', Test_queue::VERBOSE_COLOR);
	$Queue->start_redis('88325840bd016afbb75b133b59219f7b71da5a66', 'scan:invoice:abort');
	$Queue->add_worker('root', 'worker.dynaccount.com', '/var/www/worker.dynaccount.com/', 'tmp/test_proc.php', 'tmp');
	$Queue->exec('', 'proc_child.php', 'tmp');
}
catch(\Utils\Procs_queue\Procs_queue_error $e){
	echo "\n\tTEST FAILED ON URL: ".$e->getMessage()."\n\n";
}