<?php

namespace Utils\Cmd;

class Proc {
	static public function name(string $name, string $filter='', bool $stat=false): array{
		$cmd = dirname(__FILE__).'/cpp-proc/proc -name '.$name;
		if($filter){
			$cmd .= ' -grep "'.$filter.'"';
		}
		if($stat){
			$cmd .= ' -stat';
		}
		
		$Cmd = new Cmd;
		if($err = $Cmd->exec($cmd)){
			throw new Error('Could mot execute cpp-proc: '.$err);
		}
		
		if(!$output = $Cmd->output(true)){
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