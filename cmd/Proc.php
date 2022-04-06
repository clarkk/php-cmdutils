<?php

namespace Utils\Cmd;

class Proc {
	static public function name(string $name, string $filter='', bool $stat=false): array{
		if(!$output = rtrim(shell_exec(dirname(__FILE__).'/cpp-proc/proc -name '.$name.($filter ? ' -grep "'.$filter.'"' : '').($stat ? ' -stat' : '')))){
			return [];
		}
		
		$procs = explode("\n", $output);
		foreach($procs as &$proc){
			$pos 	= strpos($proc, '#');
			$cmd 	= substr($proc, $pos+1);
			$values = explode(' ', rtrim(substr($proc, 0, $pos)));
			
			if($stat){
				$proc = [
					'pid'	=> '',
					'ppid'	=> '',
					'cpu'	=> '',
					'mem'	=> '',
					'start'	=> '',
					'time'	=> '',
					'cmd'	=> $cmd
				];
			}
			else{
				$proc = [
					'pid'	=> '',
					'ppid'	=> '',
					'cmd'	=> $cmd
				];
			}
			
			foreach($proc as &$p){
				if($value = array_shift($values)){
					$p = $value;
				}
			}
		}
		
		return $procs;
	}
}