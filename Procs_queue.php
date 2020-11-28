<?php

namespace Utils\Procs_queue;

require_once 'Cmd.php';
require_once 'SSH.php';
require_once 'procs_queue/Verbose.php';
require_once 'procs_queue/trait_Commands.php';
require_once 'procs_queue/Worker_init.php';

use \Utils\Cmd\Cmd;
use \Utils\SSH\SSH;
use \Utils\SSH\SSH_error;
use \Utils\Procs_queue\Worker_init;

abstract class Procs_queue extends Verbose {
	use Commands;
	
	protected $timeout 	= 9; //999
	
	private $nproc;
	private $procs 		= [];
	
	private $workers 	= [];
	
	private $time_start;
	
	const LOCALHOST 	= 'localhost';
	
	public function __construct(int $verbose=0){
		parent::__construct($verbose);
		
		$this->nproc = (int)shell_exec('nproc');
	}
	
	public function add_worker(string $user, string $host, string $base_path, string $proc_path, string $tmp_path){
		try{
			if($this->verbose){
				$this->verbose("Add worker '$host'", self::COLOR_GRAY);
			}
			
			$ssh = new Worker_init($user, $host);
			$ssh->check_proc_path($base_path.$proc_path);
			$ssh->check_tmp_path($base_path.$tmp_path);
			$nproc = $ssh->get_nproc();
			
			if($this->verbose){
				$this->verbose("Worker '$host' initiated\nnprocs: $nproc\nproc: $proc_path\ntmpfs: $tmp_path", self::COLOR_GREEN);
			}
			
			$this->workers[$host] = [
				'nproc'	=> $nproc,
				'user'	=> $user,
				'ssh'	=> $ssh,
				'paths'	=> [
					'proc'	=> $base_path.$proc_path,
					'tmp'	=> $base_path.$tmp_path
				],
				'procs'	=> []
			];
		}
		catch(SSH_error $e){
			if($this->verbose){
				$this->verbose($e->getMessage(), self::COLOR_RED);
			}
			
			$ssh->disconnect();
		}
	}
	
	public function exec(string $base_path, string $proc_path, string $tmp_path){
		$this->check_localhost($base_path, $proc_path, $tmp_path);
		
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				break;
			}
			
			$this->read_proc_streams();
			
			if($proc_slot = $this->get_open_proc_slot()){
				if($task = $this->task_fetch()){
					$this->start_proc($proc_slot, $base_path.$proc_path, $base_path.$tmp_path, $task);
				}
			}
		}
	}
	
	abstract protected function task_fetch();
	
	private function start_proc(string $proc_slot, string $proc_path, string $tmp_path, array $task): string{
		$data_base64 = base64_encode(serialize($task));
		
		if($proc_slot == self::LOCALHOST){
			$proc = new Cmd(true);
			$proc->exec('php '.$proc_path.' -v='.$this->verbose.' -data='.$data_base64);
			
			$this->procs[] = [
				'proc'	=> $proc,
				'uid'	=> ''
			];
			
			$k 		= array_key_last($this->procs);
			$uid 	= "$proc_slot:$k:".$proc->get_pid();
			
			$this->procs[$k]['uid'] = $uid;
			
			if($this->verbose){
				$this->verbose("Proc ($uid) started", self::COLOR_GREEN);
			}
		}
		else{
			$ssh = new SSH($this->workers[$proc_slot]['user'], $proc_slot, true);
			$ssh->exec('sh -c \'echo $PPID; echo $$; php '.$this->workers[$proc_slot]['paths']['proc'].' -v='.$this->verbose.' -data='.$data_base64.'\'');
			
			$this->workers[$proc_slot]['procs'][] = [
				'ssh'	=> $ssh,
				'uid'	=> '',
				'pid'	=> 0,
				'init'	=> false
			];
			
			$k 		= array_key_last($this->workers[$proc_slot]['procs']);
			$uid 	= "$proc_slot:$k:";
			
			$this->workers[$proc_slot]['procs'][$k]['uid'] = $uid;
			
			if($this->verbose){
				$this->verbose("SSH ($uid) started", self::COLOR_GREEN);
			}
		}
		
		return $uid;
	}
	
	private function read_proc_streams(){
		if($this->verbose){
			$this->verbose("Loop started\t\t\t".$this->get_remain_time().' sec', self::COLOR_GRAY);
		}
		
		foreach($this->procs as $p => $proc){
			if($this->verbose){
				if($pipe_output = $proc['proc']->get_pipe_stream(Cmd::PIPE_STDOUT)){
					$this->verbose("Proc $p:", self::COLOR_GRAY);
					$this->verbose($pipe_output);
				}
				
				if($pipe_error = $proc['proc']->get_pipe_stream(Cmd::PIPE_STDERR)){
					$this->verbose("ERROR proc $p:", self::COLOR_RED);
					$this->verbose($pipe_error);
				}
			}
			
			if(!$proc['proc']->is_running()){
				if($this->verbose){
					$verbose = 'Proc ('.$proc['uid'].') ';
					if($proc['proc']->is_terminated()){
						$this->verbose($verbose.' aborted', self::COLOR_YELLOW);
					}
					else{
						$this->verbose($verbose.' completed', self::COLOR_GREEN);
					}
				}
				
				$proc['proc']->close();
				unset($this->procs[$p]);
			}
		}
		
		foreach($this->workers as $host => $worker){
			foreach($worker['procs'] as $p => $proc){
				if($this->verbose){
					if($pipe_output = $proc['ssh']->get_pipe_stream(SSH::PIPE_STDOUT)){
						if(!$proc['init']){
							$this->workers[$host]['procs'][$p]['init'] = true;
							
							$pos = strpos($pipe_output, "\n");
							$ppid = substr($pipe_output, 0, $pos);
							$pipe_output = substr($pipe_output, $pos+1);
							
							$pos = strpos($pipe_output, "\n");
							$pid = substr($pipe_output, 0, $pos);
							$pipe_output = substr($pipe_output, $pos+1);
							
							$this->workers[$host]['procs'][$p]['pid'] = $pid;
							
							$this->workers[$host]['procs'][$p]['uid'] .= "$ppid-$pid";
						}
						
						$this->verbose("SSH $p:", self::COLOR_GRAY);
						$this->verbose($pipe_output);
					}
					
					if($pipe_error = $proc['ssh']->get_pipe_stream(SSH::PIPE_STDERR)){
						$this->verbose("ERROR SSH $p:", self::COLOR_RED);
						$this->verbose($pipe_error);
					}
				}
				
				$worker['ssh']->exec('ps -p '.$this->workers[$host]['procs'][$p]['pid'], true);
				echo $worker['ssh']->output()."\n";
				
				exit;
			}
		}
	}
	
	private function get_open_proc_slot(): string{
		$num_procs = count($this->procs);
		if($num_procs < $this->nproc){
			if($this->verbose){
				$this->verbose("Open proc slot at '".self::LOCALHOST."' ($num_procs/$this->nproc)", self::COLOR_GRAY);
			}
			
			return self::LOCALHOST;
		}
		
		foreach($this->workers as $host => $worker){
			$num_procs = count($worker['procs']);
			if($num_procs < $worker['nproc']){
				if($this->verbose){
					$this->verbose("Open SSH slot at '$host' ($num_procs/".$worker['nproc'].")", self::COLOR_GRAY);
				}
				
				return $host;
			}
		}
		
		return '';
	}
	
	private function check_timeout(): bool{
		if($timeout = !$this->is_procs_running() && $this->get_remain_time() >= 0){
			if($this->verbose){
				$this->verbose('Timeout!', self::COLOR_GRAY);
			}
		}
		
		return $timeout;
	}
	
	private function check_localhost(string $base_path, string $proc_path, string $tmp_path){
		if(!is_file($base_path.$proc_path)){
			$err = "proc path not found on localhost: $proc_path";
			if($this->verbose){
				$this->verbose($err, self::COLOR_RED);
			}
			
			throw new Procs_queue_error($err);
		}
		
		if(!is_dir($base_path.$tmp_path)){
			$err = "tmp path not found on localhost: $tmp_path";
			if($this->verbose){
				$this->verbose($err, self::COLOR_RED);
			}
			
			throw new Procs_queue_error($err);
		}
		
		$cmd = new Cmd;
		$cmd->exec($this->cmd_set_tmpfs($base_path.$tmp_path));
		if($cmd->output(true) != 'OK'){
			$err = "tmpfs could not be mounted on localhost: $tmp_path";
			if($this->verbose){
				$this->verbose($err, self::COLOR_RED);
			}
			
			throw new Procs_queue_error($err);
		}
		
		if($this->verbose){
			$this->verbose("Localhost initiated\nnprocs: $this->nproc\nproc: $proc_path\ntmpfs: $tmp_path", self::COLOR_GREEN);
		}
	}
	
	private function is_procs_running(): bool{
		if($this->procs){
			return true;
		}
		
		foreach($this->workers as $worker){
			if($worker['procs']){
				return true;
			}
		}
		
		return false;
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
}

class Procs_queue_error extends \Error {}