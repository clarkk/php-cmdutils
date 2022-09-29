<?php

namespace Utils\Cache;

class Buffer {
	protected array $buffer = [];
	protected \Redis $redis;
	
	public function __construct(
		private string $key
	){}
	
	public function cache(Cache &$cache): self{
		$this->redis = $cache->redis();
		return $this;
	}
	
	public function fetch(string $group=''): array{
		if(!$this->redis->lLen($this->key)){
			return [];
		}
		
		$list = [];
		foreach($this->redis->multi()->lRange($this->key, 0, -1)->del($this->key)->exec()[0] ?? [] as $data){
			$data = json_decode($data, true);
			
			if($group){
				if(!isset($list[$data[$group]])){
					$list[$data[$group]] = [];
				}
				
				$list[$data[$group]][$data['id']] = $data;
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
	
	public function is_buffering(): bool{
		return !!$this->buffer;
	}
	
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
		$this->redis->rPush($this->key, json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}