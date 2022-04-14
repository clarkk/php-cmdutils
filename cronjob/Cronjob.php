<?php

namespace Utils\Cronjob;

class Cronjob extends Argv {
	protected $require_task_name 	= true;
	protected $allowed_argv = [
		self::ARG_V,
		self::ARG_PROCESS
	];
	
	private $cronjob_id;
	private $cronjob_file;
	
	private const FAILOVER_TIMEOUT 	= 60;
	private const FAILOVER_SLEEP 	= 5;
	
	private const DB_TABLE 			= 'cronjob';
	
	public function init(string $base_path, bool $use_db=true){
		if(!$this->task_name){
			throw new Error('Cronjob task name not given');
		}
		
		$this->cronjob_file = $base_path.'/'.$this->task_name.'.php';
		
		if(!is_file($this->cronjob_file)){
			throw new Error('Cronjob file invalid: '.$this->cronjob_file);
		}
		
		try{
			if($use_db){
				$this->try_db();
			}
			else{
				$this->exec(false);
			}
		}
		//	Catch DB errors
		catch(\dbdata\Error_db $e){
			$code 	= $e->getCode();
			$error 	= 'MYSQL error: '.$code.'; '.\Log\Err::format($e);
			
			\dbdata\DB::rollback();
			
			switch($code){
				//	MySQL server has gone away
				case 2006:
					\Log\Log::err(\Log\Log::ERR_FATAL, $error);
					
					echo "$error\n";
					break;
				
				default:
					\Log\Err::catch_all($e);
			}
		}
		//	Catch fatal errors
		catch(\Throwable $e){
			if($use_db){
				\dbdata\DB::rollback();
			}
			
			$error = \Log\Err::format($e);
			\Log\Log::err(self::DB_TABLE, $this->task_name.': '.$error, false);
			
			echo "$error\n";
		}
	}
	
	private function try_db(){
		$failover_start = time();
		
		while(true){
			//	Start transaction with read lock to prevent multiple of the same cronjob to run in parallel
			\dbdata\DB::begin();
			
			//	Return error if cronjob is invalid
			if(!$row = (new \dbdata\Get)
				->get_lock()
				->exec(self::DB_TABLE, [
					'select' => [
						'id',
						'is_running_time'
					],
					'where' => [
						'name' => $this->task_name
					]
			])->fetch()){
				throw new Error('Cronjob invalid: '.$this->task_name);
			}
			
			//	Execute cronjob
			if(!$row['is_running_time']){
				$this->cronjob_id = $row['id'];
				
				$this->exec(true, $failover_start);
				
				break;
			}
			//	Cronjob is already executing
			else{
				//	Commit transaction and release read lock on cronjob
				\dbdata\DB::commit();
				
				if(time() - $failover_start >= self::FAILOVER_TIMEOUT){
					if($this->verbose){
						echo "Failover timeout\n";
					}
					
					break;
				}
				
				if($this->verbose){
					echo "Cronjob '$this->task_name' is already running and has been running for ".(time() - $row['is_running_time'])." secs! Failover sleep ".self::FAILOVER_SLEEP." secs...\n";
				}
				
				sleep(self::FAILOVER_SLEEP);
			}
		}
	}
	
	private function exec(bool $use_db, int $failover_start=0){
		$pid = posix_getpid();
		
		if($this->verbose){
			echo "Cronjob '$this->task_name' starts executing (pid: $pid)...\n";
		}
		
		$time_start = time();
		
		if($use_db){
			$this->update_cronjob([
				'is_running_time'		=> $time_start,
				'is_failure_notified'	=> 0,
				'time'					=> $time_start,
				'time_offset'			=> $time_start - $failover_start,
				'ppid'					=> posix_getppid(),
				'pid'					=> $pid
			]);
			
			//	Commit transaction and release read lock on cronjob
			\dbdata\DB::commit();
			
			//	Start transaction for the task
			\dbdata\DB::begin();
		}
		
		require_once $this->cronjob_file;
		
		$class_name = '\Utils\Cronjob\\'.ucfirst($this->task_name);
		if(!class_exists($class_name)){
			throw new Error('Cronjob class missing: '.$class_name);
		}
		
		new $class_name($this->task_name, $this->verbose);
		
		$time_exec = time() - $time_start;
		
		if($use_db){
			//	Commit transaction for the task
			\dbdata\DB::commit();
			
			$this->update_cronjob([
				'is_running_time'	=> 0,
				'time_offset'		=> 0,
				'time_exec'			=> $time_exec,
				'ppid'				=> null,
				'pid'				=> null
			]);
			
			(new \dbdata\Put)->exec('log_cronjob', 0, [
				'cronjob_id'	=> $this->cronjob_id,
				'time'			=> $time_start,
				'time_exec'		=> $time_exec
			]);
		}
		else{
			\Log\Log::log(self::DB_TABLE, $this->task_name.' started '.\Time\Time::timestamp($time_start, true).' ('.$time_exec.' secs)');
		}
		
		if($this->verbose){
			echo "Cronjob completed in $time_exec secs!\n";
		}
	}
	
	private function update_cronjob(array $update){
		(new \dbdata\Put)->exec(self::DB_TABLE, $this->cronjob_id, $update);
	}
}

class Error extends \Error {}