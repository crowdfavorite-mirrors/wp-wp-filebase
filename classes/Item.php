<?php
class WPFB_Item {

	var $is_file;
	var $is_category;
	
	var $last_parent_id = 0;
	var $last_parent = null;
	
	var $locked = 0;
	
	static $tpl_uid = 0;
	static $id_var;
	
	function WPFB_Item($db_row=null)
	{
		if(!empty($db_row))
		{
			foreach($db_row as $col => $val){
				$this->$col = $val;
			}
			$this->is_file = isset($this->file_id);
			$this->is_category = isset($this->cat_id);
		}
	}
	
	function GetId(){return (int)($this->is_file?$this->file_id:$this->cat_id);}	
	function GetName(){return $this->is_file?$this->file_name:$this->cat_folder;}	
	function GetTitle($maxlen=0){
		$t = $this->is_file?$this->file_display_name:$this->cat_name;
		if($maxlen > 3 && strlen($t) > $maxlen) $t = mb_substr($t, 0, $maxlen-3,'utf8').'...';
		return $t;
	}	
	function Equals($item){return (isset($item->is_file) && $this->is_file == $item->is_file && $this->GetId() > 0 && $this->GetId() == $item->GetId());}	
	function GetParentId(){return ($this->is_file ? $this->file_category : $this->cat_parent);}	
	function GetParent()
	{
		if(($pid = $this->GetParentId()) != $this->last_parent_id)
		{ // caching
			if($pid > 0) $this->last_parent = WPFB_Category::GetCat($pid);
			else $this->last_parent = null;
			$this->last_parent_id = $pid;
		}
		return $this->last_parent;
	}
	function Lock($lock=true) {
		if($lock) $this->locked++;
		else $this->locked = max(0, $this->locked-1);
	}
	
	static function GetByName($name, $parent_id=0)
	{
		global $wpdb;
		$name = $wpdb->escape($name);
		$parent_id = intval($parent_id);
		
		$items = WPFB_Category::GetCats("WHERE cat_folder = '$name' AND cat_parent = $parent_id LIMIT 1");
		if(empty($items)){
			$items = WPFB_File::GetFiles2(array('file_name' => $name, 'file_category' => $parent_id), false, null, 1);
			if(empty($items)) return null;
		}

		return reset($items);
	}
	
	static function GetByPath($path)
	{
		global $wpdb;
		$path = trim(str_replace('\\','/',$path),'/');		
		$items = WPFB_Category::GetCats("WHERE cat_path = '".$wpdb->escape($path)."' LIMIT 1");
		if(empty($items)){
			$items = WPFB_File::GetFiles2(array('file_path' => $path), false, null, 1);
			if(empty($items)) return null;
		}

		return reset($items);
	}
	
	// Sorts an array of Items by SQL ORDER Clause
	static function Sort(&$items, $order_sql) {
		$p = strpos($order_sql,','); // strip multi order clauses
		if($p >= 0) $order_sql = substr($order_sql, $p + 1);
		$sort = explode(" ", trim($order_sql));
		$on = trim($sort[0],'`');
		$desc = (trim($sort[1]) == "DESC");					
	    $comparer = $desc ? "return -strcmp(\$a->{$on},\$b->{$on});" : "return strcmp(\$a->{$on},\$b->{$on});";
    	usort($items, create_function('$a,$b', $comparer)); 
	}

	function GetEditUrl()
	{
		$fc = ($this->is_file?'file':'cat');
		return admin_url("admin.php?page=wpfilebase_{$fc}s&action=edit{$fc}&{$fc}_id=".$this->GetId());
	}
	
	function GetLocalPath($refresh=false){return WPFB_Core::UploadDir() . '/' . $this->GetLocalPathRel($refresh);}	
	function GetLocalPathRel($refresh=false)
	{		
		if($this->is_file) $cur_path =& $this->file_path;
		else $cur_path =& $this->cat_path;

		if($refresh)
		{			
			if(($parent = $this->GetParent()) != null)	$path = $parent->GetLocalPathRel($refresh) . '/';
			else $path = '';			
			$path .= $this->is_file ? $this->file_name : $this->cat_folder;
			
			if($cur_path != $path) {
				$cur_path = $path; // by ref!!
				if(!$this->locked) $this->DBSave();
			}
			
			return $path;			
		} else {
			if(empty($cur_path)) return $this->GetLocalPathRel(true);
			return $cur_path;	
		}
	}
	
	protected function TriggerLockedError() {
		trigger_error("Cannot save locked item '".$this->GetName()."' to database!", E_USER_WARNING);
		return false;		
	}

	function DBSave()
	{
		global $wpdb;
		
		if($this->locked > 0)
			return $this->TriggerLockedError();
		
		$values = array();
		
		$id_var = ($this->is_file?'file_id':'cat_id');
		
		$vars = get_class_vars(get_class($this));
		foreach($vars as $var => $def)
		{
			$pos = strpos($var, ($this->is_file?'file_':'cat_'));
			if($pos === false || $pos != 0 || $var == $id_var || is_array($this->$var) || is_object($this->$var))
				continue;			
			$values[$var] = $this->$var; // no & ref here, this causes esc of actual objects data!!!!
		}
		
		if($this->is_file) {
			$cvars = WPFB_Core::GetCustomFields(true);
			foreach($cvars as $var => $cn)
				$values[$var] = empty($this->$var) ? '' : $this->$var;
		}
		
		$update = !empty($this->$id_var);
		$tbl = $this->is_file?$wpdb->wpfilebase_files:$wpdb->wpfilebase_cats;
		if ($update)
		{
			if( !$wpdb->update($tbl, $values, array($id_var => $this->$id_var) ))
			{
				if(!empty($wpdb->last_error))
					return array( 'error' => 'Failed to update DB! ' . $wpdb->last_error);
			}
		} else {		
			if( !$wpdb->insert($tbl, $values) )
				return array( 'error' =>'Unable to insert item into DB! ' . $wpdb->last_error);				
			$this->$id_var = (int)$wpdb->insert_id;		
		}
		
		return array( 'error' => false, $id_var => $this->$id_var, 'id' => $this->$id_var);
	}
	
	function IsAncestorOf($item)
	{			
		$p = $item->GetParent();
		if ($p == null) return false;
		if ($this->Equals($p)) return true;
		return $this->IsAncestorOf($p);
	}
	
	function CurUserCanAccess($for_tpl=false)
	{
		global $current_user;
		if($current_user->ID > 0 && empty($current_user->roles[0]))
			$current_user = new WP_User($current_user->ID);// load the roles!
		
		if(($for_tpl && !WPFB_Core::GetOpt('hide_inaccessible')) || in_array('administrator',$current_user->roles))
			return true;
		
		if($this->is_file && WPFB_Core::GetOpt('private_files') && $this->file_added_by != 0 && $this->file_added_by != $current_user->ID) // check private files
			return false;
			
		$frs = $this->GetUserRoles();
		if(empty($frs[0])) return true; // item is for everyone!		
		foreach($current_user->roles as $ur) { // check user roles against item roles
			if(in_array($ur, $frs))
				return true;
		}
		return false;
	}
	
	function CurUserCanEdit()
	{
		global $current_user;
		if($current_user->ID > 0 && empty($current_user->roles[0]))
			$current_user = new WP_User($current_user->ID);// load the roles!
		
		if(in_array('administrator',$current_user->roles)) return true;
		if(!current_user_can('upload_files')) return false;
		
		if($this->is_file)
			return ($this->file_added_by == $current_user->ID || (current_user_can('edit_others_posts') && !WPFB_Core::GetOpt('private_files')));
		else
			return current_user_can('manage_categories');
	}
	
	function GetUrl($rel=false)
	{
		$ps = WPFB_Core::GetOpt('disable_permalinks') ? null : get_option('permalink_structure');		
		if($this->is_file) {
			if(!empty($ps)) $url = home_url(WPFB_Core::GetOpt('download_base').'/'.$this->GetLocalPathRel());
			else $url = home_url('?wpfb_dl='.$this->file_id);
		} else {
			$url = get_permalink(WPFB_Core::GetOpt('file_browser_post_id'));	
			if(!empty($ps)) $url .= $this->GetLocalPathRel().'/';
			elseif($this->cat_id > 0) $url = add_query_arg(array('wpfb_cat' => $this->cat_id), $url);
			$url .= "#wpfb-cat-$this->cat_id";	
		}
		if($rel) {
			$url = substr($url, strlen(home_url()));
			if($url{0} == '?') $url = 'index.php'.$url;
			else $url = substr($url, 0); // remove trailing slash! TODO?!
		}
		return $url;
	}
	
	function GenTpl($parsed_tpl=null, $context='')
	{
		if($context!='ajax')
			WPFB_Core::$load_js = true;
		
		if(empty($parsed_tpl))
		{
			$tpo = $this->is_file?'template_file_parsed':'template_cat_parsed';
			$parsed_tpl = WPFB_Core::GetOpt($tpo);
			if(empty($parsed_tpl))
			{
				$parsed_tpl = wpfb_call('TplLib', 'Parse', WPFB_Core::GetOpt($this->is_file?'template_file':'template_cat'));
				WPFB_Core::UpdateOption($tpo, $parsed_tpl); 
			}
		}
		/*
		if($this->is_file) {
			global $wpfb_file_paths;
			if(empty($wpfb_file_paths)) $wpfb_file_paths = array();
			$wpfb_file_paths[(int)$this->file_id] = $this->GetLocalPathRel();
		}
		*/
		self::$tpl_uid++;
		$f =& $this;
		return eval("return ($parsed_tpl);");
	}
	
	function GetThumbPath($refresh=false)
	{
		static $base_dir = '';
		if(empty($base_dir) || $refresh)
			$base_dir = WPFB_Core::ThumbDir() . '/';
			
		if($this->is_file) {
			if(empty($this->file_thumbnail)) return null;			
			return  dirname($base_dir . $this->GetLocalPathRel()) . '/' . $this->file_thumbnail;
		} else {		
			if(empty($this->cat_icon)) return null;
			return $base_dir . $this->GetLocalPathRel() . '/' . $this->cat_icon;
		}
	}
	
	function GetIconUrl($size=null) {
		if($this->is_category) return WPFB_PLUGIN_URI . (empty($this->cat_icon) ? ('images/'.(($size=='small')?'folder48':'crystal_cat').'.png') : 'wp-filebase_thumb.php?cid=' . $this->cat_id);

		if(!empty($this->file_thumbnail) && file_exists($this->GetThumbPath()))
		{
			return WPFB_PLUGIN_URI . 'wp-filebase_thumb.php?fid='.$this->file_id.'&name='.$this->file_thumbnail; // name var only for correct caching!
		}
				
		$type = $this->GetType();
		$ext = substr($this->GetExtension(), 1);
		
		$img_path = ABSPATH . WPINC . '/images/';
		$img_url = get_option('siteurl').'/'. WPINC .'/images/';
		$custom_folder = '/images/fileicons/';
		
		// check for custom icons
		if(file_exists(WP_CONTENT_DIR.$custom_folder.$ext.'.png'))
			return WP_CONTENT_URL.$custom_folder.$ext.'.png';		
		if(file_exists(WP_CONTENT_DIR.$custom_folder.$type.'.png'))
			return WP_CONTENT_URL.$custom_folder.$type.'.png';
		

		if(file_exists($img_path . 'crystal/' . $ext . '.png'))
			return $img_url . 'crystal/' . $ext . '.png';
		if(file_exists($img_path . 'crystal/' . $type . '.png'))
			return $img_url . 'crystal/' . $type . '.png';	
				
		if(file_exists($img_path . $ext . '.png'))
			return $img_url . $ext . '.png';
		if(file_exists($img_path . $type . '.png'))
			return $img_url . $type . '.png';
		
		// fallback to default
		if(file_exists($img_path . 'crystal/default.png'))
			return $img_url . 'crystal/default.png';		
		if(file_exists($img_path . 'default.png'))
			return $img_url . 'default.png';
		
		// fallback to blank :(
		return $img_url . 'blank.gif';
	}
	
	// for a category this return an array of child files
	// for a file an array with a single element, the file itself
	function GetChildFiles($recursive=false,$sort_sql=null)
	{
		if($this->is_file) return array($this->GetId() => $this);
		if(empty($sort_sql)) $sort_sql = "ORDER BY file_id ASC";
		$files = WPFB_File::GetFiles('WHERE file_category = '.(int)$this->GetId()." $sort_sql");
		if($recursive) {
			$cats = $this->GetChildCats(true);
			foreach(array_keys($cats) as $i)
				$files += $cats[$i]->GetChildFiles(false,$sort_sql);
		}		
		return $files;
	}
	
	function GetUserRoles() {
		if(isset($this->roles_array)) return $this->roles_array; //caching
		$rs = $this->is_file?$this->file_user_roles:$this->cat_user_roles;
		return ($this->roles_array = empty($rs) ? array() : (is_string($rs) ? explode('|', $rs) : (array)$rs));
	}
	
	function SetUserRoles($roles) {
		if(!is_array($roles)) $roles = explode('|',$roles);
		$this->roles_array = $roles =  array_filter(array_filter(array_map('trim',$roles),'strlen')); // remove empty
		$roles = implode('|', $roles);
		if($this->is_file) $this->file_user_roles = $roles;
		else $this->cat_user_roles = $roles;
		if(!$this->locked) $this->DBSave();
	}
	
	function ChangeCategoryOrName($new_cat_id, $new_name=null, $add_existing=false, $overwrite=false)
	{
		// 1. apply new values (inherit permissions if nothing (Everyone) set!)
		// 2. check for name collision and rename
		// 3. move stuff
		// 4. notify parents
		// 5. update child paths
		if(empty($new_name)) $new_name = $this->GetName();
		$this->Lock(true);
		
		$new_cat_id = intval($new_cat_id);
		$old_cat_id = $this->GetParentId();
		$old_path_rel = $this->GetLocalPathRel(true);
		$old_path = $this->GetLocalPath();
		$old_name = $this->GetName();
		if($this->is_file) $old_thumb_path = $this->GetThumbPath();
		
		$old_cat = $this->GetParent();
		$new_cat = WPFB_Category::GetCat($new_cat_id);
		if(!$new_cat) $new_cat_id = 0;
		
		$cat_changed = $new_cat_id != $old_cat_id;
		$name_changed = $new_name != $old_name;
		
		if($this->is_file) {
			$this->file_category = $new_cat_id;
			$this->file_name = $new_name;
			$this->file_category_name = ($new_cat_id==0) ? '' : $new_cat->GetTitle();
		} else {
			$this->cat_parent = $new_cat_id;
			$this->cat_folder = $new_name;
		}
		
		// inherit user roles
		if(count($this->GetUserRoles()) == 0) 
			$this->SetUserRoles(($new_cat_id != 0) ? $new_cat->GetUserRoles() : WPFB_Core::GetOpt('default_roles'));
		
		// flush cache
		$this->last_parent_id = -1; 

		$new_path_rel = $this->GetLocalPathRel(true);
		$new_path = $this->GetLocalPath();

		if($new_path_rel != $old_path_rel) {
			$i = 1;
			if(!$add_existing) {
				$name = $this->GetName();
				if($overwrite) {
					if(@file_exists($new_path)) {
						$ex_file = WPFB_File::GetByPath($new_path_rel);
						if(!is_null($ex_file))
							$ex_file->Remove();
						else 
							@unlink($new_path);
					}
				} else {
					// rename item if filename collision
					while(@file_exists($new_path) || !is_null($ex_file = WPFB_File::GetByPath($new_path_rel))) {
						$i++;	
						if($this->is_file) {
							$p = strrpos($name, '.');
							$this->file_name = ($p <= 0) ? "$name($i)" : (substr($name, 0, $p)."($i)".substr($name, $p));
						} else
							$this->cat_folder = "$name($i)";				
						
						$new_path_rel = $this->GetLocalPathRel(true);
						$new_path = $this->GetLocalPath();
					}
				}
			}
			
			// finally move it!
			if(!empty($old_name) && @file_exists($old_path)) {
				if($this->is_file && $this->IsLocal()) {
					if(!@rename($old_path, $new_path))
						return array( 'error' => sprintf('Unable to move file %s!', $old_path));
					@chmod($new_path, octdec(WPFB_PERM_FILE));
					
					// move thumb
					if(!empty($old_thumb_path) && @is_file($old_thumb_path)) {
						$thumb_path = $this->GetThumbPath();
						if($i > 1) {
							$p = strrpos($thumb_path, '-');
							if($p <= 0) $p = strrpos($thumb_path, '.');
							$thumb_path = substr($thumb_path, 0, $p)."($i)".substr($thumb_path, $p);
							$this->file_thumbnail = basename($thumb_path);			
						}
						if(!@rename($old_thumb_path, $thumb_path)) return array( 'error' =>'Unable to move thumbnail! '.$thumb_path);
						@chmod($thumb_path, octdec(WPFB_PERM_FILE));
					}
				} else {
					if(!@is_dir($new_path)) wp_mkdir_p($new_path);
					if(!@WPFB_Admin::MoveDir($old_path, $new_path))
						return array( 'error' => sprintf('Could not move folder %s to %s', $old_path, $new_path));
				}
			} else {
				if($this->is_category) {
					if(!@is_dir($new_path) && !wp_mkdir_p($new_path))
						return array('error' => sprintf(__( 'Unable to create directory %s. Is it\'s parent directory writable?'), $new_path));		
				}
			}
			
			$all_files = $this->GetChildFiles(true); // all children files (recursivly)
			if(!empty($all_files)) foreach($all_files as $file) {
				if($cat_changed) {
					if($old_cat) $old_cat->NotifyFileRemoved($file); // notify parent cat to remove files
					if($new_cat) $new_cat->NotifyFileAdded($file);
				}
				$file->GetLocalPathRel(true); // update file's path
			}
			
			if($this->is_category) {
				$cats = $this->GetChildCats(true);
				if(!empty($cats)) foreach($cats as $cat) {
					$cat->GetLocalPathRel(true); // update cats's path
				}
			}
		}
		
		$this->Lock(false);
		if(!$this->locked) $this->DBSave();
		return array('error'=>false);
		
		/*
		 * 		// create the directory if it doesnt exist
		// move file
		if($this->IsLocal() && !empty($old_file_path) && @is_file($old_file_path) && $new_file_path != $old_file_path) {
			if(!@rename($old_file_path, $new_file_path)) return array( 'error' => sprintf('Unable to move file %s!', $this->GetLocalPath()));
			@chmod($new_file_path, octdec(WPFB_PERM_FILE));
		}
		 */
	}
}

?>