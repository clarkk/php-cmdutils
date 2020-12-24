<?php

namespace Utils\Cronjob;

trait Cronjob_error_report {
	protected function send_error_report(string $message){
		$SMTP = new \Mail\SMTP(NAME, MAIL_ALIAS.'@'.MAIL_DOMAIN);
		$SMTP->auth(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
		$SMTP->plain_message(gethostname().': '.$message, $message)
			->to_email('clk@dynaccount.com')
			->send();
	}
}