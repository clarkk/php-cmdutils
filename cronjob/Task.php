<?php

namespace Utils\Cronjob;

abstract class Task {
	use Cronjob_error_report;
	
	protected $task_name;
	protected $verbose = false;
	
	public function __construct(string $task_name, int $verbose){
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		$this->exec();
	}
}