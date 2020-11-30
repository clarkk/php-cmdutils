<?php

namespace Utils\Procs_queue;

trait Commands {
	private function cmd_set_tmpfs(string $path): string{
		return "mountpoint -q '$path' || mount -t tmpfs -o size=512m tmpfs '$path' && echo 'OK'";
	}
	
	private function cmd_group_subprocs(string $command, string $exitcode='', bool $print_pid=false): string{
		return ($print_pid ? 'echo $$;' : '').'unshare -fp --kill-child -- bash -c \''.$command.'; echo $? > '.$exitcode.'\'';
	}
}