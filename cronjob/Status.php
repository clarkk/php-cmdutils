<?php

namespace Utils\Cronjob;

class Status {
	static public function failed_task(): array{
		$result = (new \dbdata\Get)->exec('cronjob', [
			'select' => [
				'id',
				'name',
				'is_running_time',
				'is_failure_notified',
				'time_offset'
			],
			'where' => [
				'name !'				=> 'watch_cronjobs',
				'is_running_time !'		=> 0,
				'is_failure_notified'	=> 0
			]
		]);
		while($row = $result->fetch()){
			if(self::check_failed_process_time_diff($row['name'], $row['is_running_time'], $row['time_offset'])){
				if((new \dbdata\Get)->exec('cronjob', [
					'select' => [
						'is_running_time'
					],
					'where' => [
						'id' => $row['id']
					]
				])->fetch()['is_running_time']){
					return [
						'id'	=> $row['id'],
						'name'	=> $row['name']
					];
				}
			}
		}
		
		return [];
	}
	
	static public function task_status(string $task_name): array{
		$procs = [
			'master'	=> [],
			'children'	=> []
		];
		
		if(!$task = \Utils\Cmd\Proc::name('php', 'cronjob\.php '.$task_name.'\b', true)){
			return $procs;
		}
		
		foreach($task as $proc){
			if(strpos($proc['cmd'], ' -process=')){
				$procs['children'][] = $proc;
			}
			else{
				$procs['master'][] = $proc;
			}
		}
		
		usort($procs['master'], function($a, $b) {
			return $a['start'] <=> $b['start'];
		});
		
		if($procs['children']){
			usort($procs['children'], function($a, $b) {
				return $a['start'] <=> $b['start'];
			});
		}
		
		return $procs;
	}
	
	static private function check_failed_process_time_diff(string $task_name, int $is_running_time, int $time_offset): bool{
		if(!$is_running_time){
			return false;
		}
		
		if(!$master = reset(self::task_status($task_name)['master'])){
			return true;
		}
		
		return abs($is_running_time - $time_offset - $master['start']) > 1;
	}
}