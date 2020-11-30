<?php

namespace Utils\Procs_queue;

trait Commands {
	private function cmd_set_tmpfs(string $path): string{
		return "mountpoint -q '$path' || mount -t tmpfs -o size=512m tmpfs '$path' && echo 'OK'";
	}
	
	private function cmd_kill_subtree(string $command): string{
		return 'unshare -fp --kill-child -- bash -c \'echo $PPID; echo $$; '.$command.'\'';
	}
}