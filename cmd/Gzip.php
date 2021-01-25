<?php

namespace Utils\Cmd;

class Gzip extends Cmd {
	public function start(){
		$this->exec('gzip - -c');
	}
	
	public function file_stream(string $filename, string $file){
		$stats = [
			'mode'	=> '',
			'uid'	=> '',
			'gid'	=> '',
			'size'	=> strlen($file),
			'mtime'	=> ''
		];
		
		$this->input($this->start_block($filename, $stats).$file.$this->end_block($file, true));
	}
	
	public function file(string $filename, string $file){
		$stats = stat($file);
		
		if(is_dir($file)){
			$this->input($this->start_block($filename, $stats, true));
		}
		else{
			$this->input($this->start_block($filename, $stats).file_get_contents($file).$this->end_block($file));
		}
		
		clearstatcache();
	}
	
	public function end(){
		$this->input(pack('a512', ''));
		fclose($this->pipes[self::PIPE_STDIN]);
	}
	
	private function start_block(string $filename, array $stats, bool $is_dir=false): string{
		if(strlen($filename) > 99){
			throw new Error('More than 99 chars in path: '.$filename);
		}
		
		$header = pack(
			'a100a8a8a8a12A12a8a1a100a255',
			$filename,
			sprintf('%6s ',		decoct($stats['mode'])),
			sprintf('%6s ',		decoct($stats['uid'])),
			sprintf('%6s ',		decoct($stats['gid'])),
			sprintf('%11s ',	decoct($is_dir ? 0 : $stats['size'])),
			sprintf('%11s',		decoct($stats['mtime'])),
			sprintf('%8s ',		' '),
			$is_dir ? 5 : 0,
			'',
			''
		);
		
		$checksum = 0;
		for($i=0; $i<512; $i++){
			$checksum += ord($header{$i});
		}
		
		$checksum_data = pack(
			'a8',
			sprintf('%6s ',
			decoct($checksum))
		);
		
		for($i=0, $j=148; $i<8; $i++, $j++){
			$header{$j} = $checksum_data{$i};
		}
		
		return $header;
	}
	
	private function end_block(string $file, bool $is_stream=false): string{
		$filesize = $is_stream ? strlen($file) : filesize($file);
		
		return pack('a'.(512 * ceil($filesize / 512) - $filesize), '');
	}
}