# php-utils

A bundle of usefull system utility tools for Linux

# \Utils\Cmd\Cmd
Executes a command line like "shell_exec()" or "exec()", but in a more sophisticated way with **proc_\*** and **stream_\*** functions with more flexibility and feature-rich.

**Blocking system I/O calls**
```
$Cmd = new \Utils\Cmd\Cmd;
if($stderr = $Cmd->exec('cat filename')){
  throw new Error($stderr);
}
$stdout = $Cmd->output();
```

**Non-blocking system I/O calls**
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
  }
  
  sleep(5);
}
$stdout = $Cmd->output();
```
