<?php

namespace Utils\Cronjob;

abstract class Task extends \Utils\Verbose {
	protected $task_name;
	protected $session_start = false;
	
	private $time_start;
	
	public function __construct(string $task_name, int $verbose){
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		$this->time_start 	= time();
		
		parent::__construct();
		
		if($this->session_start){
			session_start();
		}
	}
	
	protected function start_task(){
		if($this->verbose){
			$this->verbose('Task \''.$this->task_name.'\' running as \''.trim(shell_exec('whoami')).'\'', self::COLOR_GREEN);
		}
	}
	
	protected function start_loop(): bool{
		$time_remain = $this->get_remain_time();
		
		if($time_remain >= 0){
			if($this->verbose){
				$this->verbose('Timeout!', self::COLOR_GRAY);
			}
			
			return false;
		}
		
		if($this->verbose){
			$this->verbose("\nLoop started\t\t\t\t\t".$time_remain.' sec', self::COLOR_GRAY);
		}
		
		return true;
	}
	
	protected function get_remain_time(): int{
		return time() - $this->time_start - static::TIMEOUT;
	}
}