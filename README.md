# php-utils
A powerful bundle of basic system (Linux) utility tools for your PHP web-application included with `cpp-proc` https://github.com/clarkk/cpp-proc

## Classes
- [\Utils\Cmd\Cmd](#utilscmdcmd)
- [\Utils\Net\Net](#utilsnetnet)
- [\Utils\SSH\SSH](#utilssshssh)
- [\Utils\WSS\Server](#utilswssserver)

## \Utils\Cmd\Cmd
Executes a command line (like "shell_exec" or "exec"), but in a more sophisticated way with **proc_\*** and **stream_\*** functions with more flexibility and feature-rich.

### Blocking system I/O call
```
$Cmd = new \Utils\Cmd\Cmd;
if($stderr = $Cmd->exec('cat filename')){
  throw new Error($stderr);
}
$stdout = $Cmd->output();
```

### Non-blocking system I/O call
```
$Cmd = new \Utils\Cmd\Cmd(true);
$Cmd->exec('a heavy command that takes more time');

while(true){
  //  Check once in a while how the process has progressed
  
  $stdout = $Cmd->get_pipe_stream(\Utils\Cmd\Cmd::PIPE_STDOUT);
  $stderr = $Cmd->get_pipe_stream(\Utils\Cmd\Cmd::PIPE_STDERR);
  
  if(!$Cmd->is_running()){
    //  The process has stopped executing
    
    if(!$Cmd->is_success()){
      //  The process completed unsuccessfully with an exitcode
      
      $exitcode = $Cmd->get_exitcode();
      
      if($Cmd->is_terminated()){
        //  The process was terminated
      }
    }
    else{
      //  The process completed successfully without an exitcode
    }
    
    break;
  }
  
  sleep(5);
}
```

## \Utils\Net\Net
Calls an URL with **curl_\*** functions.

### Single URL request
```
$Net = (new \Utils\Net\Net)
  ->decode_type();  // Decode response content type like JSON to array

//  GET request (connection is automatically closed when keep-alive is not enabled)
$response = $Net->request('https://the-url');
```

### Keep-Alive connection
```
$Net = (new \Utils\Net\Net)
  ->decode_type()  // Decode response content type like JSON to array
  ->keep_alive();  // Enable keep-alive connections

//  GET request
$response = $Net->request('https://the-first-url?query=test');

//  POST request
$response = $Net->request('https://the-second-url', 'var1='.urlencode('value of first var').'&var2='.urlencode('value of second var'));

//  POST request with JSON
$response = $Net->request('https://the-third-url', json_encode($arr));

//  Closes connection after use
$Net->close();
```

### Multipart request (file upload etc.)
```
$Net = (new \Utils\Net\Net)
  ->decode_type();  // Decode response content type like JSON to array

$file_upload      = $Net->multipart_value('post_name_of_file', file_get_contents('/path/to/file/The-file-name.txt'), 'The-file-name.txt');
$post_variable    = $Net->multipart_value('post_name_of_variable', 'the value of the variable');

$post = $file_upload.$post_variable.$Net->multipart_end();

$response = $Net->request_multipart('https://the-url', $post);
```

## \Utils\SSH\SSH
Executes remote command line via SSH2 with **ssh2_\*** and **stream_\*** functions.

Note: Remember to set the constants **RSA_PRIVATE** and **RSA_PUBLIC** with the correct paths to your RSA private and public key pair.

### SSH blocking system I/O call
```
$SSH = new \Utils\SSH\SSH('root', 'host');
if($stderr = $SSH->exec('cat filename')){
  throw new Error($stderr);
}
$stdout = $SSH->output();
$SSH->disconnect();
```

### SSH non-blocking system I/O call
```
$SSH = new \Utils\SSH\SSH('root', 'host', true);
$SSH->exec('a heavy command that takes more time');

while(true){
  //  Check once in a while how the process has progressed
  
  $stdout = $Cmd->get_pipe_stream(\Utils\SSH\SSH::PIPE_STDOUT);
  $stderr = $Cmd->get_pipe_stream(\Utils\SSH\SSH::PIPE_STDERR);
  
  /*
   *  Technically it's not possible (from the same SSH session)
   *    - to listen for a signal when the process has completed
   *    - to fetch the exitcode of the process
   *
   *  PROCESS COMPLETED (workaround)
   *  -----------------------------------
   *  Prepend the command with 'echo $$;' to print the PID of the process before it begins
   *    e.g. 'echo $$; command'
   *
   *  Then check if the PID is still running from another SSH session via
   *    $SSH->exec_is_proc_running($pid);
   *    $is_pid_running = (int)$SSH->output();
   *
   *  PROCESS EXITCODE (workaround)
   *  -----------------------------------
   *  Append the command with ';echo -e "\n$?"' to print the exitcode of the process when completed
   *    e.g. 'command; echo -e "\n$?"'
   */
  
  sleep(5);
}

$SSH->disconnect();
```

### SSH upload file
```
$SSH = new \Utils\SSH\SSH('root', 'host');
$SSH->upload('/local/path/to/file', '/remote/path/to/file');
$SSH->disconnect();
```

## \Utils\WSS\Server
High performance websocket server with **Fibers** (introduced in PHP 8.1) implemented with **asynchronous and non-blocking I/O calls**.
It's designed with an interruptible main event loop, and it "spawns" new fibers/threads (in the same process) on each socket read/write.

### Run websocket server
```
class Websocket_server extends \Utils\WSS\Server {
  public function __construct(string $task_name, int $verbose){
    parent::__construct($task_name, $verbose);
  }
  
  public function onopen(\Utils\WSS\Client $client): void{
    // A new connection was established
  }
  
  public function onmessage(\Utils\WSS\Client $client, array $message): void{
    // The client sent a message to the server (The message from the client must be JSON encoded)
  }
  
  public function onclose(\Utils\WSS\Client $client): void{
    // The client closed the connection
  }
  
  public function push(): void{
    foreach($this->clients as $socket_id => &$client){
      // Push notifications/messages to the client (The message is JSON encoded before sent)
      $this->send($client, [
        'msg' => 'Message to the client'
      ]);
    }
  }
}

try{
  $WSS = new Websocket_server('websocket_instance1');
  $WSS->run();
}
catch(\Utils\WSS\Socket_error $e){
  \Log\Err::fatal($e);
}
```