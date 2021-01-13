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
	
	public function init(string $base_path, bool $use_db=true){
		if(!$this->task_name){
			throw new Error('Cronjob task not given');
		}
		
		$this->cronjob_file = $base_path.'/'.$this->task_name.'.php';
		
		if(!is_file($this->cronjob_file)){
			throw new Error('Cronjob file invalid: '.$this->cronjob_file);
		}
		
		if($use_db){
			//	Start transaction with read lock to prevent multiple of the same cronjob to run in parallel
			\dbdata\DB::begin();
			
			try{
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
				}
				else{
					if($this->verbose){
						echo "Cronjob '$this->task_name' is already running and has been running for ".(time() - $row['is_running_time'])." secs\n";
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
		
		$class_name = '\\Utils\\Cronjob\\'.ucfirst($this->task_name);
		if(!class_exists($class_name)){
			throw new Error('Cronjob class missing: '.$class_name);
		}
		
		if($use_db){
			\dbdata\DB::begin();
		}
		
		new $class_name($this->task_name, $this->verbose);
		
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