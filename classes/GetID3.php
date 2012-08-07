<?php
class WPFB_GetID3 {
	static $engine;
	
	static function InitClass()
	{
		require_once(WPFB_PLUGIN_ROOT.'extras/getid3/getid3.php');		
		self::$engine = new getID3;
	//$getID3->setOption(array(
	//	'option_md5_data'  => $AutoGetHashes,
	//	'option_sha1_data' => $AutoGetHashes,
	//));		
	}
	
	static function AnalyzeFile($file)
	{
		$filename = is_string($file) ? $file : $file->GetLocalPath();
		
		if(WPFB_Core::GetOpt('disable_id3')) $info = array();
		else $info =& self::$engine->analyze($filename);
		
		return $info;
	}
	
	static function StoreFileInfo($file_id, $info)
	{
		global $wpdb;
		
		self::cleanInfoByRef($info);

		$data = empty($info) ? '0' : base64_encode(serialize($info));
		
		$keywords = array();
		self::getKeywords($info, $keywords);
		$keywords = strip_tags(join(' ', $keywords));
		$keywords = str_replace(array('\n','&#10;'),'', $keywords);
		$keywords = preg_replace('/\s\s+/', ' ', $keywords);
		$keywords = utf8_encode($keywords);
		return $wpdb->replace($wpdb->wpfilebase_files_id3, array(
			'file_id' => (int)$file_id,
			'analyzetime' => time(),
			'value' => $data,
			'keywords' => $keywords
		));
	}
	
	static function UpdateCachedFileInfo($file)
	{
		$info =& self::AnalyzeFile($file);
		self::StoreFileInfo($file->GetId(), $info);
		return $info;
	}
	
	// gets file info out of the cache or analyzes the file if not cached
	static function GetFileInfo($file, $get_keywords=false)
	{
		global $wpdb;
		$sql = "SELECT value".($get_keywords?", keywords":"")." FROM $wpdb->wpfilebase_files_id3 WHERE file_id = " . $file->GetId();
		if($get_keywords) {   // TODO: cache not updated if get_keywords
			$info = $wpdb->get_row($sql);
			if(!empty($info))
				$info->value = unserialize(base64_decode($info->value));
			return $info;
		}
		if(is_null($info = $wpdb->get_var($sql)))
			return self::UpdateCachedFileInfo($file);
		return ($info=='0') ? null : unserialize(base64_decode($info));
	}
	
	static function GetFileAnalyzeTime($file)
	{
		global $wpdb;
		$t = $wpdb->get_var("SELECT analyzetime FROM $wpdb->wpfilebase_files_id3 WHERE file_id = ".$file->GetId());
		if(is_null($t)) $t = 0;
		return $t;
	}
	
	private static function cleanInfoByRef(&$info)
	{
		static $skip_keys = array('getid3_version','streams','seektable','streaminfo',
		'comments_raw','encoding', 'flags', 'image_data','toc','lame', 'filename', 'filesize', 'md5_file',
		'data', 'warning', 'error', 'filenamepath', 'filepath','popm','email','priv','ownerid','central_directory','raw','apic','iTXt','IDAT');

		foreach($info as $key => &$val)
		{
			if(empty($val) || in_array(strtolower($key), $skip_keys) || strpos($key, "UndefinedTag") !== false || strpos($key, "XML") !== false)
			{
				unset($info[$key]);
				continue;
			}
				
			if(is_array($val) || is_object($val))
				self::cleanInfoByRef($info[$key]);
			else if(is_string($val))
			{
				$a = ord($val{0});
				if($a < 32 || $a > 126 || $val{0} == '?' || strpos($val, chr(01)) !== false || strpos($val, chr(0x09)) !== false)  // check for binary data
				{
					unset($info[$key]);
					continue;
				}
			}
		}
	}
	
	private static function getKeywords($info, &$keywords) {
		foreach($info as $key => $val)
		{
			if(is_array($val) || is_object($val)) {
				self::getKeywords($val, $keywords);
				self::getKeywords(array_keys($val), $keywords); // this is for archive files, where file names are array keys
			} else if(is_string($val)) {				
				if(!in_array($val, $keywords))
					array_push($keywords, $val);
			}
		}
		return $keywords;
	}
}