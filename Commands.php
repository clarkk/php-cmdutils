<?php

namespace Utils;

class Commands {
	static public function set_tmpfs(string $path): string{
		return "mountpoint -q '$path' || mount -t tmpfs -o size=512m tmpfs '$path' && echo 'OK'";
	}
	
	static public function group_subprocs(string $command, string $init_command='', string $exitcode='', bool $print_pid=false): string{
		// print PPID and PID
		// echo $PPID; echo $$;
		
		$cmd = ($print_pid ? 'echo $$;' : '');
		
		if($init_command){
			self::apply_syntax_end($init_command);
			$cmd .= $init_command;
		}
		
		if($exitcode){
			self::apply_syntax_end($command);
			$command .= "echo $? > $exitcode";
		}
		
		return "$cmd unshare -fp --kill-child -- bash -c '$command'";
	}
	
	static public function timeout_proc(string $command, int $timeout): string{
		return ($timeout ? 'timeout -s '.Cmd\Cmd::SIGKILL." $timeout " : '').$command;
	}
	
	static private function apply_syntax_end(string &$syntax){
		$syntax = rtrim($syntax);
		if(substr($syntax, -1) != ';'){
			$syntax .= ';';
		}
	}
}