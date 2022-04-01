<?php

namespace Utils\Cronjob;

class Cronjob extends Argv {
	private $cronjob_id;
	private $cronjob_file;
	
	protected $require_task_name = true;
	protected $allowed_argv = [
		'v',
		'process'
	];
	
	const DB_RETRY_TIMEOUT 	= 60;
	const DB_RETRY_SLEEP 	= 5;
	
	static public function task_status(string $task_name, bool $quick=false): array{
		$procs = [];
		
		$output = trim(shell_exec('ps --noheader -o pid,ppid,cmd -C php | grep "cronjob\.php '.$task_name.'\b"'));
		
		foreach(array_filter(explode("\n", $output)) as $proc){
			$pid 	= (int)$proc;
			$ppid 	= (int)substr($proc, strpos($proc, ' '));
			
			$cmd = [
				'pid'	=> $pid,
				'ppid'	=> $ppid,
				'cmd'	=> substr($proc, strpos($proc, 'cronjob.php'))
			];
			
			if($quick){
				$procs[] = $cmd;
			}
			else{
				$cmd += [
					'pcmd' => trim(shell_exec('ps --noheader -p '.$ppid.' -o cmd'))
				] + (new \Utils\Cmd\Proc)->stat($pid);
				
				if(strpos($proc, ' -process=')){
					$procs[] = $cmd;
				}
				else{
					array_unshift($procs, $cmd);
				}
			}
		}
		
		return $procs;
	}
	
	public function init(string $base_path, bool $use_db=true){
		if(!$this->task_name){
			throw new Error('Cronjob task not given');
		}
		
		$this->cronjob_file = $base_path.'/'.$this->task_name.'.php';
		
		if(!is_file($this->cronjob_file)){
			throw new Error('Cronjob file invalid: '.$this->cronjob_file);
		}
		
		if($use_db){
			try{
				$db_retry_start = time();
				while(true){
					//	Start transaction with read lock to prevent multiple of the same cronjob to run in parallel
					\dbdata\DB::begin();
					
					$row = (new \dbdata\Get)
						->get_lock()
						->exec('cronjob', [
							'select' => [
								'id',
								'is_running_time'
							],
							'where' => [
								'name' => $this->task_name
							]
					])->fetch();
					
					//	Return error if cronjob is invalid
					if(!$row){
						throw new Error('Cronjob invalid: '.$this->task_name);
					}
					
					if(!$row['is_running_time']){
						$this->cronjob_id = $row['id'];
						
						$this->exec($use_db);
						
						break;
					}
					else{
						//	Commit transaction and release read lock
						\dbdata\DB::commit();
						
						if(time() - $db_retry_start >= self::DB_RETRY_TIMEOUT){
							if($this->verbose){
								echo "Retry timeout\n";
							}
							
							break;
						}
						
						if($this->verbose){
							echo "Cronjob '$this->task_name' is already running and has been running for ".(time() - $row['is_running_time'])." secs! Retry in ".self::DB_RETRY_SLEEP." secs...\n";
						}
						
						sleep(self::DB_RETRY_SLEEP);
					}
				}
			}
			catch(\dbdata\Error_db $e){
				$code = $e->getCode();
				
				if($this->verbose){
					echo 'MYSQL error: '.$code.'; '.\Log\Err::format($e)."\n";
				}
				
				\dbdata\DB::rollback();
				
				//	MySQL server has gone away
				if($code == 2006){
					
				}
				else{
					boot_catch_error($e);
				}
			}
			catch(\Throwable $e){
				\dbdata\DB::rollback();
				
				boot_catch_error($e);
			}
		}
		else{
			$this->exec($use_db);
		}
	}
	
	private function exec(bool $use_db){
		$ppid 	= posix_getppid();
		$pid 	= posix_getpid();
		
		if($this->verbose){
			echo "Cronjob '$this->task_name' starts executing (pid: $pid)...\n";
		}
		
		$time = time();
		
		if($use_db){
			(new \dbdata\Put)->exec('cronjob', $this->cronjob_id, [
				'is_running_time'		=> $time,
				'is_failure_notified'	=> 0,
				'time'					=> $time,
				'ppid'					=> $ppid,
				'pid'					=> $pid
			]);
			
			//	Commit transaction and release read lock
			\dbdata\DB::commit();
		}
		
		require_once $this->cronjob_file;
		
		$class_name = '\Utils\Cronjob\\'.ucfirst($this->task_name);
		if(!class_exists($class_name)){
			throw new Error('Cronjob class missing: '.$class_name);
		}
		
		//	Start transaction for the task
		if($use_db){
			\dbdata\DB::begin();
		}
		
		new $class_name($this->task_name, $this->verbose);
		
		//	Commit transaction for the task
		if($use_db){
			\dbdata\DB::commit();
		}
		
		$time_exec = time() - $time;
		
		if($use_db){
			(new \dbdata\Put)->exec('cronjob', $this->cronjob_id, [
				'is_running_time'	=> 0,
				'time_exec'			=> $time_exec,
				'ppid'				=> null,
				'pid'				=> null
			]);
			
			(new \dbdata\Put)->exec('log_cronjob', 0, [
				'cronjob_id'	=> $this->cronjob_id,
				'time'			=> $time,
				'time_exec'		=> $time_exec
			]);
		}
		
		if($this->verbose){
			echo "Cronjob completed in $time_exec secs!\n";
		}
	}
}

class Error extends \Error {}