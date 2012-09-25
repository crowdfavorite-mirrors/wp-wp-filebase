<?php class WPFB_ProgressReporter {
	var $quiet;
	
	function Log($msg, $no_new_line=false) {
		if(!$this->quiet)
			self::DEcho((!$no_new_line) ? ($msg."<br />") : $msg);
	}
	
	function LogError($err)
	{
		
	}
	
	function SetProgress($percentage)
	{
		
	}

	static function DEcho($str) {
		echo $str;
		@ob_flush();
		@flush();	
	}
}