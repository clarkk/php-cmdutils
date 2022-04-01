# php-utils
A bundle of usefull system utility tools for Linux

# \Utils\Cmd\Cmd
Executes a command line like "shell_exec()" or "exec()", but in a more sophisticated way with **proc_\*** and **stream_\*** functions with more flexibility and feature-rich.

**Blocking system I/O call**
```
$Cmd = new \Utils\Cmd\Cmd;
if($stderr = $Cmd->exec('cat filename')){
  throw new Error($stderr);
}
$stdout = $Cmd->output();
```

**Non-blocking system I/O call**
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

# \Utils\Cmd\Net
Calls an URL with **curl_\*** functions.

**Single URL request**
```
$Net = new \Utils\Net\Net()
  ->decode_type();  // Decode response content type like JSON to array

//  The connection is automatically closed when keep-alive is not enabled
$response = $Net->request('https://the-url');
```

**Keep-Alive connection**
```
$Net = new \Utils\Net\Net()
  ->decode_type()  // Decode response content type like JSON to array
  ->keep_alive();  // Enabled keep-alive connection

$response = $Net->request('https://the-first-url');

$response = $Net->request('https://the-second-url', 'var1='.urlencode('value of first var').'&var2='.urlencode('value of second var'));

//  Closes connection after use
$Net->close();
```

**Multipart request (file upload etc.)**
```
$Net = new \Utils\Net\Net()
  ->decode_type();  // Decode response content type like JSON to array

$file_upload = $Net->multipart_value('post_name_of_file', file_get_contents('/path/to/file/The-file-name.txt'), 'The-file-name.txt', 'text/plain');
$post_variable = $Net->multipart_value('post_name_of_variable', 'the value of the variable');

$custom_headers = [];
$custom_curl_opt = [];
$post = $file_upload.$post_variable.$Net->multipart_end();

$response = $Net->request('https://the-first-url', $post, $custom_headers, $custom_curl_opt, true);
```
