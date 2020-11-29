<?php

require_once '../Proc_child.php';

class Test_proc_child extends \Utils\Procs_queue\Proc_child {
	protected $allowed_argv = [
		'v',
		'tmp',
		'data'
	];
}

new Test_proc_child($argv);