<?php

namespace Utils\Procs_queue;

require_once 'Cmd.php';
require_once 'SSH.php';
require_once 'procs_queue/Worker_init.php';

use \Utils\Cmd\Cmd;
use \Utils\SSH\SSH_error;
use \Utils\Procs_queue\Worker_init;

abstract class Procs_queue {
	protected $timeout = 9; // 999
	
	private $nproc;
	private $procs 		= [];
	
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
	
	const LOCALHOST 	= 'localhost';
	
	public function __construct(int $verbose=0){
		$this->nproc 	= (int)shell_exec('nproc');
		$this->verbose 	= $verbose;
	}
	
	public function add_worker(string $user, string $host, string $base_path, string $proc_path, string $tmp_path){
		try{
			$this->verbose("Add worker '$host'", self::COLOR_GRAY);
			
			$ssh = new Worker_init($user, $host);
			$ssh->check_proc_path($base_path.$proc_path);
			$ssh->check_tmp_path($base_path.$tmp_path);
			$nproc = $ssh->get_nproc();
			
			$this->verbose("Worker '$host' initiated\nnprocs: $nproc\ntmpfs: $tmp_path", self::COLOR_GREEN);
			
			$this->workers[$host] = [
				'nproc'	=> $nproc,
				'procs'	=> []
			];
		}
		catch(SSH_error $e){
			$this->verbose($e->getMessage(), self::COLOR_RED);
		}
		
		$ssh->disconnect();
	}
	
	public function exec(){
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				break;
			}
			
			if($proc_slot = $this->get_open_proc_slot()){
				$this->task_fetch();
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
	
	private function get_open_proc_slot(): string{
		$num_procs = count($this->procs);
		if($num_procs < $this->nproc){
			$this->verbose("Open proc slot at '".self::LOCALHOST."' ($num_procs/$this->nproc)", self::COLOR_GRAY);
			
			return self::LOCALHOST;
		}
		
		foreach($this->workers as $host => $worker){
			$num_procs = count($worker['procs']);
			if($num_procs < $worker['nproc']){
				$this->verbose("Open proc slot at '$host' ($num_procs/".$worker['nproc'].")", self::COLOR_GRAY);
				
				return $host;
			}
		}
		
		return '';
	}
	
	private function check_timeout(): bool{
		return !$this->get_total_procs() && $this->get_remain_time() >= 0;
	}
	
	private function get_total_procs(): int{
		$total = count($this->procs);
		foreach($this->workers as $k => $worker){
			$total += count($worker['procs']);
		}
		
		return $total;
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
		$string = str_replace("\n", "\n\t> ", $string);
		
		if($this->verbose == self::VERBOSE_COLOR){
			$string = "\033[".$color.'m'.$string."\033[0m";
		}
		
		echo "$string\n";
	}
}

class Procs_queue_error extends \Error {}