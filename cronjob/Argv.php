<?php

namespace Utils\Cronjob;

abstract class Argv {
	protected $task_name;
	protected $args_var 			= [];
	
	protected $require_task_name 	= false;
	protected $allowed_argv 		= [];
	
	protected $verbose 				= false;
	
	public function __construct(){
		global $argv;
		
		$this->parse_argv($argv);
		
		if(isset($this->args_var['v'])){
			$this->verbose = (int)($this->args_var['v'] ?: 1);
		}
	}
	
	public function get_task_name(): string{
		return $this->task_name;
	}
	
	public function get_arg_var(string $arg, bool $decode=false){
		if(isset($this->args_var[$arg])){
			return $decode ? unserialize(base64_decode($this->args_var[$arg])) : $this->args_var[$arg];
		}
		
		return null;
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
					
					$this->args_var[$key] = '';
				}
				
				if(!in_array($key, $this->allowed_argv)){
					throw new Error('Invalid argument: -'.$arg);
				}
			}
			else{
				if(!$this->require_task_name){
					throw new Error('Task name is not allowed');
				}
				
				$this->task_name = $arg;
			}
		}
		
		if($this->require_task_name && !$this->task_name){
			throw new Error('Task name is not given');
		}
	}
}