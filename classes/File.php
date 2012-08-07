<?php
wpfb_loadclass('Item');

class WPFB_File extends WPFB_Item {
	
	static $thumbnail_regex = '/^-([0-9]+)x([0-9]+)\.(jpg|jpeg|png|gif)$/i';

	var $file_id = 0;
	var $file_name;
	var $file_path;
	var $file_size = 0;
	var $file_date;
	var $file_mtime = 0;
	var $file_hash;
	var $file_remote_uri;
	var $file_thumbnail;
	var $file_display_name;
	var $file_description;
	var $file_tags; // 0.2.9.9
	var $file_version;
	var $file_author;
	var $file_language;
	var $file_platform;
	var $file_requirement;
	var $file_license;
	var $file_user_roles;
	var $file_offline = 0;
	var $file_direct_linking = 0;
	var $file_force_download = 0;
	var $file_category = 0;
	var $file_category_name;
	var $file_update_of = 0; // TODO
	var $file_post_id = 0;
	var $file_attach_order = 0;
	var $file_wpattach_id = 0;
	var $file_added_by = 0;
	var $file_hits = 0;
	var $file_ratings = 0; // TODO
	var $file_rating_sum = 0; // TODO
	var $file_last_dl_ip;
	var $file_last_dl_time;
	
	static $cache = array();
	//static $cache_complete = false;
	
	static function InitClass()
	{
		global $wpdb;
		self::$id_var = 'file_id';
	}			
		
	static function GetFiles($extra_sql = '')
	{
		global $wpdb;
		$files = array();		
		$results = $wpdb->get_results("SELECT `$wpdb->wpfilebase_files`.* FROM $wpdb->wpfilebase_files $extra_sql");
		if(!empty($results)) {
			foreach(array_keys($results) as $i) {				
				$id = (int)$results[$i]->file_id;
				self::$cache[$id] = new WPFB_File($results[$i]);	
				$files[$id] = self::$cache[$id];
			}
		}		
		return $files;
	}
	
	static function GetPermissionWhere() {
		global $wpdb, $current_user;
		static $permission_sql = '';
		if(empty($permission_sql)) { // only generate once per request
			if($current_user->ID > 0 && empty($current_user->roles[0]))
				$current_user = new WP_User($current_user->ID);// load the roles

			if(in_array('administrator',$current_user->roles)) $permission_sql = '1=1'; // administrator can access everything!
			elseif(WPFB_Core::GetOpt('private_files')) {
				$permission_sql = "file_added_by = 0 OR file_added_by = " . (int)$current_user->ID;
			} else {
				$permission_sql = "file_user_roles = ''";
				foreach($current_user->roles as $ur) {
					$ur = $wpdb->escape($ur);
					$permission_sql .= " OR (file_user_roles = '{$ur}') OR (file_user_roles LIKE '{$ur}|%') OR (file_user_roles LIKE '%|{$ur}|%') OR (file_user_roles LIKE '%|{$ur}')";
				}
				if($current_user->ID > 0)
					$permission_sql .= " OR (file_added_by = " . (int)$current_user->ID . ")";
			}
		}
		return $permission_sql;
	}
	
	static function GetSqlCatWhereStr($cat_id)
	{
		$cat_id = (int)$cat_id;
		return " (`file_category` = $cat_id) ";
	}
	
	private static function genSelectSql($where, $check_permissions, $order = null, $limit = -1, $offset = -1)
	{
		global $wpdb, $current_user;
		
		// parse where
		if(empty($where)) $where_str = '1=1';
		elseif(is_array($where)) {
			$where_str = '';
			foreach($where as $field => $value) {
				if($where_str != '') $where_str .= "AND ";
				if(is_numeric($value)) $where_str .= "$field = $value ";
				else $where_str .= "$field = '".$wpdb->escape($value)."' ";
			}
		} else $where_str =& $where;
		
		if($check_permissions != false) {
			if(is_string($check_permissions) && $check_permissions == 'edit') {
				$edit_cond = (current_user_can('edit_others_posts') && !WPFB_Core::GetOpt('private_files')) ? "1=1" : ("file_added_by = ".((int)$current_user->ID));
				$where_str = "($where_str) AND ($edit_cond)";
			} else
				$where_str = "($where_str) AND (".self::GetPermissionWhere().") AND file_offline = '0'";
		}
			
		
		// join id3 table if found in where clause
		$join_str = (strpos($where_str, $wpdb->wpfilebase_files_id3) !== false) ? " LEFT JOIN $wpdb->wpfilebase_files_id3 ON ( $wpdb->wpfilebase_files_id3.file_id = $wpdb->wpfilebase_files.file_id ) " : "";
		
		// parse order
		if(empty($order))
		$order_str = '';
		elseif(is_array($order)) {
			$order_str = 'ORDER BY ';
			foreach($order as $field => $dir)
			$order_str .= "$field " . ((strtoupper($dir)=="DESC")?"DESC":"ASC") . ", ";
			$order_str .= "$wpdb->wpfilebase_files.file_id ASC";
		} else $order_str = "ORDER BY $order";
		
		if($offset > 0) $limit_str = "LIMIT ".((int)$offset).", ".((int)$limit);
		elseif($limit > 0) $limit_str = "LIMIT ".((int)$limit);
		else $limit_str = '';
		
		//echo "$wpdb->wpfilebase_files $join_str WHERE ($where_str) $order_str $limit_str";
		return "$wpdb->wpfilebase_files $join_str WHERE ($where_str) $order_str $limit_str";
	}
	
	static function GetFiles2($where = null, $check_permissions = false, $order = null, $limit = -1, $offset = -1)
	{
		global $wpdb;
		$files = array();
		$results = $wpdb->get_results("SELECT `$wpdb->wpfilebase_files`.* FROM ". self::genSelectSql($where, $check_permissions, $order, $limit, $offset));
		if(!empty($results)) {
			foreach(array_keys($results) as $i) {
				$id = (int)$results[$i]->file_id;
				self::$cache[$id] = new WPFB_File($results[$i]);
				$files[$id] = self::$cache[$id];
			}
		} elseif(!empty($wpdb->last_error) && current_user_can('upload_files')) {
			echo "<b>Database error</b>: ".$wpdb->last_error; // print debug only if usr can upload
		}
		return $files;
	}
	
	static function GetFile($id)
	{		
		$id = intval($id);		
		if(isset(self::$cache[$id]) || WPFB_File::GetFiles("WHERE file_id = $id")) return self::$cache[$id];
		return null;
	}
	
	static function GetNumFiles($sql_or_cat = -1)
	{
		global $wpdb;
		static $n = -1;
		if($sql_or_cat == -1 && $n >= 0) return $n;
		if(is_numeric($sql_or_cat)) $sql_or_cat = (($sql_or_cat>=0)?" WHERE file_category = $sql_or_cat":"");
		$nn = $wpdb->get_var("SELECT COUNT($wpdb->wpfilebase_files.file_id) FROM $wpdb->wpfilebase_files $sql_or_cat"); 
		if($sql_or_cat == -1) $n = $nn;
		return $nn; 
	}
	
	static function GetNumFiles2($where, $check_permissions = true)
	{
		global $wpdb;
		return (int)$wpdb->get_var("SELECT COUNT($wpdb->wpfilebase_files.file_id) FROM ".self::genSelectSql($where, $check_permissions));
	}
	
	static function GetAttachedFiles($post_id)
	{
		$post_id = intval($post_id);
		return WPFB_File::GetFiles2(array('file_post_id' => $post_id), WPFB_Core::GetOpt('hide_inaccessible'), WPFB_Core::GetFileListSortSql(null, true));
	}
	
	function WPFB_File($db_row=null) {		
		parent::WPFB_Item($db_row);
		$this->is_file = true;
	}
	
	function DBSave()
	{ // validate some values before saving (fixes for mysql strict mode)
		if($this->locked > 0) return $this->TriggerLockedError();	
		$ints = array('file_size','file_category','file_post_id','file_attach_order','file_wpattach_id','file_added_by','file_update_of','file_hits','file_ratings','file_rating_sum');
		foreach($ints as $i) $this->$i = intval($this->$i);
		$this->file_offline = (int)!empty($this->file_offline);
		$this->file_direct_linking = (int)!empty($this->file_direct_linking);
		$this->file_force_download = (int)!empty($this->file_force_download);
		if(empty($this->file_last_dl_time)) $this->file_last_dl_time = '0000-00-00 00:00:00';
		$r = parent::DBSave();
		//$this->UpdateWPAttachment();
		return $r;
	}
	
	// gets the extension of the file (including .)
	function GetExtension() { return strtolower(strrchr($this->file_name, '.')); }
	
	function GetType()
	{
		$ext = substr($this->GetExtension(), 1);
		if( ($type = wp_ext2type($ext)) ) return $type;		
		return $ext;
	}	
	
	function CreateThumbnail($src_image='', $del_src=false)
	{		
		$src_set = !empty($src_image) && file_exists($src_image);
		$tmp_src = $del_src;
		if(!$src_set)
		{
			if(file_exists($this->GetLocalPath()))
				$src_image = $this->GetLocalPath();
			elseif($this->IsRemote()) {
				// if remote file, download it and use as source
				require_once(ABSPATH . 'wp-admin/includes/file.php');			
				$src_image = wpfb_call('Admin', 'SideloadFile', $this->file_remote_uri);
				$tmp_src = true;
			}
		}
		
		if(!file_exists($src_image) || @filesize($src_image) < 3) {
			if($tmp_src) @unlink($src_image);
			return;
		}
		
		$ext = trim($this->GetExtension(), '.');
	
		if($ext != 'bmp' && 
		($src_size = @getimagesize($src_image)) === false) { // check if valid image
			if($tmp_src) @unlink($src_image);
			return;
		}
		$this->DeleteThumbnail(); // delete old thumbnail
		
		$thumb = null;
		$thumb_size = (int)WPFB_Core::GetOpt('thumbnail_size');
		
		if(!function_exists('wp_create_thumbnail')) {
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			if(!function_exists('wp_create_thumbnail'))
			{
				if($tmp_src) @unlink($src_image);
				wp_die('Function wp_create_thumbnail does not exist!');
				return;
			}
		}
			
		$extras_dir = WPFB_PLUGIN_ROOT . 'extras/';
		
		if($ext == 'bmp') {			
			if(@file_exists($extras_dir . 'phpthumb.functions.php') && @file_exists($extras_dir . 'phpthumb.bmp.php'))
			{
				@include($extras_dir . 'phpthumb.functions.php');
				@include($extras_dir . 'phpthumb.bmp.php');
				
				if(class_exists('phpthumb_functions') && class_exists('phpthumb_bmp'))
				{
					$phpthumb_bmp = new phpthumb_bmp();
					
					$im = $phpthumb_bmp->phpthumb_bmpfile2gd($src_image);
					if($im) {
						$jpg_file = $src_image . '_thumb.jpg';
						@imagejpeg($im, $jpg_file, 100);
						if(@file_exists($jpg_file) && @filesize($jpg_file) > 0)
						{
							$thumb = @wp_create_thumbnail($jpg_file, $thumb_size);
						}
						@unlink($jpg_file);
					}						
				}
			}
		} else {
			$thumb = @wp_create_thumbnail($src_image, $thumb_size);
			if(is_wp_error($thumb) && max($src_size) <= $thumb_size) { // error occurs when image is smaller than thumb_size. in this case, just copy original
				$name = wp_basename($src_image, ".$ext");
				$thumb = dirname($src_image)."/{$name}-{$src_size[0]}x{$src_size[1]}.{$ext}";
				copy($src_image, $thumb);
			}
		}
		
		$success = (!empty($thumb) && !is_wp_error($thumb) && is_string($thumb) && file_exists($thumb));

		if(!$src_set && !$success) {
			$this->file_thumbnail = null;
		} else {
			// fallback to source image WARNING: src img will be moved or deleted!
			if($src_set && !$success)
				$thumb = $src_image;
			
			$this->file_thumbnail = basename(trim($thumb , '.')); // FIX: need to trim . when image has no extension
			
			if(!is_dir(dirname($this->GetThumbPath()))) WPFB_Admin::Mkdir(dirname($this->GetThumbPath()));
			if(!@rename($thumb, $this->GetThumbPath())) {
				$this->file_thumbnail = null;
				@unlink($thumb);
			} else
				@chmod($this->GetThumbPath(), octdec(WPFB_PERM_FILE));
		}
		
		if($tmp_src) @unlink($src_image);
	}

	function GetPostUrl() { return empty($this->file_post_id) ? '' : WPFB_Core::GetPostUrl($this->file_post_id).'#wpfb-file-'.$this->file_id; }
	function GetFormattedSize() { return wpfb_call('Output', 'FormatFilesize', $this->file_size); }
	function GetFormattedDate($f='file_date') { return (empty($this->$f) || $this->$f == '0000-01-00 00:00:00') ? null : mysql2date(WPFB_Core::GetOpt('file_date_format'), $this->$f); }
	function GetModifiedTime($gmt=false) { return $this->file_mtime + ($gmt ? ( get_option( 'gmt_offset' ) * 3600 ) : 0); }
	
	// only deletes file/thumbnail on FS, keeping DB entry
	function Delete()
	{
		$this->DeleteThumbnail();
		
		$this->file_remote_uri = null;
		
		if($this->IsLocal() && @unlink($this->GetLocalPath()))
		{
			$this->file_name = null;
			$this->file_size = null;
			$this->file_date = null;		
			return true;
		}		
		return false;
	}	
	
	function DeleteThumbnail()
	{
		$thumb = $this->GetThumbPath();
		if(!empty($thumb) && file_exists($thumb)) @unlink($thumb);			
		$this->file_thumbnail = null;
		if(!$this->locked) $this->DBSave();
	}	

	// completly removes the file from DB and FS
	function Remove($bulk=false)
	{	
		global $wpdb;

		if($this->file_category > 0 && ($parent = $this->GetParent()) != null)
			$parent->NotifyFileRemoved($this);
		
		// remove file entry
		$wpdb->query("DELETE FROM $wpdb->wpfilebase_files WHERE file_id = " . (int)$this->file_id);
		
		$wpdb->query("DELETE FROM $wpdb->wpfilebase_files_id3 WHERE file_id = " . (int)$this->file_id);
		
		// delete WP attachment entry
		$wpa_id = (int)$this->file_wpattach_id;
		if($wpa_id > 0 && $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d AND post_type = 'attachment' AND post_status IN ('private', 'publish')", $wpa_id)))
			wp_delete_attachment($wpa_id, true);
			
		if(!$bulk)
			self::UpdateTags();			
		
		return $this->Delete();
	}
	
	
	private function getInfoValue($path)
	{
		if(!isset($this->info)) // caching
		{
			global $wpdb;
			if($this->file_id <= 0) return join('->', $path);			
			$info = $wpdb->get_var("SELECT value FROM $wpdb->wpfilebase_files_id3 WHERE file_id = $this->file_id");
			$this->info = is_null($info) ? 0 : unserialize(base64_decode($info));
		}
		
		if(empty($this->info))
			return null;
		
		$val = $this->info;
		foreach($path as $p)
		{
			if(!isset($val[$p])) {
				if(isset($val[0]) && count($val) == 1) // if single array skip to first element
					$val = $val[0];
				else
					return null;				
			}
			$val = $val[$p];
		}		
		if(is_array($val)) $val = join(', ', $val);
		if($p == 'bitrate') {
			$val /= 1000;
			$val = round($val).' kBit/s';
		}
		return $val;
	}
    
    public function get_tpl_var($name)
    {		
		switch($name) {
			case 'file_url':			return htmlspecialchars($this->GetUrl());
			case 'file_url_rel':		return htmlspecialchars(WPFB_Core::GetOpt('download_base') . '/' . str_replace('\\', '/', $this->GetLocalPathRel()));
			case 'file_post_url':		return htmlspecialchars(!($url = $this->GetPostUrl()) ? $this->GetUrl() : $url);			
			case 'file_icon_url':		return htmlspecialchars($this->GetIconUrl());
			case 'file_small_icon':		return '<img src="'.esc_attr($this->GetIconUrl('small')).'" style="vertical-align:middle;height:32px;" />';
			case 'file_size':			return $this->GetFormattedSize();
			case 'file_path':			return htmlspecialchars($this->GetLocalPathRel());
			
			case 'file_category':		return htmlspecialchars(is_object($cat = $this->GetParent()) ? $cat->cat_name : '');
			case 'cat_small_icon':		return is_null($cat = $this->GetParent()) ? '' : ('<img align="" src="'.htmlspecialchars($cat->GetIconUrl('small')).'" style="height:32px;vertical-align:middle;" />');
			case 'cat_icon_url':		return is_null($cat = $this->GetParent()) ? '' : htmlspecialchars($cat->GetIconUrl());
			case 'cat_url':				return is_null($cat = $this->GetParent()) ? '' : htmlspecialchars($cat->GetUrl());
			
			case 'file_languages':		return wpfb_call('Output','ParseSelOpts', array('languages', $this->file_language),true);
			case 'file_platforms':		return wpfb_call('Output','ParseSelOpts', array('platforms', $this->file_platform),true);
			case 'file_requirements':	return wpfb_call('Output','ParseSelOpts', array('requirements', $this->file_requirement, true),true);
			case 'file_license':		return wpfb_call('Output','ParseSelOpts', array('licenses', $this->file_license, true), true);
			
			//case 'file_required_level':	return ($this->file_required_level - 1);
			
			case 'file_description':	return nl2br($this->file_description);
			case 'file_tags':			return str_replace(',',', ',trim($this->file_tags,','));
			
			case 'file_date':
			case 'file_last_dl_time':	return htmlspecialchars($this->GetFormattedDate($name));
			
			case 'file_extension':		return strtolower(substr(strrchr($this->file_name, '.'), 1));
			case 'file_type': 			return wpfb_call('Download', 'GetFileType', $this->file_name);
			
			case 'file_url_encoded':	return htmlspecialchars(urlencode($this->GetUrl()));
			
			case 'file_added_by':		return (empty($this->file_added_by) || !($usr = get_userdata($this->file_added_by))) ? '' : esc_html($usr->display_name);
			
			case 'uid':					return self::$tpl_uid;
		}
		
    	if(strpos($name, 'file_info/') === 0)
		{
			$path = explode('/',substr($name, 10));
			return htmlspecialchars($this->getInfoValue($path));
		} elseif(strpos($name, 'file_custom') === 0) // dont esc custom
			return isset($this->$name) ? $this->$name : '';		
		return isset($this->$name) ? htmlspecialchars($this->$name) : '';
    }
	
	function DownloadDenied($msg_id) {
		if(WPFB_Core::GetOpt('inaccessible_redirect') && !is_user_logged_in()) {
			//auth_redirect();
			$redirect = (WPFB_Core::GetOpt('login_redirect_src') && wp_get_referer()) ? wp_get_referer() : $this->GetUrl();
			$login_url = wp_login_url($redirect, true); // force re-auth
			wp_redirect($login_url);
			exit;
		}
		$msg = WPFB_Core::GetOpt($msg_id);
		if(!$msg) $msg = $msg_id;
		elseif(preg_match('/^https?:\/\//i',$msg)) {
			wp_redirect($msg); // redirect if msg is url
			exit;
		}
		wp_die(empty($msg) ? __('Cheatin&#8217; uh?') : $msg);
		exit;
	}
	
	// checks permissions, tracks download and sends the file
	function Download()
	{
		global $wpdb, $current_user, $user_ID;
		
		@error_reporting(0);
		wpfb_loadclass('Category', 'Download');
		$downloader_ip = preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR']);
		get_currentuserinfo();
		$logged_in = (!empty($user_ID));
		$user_role = $logged_in ? array_shift($current_user->roles) : null; // get user's highest role (like in user-eidt.php)
		$is_admin = ('administrator' == $user_role); 
		
		// check user level
		if(!$this->CurUserCanAccess())
			$this->DownloadDenied('inaccessible_msg');
		
		// check offline
		if($this->file_offline)
			wp_die(WPFB_Core::GetOpt('file_offline_msg'));
		
		// check referrer
		if(!$this->file_direct_linking) {			
			// if referer check failed, redirect to the file post
			if(!WPFB_Download::RefererCheck()) {
				$url = WPFB_Core::GetPostUrl($this->file_post_id);
				if(empty($url)) $url = home_url();
				wp_redirect($url);
				exit;
			}
		}
		
		// check traffic
		if($this->IsLocal() && !WPFB_Download::CheckTraffic($this->file_size)) {
			header('HTTP/1.x 503 Service Unavailable');
			wp_die(WPFB_Core::GetOpt('traffic_exceeded_msg'));
		}

		// check daily user limit
		if(!$is_admin && WPFB_Core::GetOpt('daily_user_limits')) {
			if(!$logged_in)
				$this->DownloadDenied('inaccessible_msg');
			
			$today = intval(date('z'));
			$usr_dls_today = intval(get_user_option(WPFB_OPT_NAME . '_dls_today'));
			$usr_last_dl_day = intval(date('z', intval(get_user_option(WPFB_OPT_NAME . '_last_dl'))));
			if($today != $usr_last_dl_day)
				$usr_dls_today = 0;
			
			// check for limit
			$dl_limit = intval(WPFB_Core::GetOpt('daily_limit_'.$user_role));
			if($dl_limit > 0 && $usr_dls_today >= $dl_limit)
				$this->DownloadDenied(sprintf(WPFB_Core::GetOpt('daily_limit_exceeded_msg'), $dl_limit));			
			
			$usr_dls_today++;
			update_user_option($user_ID, WPFB_OPT_NAME . '_dls_today', $usr_dls_today);
			update_user_option($user_ID, WPFB_OPT_NAME . '_last_dl', time());
		}			
		
		// count download
		if(!$is_admin || !WPFB_Core::GetOpt('ignore_admin_dls')) {
			$last_dl_time = mysql2date('U', $this->file_last_dl_time , false);
			if(empty($this->file_last_dl_ip) || $this->file_last_dl_ip != $downloader_ip || ((time() - $last_dl_time) > 86400))
				$wpdb->query("UPDATE " . $wpdb->wpfilebase_files . " SET file_hits = file_hits + 1, file_last_dl_ip = '" . $downloader_ip . "', file_last_dl_time = '" . current_time('mysql') . "' WHERE file_id = " . (int)$this->file_id);
		}
		
		// download or redirect
		if($this->IsLocal())
			WPFB_Download::SendFile($this->GetLocalPath(), array(
				'bandwidth' => WPFB_Core::GetOpt('bitrate_' . ($logged_in?'registered':'unregistered')),
				'etag' => $this->file_hash,
				'md5_hash' => $this->file_hash,
				'force_download' => $this->file_force_download
			));
		else {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: '.$this->file_remote_uri);
		}
		
		exit;
	}
	
	function SetPostId($id)
	{
		$id = intval($id);
		if($this->file_post_id == $id) return;
		$this->file_post_id = $id;	
		if($id > 0)
			$this->file_attach_order = count(self::GetAttachedFiles($id)) + 1;		
		if(!$this->locked) $this->DBSave();
	}
	
	function SetModifiedTime($mysql_date_or_timestamp)
	{
		if(!is_numeric($mysql_date_or_timestamp)) $mysql_date_or_timestamp = mysql2date('U', $mysql_date_or_timestamp);
		if($this->IsLocal()) {
			if(!@touch($this->GetLocalPath(), $mysql_date_or_timestamp))
				return false;
			$this->file_mtime = filemtime($this->GetLocalPath());
		} else {
			$this->file_mtime = $mysql_date_or_timestamp;
		}
		if(!$this->locked) $this->DBSave();
		return $this->file_mtime;
	}
	
	function SetTags($tags) {
		if(is_string($tags)) $tags = explode(',', $tags);
		$tags = array_unique(array_map('trim',(array)$tags));
		$this->file_tags = ','.implode(',',$tags).',';
		if(!$this->locked) $this->DBSave();
		self::UpdateTags($this);
	}
	
	function GetTags() {
		return explode(',', trim($this->file_tags,','));
	}
	
	static function UpdateTags($cur_file=null)
	{
		$tags = array();
		$files = self::GetFiles2((empty($cur_file) ? "" : "file_id <> $cur_file->file_id AND ") . "file_tags <> ''", false);
		if(!empty($cur_file)) $files[$cur_file->file_id] = $cur_file;
		foreach($files as $file) {
			$fts = $file->GetTags();
			foreach($fts as $ft) {
				$tags[$ft] = isset($tags[$ft]) ? ($tags[$ft]+1) : 1;
			}
		}
		ksort($tags);		
		update_option(WPFB_OPT_NAME.'_ftags', $tags);
	}
	
	function GetWPAttachmentID() {
		return $this->file_wpattach_id;
		//global $wpdb;
		//return $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid = %s", $this->GetUrl()) );
	}
	
	// TODO:
	function UpdateWPAttachment($file_changed=false) {
		global $wpdb;		
		
		return 0; // beta!!
		/*
		
		if($this->locked > 0) $this->TriggerLockedError();
		
		$rel_path = $this->GetLocalPath();
		
		
		if(!($uploads = wp_upload_dir()) || $uploads['error'] || strpos($rel_path, $uploads['basedir'].'/') === false) {
			echo "Path error. Cannot create WP attachmet!";
			return false;
		}
		
		$rel_path = str_replace(WPFB_Core::UploadDir(), '/'.WPFB, $rel_path);
		
		$object = array(
		'post_author' => $this->file_added_by,
		'post_content' => '[wpfilebase tag=file id='.$this->GetId().' tpl=single]',
		'post_title' => $this->GetTitle(),
		'post_excerpt' => $this->GenTpl(WPFB_Core::GetParsedTpl('file','excerpt')),
		'post_status' => $this->file_offline ? 'private' : 'publish',
		'post_password' => '',
		'post_name' => $this->GetName(),
		'to_ping' =>  '', 'pinged' => '',
		'post_content_filtered' => '',
		'post_parent' => $this->file_offline ? 0 : (int)WPFB_Core::GetOpt('file_browser_post_id'), //$this->file_post_id,
		'guid' => $this->GetUrl(),
		'menu_order' => $this->file_attach_order,
		'post_type' => 'attachment',
		'post_mime_type' => 'application/octet-stream' //wpfb_call('Download', 'GetFileType', $this->file_name),
		//'import_id' => $this->GetId()
		);
		
		
		$object = sanitize_post($object, 'db');
		
		// export array as variables
		extract($object, EXTR_SKIP);
		
		//$post_category = array( get_option('default_category') );
		$post_category = array();
		
		
		$ID = $this->file_wpattach_id;
		// Are we updating or creating?
		if ( !empty($ID) ) {
			$update = true;
			$post_ID = (int) $ID;
		} else {
			$update = false;
			$post_ID = 0;
		}
		
		// Create a valid post name.
		if ( empty($post_name) ) $post_name = sanitize_title($post_title);
		else $post_name = sanitize_title($post_name);
		
		// expected_slashed ($post_name)
		$post_name = wp_unique_post_slug($post_name, $post_ID, $post_status, $post_type, $post_parent);
		
		$post_modified = $post_date = gmdate('Y-m-d H:i:s', $this->GetModifiedTime());
		$post_modified_gmt = $post_date_gmt = gmdate('Y-m-d H:i:s', $this->GetModifiedTime(true));
	
		
		if ( empty($comment_status) ) {
			if ( $update ) $comment_status = 'closed';
			else $comment_status = get_option('default_comment_status');
		}
		
		if ( empty($ping_status) ) $ping_status = get_option('default_ping_status');		
		if ( isset($to_ping) ) $to_ping = preg_replace('|\s+|', "\n", $to_ping);
		else $to_ping = '';
		
		if ( isset($post_parent) ) $post_parent = (int) $post_parent;
		else $post_parent = 0;
		
		if ( isset($menu_order) ) $menu_order = (int) $menu_order;
		else $menu_order = 0;
		
		if ( !isset($post_password) ) $post_password = '';
		
		if ( ! isset($pinged) )	$pinged = '';
		
		
		// expected_slashed (everything!)
		$data = compact( array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt', 'post_status', 'post_type', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type', 'guid' ) );
		$data = stripslashes_deep( $data );
		
		if ( $update ) {
			$wpdb->update( $wpdb->posts, $data, array( 'ID' => $post_ID ) );
		} else {
			// If there is a suggested ID, use it if not already present
			if ( !empty($import_id) ) {
				$import_id = (int) $import_id;
				if ( ! $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d", $import_id) ) ) {
					$data['ID'] = $import_id;
				}
			}
		
			$wpdb->insert( $wpdb->posts, $data );
			$post_ID = (int) $wpdb->insert_id;
		}
		
		if ( empty($post_name) ) {
			$post_name = sanitize_title($post_title, $post_ID);
			$wpdb->update( $wpdb->posts, compact("post_name"), array( 'ID' => $post_ID ) );
		}
		
		wp_set_post_categories($post_ID, $post_category);
		
		update_post_meta($post_ID, '_wp_attached_file',  $rel_path);
		
		clean_post_cache($post_ID);
		
		if ( isset($post_parent) && $post_parent < 0 )
		add_post_meta($post_ID, '_wp_attachment_temp_parent', $post_parent, true);
		
		if ( ! empty( $context ) )
		add_post_meta( $post_ID, '_wp_attachment_context', $context, true );
		
		if ( $update) {
			do_action('edit_attachment', $post_ID);
		} else {
			do_action('add_attachment', $post_ID);
		}
		
		if(!$update || $file_changed || true) {
			$metadata = array();
			$w = (int)$this->getInfoValue(array('video','resolution_x'));
			if($w > 0) {
				$metadata['width'] = $w;
				$metadata['height'] = $h = (int)$this->getInfoValue(array('video','resolution_y'));
				$metadata['file'] = ''; //$rel_path; TODO invalid, must be relative to wp-content/upload
				// 	$metadata['hwstring_small'] = "height='$uheight' width='$uwidth'";
				
				if(!empty($this->file_thumbnail)) {
					// calc thumb size
					$max_side = max(array($w,$h));
					$thumb_size = (int)WPFB_Core::GetOpt('thumbnail_size');
					if($max_side > $thumb_size) {
						$w *= $thumb_size / $max_side;
						$h *= $thumb_size / $max_side;
					}
					
					$img_sizes = array('thumbnail','medium','post-thumbnail','large-feature','small-feature');					
					$metadata['sizes'] = array();
					foreach($img_sizes as $is) {
						$metadata['sizes'][$is] = array(
							'file' => $this->file_thumbnail,
							'width' => (int)round($w),
							'height' => (int)round($h)
						);
					}
				}
			}
			// $metadata['file'] = _wp_relative_upload_path($file);
			if(!empty($metadata))
				wp_update_attachment_metadata($post_ID, $metadata);			
		}
		
		if($this->file_wpattach_id != $post_ID) {
			$this->file_wpattach_id = $post_ID;
			if($this->locked == 0) $this->DBSave();
		}					
		
		return $post_ID;
		/**/
	}

	
	function IsRemote() { return !empty($this->file_remote_uri); }	
	function IsLocal() { return empty($this->file_remote_uri); }
		
	function CurUserIsOwner() {
		global $current_user;
		return (!empty($current_user->ID) && $this->file_added_by > 0 && $this->file_added_by == $current_user->ID);
	}
}

?>