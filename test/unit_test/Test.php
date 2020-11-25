<?php

namespace Cmdutils\Test;

class Unit {
	public function proc(string $command, string $error, string $output){
		echo "Test command: '$command'\n---\n";
		
		$cmd = new \Cmdutils\Cmd;
		$err = $cmd->exec($command);
		
		echo "ERROR: $err\n";
		
		if($error != trim($err)){
			throw new Error($command);
		}
		
		$out = trim($cmd->output());
		
		echo "Output: $out\n";
		
		if($output != trim($out)){
			throw new Error($command);
		}
		
		echo "Command completed!\n\n";
	}
	
	public function proc_stream(string $command, string $error, string $output, int $code=0){
		echo "Test command: '$command'\n---\n";
		
		$cmd = new \Cmdutils\Cmd(true);
		$cmd->exec($command);
		
		echo 'pid: '.$cmd->get_pid()."\n";
		
		$test_err = '';
		$test_out = '';
		$exitcode = null;
		
		while(true){
			if($err = $cmd->get_pipe_stream(\Cmdutils\Cmd::PIPE_STDERR)){
				echo "ERROR: $err\n";
				
				$test_err .= "$err\n";
			}
			
			if($out = $cmd->get_pipe_stream(\Cmdutils\Cmd::PIPE_STDOUT)){
				echo "Output: $out\n";
				
				$test_out .= "$out\n";
			}
			
			if(!$cmd->is_running()){
				$exitcode = $cmd->get_exitcode();
				
				if($cmd->is_success()){
					echo "Command successful! ($exitcode)\n";
				}
				else{
					echo "Command failed! ($exitcode)\n";
				}
				
				break;
			}
		}
		
		if($error != trim($test_err)){
			throw new Error($command);
		}
		
		if($output != trim($test_out)){
			throw new Error($command);
		}
		
		if($code != $exitcode){
			throw new Error($command);
		}
		
		echo "\n";
	}
}

class Error extends \Error {}