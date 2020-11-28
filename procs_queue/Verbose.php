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
	
	public function __construct(int $verbose=0){
		$this->verbose 	= $verbose;
	}
	
	protected function verbose(string $string, string $color=''){
		if($color){
			$string = str_replace("\n", "\n\t> ", $string);
			
			if($this->verbose == self::VERBOSE_COLOR){
				$string = "\033[".$color.'m'.$string."\033[0m";
			}
		}
		
		echo "$string\n";
	}
}