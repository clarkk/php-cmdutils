<?php

namespace Utils\Cache;

class Cache {
	private \Redis $redis;
	
	public function __construct(string $auth, string $host='127.0.0.1'){
		$this->redis = new \Redis;
		$this->redis->connect($host);
		$this->redis->auth($auth);
	}
	
	static public function json_encode(array $data): string{
		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}
	
	public function redis(): \Redis{
		return $this->redis;
	}
	
	//	Set redis key value with TTL (expire) in seconds
	public function write(string $key, string $data, int $expire): void{
		$this->redis->setEx($key, $expire, $data);
	}
	
	//	Set JSON encoded redis key value with TTL (expire) in seconds
	public function write_json(string $key, array $data, int $expire): void{
		$this->write($key, self::json_encode($data), $expire);
	}
	
	//	Get redis key value
	public function fetch(string $key): string{
		return $this->redis->get($key);
	}
	
	//	Get JSON encoded redis key value
	public function fetch_json(string $key): array{
		return json_decode($this->fetch($key), true) ?? [];
	}
	
	//	Find keys by pattern
	public function scan(string $pattern): array{
		$list = [];
		$this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
		$iterator = null;
		while($iterator !== 0){
			$keys = $this->redis->scan($iterator, $pattern);
			$list = array_merge($list, $keys);
		}
		return $list;
	}
	
	public function close(): void{
		$this->redis->close();
	}
}