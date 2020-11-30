<?php

namespace Utils\Procs_queue;

if(PHP_SAPI != 'cli') exit;

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
	
	protected $timeout 	= 999;
	
	private $nproc;
	private $procs 		= [];
	
	private $workers 	= [];
	
	private $time_start;
	
	private $task_name;
	
	private $localhost_tmp_path;
	private $localhost_proc_path;
	
	private $redis;
	private $redis_abort_list;
	
	const LOCALHOST 	= 'localhost';
	
	public function __construct(string $task_name, int $verbose=0){
		parent::__construct($verbose);
		
		$this->nproc 		= (int)shell_exec('nproc');
		$this->task_name 	= $task_name;
		
		if($this->verbose){
			$this->verbose('Procs queue \''.$this->task_name.'\' (pid: '.getmypid().') running as \''.posix_getpwuid(posix_geteuid())['name'].'\'', self::COLOR_GREEN);
		}
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
	
	public function start_redis(string $auth, string $abort_list){
		try{
			$this->redis = new \Redis;
			if(!$this->redis->connect('127.0.0.1')){
				throw new Procs_queue_error('Redis: Connecting to server failed!');
			}
			$this->redis->auth($auth);
			
			$this->redis_abort_list = $abort_list;
		}
		catch(\RedisException $e){
			throw new Procs_queue_error('Redis: '.$e->getMessage(), 0, $e);
		}
	}
	
	public function exec(string $localhost_base_path, string $localhost_proc_path, string $localhost_tmp_path){
		$this->check_localhost($localhost_base_path, $localhost_proc_path, $localhost_tmp_path);
		
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				break;
			}
			
			$this->kill_aborted_tasks();
			
			$this->read_proc_streams();
			
			if($proc_slot = $this->get_open_proc_slot()){
				if($task = $this->task_fetch()){
					$this->start_proc($proc_slot, $task['data'], $task['file']);
				}
				else{
					$this->verbose('... No pending tasks ...', self::COLOR_GRAY);
				}
			}
			
			if(!$this->is_procs_running()){
				$this->verbose('... No processing tasks. Sleep 1 sec ...', self::COLOR_GRAY);
				
				sleep(1);
			}
			
			// test
			//sleep(2);
		}
	}
	
	abstract protected function task_fetch(): array;
	abstract protected function task_success(int $id, array $data);
	abstract protected function task_failed(int $id, array $data);
	
	private function kill_aborted_tasks(){
		if($this->redis->lLen($this->redis_abort_list)){
			if($entries = $this->redis->multi()->lRange($this->redis_abort_list, 0, -1)->del($this->redis_abort_list)->exec()[0]){
				foreach($entries as $entry){
					$proc = explode(':', $entry);
					
					if($proc[0] == self::LOCALHOST){
						if($this->procs[$proc[1]]['pid'] == $proc[2]){
							$this->kill_process_tree($proc[2]);
						}
					}
					else{
						
					}
				}
			}
		}
	}
	
	protected function kill_process_tree(string $pid, string $worker=''){
		echo shell_exec('ps -o pid= -o cmd= --ppid '.$pid);
		exit;
		
		// ps --forest --no-headers -o pid,cmd -g $(ps -o sid= -p 2795)
		// ps -o pid= --ppid 123
		
		// kill $(ps -o pid= --ppid $$)
		
		$pid = (int)$pid;
		foreach(array_filter(array_map('trim', explode("\n", shell_exec('ps -o pid= -o cmd= --ppid '.$pid)))) as $ps){
			echo "$ps\n\n";
		}
		
		if($worker){
			
		}
		else{
			//
		}
		
		exit;
		
		/*
		foreach(array_filter(array_map('trim', explode("\n", shell_exec('ps -o pid= -o cmd= --ppid '.$pid)))) as $ps){
			if($list_files && !strpos($ps, 'php '.CWD)){
				return;
			}
			
			if($this->verbose){
				echo $this->output("> Kill process: $ps", self::COLOR_YELLOW)."\n";
			}
			
			posix_kill($pid, 9);
			$this->kill_process_tree($ps);
		}*/
	}
	
	private function start_proc(string $proc_slot, array $data, string $file): string{
		// unshare -fp --kill-child -- bash -c "watch /bin/sleep 10000 && echo hi"
		
		if($proc_slot == self::LOCALHOST){
			$tmp_path = $this->task_tmp_path($this->localhost_tmp_path, $data);
			
			//$this->cmd_kill_subtree();
			
			$proc = new Cmd(true);
			$proc->exec('mkdir -p '.$tmp_path.'; cp '.$file.' '.$tmp_path.'; '.$this->php_command($this->localhost_proc_path, $tmp_path, $data, $file));
			
			$this->procs[] = [
				'proc'		=> $proc,
				'tmp_path'	=> $tmp_path
			];
			
			$k 		= array_key_last($this->procs);
			$id 	= "$proc_slot:$k";
			$pid 	= $proc->get_pid();
			$uid 	= "$id:$pid";
			
			$this->procs[$k]['id']	= $id;
			$this->procs[$k]['pid']	= $pid;
			$this->procs[$k]['uid'] = $uid;
			
			if($this->verbose){
				$this->verbose("Proc $id (pid: $pid) started", self::COLOR_GREEN);
			}
		}
		else{
			$tmp_path = $this->task_tmp_path($this->workers[$proc_slot]['paths']['tmp'], $data);
			
			$exitcode = $tmp_path.'exitcode';
			
			$this->workers[$proc_slot]['ssh']->exec('mkdir -p '.$tmp_path);
			$this->workers[$proc_slot]['ssh']->upload($file, $tmp_path.basename($file));
			
			$ssh = new SSH($this->workers[$proc_slot]['user'], $proc_slot, true);
			$ssh->exec('sh -c \'echo $PPID; echo $$; '.$this->php_command($this->workers[$proc_slot]['paths']['proc'], $tmp_path, $data, $file).'\'; echo $? > '.$exitcode);
			
			[$ppid, $pid] = explode("\n", $ssh->output(true, true));
			
			$this->workers[$proc_slot]['procs'][] = [
				'ssh'		=> $ssh,
				'tmp_path'	=> $tmp_path,
				'exitcode'	=> $exitcode
			];
			
			$k 		= array_key_last($this->workers[$proc_slot]['procs']);
			$id 	= "$proc_slot:$k";
			$uid 	= "$id:$ppid:$pid";
			
			$this->workers[$proc_slot]['procs'][$k]['id']	= $id;
			$this->workers[$proc_slot]['procs'][$k]['ppid']	= $ppid;
			$this->workers[$proc_slot]['procs'][$k]['pid']	= $pid;
			$this->workers[$proc_slot]['procs'][$k]['uid']	= $uid;
			
			if($this->verbose){
				$this->verbose("SSH $id (pid: $pid) started", self::COLOR_GREEN);
			}
		}
		
		return $uid;
	}
	
	private function read_proc_streams(){
		if($this->verbose){
			$this->verbose("\nLoop started\t\t\t\t\t".$this->get_remain_time().' sec', self::COLOR_GRAY);
		}
		
		foreach($this->procs as $p => $proc){
			if($this->verbose){
				if($pipe_output = $proc['proc']->get_pipe_stream(Cmd::PIPE_STDOUT)){
					$this->verbose('Proc '.$proc['id'], self::COLOR_GRAY);
					$this->verbose($pipe_output);
				}
				
				if($pipe_error = $proc['proc']->get_pipe_stream(Cmd::PIPE_STDERR)){
					$this->verbose('ERROR proc '.$proc['id'], self::COLOR_RED);
					$this->verbose($pipe_error);
				}
			}
			
			if(!$proc['proc']->is_running()){
				if($this->verbose){
					$verbose = 'Proc '.$proc['id'];
					if($proc['proc']->is_success()){
						$this->verbose($verbose.' completed', self::COLOR_GREEN);
					}
					else{
						if($proc['proc']->is_terminated()){
							$color 		= self::COLOR_YELLOW;
							$verbose 	.= ' aborted';
						}
						else{
							$color 		= self::COLOR_RED;
							$verbose 	.= ' failed';
						}
						
						$this->verbose($verbose.' (exitcode: '.$proc['proc']->get_exitcode().')', $color);
					}
				}
				
				shell_exec('rm -r '.$proc['tmp_path']);
				$proc['proc']->close();
				unset($this->procs[$p]);
			}
		}
		
		foreach($this->workers as $host => $worker){
			foreach($worker['procs'] as $p => $proc){
				if($this->verbose){
					if($pipe_output = $proc['ssh']->get_pipe_stream(SSH::PIPE_STDOUT)){
						$this->verbose('SSH '.$proc['id'], self::COLOR_GRAY);
						$this->verbose($pipe_output);
					}
					
					if($pipe_error = $proc['ssh']->get_pipe_stream(SSH::PIPE_STDERR)){
						$this->verbose('ERROR SSH '.$proc['id'], self::COLOR_RED);
						$this->verbose($pipe_error);
					}
				}
				
				//	Check if proc has stopped
				$worker['ssh']->exec('ps --no-headers -p '.$proc['pid']);
				if(!$worker['ssh']->output(true)){
					$worker['ssh']->exec('cat '.$proc['exitcode']);
					$exitcode = (int)$worker['ssh']->output(true);
					
					if($this->verbose){
						$verbose = 'SSH '.$proc['id'];
						if($exitcode){
							if($exitcode == 143){
								$color 		= self::COLOR_YELLOW;
								$verbose 	.= ' aborted';
							}
							else{
								$color 		= self::COLOR_RED;
								$verbose 	.= ' failed';
							}
							
							$this->verbose($verbose.' (exitcode: '.$exitcode.')', $color);
						}
						else{
							$this->verbose($verbose.' completed', self::COLOR_GREEN);
						}
					}
					
					$worker['ssh']->exec('kill '.$proc['ppid'].' '.$proc['pid'].'; rm -r '.$proc['tmp_path']);
					//$proc['ssh']->disconnect();
					unset($this->workers[$host]['procs'][$p]);
				}
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
		if(!$this->is_procs_running() && $this->get_remain_time() >= 0){
			if($this->verbose){
				$this->verbose('Timeout!', self::COLOR_GRAY);
			}
			
			return true;
		}
		
		return false;
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
		
		$this->localhost_tmp_path 	= realpath($base_path.$tmp_path);
		$this->localhost_proc_path 	= realpath($base_path.$proc_path);
	}
	
	private function task_tmp_path(string $base_path, array $task): string{
		return $base_path.'/'.date('Y-m-d', time()).'_'.$this->task_name.'_'.$task['id'].'/';
	}
	
	private function php_command(string $php_path, string $tmp_path, array $data, string $file): string{
		return 'php '.$php_path.' -v='.$this->verbose.' -tmp='.$tmp_path.' -data='.base64_encode(serialize($data)).' -file='.basename($file);
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