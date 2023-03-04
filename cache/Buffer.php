<?php

namespace Utils\Cache;

class Buffer {
	private array $buffer 	= [];
	private \Redis $redis;
	
	public function __construct(
		private string $key,
		private bool $use_json=true
	){}
	
	public function cache(Cache &$cache): self{
		$this->redis = $cache->redis();
		return $this;
	}
	
	//	Read redis buffer data
	public function fetch(string $group_key=''): array{
		if(!$this->redis->lLen($this->key)){
			return [];
		}
		
		$list = [];
		foreach($this->redis->multi()->lRange($this->key, 0, -1)->del($this->key)->exec()[0] ?? [] as $data){
			if($this->use_json){
				$data = json_decode($data, true);
			}
			
			if($group_key){
				if(!isset($list[$data[$group_key]])){
					$list[$data[$group_key]] = [];
				}
				
				$list[$data[$group_key]][$data['id']] = $data;
			}
			elseif(!empty($data['id'])){
				$list[$data['id']] = $data;
			}
			else{
				$list[] = $data;
			}
		}
		
		return $list;
	}
	
	//	Check if data is buffered
	public function is_buffering(): bool{
		return !!$this->buffer;
	}
	
	//	Add data to buffer
	public function buffer(int $id, array $entry, string $group=''): void{
		if($group){
			if(!isset($this->buffer[$group])){
				$this->buffer[$group] = [];
			}
			
			$this->buffer[$group][$id] = $entry;
		}
		else{
			$this->buffer[$id] = $entry;
		}
	}
	
	//	Get buffered entry ids
	public function get_buffered_ids(bool $is_grouped=false): array{
		if($is_grouped){
			$list = [];
			foreach($this->buffer as $name => $ids){
				$list[$name] = array_keys($ids);
			}
			
			return $list;
		}
		else{
			return array_keys($this->buffer);
		}
	}
	
	//	Write buffer to redis
	public function write(bool $is_grouped=false): void{
		if($is_grouped){
			foreach($this->buffer as $entries){
				foreach($entries as $entry){
					$this->push($entry);
				}
			}
		}
		else{
			foreach($this->buffer as $entry){
				$this->push($entry);
			}
		}
		
		$this->buffer = [];
	}
	
	private function push(array $data): void{
		$this->redis->rPush($this->key, $this->use_json ? Cache::json_encode($data) : $data);
	}
}