<?php

namespace Utils\Cache;

class Lists {
	protected array $buffer = [];
	
	public function __construct(
		private string $key
	){}
	
	public function has_data(): bool{
		return $this->buffer ? true : false;
	}
	
	public function write(\Redis &$redis, array $data): void{
		$redis->rPush($this->key, json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}