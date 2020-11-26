<?php

namespace Utils\Procs_queue;

require_once 'Cmd.php';

use \Utils\Cmd\Cmd;

abstract class Procs_queue {
	protected $timeout = 9;
	
	private $procs 		= [];
	private $nproc;
	
	private $workers 	= [];
	
	private $verbose 	= false;
	
	private $time_start;
	
	const COLOR_GRAY 	= '1;30';
	const COLOR_GREEN 	= '0;32';
	const COLOR_YELLOW 	= '1;33';
	const COLOR_RED 	= '0;31';
	const COLOR_PURPLE 	= '0;35';
	
	const VERBOSE_PLAIN = 1;
	const VERBOSE_COLOR = 2;
	
	public function __construct(int $verbose=0){
		$this->nproc 	= (int)shell_exec('nproc');
		$this->verbose 	= $verbose;
		
		// test
		$this->nproc++;
	}
	
	public function add_worker(string $user, string $host, string $tmp_dir){
		if($ssh_stream = $this->worker_ssh_connect($user, $host)){
			$stream = ssh2_exec($ssh_stream, 'nproc');
			$pipe_stdout = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
			$pipe_stderr = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
			stream_set_blocking($pipe_stdout, true);
			stream_set_blocking($pipe_stderr, true);
			$stdout = stream_get_contents($pipe_stdout);
			$stderr = stream_get_contents($pipe_stderr);
			
			//$sftp = ssh2_sftp($ssh_stream);
			//stream_copy_to_stream(fopen("/root/test.pdf", 'r'), fopen("ssh2.sftp://$sftp/root/test.pdf", 'w'));
			
			$this->verbose("Worker '$host' initiated width $nproc procs", self::COLOR_GREEN);
		}
		else{
			$this->verbose("Worker '$host' failed", self::COLOR_RED);
		}
		
		/*$ssh_login = $this->ssh_login($user, $host);
		
		$cmd = new Cmd;
		if($err = $cmd->exec("$ssh_login 'nproc'")){
			
		}
		else{
			$nproc = (int)$cmd->output();
			
			
			
			$this->workers[$host] = [
				'ssh_login'	=> $ssh_login,
				'nproc'		=> $nproc
			];
			
			// sh -c 'echo $$; echo $PPID; sleep 10; nproc'
		}*/
	}
	
	public function exec(){
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				break;
			}
			
			
		}
	}
	
	abstract protected function fetch();
	
	/*public function put(string $command){
		if($this->free_proc_slots()){
			$proc = new Cmd(true);
			$proc->exec($command);
			
			$pid = $proc->get_pid();
			
			if($this->verbose){
				$this->verbose("Process start (pid: $pid)", self::COLOR_GREEN);
			}
		}
		
		$this->get_streams();
	}*/
	
	/*private function get_streams(){
		foreach($this->procs as $pid => $proc){
			
		}
	}*/
	
	/*private function free_proc_slots(): bool{
		return count($this->procs) < $this->nproc;
	}*/
	
	private function worker_ssh_connect(string $user, string $host){
		if(!$session = ssh2_connect($host)){
			return false;
		}
		
		ssh2_auth_pubkey_file($session, $user, '/var/www/.ssh/id_rsa.pub', '/var/www/.ssh/id_rsa');
		
		return $session;
	}
	
	private function check_timeout(): bool{
		return !$this->procs && $this->get_remain_time() >= 0;
	}
	
	private function get_remain_time(): int{
		return time() - $this->time_start - $this->timeout;
	}
	
	private function start_time(){
		if($this->verbose){
			$this->verbose('Starting master process', self::COLOR_GRAY);
		}
		
		$this->time_start = time();
	}
	
	private function verbose(string $string, string $color){
		if($this->verbose == self::VERBOSE_COLOR){
			$string = "\033[".$color.'m'.$string."\033[0m";
		}
		
		echo "$string\n";
	}
}

class Error extends \Error {}