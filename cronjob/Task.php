<?php

namespace Utils\Cronjob;

abstract class Task {
	protected $task_name;
	protected $verbose = false;
	
	private $time_start;
	
	public function __construct(string $task_name, int $verbose){
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		$this->time_start 	= time();
		
		$this->exec();
	}
	
	protected function get_remain_time(): int{
		return time() - $this->time_start - static::TIMEOUT;
	}
}