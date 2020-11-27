<?php

namespace Utils\Procs_queue;

if(PHP_SAPI != 'cli') exit;

abstract class Proc_child {
	public function __construct(){
		echo "hey\n";
		sleep(2);
		echo "hmm\n";
		sleep(2);
		echo "weee\n";
	}
}