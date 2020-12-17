<?php

namespace Utils\Cronjob;

abstract class Argv {
	protected $task_name;
	protected $args_switch 		= [];
	protected $args_var 		= [];
	
	protected $allow_task_name 	= false;
	protected $allowed_argv 	= [];
	
	protected $verbose 			= false;
	
	public function __construct(){
		global $argv;
		
		$this->parse_argv($argv);
		
		if(in_array('v', $this->args_switch)){
			$this->verbose = true;
		}
	}
	
	private function parse_argv(array $argv){
		array_shift($argv);
		
		foreach($argv as $arg){
			if(substr($arg, 0, 1) == '-'){
				$arg = substr($arg, 1);
				
				if($pos = strpos($arg, '=')){
					$key 	= substr($arg, 0, $pos);
					$value 	= substr($arg, $pos+1);
					
					$this->args_var[$key] = $value;
				}
				else{
					$key = $arg;
					
					$this->args_switch[] = $arg;
				}
				
				if(!in_array($key, $this->allowed_argv)){
					throw new Error('Invalid argument: -'.$arg);
				}
			}
			else{
				if($this->allow_task_name){
					$this->task_name = $arg;
				}
				else{
					throw new Error('Task name is not allowed');
				}
			}
		}
	}
}