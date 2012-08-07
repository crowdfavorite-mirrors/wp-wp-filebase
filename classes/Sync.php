<?php class WPFB_Sync {

private static function cleanPath($path) {
	return str_replace('//','/',str_replace('\\', '/', $path));
}

static function DEcho($str) {
	echo $str;
	@ob_flush();
	@flush();	
}

static function UpdateItemsPath() {
	wpfb_loadclass('File','Category');
	$cats = WPFB_Category::GetCats();
	$files = WPFB_File::GetFiles2();	
	foreach(array_keys($cats) as $i) $cats[$i]->Lock(true);
	foreach(array_keys($files) as $i) $files[$i]->GetLocalPath(true);
	foreach(array_keys($cats) as $i) {
		$cats[$i]->Lock(false);
		$cats[$i]->DBSave();
	}
}

static function SyncCats()
{
	$updated_cats = array();
	
	// sync file count
	$cats = WPFB_Category::GetCats();
	foreach(array_keys($cats) as $i)
	{
		$cat = $cats[$i];
		$child_files = $cat->GetChildFiles(false);
		$num_files = (int)count($child_files);
		$num_files_total = (int)count($cat->GetChildFiles(true));
		if($num_files != $cat->cat_num_files || $num_files_total != $cat->cat_num_files_total)
		{
			$cat->cat_num_files = $num_files;
			$cat->cat_num_files_total = $num_files_total;
			$cat->DBSave();			
			$updated_cats[] = $cat;
		}
		
		// update category names
		if($child_files) {
			foreach($child_files as $file) {
				if($file->file_category_name != $cat->GetTitle()) {
					$file->file_category_name = $cat->GetTitle();
					if(!$file->locked)
						$file->DBSave();
				}
			}
		}
		
		@chmod ($cat->GetLocalPath(), octdec(WPFB_PERM_DIR));
	}
	
	return $updated_cats;
}

static function AddNewFiles($new_files, $thumbnails, $progress_bar=null)
{
	$num_new_files = count($new_files);
	$upload_dir = self::cleanPath(WPFB_Core::UploadDir());
	$upload_dir_len = strlen($upload_dir);
	
	for($i = 0; $i < $num_new_files; $i++)
	{
		$fn = $new_files[$i];
		if(empty($fn)) continue;
		$fbn = basename($fn);
					
		$res = WPFB_Admin::AddExistingFile($fn, empty($thumbnails[$fn]) ? null : $thumbnails[$fn]);			
		if(empty($res['error']))
			$result['added'][] = empty($res['file']) ? substr($fn, $upload_dir_len) : $res['file'];
		else
			$result['error'][] = $res['error'] . " (file $fn)";
		
		if(!empty($progress_bar))
			$progress_bar->step(1);
	}
	
	if(!empty($progress_bar))
		$progress_bar->complete();
}

static function Sync($hash_sync=false, $output=false)
{
	@ini_set('max_execution_time', '0');
	@set_time_limit(0);
	
	wpfb_loadclass("Admin", "GetID3");
	require_once(ABSPATH . 'wp-admin/includes/file.php');
	
	$result = array('missing_files' => array(), 'missing_folders' => array(), 'changed' => array(), 'not_added' => array(), 'error' => array(), 'updated_categories' => array());
	
	$sync_id3 = !WPFB_Core::GetOpt('disable_id3');
	
	// some syncing/updating
	self::UpdateItemsPath();
	WPFB_Admin::SyncCustomFields();
	
	$files = WPFB_File::GetFiles2();
	$cats = WPFB_Category::GetCats();
	
	if($output) self::DEcho('<p>'. __('Checking for file changes...',WPFB).' ');
	$db_files = array();
	foreach($files as $id => /* & PHP 4 compability */ $file)
	{
		$file_path = self::cleanPath($file->GetLocalPath(true));
		$db_files[] = $file_path;
		if($file->GetThumbPath())
			$db_files[] = self::cleanPath($file->GetThumbPath());
		
		if($file->file_category > 0 && is_null($file->GetParent()))
			$result['warnings'][] = sprintf(__('Category (ID %d) of file %s does not exist!', WPFB), $file->file_category, $file->GetLocalPathRel()); 
			
		// TODO: check for file changes remotly
		if($file->IsRemote())
			continue;
			
		if(!@is_file($file_path) || !@is_readable($file_path))
		{
			$result['missing_files'][$id] = $file;
			continue;
		}
		
		if($hash_sync) $file_hash = @md5_file($file_path);
		$file_size = (int)@filesize($file_path);
		$file_mtime = filemtime($file_path);
		$file_analyzetime = !$sync_id3 ? $file_mtime : WPFB_GetID3::GetFileAnalyzeTime($file);
		if(is_null($file_analyzetime)) $file_analyzetime = 0;
		
		if( ($hash_sync && $file->file_hash != $file_hash)
			|| $file->file_size != $file_size || $file->file_mtime != $file_mtime
			|| $file_analyzetime < $file_mtime)
		{
			$file->file_size = $file_size;
			$file->file_mtime = $file_mtime;
			$file->file_hash = $hash_sync ? $file_hash : @md5_file($file_path);
			
			WPFB_GetID3::UpdateCachedFileInfo($file);
			
			$res = $file->DBSave();
			
			if(!empty($res['error']))
				$result['error'][$id] = $file;
			else
				$result['changed'][$id] = $file;
		}
	}
	if($output) self::DEcho('done!</p>');
	
	foreach($cats as $id => $cat) {
		$cat_path = $cat->GetLocalPath(true);
		if(!@is_dir($cat_path) || !@is_readable($cat_path))
		{
			$result['missing_folders'][$id] = $cat;
			continue;
		}		
	}
	
	if($output) self::DEcho('<p>'. __('Searching for new files...',WPFB).' ');
	
	// search for not added files
	$upload_dir = self::cleanPath(WPFB_Core::UploadDir());
	$upload_dir_len = strlen($upload_dir);
	
	$all_files = self::cleanPath(list_files($upload_dir));
	$num_all_files = count($all_files);
	
	$new_files = array();
	$num_new_files = 0;
	$num_files_to_add = 0;
	
	// 1ps filter	 (check extension, special file names, and filter existing file names and thumbnails)
	$fext_blacklist = array_map('strtolower', array_map('trim', explode(',', WPFB_Core::GetOpt('fext_blacklist'))));
	for($i = 0; $i < $num_all_files; $i++)
	{
		$fn = $all_files[$i];
		$fbn = basename($fn);
		if(strlen($fn) < 2 || $fbn{0} == '.' || strpos($fn, '/.tmp') !== false
				|| $fbn == '_wp-filebase.css' || strpos($fbn, '_caticon.') !== false
				|| in_array($fn, $db_files)
				|| !is_file($fn) || !is_readable($fn)
				|| (!empty($fext_blacklist) && in_array(trim(strrchr($fbn, '.'),'.'), $fext_blacklist)) // check for blacklisted extension
			)
			continue;
		$new_files[$num_new_files] = $fn;
		$num_new_files++;
	}
	
	$num_files_to_add = $num_new_files;
		

	
	$thumbnails = array();	
	// look for thumnails
	// find files that have names formatted like thumbnails e.g. file-XXxYY.(jpg|jpeg|png|gif)
	for($i = 1; $i < $num_new_files; $i++)
	{
		$len = strrpos($new_files[$i], '.');
		
		// file and thumbnail should be neighbours in the list, so only check the prev element for matching name
		if(strlen($new_files[$i-1]) > ($len+2) && substr($new_files[$i-1],0,$len) == substr($new_files[$i],0,$len) && !in_array($new_files[$i-1], $db_files))
		{
			$suffix = substr($new_files[$i-1], $len);
			
			$matches = array();
			if(preg_match(WPFB_File::$thumbnail_regex, $suffix, $matches) && ($is = getimagesize($new_files[$i-1])))
			{
				if($is[0] == $matches[1] && $is[1] == $matches[2])
				{
					//ok, found a thumbnail here
					$thumbnails[$new_files[$i]] = basename($new_files[$i-1]);
					$new_files[$i-1] = ''; // remove the file from the list
					$num_files_to_add--;
					continue;
				}
			}			
		}
	}
	

	if(WPFB_Core::GetOpt('base_auto_thumb')) {
		for($i = 0; $i < $num_new_files; $i++)
		{
			$len = strrpos($new_files[$i], '.');
			$ext = strtolower(substr($new_files[$i], $len+1));

			if($ext != 'jpg' && $ext != 'png' && $ext != 'gif') {
				$prefix = substr($new_files[$i], 0, $len);

				for($ii = $i-1; $ii >= 0; $ii--)
				{
					if(substr($new_files[$ii],0, $len) != $prefix) break;						
					$e = strtolower(substr($new_files[$ii], $len+1));
					if($e == 'jpg' || $e == 'png' || $e == 'gif') {
						$thumbnails[$new_files[$i]] = basename($new_files[$ii]);
						$new_files[$ii] = ''; // remove the file from the list
						$num_files_to_add--;	
						break;				
					}
				}
				
				for($ii = $i+1; $ii < $num_new_files; $ii++)
				{
					if(substr($new_files[$ii],0, $len) != $prefix) break;						
					$e = strtolower(substr($new_files[$ii], $len+1));
					if($e == 'jpg' || $e == 'png' || $e == 'gif') {
						$thumbnails[$new_files[$i]] = basename($new_files[$ii]);
						$new_files[$ii] = ''; // remove the file from the list
						$num_files_to_add--;
						break;				
					}
				}
			}
		}
	}
	
	if($output && $num_files_to_add > 0) {
		echo "<p>";
		printf(__('%d Files found, %d new.', WPFB), $num_all_files, $num_files_to_add);
		echo "</p>";
		
		include(WPFB_PLUGIN_ROOT.'extras/progressbar.class.php');
		$progress_bar = new progressbar(0, $num_files_to_add);
		$progress_bar->print_code();
	} else {
		if($output) self::DEcho('done!</p>');
	}
	
	self::AddNewFiles($new_files, $thumbnails, $progress_bar);
	
	// chmod
	if($output) self::DEcho('<p>Setting permissions...');
	@chmod ($upload_dir, octdec(WPFB_PERM_DIR));
	for($i = 0; $i < count($db_files); $i++)
	{
		if(file_exists($db_files[$i]))
		{
			@chmod ($db_files[$i], octdec(WPFB_PERM_FILE));
			if(!is_writable($db_files[$i]) && !is_writable(dirname($db_files[$i])))
				$result['warnings'][] = sprintf(__('File <b>%s</b> is not writable!', WPFB), substr($db_files[$i], $upload_dir_len));
		}
	}
	if($output) self::DEcho('done!</p>');
	
	// sync categories
	if($output) self::DEcho('<p>Syncing categories... ');
	$result['updated_categories'] = self::SyncCats();
	if($output) self::DEcho('done!</p>');
	
	wpfb_call('Setup','ProtectUploadPath');
	WPFB_File::UpdateTags();
	
	return $result;
}

}
