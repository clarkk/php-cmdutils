<?php

namespace Utils\Procs_queue;

require_once 'Cmd.php';
require_once 'SSH.php';
require_once 'procs_queue/Verbose.php';
require_once 'procs_queue/trait_Commands.php';
require_once 'procs_queue/Worker_init.php';

use \Utils\Cmd\Cmd;
use \Utils\SSH\SSH_error;
use \Utils\Procs_queue\Worker_init;

abstract class Procs_queue extends Verbose {
	use Commands;
	
	protected $timeout = 9; // 999
	
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
		}
		
		$ssh->disconnect();
	}
	
	public function exec(string $base_path, string $proc_path, string $tmp_path){
		$this->check_localhost($base_path, $proc_path, $tmp_path);
		
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				break;
			}
			
			if($proc_slot = $this->get_open_proc_slot()){
				if($task = $this->task_fetch()){
					$this->start_proc($proc_slot, $base_path.$proc_path, $base_path.$tmp_path, $task);
				}
			}
			
			break;
		}
	}
	
	abstract protected function task_fetch();
	
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
	
	private function start_proc(string $proc_slot, string $proc_path, string $tmp_path, array $task){
		print_r($task);
		
		if($proc_slot == self::LOCALHOST){
			$proc = new Cmd(true);
			$proc->exec('php '.$proc_path.' -v='.$this->verbose.' -data='.base64_encode(serialize($task)));
			
			$pid = $proc->get_pid();
			
			$this->procs[] = $proc;
			
			array_key_last($this->procs);
			
			if($this->verbose){
				//$this->verbose($err, self::COLOR_RED);
			}
		}
		else{
			
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
					$this->verbose("Open proc slot at '$host' ($num_procs/".$worker['nproc'].")", self::COLOR_GRAY);
				}
				
				return $host;
			}
		}
		
		return '';
	}
	
	private function check_timeout(): bool{
		return !$this->is_procs_running() && $this->get_remain_time() >= 0;
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