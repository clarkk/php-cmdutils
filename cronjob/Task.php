<?php

namespace Utils\Cronjob;

abstract class Task extends \Utils\Verbose {
	protected $task_name;
	
	private $time_start;
	
	public function __construct(string $task_name, int $verbose){
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		$this->time_start 	= time();
		
		parent::__construct();
		
		$this->exec();
	}
	
	protected function get_remain_time(): int{
		return time() - $this->time_start - static::TIMEOUT;
	}
}