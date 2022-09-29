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
	
	public function is_buffering(): bool{
		return !!$this->buffer;
	}
	
	public function buffer(int $id, array $entry, string $dimension=''): void{
		if($dimension){
			if(!isset($this->buffer[$dimension])){
				$this->buffer[$dimension] = [];
			}
			
			$this->buffer[$dimension][$id] = $entry;
		}
		else{
			$this->buffer[$id] = $entry;
		}
	}
	
	public function write(bool $dimension=false): void{
		if($dimension){
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
	}
	
	private function push(array $data): void{
		$this->redis->rPush($this->key, json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}