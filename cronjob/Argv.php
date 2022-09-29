<?php

namespace Utils\Cronjob;

abstract class Argv {
	const ARG_V 						= 'v';
	const ARG_PROCESS 					= 'process';
	
	protected string $task_name;
	protected array $args_var 			= [];
	
	protected bool $require_task_name 	= false;
	protected array $allowed_argv 		= [];
	
	protected int $verbose	 			= 0;
	
	public function __construct(int $max_exec_time_min=120){
		global $argv;
		
		ini_set('max_execution_time', 60 * $max_exec_time_min);
		
		$this->parse_argv($argv);
		
		if(isset($this->args_var[self::ARG_V])){
			$this->verbose = (int)($this->args_var[self::ARG_V] ?: 1);
		}
	}
	
	public function get_task_name(): string{
		return $this->task_name;
	}
	
	public function get_arg_var(string $arg, bool $decode=false): ?string{
		if(isset($this->args_var[$arg])){
			return $decode ? unserialize(base64_decode($this->args_var[$arg])) : $this->args_var[$arg];
		}
		
		return null;
	}
	
	private function parse_argv(array $argv): void{
		array_shift($argv);
		
		foreach($argv as $arg){
			if(substr($arg, 0, 1) == '-'){
				$arg = substr($arg, 1);
				
				if($pos = strpos($arg, '=')){
					$key 					= substr($arg, 0, $pos);
					$this->args_var[$key]	= substr($arg, $pos+1);
				}
				else{
					$key 					= $arg;
					$this->args_var[$key]	= '';
				}
				
				if(!in_array($key, $this->allowed_argv)){
					throw new Error("Invalid argument: -$arg");
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