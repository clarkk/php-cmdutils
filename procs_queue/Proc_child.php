<?php

namespace Utils\Procs_queue;

if(PHP_SAPI != 'cli') exit;

abstract class Proc_child {
	protected $allowed_argv 	= [];
	protected $args 			= [];
	
	public function __construct(array $argv){
		ini_set('max_execution_time', 60*60*3);
		
		$this->parse_argv($argv);
		
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
		
		//$this->allowed_argv
		
		foreach($argv as $arg){
			$this->error("Test exit 1");
			if(substr($arg, 0, 1) != '-'){
				$this->error("Invalid argument: $arg");
			}
			
			$arg = substr($arg, 1);
			
			$pos = strpos($arg, '=');
			echo "pos: $pos\n";
		}
		print_r($argv);
	}
}