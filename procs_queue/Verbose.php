<?php

namespace Utils\Procs_queue;

abstract class Verbose {
	protected $verbose 	= 0;
	
	const COLOR_GRAY 	= '1;30';
	const COLOR_GREEN 	= '0;32';
	const COLOR_YELLOW 	= '1;33';
	const COLOR_RED 	= '0;31';
	const COLOR_PURPLE 	= '0;35';
	
	const VERBOSE_PLAIN = 1;
	const VERBOSE_COLOR = 2;
	
	const CRLF = "\r\n";
	
	private $log;
	
	public function __construct(){
		$this->log = \Log\Log::get_log_file($this->task_name, false, true);
	}
	
	protected function verbose(string $output, string $color=''){
		if($color){
			$output = $this->output($output);
			
			$this->log($output);
			
			if($this->verbose == self::VERBOSE_COLOR){
				$output = "\033[".$color.'m'.$output."\033[0m";
			}
		}
		else{
			$output = "\t> ".$this->output($output);
			
			$this->log($output);
		}
		
		echo $output.self::CRLF;
	}
	
	private function output(string $output): string{
		return str_replace("\n", self::CRLF."\t> ", $output);
	}
	
	private function log(string $output){
		file_put_contents($this->log, $output.self::CRLF, FILE_APPEND);
	}
}