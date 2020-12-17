<?php

namespace Utils\Cronjob;

class Cronjob extends Argv {
	private $cronjob_id;
	private $cronjob_file;
	
	protected $allow_task_name 	= true;
	protected $allowed_argv = [
		'v'
	];
	
	public function __construct(string $base_path, bool $use_db=true){
		parent::__construct();
		
		if(!$this->task_name){
			throw new Error('Cronjob task not given');
		}
		
		$this->cronjob_file = realpath($base_path.'/'.$this->task_name.'.php');
		
		if(!is_file($this->cronjob_file)){
			throw new Error('Cronjob file invalid: '.$this->cronjob_file);
		}
		
		if($use_db){
			try{
				$result = (new \dbdata\Get)->exec('cronjob', [
					'select' => [
						'id',
						'is_running_time'
					],
					'where' => [
						'name' => $this->task_name
					]
				]);
				if($row = $result->fetch()){
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
				//	Return error if cronjob is invalid
				else{
					throw new Error('Cronjob invalid: '.$this->task_name);
				}
			}
			catch(\dbdata\Error_db $e){
				$code = $e->getCode();
				
				$error = 'MYSQL error: '.$code.'; '.\Log\Err::format($e);
				
				\Log\Log::err($error, \Log\Log::ERR_FATAL);
				
				echo $error;
				
				\dbdata\DB::rollback();
				
				//	MySQL server has gone away
				if($code == 2006){
					
				}
				else{
					//$this->reset_cronjob();
				}
			}
			catch(\Throwable $e){
				\dbdata\DB::rollback();
				
				boot_catch_error($e);
				
				//$this->reset_cronjob();
			}
		}
		else{
			$this->exec($use_db);
		}
	}
	
	/*private function reset_cronjob(){
		if($this->cronjob_id){
			(new \dbdata\Put)->exec('cronjob', $this->cronjob_id, [
				'is_running_time' => 0
			]);
		}
	}*/
	
	private function exec(bool $use_db){
		if($this->verbose){
			echo "Cronjob '$this->task_name' starts executing (pid: ".getmypid().")...\n";
		}
		
		$time = time();
		
		if($use_db){
			(new \dbdata\Put)->exec('cronjob', $this->cronjob_id, [
				'is_running_time'		=> $time,
				'is_failure_notified'	=> 0,
				'time'					=> $time
			]);
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
				'time_exec'			=> $time_exec
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