<?php

require_once '../Cmd.php';

$cmd = new \Cmdutils\Cmd;
$err = $cmd->exec('hostname');

echo "ERROR: $err\n";
echo 'Output: '.$cmd->output();
echo "Command complete!\n\n";

$cmd = new \Cmdutils\Cmd(true);
$cmd->exec('php procs/proc.php');
echo 'pid: '.$cmd->get_pid()."\n";
while(true){
	if($err = $cmd->get_pipe_stream(\Cmdutils\Cmd::PIPE_STDERR)){
		echo "ERROR: $err\n";
	}
	
	if($out = $cmd->get_pipe_stream(\Cmdutils\Cmd::PIPE_STDOUT)){
		echo "Output: $out\n";
	}
	
	if(!$cmd->is_running()){
		$exitcode = $cmd->get_exitcode();
		
		if($cmd->is_success()){
			echo "Command successful! ($exitcode)\n";
		}
		else{
			echo "Command failed! ($exitcode)\n";
		}
		
		if($cmd->is_terminated()){
			echo "Command terminated!\n";
		}
		
		break;
	}
}

echo "\n";

$cmd = new \Cmdutils\Cmd(true);
$cmd->exec('php procs/proc_term.php');
echo 'pid: '.$cmd->get_pid()."\n";
while(true){
	if($err = $cmd->get_pipe_stream(\Cmdutils\Cmd::PIPE_STDERR)){
		echo "ERROR: $err\n";
	}
	
	if($out = $cmd->get_pipe_stream(\Cmdutils\Cmd::PIPE_STDOUT)){
		echo "Output: $out\n";
	}
	
	if(!$cmd->is_running()){
		$exitcode = $cmd->get_exitcode();
		
		if($cmd->is_success()){
			echo "Command successful! ($exitcode)\n";
		}
		else{
			echo "Command failed! ($exitcode)\n";
		}
		
		if($cmd->is_terminated()){
			echo "Command terminated!\n";
		}
		
		break;
	}
}