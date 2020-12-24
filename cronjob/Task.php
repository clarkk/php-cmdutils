<?php

namespace Utils\Cronjob;

class Task {
	protected $task_name;
	protected $verbose = false;
	
	const REPORT_EMAIL = 'clk@dynaccount.com';
	
	public function __construct(string $task_name, int $verbose){
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		$this->exec();
	}
	
	protected function send_error_report(string $message){
		$SMTP = new \Mail\SMTP(NAME, MAIL_ALIAS.'@'.MAIL_DOMAIN);
		$SMTP->auth(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
		$SMTP->plain_message(gethostname().': '.$message, $message)
			->to_email(self::REPORT_EMAIL)
			->send();
	}
}