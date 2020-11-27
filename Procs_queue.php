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
	
	public function __construct(int $verbose=0){
		$this->nproc 	= (int)shell_exec('nproc');
		$this->verbose 	= $verbose;
		
		// test
		$this->nproc++;
	}
	
	public function add_worker(string $user, string $host, string $tmp_dir){
		try{
			$this->verbose("Add worker '$host'", self::COLOR_GRAY);
			
			$ssh = new Worker_init($user, $host);
			$ssh->check_tmpdir($tmp_dir);
			$nproc = $ssh->get_nproc();
			
			$this->verbose("Worker '$host' initiated\nnprocs: $nproc\ntmpfs: $tmp_dir", self::COLOR_GREEN);
			
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
			
			$this->task_fetch();
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