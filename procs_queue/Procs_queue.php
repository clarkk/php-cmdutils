<?php

namespace Utils\Procs_queue;

if(!PHP_CLI){
	exit;
}

use \Utils\Cmd\Cmd;
use \Utils\SSH\SSH;
use \Utils\SSH\SSH_error;
use \Utils\Procs_queue\Worker_init;

abstract class Procs_queue extends Verbose {
	protected $task_name;
	
	private $timeout 		= 999;
	private $ssh_timeout 	= 60;
	
	private $nproc;
	private $procs 			= [];
	
	private $workers 		= [];
	
	private $time_start;
	
	private $localhost_tmp_path;
	private $localhost_proc_path;
	
	private $redis;
	private $redis_abort_list;
	
	const LOCALHOST 		= 'localhost';
	
	public function __construct(string $task_name, int $verbose=0){
		$this->verbose 		= $verbose;
		$this->nproc 		= (int)shell_exec('nproc');
		$this->task_name 	= $task_name;
		
		parent::__construct();
		
		if($this->verbose){
			$this->verbose('Procs queue \''.$this->task_name.'\' (pid: '.getmypid().') running as \''.trim(shell_exec('whoami')).'\'', self::COLOR_GREEN);
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
				'nproc'		=> $nproc,
				'user'		=> $user,
				'ssh'		=> $ssh,
				'paths'		=> [
					'proc'	=> $base_path.$proc_path,
					'tmp'	=> $base_path.$tmp_path
				],
				'procs'		=> [],
				'ssh_pool'	=> []
			];
		}
		catch(SSH_error $e){
			$error = $e->getMessage();
			
			if($this->verbose){
				$this->verbose($error, self::COLOR_RED);
			}
			
			$this->error($error);
			
			$ssh->disconnect();
		}
	}
	
	public function start_redis(string $auth, string $abort_list){
		try{
			$this->redis = new \Redis;
			if(!$this->redis->connect('127.0.0.1')){
				throw new \RedisException('Connecting to server failed');
			}
			
			if(!$this->redis->auth($auth)){
				throw new \RedisException('Authentication failed');
			}
			
			$this->redis_abort_list = $abort_list;
			
			if($this->verbose){
				$this->verbose('Redis abort list \''.$abort_list.'\' connected', self::COLOR_GREEN);
			}
		}
		catch(\RedisException $e){
			throw new Error('Redis: '.$e->getMessage(), 0, $e);
		}
	}
	
	public function exec(string $localhost_proc_path, string $localhost_tmp_path){
		$this->check_localhost($localhost_proc_path, $localhost_tmp_path);
		
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				return;
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
			
			$this->ssh_connection_status();
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
							shell_exec('kill '.$proc[2]);
						}
					}
					else{
						if($this->workers[$proc[0]]['procs'][$proc[1]]['pid'] == $proc[2]){
							$this->workers[$proc[0]]['ssh']->exec('kill '.$proc[2]);
						}
					}
				}
			}
		}
	}
	
	private function start_proc(string $proc_slot, array $data, string $file): string{
		if($proc_slot == self::LOCALHOST){
			$tmp_path = $this->task_tmp_path($this->localhost_tmp_path, $data);
			$exitcode = $tmp_path.'exitcode';
			
			$cmd = (new \Utils\Commands)->group_subprocs($this->task_php_command($this->localhost_proc_path, $tmp_path, $data, $file), $exitcode);
			
			
			
			
			$proc = new Cmd;
			$err = $proc->exec('mkdir '.$tmp_path.'; cp '.$file.' '.$tmp_path.'; '.$cmd);
			echo $proc->output();
			exit;
			
			
			
			
			$proc = new Cmd(true);
			$proc->exec('mkdir '.$tmp_path.'; cp '.$file.' '.$tmp_path.'; '.$cmd);
			
			$this->procs[] = [
				'cmd'		=> $proc,
				'tmp_path'	=> $tmp_path,
				'exitcode'	=> $exitcode
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
			
			$this->workers[$proc_slot]['ssh']->exec('mkdir '.$tmp_path);
			$this->workers[$proc_slot]['ssh']->upload($file, $tmp_path.basename($file));
			
			$cmd = (new \Utils\Commands)->group_subprocs($this->task_php_command($this->workers[$proc_slot]['paths']['proc'], $tmp_path, $data, $file), $exitcode, true);
			
			$ssh = $this->ssh_pool($proc_slot);
			$ssh->exec($cmd);
			
			$pid = $ssh->output(true, true);
			
			$this->workers[$proc_slot]['procs'][] = [
				'ssh'		=> $ssh,
				'tmp_path'	=> $tmp_path,
				'exitcode'	=> $exitcode
			];
			
			$k 		= array_key_last($this->workers[$proc_slot]['procs']);
			$id 	= "$proc_slot:$k";
			$uid 	= "$id:$pid";
			
			$this->workers[$proc_slot]['procs'][$k]['id']	= $id;
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
			$this->read_proc_stream($proc['cmd'], $proc['id']);
			
			if(!$proc['cmd']->is_running()){
				if($this->verbose){
					$exitcode = $this->parse_exitcode(trim(shell_exec('cat '.$proc['exitcode'].' 2>/dev/null')));
					
					$this->verbose_proc_complete('Proc '.$proc['id'], $exitcode);
				}
				
				shell_exec('rm -r '.$proc['tmp_path']);
				$proc['cmd']->close();
				unset($this->procs[$p]);
			}
		}
		
		foreach($this->workers as $host => $worker){
			foreach($worker['procs'] as $p => $proc){
				$this->read_proc_stream($proc['ssh'], $proc['id'], true);
				
				//	Check if proc has stopped
				$worker['ssh']->exec('ps --no-headers -p '.$proc['pid']);
				if(!$worker['ssh']->output(true)){
					$worker['ssh']->exec('cat '.$proc['exitcode'].' 2>/dev/null');
					$exitcode = $this->parse_exitcode($worker['ssh']->output(true));
					
					if($this->verbose){
						$this->verbose_proc_complete('SSH '.$proc['id'], $exitcode);
					}
					
					$worker['ssh']->exec('rm -r '.$proc['tmp_path']);
					$this->ssh_pool($host, $proc['ssh']);
					unset($this->workers[$host]['procs'][$p]);
				}
			}
		}
	}
	
	private function ssh_pool(string $proc_slot, SSH $ssh=null): ?SSH{
		if($ssh){
			$this->workers[$proc_slot]['ssh_pool'][] = $ssh;
			
			if($this->verbose){
				$this->verbose("\t\t\t\t\t\t\t\t\tSSH --> Pool ".count($this->workers[$proc_slot]['ssh_pool']), self::COLOR_GRAY);
			}
			
			return null;
		}
		else{
			if($this->workers[$proc_slot]['ssh_pool']){
				if($this->verbose){
					$this->verbose("\t\t\t\t\t\t\t\t\tSSH <-- Pool ".(count($this->workers[$proc_slot]['ssh_pool']) - 1), self::COLOR_GRAY);
				}
				
				return array_shift($this->workers[$proc_slot]['ssh_pool']);
			}
			
			if($this->verbose){
				$this->verbose("\t\t\t\t\t\t\t\t\tSSH initiated", self::COLOR_GRAY);
			}
			
			return new SSH($this->workers[$proc_slot]['user'], $proc_slot, true);
		}
	}
	
	private function ssh_connection_status(){
		foreach($this->workers as $host => &$worker){
			if($worker['ssh']->get_idle_time() > $this->ssh_timeout){
				if($this->verbose){
					$this->verbose("\t\t\t\t\t\t\t\t\tSSH worker status ($host)", self::COLOR_GRAY);
				}
				
				$worker['ssh']->exec('cd ./');
			}
			
			foreach($worker['ssh_pool'] as $k => &$ssh){
				if($ssh->get_idle_time() > $this->ssh_timeout){
					if($this->verbose){
						$this->verbose("\t\t\t\t\t\t\t\t\tSSH timeout ($host) ".(count($worker['ssh_pool']) - 1), self::COLOR_GRAY);
					}
					
					$ssh->disconnect();
					unset($this->workers[$host]['ssh_pool'][$k]);
				}
			}
		}
	}
	
	private function parse_exitcode(string $exitcode): int{
		return !strlen($exitcode) ? 255 : (int)$exitcode;
	}
	
	private function read_proc_stream($interface, string $proc_id, bool $is_worker=false){
		if($is_worker){
			$verbose 	= 'SSH '.$proc_id;
			$stdout 	= SSH::PIPE_STDOUT;
			$stderr 	= SSH::PIPE_STDERR;
		}
		else{
			$verbose 	= 'Proc '.$proc_id;
			$stdout 	= Cmd::PIPE_STDOUT;
			$stderr 	= Cmd::PIPE_STDERR;
		}
		
		if($pipe_output = $interface->get_pipe_stream($stdout)){
			if($this->verbose){
				$this->verbose($verbose, self::COLOR_GRAY);
				$this->verbose($pipe_output);
			}
		}
		
		if($pipe_error = $interface->get_pipe_stream($stderr)){
			if($this->verbose){
				$this->verbose('ERROR '.$verbose, self::COLOR_RED);
				$this->verbose($pipe_error);
			}
		}
	}
	
	private function verbose_proc_complete(string $verbose, int $exitcode){
		if($exitcode){
			if($exitcode == 255){
				$color 		= self::COLOR_YELLOW;
				$verbose 	.= ' aborted';
			}
			else{
				$color 		= self::COLOR_RED;
				$verbose 	.= ' failed';
			}
		}
		else{
			$color 		= self::COLOR_GREEN;
			$verbose 	.= ' success';
		}
		
		$this->verbose($verbose.' (exitcode: '.$exitcode.')', $color);
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
	
	private function check_localhost(string $proc_path, string $tmp_path){
		if(!is_file($proc_path)){
			$err = "proc path not found on localhost: $proc_path";
			if($this->verbose){
				$this->verbose($err, self::COLOR_RED);
			}
			
			throw new Error($err);
		}
		
		if(!is_dir($tmp_path)){
			$err = "tmp path not found on localhost: $tmp_path";
			if($this->verbose){
				$this->verbose($err, self::COLOR_RED);
			}
			
			throw new Error($err);
		}
		
		if(!is_writeable($tmp_path)){
			$err = "tmp path not writeable on localhost: $tmp_path";
			if($this->verbose){
				$this->verbose($err, self::COLOR_RED);
			}
			
			throw new Error($err);
		}
		
		if($this->verbose){
			$this->verbose("Localhost initiated\nnprocs: $this->nproc\nproc: $proc_path\ntmp: $tmp_path", self::COLOR_GREEN);
		}
		
		$this->localhost_tmp_path 	= realpath($tmp_path);
		$this->localhost_proc_path 	= realpath($proc_path);
	}
	
	private function task_tmp_path(string $base_path, array $task): string{
		$local_time = time() + (new \DateTimeZone('Europe/Copenhagen'))->getOffset(new \DateTime('now'));
		
		return $base_path.'/'.date('Y-m-d-His', $local_time).'_'.$this->task_name.'_'.$task['id'].'/';
	}
	
	private function task_php_command(string $php_path, string $tmp_path, array $data, string $file): string{
		$process_data = [
			'data'	=> $data,
			'tmp'	=> $tmp_path,
			'file'	=> basename($file)
		];
		
		return 'php '.$php_path.' '.$this->task_name.' '.($this->verbose ? '-v='.$this->verbose : '').' -process='.base64_encode(serialize($process_data));
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
	
	public function __destruct(){
		foreach($this->workers as $host => $worker){
			$worker['ssh']->disconnect();
			
			foreach($worker['ssh_pool'] as $ssh){
				$ssh->disconnect();
			}
		}
	}
}

class Error extends \Error {}