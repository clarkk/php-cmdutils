<?php

namespace Utils\Cache;

class Cache {
	private \Redis $redis;
	
	public function __construct(string $auth){
		$this->redis = new \Redis;
		$this->redis->connect('127.0.0.1');
		$this->redis->auth($auth);
	}
	
	public function write(Lists $buffer): void{
		$buffer->send($this->redis);
	}
	
	public function close(): void{
		$this->redis->close();
	}
}