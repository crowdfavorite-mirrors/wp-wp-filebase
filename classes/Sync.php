<?php class WPFB_Sync {
	
static function InitClass()
{
	wpfb_loadclass("Admin", "GetID3");
	require_once(ABSPATH . 'wp-admin/includes/file.php');
}

private static function cleanPath($path) {
	return str_replace('//','/',str_replace('\\', '/', $path));
}

static function DEcho($str) {
	echo $str;
	@ob_flush();
	@flush();	
}

private static function PreSync($sync_data)
{
	@ini_set('max_execution_time', '0');
	@set_time_limit(0);	
	
	// some syncing/updating
	self::UpdateItemsPath($sync_data->files, $sync_data->cats);
	WPFB_Admin::SyncCustomFields();
}

private static function SyncPase1($sync_data, $output)
{
	if($output) self::DEcho('<p>'. __('Checking for file changes...',WPFB).' ');
	self::CheckChangedFiles($sync_data);
	if($output) self::DEcho('done!</p>');	

	foreach($sync_data->cats as $id => $cat) {
		$cat_path = $cat->GetLocalPath(true);
		if(!@is_dir($cat_path) || !@is_readable($cat_path))
		{
			$sync_data->log['missing_folders'][$id] = $cat;
			continue;
		}		
	}
	
	if($output) self::DEcho('<p>'. __('Searching for new files...',WPFB).' ');
	
	// search for not added files
	$upload_dir = self::cleanPath(WPFB_Core::UploadDir());	
	$all_files = self::cleanPath(list_files($upload_dir));
	$sync_data->num_all_files = count($all_files);
	
	$num_new_files = 0;
	
	// 1ps filter	 (check extension, special file names, and filter existing file names and thumbnails)
	$fext_blacklist = array_map('strtolower', array_map('trim', explode(',', WPFB_Core::GetOpt('fext_blacklist'))));
	for($i = 0; $i < $sync_data->num_all_files; $i++)
	{
		$fn = $all_files[$i];
		$fbn = basename($fn);
		if(strlen($fn) < 2 || $fbn{0} == '.' || strpos($fn, '/.tmp') !== false
				|| $fbn == '_wp-filebase.css' || strpos($fbn, '_caticon.') !== false
				|| in_array($fn, $sync_data->known_filenames)
				|| !is_file($fn) || !is_readable($fn)
				|| (!empty($fext_blacklist) && in_array(trim(strrchr($fbn, '.'),'.'), $fext_blacklist)) // check for blacklisted extension
			)
			continue;
		$sync_data->new_files[$num_new_files] = $fn;
		$num_new_files++;
	}

	$sync_data->num_files_to_add = $num_new_files;
	
	// handle thumbnails
	self::GetThumbnails($sync_data);
}

static function Sync($hash_sync=false, $output=false)
{	
	$sync_data = new WPFB_SyncData(true);
	$sync_data->hash_sync = $hash_sync;
	
	self::PreSync($sync_data);			
	self::SyncPase1($sync_data, $output);
	
	if($output && $sync_data->num_files_to_add > 0) {
		echo "<p>";
		printf(__('%d Files found, %d new.', WPFB), $sync_data->num_all_files, $sync_data->num_files_to_add);
		echo "</p>";
		
		include_once(WPFB_PLUGIN_ROOT.'extras/progressbar.class.php');
		$progress_bar = new progressbar(0, $sync_data->num_files_to_add);
		$progress_bar->print_code();
	} else {
		$progress_bar = null; 
		if($output) self::DEcho('done!</p>');
	}
	
	self::AddNewFiles($sync_data, $progress_bar);	
	self::PostSync($sync_data, $output);
	
	return $sync_data->log;
}

private function PostSync($sync_data, $output)
{
	// chmod
	if($output) self::DEcho('<p>Setting permissions...');
	$sync_data->log['warnings'] += self::Chmod($sync_data->known_filenames);
	if($output) self::DEcho('done!</p>');
	
	// sync categories
	if($output) self::DEcho('<p>Syncing categories... ');
	$sync_data->log['updated_categories'] = self::SyncCats($sync_data->cats);
	if($output) self::DEcho('done!</p>');
	
	wpfb_call('Setup','ProtectUploadPath');
	WPFB_File::UpdateTags();
	
	$mem_peak = max($sync_data->mem_peak, memory_get_peak_usage());
	
	if($output) printf("<p>".__('Sync Time: %01.2f s, Memory Peak: %s', WPFB)."</p>", microtime(true) - $sync_data->time_begin, WPFB_Output::FormatFilesize($mem_peak));
}

static function UpdateItemsPath(&$files=null, &$cats=null) {
	wpfb_loadclass('File','Category');
	if(is_null($files)) $files = WPFB_File::GetFiles2();
	if(is_null($cats)) $cats = WPFB_Category::GetCats();
	foreach(array_keys($cats) as $i) $cats[$i]->Lock(true);
	foreach(array_keys($files) as $i) $files[$i]->GetLocalPath(true);
	foreach(array_keys($cats) as $i) {
		$cats[$i]->Lock(false);
		$cats[$i]->DBSave();
	}
}

static function CheckChangedFiles($sync_data)
{
	$sync_id3 = !WPFB_Core::GetOpt('disable_id3');
	foreach($sync_data->files as $id => $file)
	{
		$file_path = self::cleanPath($file->GetLocalPath(true));
		$sync_data->known_filenames[] = $file_path;
		if($file->GetThumbPath())
			$sync_data->known_filenames[] = self::cleanPath($file->GetThumbPath());
		
		if($file->file_category > 0 && is_null($file->GetParent()))
			$sync_data->log['warnings'][] = sprintf(__('Category (ID %d) of file %s does not exist!', WPFB), $file->file_category, $file->GetLocalPathRel()); 
			
		// TODO: check for file changes remotly
		if($file->IsRemote())
			continue;
			
		if(!@is_file($file_path) || !@is_readable($file_path))
		{
			$sync_data->log['missing_files'][$id] = $file;
			continue;
		}
		
		if($sync_data->hash_sync) $file_hash = WPFB_Admin::GetFileHash($file_path);
		$file_size = (int)@filesize($file_path);
		$file_mtime = filemtime($file_path);
		$file_analyzetime = !$sync_id3 ? $file_mtime : WPFB_GetID3::GetFileAnalyzeTime($file);
		if(is_null($file_analyzetime)) $file_analyzetime = 0;
		
		if( ($sync_data->hash_sync && $file->file_hash != $file_hash)
			|| $file->file_size != $file_size || $file->file_mtime != $file_mtime
			|| $file_analyzetime < $file_mtime)
		{
			$file->file_size = $file_size;
			$file->file_mtime = $file_mtime;
			$file->file_hash = $sync_data->hash_sync ? $file_hash : WPFB_Admin::GetFileHash($file_path);
			
			WPFB_GetID3::UpdateCachedFileInfo($file);
			
			$res = $file->DBSave();
			
			if(!empty($res['error']))
				$sync_data->log['error'][$id] = $file;
			else
				$sync_data->log['changed'][$id] = $file;
		}
	}
}

static function AddNewFiles($sync_data, $progress_bar=null, $max_batch_size=0)
{
	$keys = array_keys($sync_data->new_files);
	$upload_dir = self::cleanPath(WPFB_Core::UploadDir());
	$upload_dir_len = strlen($upload_dir);
	$batch_size = 0;

	foreach($keys as $i)
	{
		$fn = $sync_data->new_files[$i];
		unset($sync_data->new_files[$i]);
		if(empty($fn)) continue;
		
		$fbn = basename($fn);
					
		$res = WPFB_Admin::AddExistingFile($fn, empty($sync_data->thumbnails[$fn]) ? null : $sync_data->thumbnails[$fn]);
		if(empty($res['error'])) {
			$sync_data->log['added'][] = empty($res['file']) ? substr($fn, $upload_dir_len) : $res['file'];
			
			$sync_data->known_filenames[] = $fn;
			if(!empty($res['file']) && $res['file']->GetThumbPath())
				$sync_data->known_filenames[] = self::cleanPath($res['file']->GetThumbPath());
		} else
			$sync_data->log['error'][] = $res['error'] . " (file $fn)";
		
		$sync_data->num_files_processed++;
			
		if(!empty($progress_bar))
			$progress_bar->step();
		
		if(!empty($res['file'])) {
			$batch_size += $res['file']->file_size;
			if($max_batch_size > 0 && $batch_size > $max_batch_size)
				return false;
		}
	}
	
	if(!empty($progress_bar))
		$progress_bar->complete();
		
	return true;
}

static function GetThumbnails($sync_data)
{
	$num_files_to_add = $num_new_files = count($sync_data->new_files);
	
	// look for thumnails
	// find files that have names formatted like thumbnails e.g. file-XXxYY.(jpg|jpeg|png|gif)
	for($i = 1; $i < $num_new_files; $i++)
	{
		$len = strrpos($sync_data->new_files[$i], '.');
		
		// file and thumbnail should be neighbours in the list, so only check the prev element for matching name
		if(strlen($sync_data->new_files[$i-1]) > ($len+2) && substr($sync_data->new_files[$i-1],0,$len) == substr($sync_data->new_files[$i],0,$len) && !in_array($sync_data->new_files[$i-1], $sync_data->known_filenames))
		{
			$suffix = substr($sync_data->new_files[$i-1], $len);
			
			$matches = array();
			if(preg_match(WPFB_File::THUMB_REGEX, $suffix, $matches) && ($is = getimagesize($sync_data->new_files[$i-1])))
			{
				if($is[0] == $matches[1] && $is[1] == $matches[2])
				{
					//ok, found a thumbnail here
					$sync_data->thumbnails[$sync_data->new_files[$i]] = basename($sync_data->new_files[$i-1]);
					$sync_data->new_files[$i-1] = ''; // remove the file from the list
					$sync_data->num_files_to_add--;
					continue;
				}
			}			
		}
	}
	

	if(WPFB_Core::GetOpt('base_auto_thumb')) {
		for($i = 0; $i < $num_new_files; $i++)
		{
			$len = strrpos($sync_data->new_files[$i], '.');
			$ext = strtolower(substr($sync_data->new_files[$i], $len+1));

			if($ext != 'jpg' && $ext != 'png' && $ext != 'gif') {
				$prefix = substr($sync_data->new_files[$i], 0, $len);

				for($ii = $i-1; $ii >= 0; $ii--)
				{
					if(substr($sync_data->new_files[$ii],0, $len) != $prefix) break;						
					$e = strtolower(substr($sync_data->new_files[$ii], $len+1));
					if($e == 'jpg' || $e == 'png' || $e == 'gif') {
						$sync_data->thumbnails[$sync_data->new_files[$i]] = basename($sync_data->new_files[$ii]);
						$sync_data->new_files[$ii] = ''; // remove the file from the list
						$sync_data->num_files_to_add--;	
						break;				
					}
				}
				
				for($ii = $i+1; $ii < $num_new_files; $ii++)
				{
					if(substr($sync_data->new_files[$ii],0, $len) != $prefix) break;						
					$e = strtolower(substr($sync_data->new_files[$ii], $len+1));
					if($e == 'jpg' || $e == 'png' || $e == 'gif') {
						$sync_data->thumbnails[$sync_data->new_files[$i]] = basename($sync_data->new_files[$ii]);
						$sync_data->new_files[$ii] = ''; // remove the file from the list
						$sync_data->num_files_to_add--;
						break;				
					}
				}
			}
		}
	}
}

static function SyncCats(&$cats = null)
{
	$updated_cats = array();
	
	// sync file count
	if(is_null($cats)) $cats = WPFB_Category::GetCats();
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

function Chmod($files)
{
	$result = array();
	
	$upload_dir = self::cleanPath(WPFB_Core::UploadDir());
	$upload_dir_len = strlen($upload_dir);
	
	// chmod
	@chmod ($upload_dir, octdec(WPFB_PERM_DIR));
	for($i = 0; $i < count($files); $i++)
	{
		if(file_exists($files[$i]))
		{
			@chmod ($files[$i], octdec(WPFB_PERM_FILE));
			if(!is_writable($files[$i]) && !is_writable(dirname($files[$i])))
				$result[] = sprintf(__('File <b>%s</b> is not writable!', WPFB), substr($files[$i], $upload_dir_len));
		}
	}
	
	return $result;
}

static function PrintResult(&$result)
{
		$num_changed = $num_added = $num_errors = 0;
		foreach($result as $tag => $group)
		{
			if(empty($group) || !is_array($group) || count($group) == 0)
				continue;
				
			$t = str_replace('_', ' ', $tag);
			$t{0} = strtoupper($t{0});
			
			if($tag == 'added')
				$num_added += count($group);
			elseif($tag == 'error')
				$num_errors++;
			elseif($tag != 'warnings')
				$num_changed += count($group);
			
			echo '<h2>' . __($t) . '</h2><ul>';
			foreach($group as $item)
				echo '<li>' . (is_object($item) ? ('<a href="'.$item->GetEditUrl().'">'.$item->GetLocalPathRel().'</a>') : $item) . '</li>';
			echo '</ul>';
		}
		
		echo '<p>';
		if($num_changed == 0 && $num_added == 0)
			_e('Nothing changed!', WPFB);

		if($num_changed > 0)
			printf(__('Changed %d items.', WPFB), $num_changed);
			
		if($num_added > 0) {
			echo '<br />';
			printf(__('Added %d files.', WPFB), $num_added);
		}
		echo '</p>';
		
		if( $num_errors == 0)
			echo '<p>' . __('Filebase successfully synced.', WPFB) . '</p>';
			
			$clean_uri = remove_query_arg(array('message', 'action', 'file_id', 'cat_id', 'deltpl', 'hash_sync', 'doit', 'ids', 'files', 'cats', 'batch_sync' /* , 's'*/)); // keep search keyword	
			
			// first files should be deleted, then cats!
			if(!empty($result['missing_files'])) {
				echo '<p>' . sprintf(__('%d Files could not be found.', WPFB), count($result['missing_files'])) . ' <a href="'.$clean_uri.'&amp;action=del&amp;files='.join(',',array_keys($result['missing_files'])).'" class="button">'.__('Remove entries from database').'</a></p>';
			} elseif(!empty($result['missing_folders'])) {
				echo '<p>' . sprintf(__('%d Category Folders could not be found.', WPFB), count($result['missing_folders'])) . ' <a href="'.$clean_uri.'&amp;action=del&amp;cats='.join(',',array_keys($result['missing_folders'])).'" class="button">'.__('Remove entries from database').'</a></p>';
			}
}
}


class WPFB_SyncData {
	
	var $files;
	var $cats;	
	
	var $hash_sync;
	
	var $log;
	var $time_begin;
	var $mem_peak;
	
	var $known_filenames;
	var $new_files;
	var $thumbnails;
	
	var $num_files_to_add;
	var $num_all_files;
	var $num_files_processed;
	
	function WPFB_SyncData($init=false)
	{
		if($init) {
			$this->files = WPFB_File::GetFiles2();
			$this->cats = WPFB_Category::GetCats();
			$this->log = array('missing_files' => array(), 'missing_folders' => array(), 'changed' => array(), 'not_added' => array(), 'error' => array(), 'updated_categories' => array(), 'warnings' => array());
			
			$this->known_filenames = array();
			$this->new_files = array();
			$this->num_files_to_add = 0;
			$this->num_all_files = 0;
			$this->num_files_processed = 0;
			
			$this->time_begin = microtime(true);
			$this->mem_peak = memory_get_peak_usage();
		}
	}
	
}
