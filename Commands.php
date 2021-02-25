<?php

namespace Utils;

class Commands {
	public function set_tmpfs(string $path): string{
		return "mountpoint -q '$path' || mount -t tmpfs -o size=512m tmpfs '$path' && echo 'OK'";
	}
	
	// print PPID and PID
	// echo $PPID; echo $$;
	
	public function group_subprocs(string $command, string $exitcode='', bool $print_pid=false): string{
		return ($print_pid ? 'echo $$;' : '').'unshare -fp --kill-child -- bash -c \''.$command.'; echo $? > '.$exitcode.'\'';
	}
	
	public function timeout_proc(string $command, int $timeout): string{
		return ($timeout ? 'timeout --kill-after='.$timeout.' --signal='.Cmd\Cmd::SIGKILL.' --preserve-status '.$timeout.' ' : '').$command;
	}
}