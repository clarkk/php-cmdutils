<?php

/*
	Add remote hosts to known_hosts with SSH key fingerprint
	
	# ssh worker.dynaccount.com
	# add_ssh_known_hosts.sh
		- api-scan.dynaccount.com
		- api-scan-bak.dynaccount.com
		- api-scan.dyntest.dk
*/

namespace Utils\Procs_queue;

if(!PHP_CLI){
	exit;
}

use \Utils\Cmd\Cmd;
use \Utils\SSH\SSH;
use \Utils\Procs_queue\Worker_init;

abstract class Procs_queue extends \Utils\Verbose {
	const SSH_KILL_TIMEOUT 				= true;
	
	const TIMEOUT 						= 999;
	const SSH_TIMEOUT 					= 60;
	
	const DEFAULT_TASK_TIMEOUT 			= 60 * 2;
	
	/*
	*	Highest priority:		-20
	*	High priority:			< -9
	*	Above normal priority:	< -4
	*	Normal priority:		-5 to 5
	*	Below normal priority:	> 5
	*	Idle priority:			> 9
	*	Lowest priority:		19	
	*/
	const TASK_PROC_NICE 				= 8;
	
	private int $loop_idle_sleep 		= 1;
	
	protected string $task_name;
	private int $task_timeout 			= self::DEFAULT_TASK_TIMEOUT;
	
	private int $task_fetch_idle_time 	= 1;
	private float $task_fetch_time_last	= 0;
	
	private array $tasks 				= [];
	
	private int $nproc;
	private int $nproc_max 				= 0;
	private array $procs 				= [];
	private array $workers 				= [];
	
	private int $time_start;
	
	private string $localhost_tmp_path;
	private string $localhost_proc_path;
	
	private $buffer_abort_list;
	
	const EXITCODE_ABORT 				= 255;
	
	const LOCALHOST 					= 'localhost';
	
	const OUTPUT_FILE 					= 'output.json';
	
	public function __construct(string $task_name, int $verbose=0){
		$this->nproc 		= $this->nproc_max_limit(shell_exec('nproc'));
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		parent::__construct();
		
		if($this->verbose){
			$this->verbose('Procs queue \''.$this->task_name.'\' (pid: '.posix_getpid().') running as \''.trim(shell_exec('whoami')).'\'', self::COLOR_GREEN);
		}
	}
	
	public function task_fetch_idle(int $time): self{
		$this->task_fetch_idle_time = $time;
		
		return $this;
	}
	
	public function task_timeout(int $timeout): self{
		$this->task_timeout = $timeout;
		
		return $this;
	}
	
	public function loop_idle_sleep(int $sleep): self{
		$this->loop_idle_sleep = $sleep;
		
		return $this;
	}
	
	public function nproc_max(int $max): self{
		$this->nproc_max = $max;
		
		return $this;
	}
	
	public function add_worker(string $user, string $host, string $proc_path, string $tmp_path): self{
		try{
			if($this->verbose){
				$this->verbose("Add worker '$host'", self::COLOR_GRAY);
			}
			
			$ssh = new Worker_init($user, $host);
			$ssh->check_proc_path($proc_path);
			$ssh->check_tmp_path($tmp_path);
			$nproc = $this->nproc_max_limit($ssh->get_nproc());
			
			if($this->verbose){
				$this->verbose("Worker '$host' initiated\nnprocs: $nproc\nproc: $proc_path\ntmp: $tmp_path", self::COLOR_BLUE);
			}
			
			$this->workers[$host] = [
				'nproc'		=> $nproc,
				'user'		=> $user,
				'ssh'		=> $ssh,
				'paths'		=> [
					'proc'	=> $proc_path,
					'tmp'	=> $tmp_path
				],
				'procs'		=> [],
				'ssh_pool'	=> []
			];
		}
		catch(\Utils\SSH\Error $e){
			$error = $e->getMessage();
			
			if($this->verbose){
				$this->verbose($error, self::COLOR_RED);
			}
			
			\Log\Err::fatal($e);
			
			if(isset($ssh)){
				$ssh->disconnect();
			}
		}
		
		return $this;
	}
	
	public function start_redis(string $auth, string $abort_list): self{
		try{
			$cache = new \Utils\Cache\Cache($auth);
			$this->buffer_abort_list = (new \Utils\Cache\Buffer($abort_list, false))->cache($cache);
			
			if($this->verbose){
				$this->verbose('Redis abort list \''.$abort_list.'\' connected', self::COLOR_GREEN);
			}
		}
		catch(\RedisException $e){
			\Log\Err::fatal($e);
			
			throw new Error('Redis: '.$e->getMessage(), 0, $e);
		}
		
		return $this;
	}
	
	public function exec(string $localhost_proc_path, string $localhost_tmp_path): void{
		$this->check_localhost($localhost_proc_path, $localhost_tmp_path);
		
		$this->start_time();
		
		while(true){
			if($this->check_timeout()){
				return;
			}
			
			$this->kill_aborted_tasks();
			
			$this->process_tasks();
			
			$is_procs_running = $this->is_procs_running();
			
			if($this->verbose){
				if(!$is_procs_running){
					$this->verbose("\nNo processing tasks", self::COLOR_GRAY);
				}
			}
			
			$this->ssh_connection_status();
			
			if($is_procs_running || $this->tasks){
				//	Sleep 0.1 sec
				usleep(100000);
				
				continue;
			}
			
			if($this->verbose){
				$this->verbose('Sleep '.$this->loop_idle_sleep.' secs...', self::COLOR_GRAY);
			}
			
			sleep($this->loop_idle_sleep);
		}
	}
	
	protected function update_task_fetch_time(): void{
		$this->task_fetch_time_last = microtime(true);
	}
	
	abstract protected function task_fetch(int $num): array;
	abstract protected function task_start(array $data, string $pid);
	abstract protected function task_success(array $data, string $json);
	abstract protected function task_failed(array $data): void;
	
	private function process_tasks(): void{
		$this->read_proc_streams();
		
		$proc_slots = $this->get_open_proc_slots();
		
		if(!$proc_slots['num']){
			return;
		}
		
		$task_fetch_idle_time 	= $this->get_task_fetch_time_idle();
		$is_task_idle 			= $task_fetch_idle_time < $this->task_fetch_idle_time;
		
		if($is_task_idle && !$this->tasks){
			if($this->verbose){
				$this->verbose("Task fetch idle\t\t\t\t\t\t".$task_fetch_idle_time.' secs', self::COLOR_GRAY);
			}
		}
		else{
			if(!$is_task_idle){
				if($this->verbose){
					$task_time = microtime(true);
				}
				
				$this->tasks = $this->task_fetch($proc_slots['num']);
				
				if($this->verbose){
					$this->verbose('Task fetch ('.round(microtime(true) - $task_time, 4).' secs)', self::COLOR_YELLOW);
				}
			}
			
			if($this->tasks){
				foreach($this->tasks as $t => $task){
					//	No more free proc slots
					if(!$proc_slots['list']){
						break;
					}
					
					$proc_slot = array_key_first($proc_slots['list']);
					
					$this->start_proc($proc_slot, $task['data'], $task['file'] ?? '');
					unset($this->tasks[$t]);
					
					if($proc_slots['list'][$proc_slot] == 1){
						unset($proc_slots['list'][$proc_slot]);
					}
					else{
						$proc_slots['list'][$proc_slot]--;
					}
				}
			}
			else{
				if($this->verbose){
					$this->verbose("\nNo pending tasks", self::COLOR_GRAY);
				}
			}
		}
	}
	
	private function get_task_fetch_time_idle(): float{
		return round(microtime(true) - $this->task_fetch_time_last, 2);
	}
	
	private function kill_aborted_tasks(): void{
		if($entries = $this->buffer_abort_list?->fetch()){
			foreach($entries as $entry){
				$proc = explode(':', $entry);
				
				if($proc[0] == self::LOCALHOST){
					if(isset($this->procs[$proc[1]]) && $this->procs[$proc[1]]['pid'] == $proc[2]){
						shell_exec('kill '.$proc[2]);
					}
				}
				else{
					if(isset($this->workers[$proc[0]]['procs'][$proc[1]]) && $this->workers[$proc[0]]['procs'][$proc[1]]['pid'] == $proc[2]){
						$this->workers[$proc[0]]['ssh']->exec('kill '.$proc[2]);
					}
				}
			}
		}
	}
	
	private function start_proc(string $proc_slot, array $data, string $file): void{
		if($proc_slot == self::LOCALHOST){
			$tmp_path 		= $this->task_tmp_path($this->localhost_tmp_path, $data);
			$exitcode 		= $tmp_path.'/exitcode';
			$cmd_tmp_path 	= "mkdir $tmp_path;".($file ? "cp $file $tmp_path;" : '');
			
			$proc = new Cmd(true);
			$proc->exec(\Utils\Commands::group_subprocs($this->task_php_command($this->localhost_proc_path, $tmp_path, $data, $file), $cmd_tmp_path, $exitcode));
			
			$this->procs[] = [
				'cmd'		=> $proc,
				'tmp_path'	=> $tmp_path,
				'exitcode'	=> $exitcode,
				'data'		=> $data
			];
			
			$k 		= array_key_last($this->procs);
			$id 	= "$proc_slot:$k";
			$pid 	= $proc->get_pid();
			$uid 	= "$id:$pid";
			
			$this->procs[$k]['id']	= $id;
			$this->procs[$k]['pid']	= $pid;
			$this->procs[$k]['uid'] = $uid;
			
			if($this->verbose){
				$this->verbose("Proc $id (pid: $pid, data_id: ".$data['id'].") started", self::COLOR_GREEN);
			}
		}
		else{
			$tmp_path 		= $this->task_tmp_path($this->workers[$proc_slot]['paths']['tmp'], $data);
			$exitcode 		= $tmp_path.'/exitcode';
			$cmd_tmp_path 	= "mkdir $tmp_path;".($file ? "scp root@".SLD.'.'.TLD.":$file ".$tmp_path.'/'.basename($file).';' : '');
			
			$ssh = $this->ssh_pool($proc_slot);
			$ssh->exec(\Utils\Commands::group_subprocs($this->task_php_command($this->workers[$proc_slot]['paths']['proc'], $tmp_path, $data, $file), $cmd_tmp_path, $exitcode, true));
			
			if(!$pid = (int)$ssh->output(true, true)){
				if($this->verbose){
					$this->verbose('The task failed due to an error on the worker', self::COLOR_RED);
				}
				
				$this->task_failed($data);
				
				return;
			}
			
			$this->workers[$proc_slot]['procs'][] = [
				'ssh'		=> $ssh,
				'tmp_path'	=> $tmp_path,
				'exitcode'	=> $exitcode,
				'data'		=> $data
			];
			
			$k 		= array_key_last($this->workers[$proc_slot]['procs']);
			$id 	= "$proc_slot:$k";
			$uid 	= "$id:$pid";
			
			$this->workers[$proc_slot]['procs'][$k]['id']	= $id;
			$this->workers[$proc_slot]['procs'][$k]['pid']	= $pid;
			$this->workers[$proc_slot]['procs'][$k]['uid']	= $uid;
			
			if($this->verbose){
				$this->verbose("SSH $id (pid: $pid, data_id: ".$data['id'].") started", self::COLOR_BLUE);
			}
		}
		
		$this->task_start($data, $uid);
	}
	
	private function read_proc_streams(): void{
		if($this->verbose){
			$this->verbose("\nLoop started\t\t\t\t\t\t".$this->get_remain_time().' secs', self::COLOR_GRAY);
		}
		
		foreach($this->procs as $p => $proc){
			$this->read_proc_stream($proc['cmd'], $proc['id']);
			
			if(!$proc['cmd']->is_running()){
				$exitcode 	= $this->parse_exitcode(trim(file_get_contents($proc['exitcode']) ?: ''));
				$output 	= $proc['tmp_path'].'/'.self::OUTPUT_FILE;
				
				if($this->verbose){
					$this->verbose_proc_complete('Proc '.$proc['id'], $exitcode);
				}
				
				//	Failed
				if($exitcode || !is_file($output)){
					$this->task_failed($proc['data']);
				}
				//	Success
				else{
					$json = file_get_contents($output);
					
					$this->task_success($proc['data'], $json);
					
					if($this->verbose){
						$this->verbose($json, self::COLOR_PURPLE);
					}
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
				$worker['ssh']->exec_is_proc_running($proc['pid']);
				if(!(int)$worker['ssh']->output()){
					$worker['ssh']->exec('cat '.$proc['exitcode'].' 2>/dev/null');
					$exitcode = $this->parse_exitcode($worker['ssh']->output(true));
					
					if($this->verbose){
						$this->verbose_proc_complete('SSH '.$proc['id'], $exitcode, true);
					}
					
					//	Failed
					if($exitcode){
						$this->task_failed($proc['data']);
					}
					//	Success
					else{
						$worker['ssh']->exec('cat '.$proc['tmp_path'].'/'.self::OUTPUT_FILE.' 2>/dev/null');
						if($json = $worker['ssh']->output(true)){
							$this->task_success($proc['data'], $json);
							
							if($this->verbose){
								$this->verbose($json, self::COLOR_PURPLE);
							}
						}
						else{
							$this->task_failed($proc['data']);
						}
					}
					
					$worker['ssh']->exec('rm -r '.$proc['tmp_path']);
					$this->ssh_pool($host, $proc['ssh']);
					unset($this->workers[$host]['procs'][$p]);
				}
			}
		}
	}
	
	private function ssh_pool(string $proc_slot, SSH $ssh=null): ?SSH{
		//	Add inactive SSH to pool
		if($ssh){
			$this->workers[$proc_slot]['ssh_pool'][] = $ssh;
			
			if($this->verbose){
				$this->verbose(self::VERBOSE_INDENTATION.'SSH --> Pool '.count($this->workers[$proc_slot]['ssh_pool']), self::COLOR_BLUE);
			}
			
			return null;
		}
		//	Fetch SSH
		else{
			//	Fetch from SSH pool
			if($this->workers[$proc_slot]['ssh_pool']){
				if($this->verbose){
					$this->verbose(self::VERBOSE_INDENTATION.'SSH <-- Pool '.(count($this->workers[$proc_slot]['ssh_pool']) - 1), self::COLOR_BLUE);
				}
				
				return array_shift($this->workers[$proc_slot]['ssh_pool']);
			}
			
			//	Initiate new SSH
			if($this->verbose){
				$this->verbose(self::VERBOSE_INDENTATION.'SSH initiated', self::COLOR_BLUE);
			}
			
			return new SSH($this->workers[$proc_slot]['user'], $proc_slot, true);
		}
	}
	
	private function ssh_connection_status(): void{
		foreach($this->workers as $host => &$worker){
			if($worker['ssh']->get_idle_time() > self::SSH_TIMEOUT){
				if($this->verbose){
					$this->verbose(self::VERBOSE_INDENTATION.'Timeout: Master SSH ping '.$host, self::COLOR_BLUE);
				}
				
				$worker['ssh']->exec('cd .');
			}
			
			foreach($worker['ssh_pool'] as $k => &$ssh){
				if($ssh->get_idle_time() > self::SSH_TIMEOUT){
					if(self::SSH_KILL_TIMEOUT){
						if($this->verbose){
							$this->verbose(self::VERBOSE_INDENTATION.'Worker SSH kill timeout '.$host.' (Pool: '.(count($worker['ssh_pool']) - 1).')', self::COLOR_BLUE);
						}
						
						$ssh->disconnect();
						unset($this->workers[$host]['ssh_pool'][$k]);
					}
					else{
						if($this->verbose){
							$this->verbose(self::VERBOSE_INDENTATION.'Worker SSH ping timeout '.$host.' (Pool: '.count($worker['ssh_pool']).')', self::COLOR_BLUE);
						}
						
						$ssh->exec('cd .');
					}
				}
			}
		}
	}
	
	private function parse_exitcode(string $exitcode): int{
		return !strlen($exitcode) ? self::EXITCODE_ABORT : $exitcode;
	}
	
	private function read_proc_stream($interface, string $proc_id, bool $is_worker=false): void{
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
	
	private function verbose_proc_complete(string $verbose, int $exitcode, bool $is_worker=false): void{
		if($exitcode){
			if($exitcode == self::EXITCODE_ABORT){
				$color 		= self::COLOR_YELLOW;
				$verbose 	.= ' aborted';
			}
			else{
				$color 		= self::COLOR_RED;
				$verbose 	.= ' failed';
			}
		}
		else{
			$color 		= $is_worker ? self::COLOR_BLUE : self::COLOR_GREEN;
			$verbose 	.= ' success';
		}
		
		$this->verbose($verbose.' (exitcode: '.$exitcode.')', $color);
	}
	
	private function get_open_proc_slots(): array{
		$list 	= [];
		$num 	= 0;
		
		$num_procs 		= count($this->procs);
		$open_procs 	= $this->nproc - $num_procs;
		
		if($open_procs > 0){
			if($this->verbose){
				$this->verbose("$open_procs open proc slots at '".self::LOCALHOST."' ($num_procs/$this->nproc)", self::COLOR_GRAY);
			}
			
			$list[self::LOCALHOST] = $open_procs;
			$num += $open_procs;
		}
		
		foreach($this->workers as $host => $worker){
			$num_procs 		= count($worker['procs']);
			$open_procs 	= $worker['nproc'] - $num_procs;
			
			if($open_procs > 0){
				if($this->verbose){
					$this->verbose("$open_procs open SSH slots at '$host' ($num_procs/".$worker['nproc'].")", self::COLOR_GRAY);
				}
				
				$list[$host] = $open_procs;
				$num += $open_procs;
			}
		}
		
		return [
			'list'	=> $list,
			'num'	=> $num
		];
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
	
	private function check_localhost(string $proc_path, string $tmp_path): void{
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
		return $base_path.'/'.\Time\Time::file_timestamp().'_'.$this->task_name.'_'.$task['id'];
	}
	
	private function task_php_command(string $php_path, string $tmp_path, array $data, string $file): string{
		if($this->verbose && $this->task_timeout){
			$this->verbose('Task timeout: '.$this->task_timeout.' secs', self::COLOR_YELLOW);
		}
		
		$process_data = [
			'data'	=> $data,
			'tmp'	=> $tmp_path,
			'file'	=> $file ? basename($file) : ''
		];
		
		$cmd = '';
		if(self::TASK_PROC_NICE){
			$cmd .= 'nice -n '.self::TASK_PROC_NICE.' ';
		}
		$cmd .= 'php '.$php_path.' '.$this->task_name.' '.($this->verbose ? '-v='.$this->verbose : '').' -process='.base64_encode(serialize($process_data));
		
		return \Utils\Commands::timeout_proc($cmd, $this->task_timeout);
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
	
	private function get_remain_time(): float{
		return round(microtime(true) - $this->time_start - self::TIMEOUT, 2);
	}
	
	private function start_time(): void{
		if($this->verbose){
			$this->verbose('Starting master process', self::COLOR_GRAY);
		}
		
		$this->time_start = time();
	}
	
	private function nproc_max_limit(int $nproc): int{
		return $this->nproc_max && $this->nproc_max < $nproc ? $this->nproc_max : $nproc;
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