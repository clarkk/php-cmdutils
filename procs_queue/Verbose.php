<?php

namespace Utils\Procs_queue;

abstract class Verbose {
	protected $verbose 	= false;
	
	const COLOR_GRAY 	= '1;30';
	const COLOR_GREEN 	= '0;32';
	const COLOR_YELLOW 	= '1;33';
	const COLOR_RED 	= '0;31';
	const COLOR_PURPLE 	= '0;35';
	
	const VERBOSE_PLAIN = 1;
	const VERBOSE_COLOR = 2;
	
	private $log;
	
	const CRLF = "\r\n";
	
	public function __construct(int $verbose=0){
		$this->verbose 	= $verbose;
		
		$this->log = dirname(__FILE__).'/log/output.log';
		file_put_contents($this->log, '');
	}
	
	protected function verbose(string $output, string $color=''){
		if($color){
			$output = str_replace("\n", self::CRLF."\t> ", $output);
			
			$this->log($output);
			
			if($this->verbose == self::VERBOSE_COLOR){
				$output = "\033[".$color.'m'.$output."\033[0m";
			}
		}
		else{
			$output = "\t> ".str_replace("\n", self::CRLF."\t> ", $output);
			$this->log($output);
		}
		
		echo $output.self::CRLF;
	}
	
	private function log(string $output){
		file_put_contents($this->log, $output.self::CRLF, FILE_APPEND);
	}
}