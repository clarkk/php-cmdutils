<?php

namespace Utils\Cache;

class Cache {
	private \Redis $redis;
	
	public function __construct(string $auth){
		$this->redis = new \Redis;
		$this->redis->connect('127.0.0.1');
		$this->redis->auth($auth);
	}
	
	static public function json_encode(array $data): string{
		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}
	
	public function redis(): \Redis{
		return $this->redis;
	}
	
	public function write(string $key, string $data, int $expire): void{
		$this->redis->setEx($key, $expire, $data);
	}
	
	public function write_json(string $key, array $data, int $expire): void{
		$this->write($key, self::json_encode($data), $expire);
	}
	
	public function fetch(string $key): string{
		return $this->redis->get($key);
	}
	
	public function fetch_json(string $key): array{
		return json_decode($this->fetch($key), true) ?? [];
	}
	
	public function close(): void{
		$this->redis->close();
	}
}