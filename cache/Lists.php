<?php

namespace Utils\Cache;

class Lists {
	protected array $buffer = [];
	
	public function __construct(
		private string $key
	){}
	
	public function has_data(){
		return $this->buffer ? true : false;
	}
	
	public function write(\Redis &$redis, array $data){
		$redis->rPush($this->key, json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}