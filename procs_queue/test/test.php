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
	$Queue->add_worker('root', 'worker.dynaccount.com', '/var/www/worker.dynaccount.com/', 'tmp/test_proc.php', 'tmp');
	$Queue->exec('', 'proc_child.php', 'tmp');
}
catch(\Utils\Procs_queue\Procs_queue_error $e){
	echo "\n\tTEST FAILED ON URL: ".$e->getMessage()."\n\n";
}