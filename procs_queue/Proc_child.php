<?php

namespace Utils\Procs_queue;

if(PHP_SAPI != 'cli') exit;

abstract class Proc_child {
	protected $allowed_argv 	= [];
	protected $args 			= [];
	
	public function __construct(array $argv){
		ini_set('max_execution_time', 60*60*3);
		
		$this->parse_argv($argv);
		
		//print_r($this->args);
		
		echo "hey\n";
		sleep(2);
		echo "hmm\n";
		sleep(2);
		fwrite(STDERR, 'An error occurred!');
		sleep(2);
		echo "weee\n";
	}
	
	protected function error(string $error){
		fwrite(STDERR, $error);
		exit(1);
	}
	
	private function parse_argv(array $argv){
		array_shift($argv);
		
		foreach($argv as $arg){
			if(substr($arg, 0, 1) != '-'){
				$this->error("Invalid argument: $arg");
			}
			
			$arg = substr($arg, 1);
			
			if($pos = strpos($arg, '=')){
				$key 	= substr($arg, 0, $pos);
				$value 	= substr($arg, $pos+1);
			}
			else{
				$key 	= $arg;
				$value 	= '';
			}
			
			if(!in_array($key, $this->allowed_argv)){
				$this->error("Invalid argument: -$arg");
			}
			
			$this->args[$key] = $value;
		}
	}
}