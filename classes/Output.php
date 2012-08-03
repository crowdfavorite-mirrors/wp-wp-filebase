<?php
class WPFB_Output {
static $page_title = '';
static $page_content = '';

static function ProcessShortCode($args, $content = null)
{
	$id = empty($args ['id']) ? -1 : intval($args ['id']);
	if($id <= 0 && !empty($args['path'])) { // path indentification
		wpfb_loadclass('File','Category');
		$args ['id'] = $id = is_null($item = WPFB_Item::GetByPath($args['path'])) ? 0 : $item->GetId();
	}
	
	switch($args['tag']) {
		case 'list': return do_shortcode(self::FileList($args));
		
		case 'file':
			wpfb_loadclass('File','Category');
			if($id > 0 && ($file = WPFB_File::GetFile($id)) != null && $file->CurUserCanAccess(true))
				return do_shortcode($file->GenTpl(WPFB_Core::GetParsedTpl('file',$args['tpl'])));
			else break;
			
		case 'fileurl':
			if($id > 0 && ($file = wpfb_call('File','GetFile',$id)) != null) {
				if(empty($args['linktext']))	return $file->GetUrl();
				return '<a href="'.$file->GetUrl().'">'.$args['linktext'].'</a>';
			}
			else break;
			
		case 'attachments':	return do_shortcode(self::PostAttachments(false, $args['tpl']));
		
		case 'browser':
				$content = '';
				self::FileBrowser($content, $id, 0); // by ref
				return $content;
	}	
	return '';
}

private static function genFileList(&$files, $tpl_tag=null)
{
	if(!empty($tpl_tag)) $tpl = self::GetParsedTpl($tpl_tag);
	else $tpl = null;	
		
	$content = '';
	foreach(array_keys($files) as $i)
		$content .= $files[$i]->GenTpl($tpl);
	$content .= '<div style="clear:both;"></div>';

	return $content;
}

static function GetPostId()
{
	global $id, $wp_query;
	if(!empty($id) && $id > 0) return $id;
	if(!empty($wp_query->post) && !empty($wp_query->post->ID) && $wp_query->post->ID > 0) return $wp_query->post->ID;
	
	return 0;	
}

static function PostAttachments($check_attached = false, $tpl_tag=null)
{
	static $attached = false;
	
	wpfb_loadclass('File', 'Category');
	
	$pid = self::GetPostId();
	
	
	if($pid==0 || ($check_attached && $attached) || count($files = &WPFB_File::GetAttachedFiles($pid)) == 0)
		return '';

	$attached = true;
	return self::genFileList($files, $tpl_tag);
}

static function FileList($args)
{
	global $wpdb;
	
	wpfb_loadclass('File','Category','ListTpl');
	$tpl_tag = empty($args['tpl'])?'default':$args['tpl'];
	$tpl = WPFB_ListTpl::Get($tpl_tag);
	
	if(empty($tpl)) {
		if(current_user_can('edit_posts')) {
			return "<p>[".WPFB_PLUGIN_NAME."]: <b>WARNING</b>: List template $tpl_tag does not exist!</p>";
		} elseif(is_null($tpl = WPFB_ListTpl::Get('default'))) {
			return '';
		}
	}
	
	if(empty($args['id']) || $args['id'] == -1) {
		$cats = $args['showcats'] ? WPFB_Category::GetCats() : null;
	} else {
		$cats = array();	
		$cat_ids = explode(',', $args['id']);	
		foreach($cat_ids as $cat_id) {
			if(!is_null($cat = WPFB_Category::GetCat($cat_id))) $cats[] = $cat;
		}
	}
	
	return $tpl->Generate($cats, $args['showcats'], $args['sort'], $args['num']);
}

static function FileBrowser(&$content, $root_cat_id=0, $cur_cat_id=0)
{
	static $fb_id = 0;
	$fb_id++;
	
	wpfb_loadclass('Category','File');
	
	if(WPFB_Core::$file_browser_search) {
		
	} else {
		$root_cat = ($root_cat_id==0) ? null : WPFB_Category::GetCat($root_cat_id);
		
		$cur_cat = null;
		if($cur_cat_id > 0) {
			$cur_cat = WPFB_Category::GetCat($cur_cat_id);
		} else {
			$url = (is_ssl()?'https':'http').'://'.$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];		
			$path = trim(substr($url, strlen(WPFB_Core::GetPostUrl(self::GetPostId()))), '/');
			if(!empty($path))
				$cur_cat = WPFB_Category::GetByPath($path);
		}
		
		// make sure cur cat is a child cat of parent
		if(!is_null($cur_cat) && !is_null($root_cat) && !$root_cat->IsAncestorOf($cur_cat))
			$cur_cat = null;
		
		$el_id = "wpfb-filebrowser-$fb_id";
		self::InitFileTreeView($el_id, $root_cat);
		
		// thats all, JS is loaded in Core::Header
		$content .= '<ul id="'.$el_id.'">';
	
		$parents = array();
		if(!is_null($cur_cat)) {
			$p = $cur_cat;
			do { array_push($parents, $p); } while(!is_null($p = $p->GetParent()) && !$p->Equals($root_cat));
		}
		
		$cat_tpl = WPFB_Core::GetParsedTpl('cat', 'filebrowser');
		$file_tpl = WPFB_Core::GetParsedTpl('file', 'filebrowser');
		
		self::FileBrowserList($content, $parents, $cat_tpl, $file_tpl, $root_cat);
			
		$content .= '</ul><div style="clear:both;"></div>';
	}
}

static function FileBrowserList(&$content, &$parents, $cat_tpl, $file_tpl, $root_cat=null)
{
	$cats = WPFB_Category::GetFileBrowserCats(is_null($root_cat) ? 0 : $root_cat->cat_id);
	$open_cat = array_pop($parents);
	foreach($cats as $cat) {
		if(!$cat->CurUserCanAccess()) continue;
		
		$liclass = '';
		if($has_children = ($cat->cat_num_files_total > 0)) $liclass .= 'hasChildren';
		if($open = $cat->Equals($open_cat)) $liclass .= ' open';
		
		$content .= '<li id="wpfb-cat-'.$cat->cat_id.'" class="'.$liclass.'">';
		$content .= '<span>'.$cat->GenTpl($cat_tpl, 'ajax').'</span>';

		if($has_children) {
			$content .= "<ul>\n";			
			if($open) self::FileBrowserList($content, $parents, $cat_tpl, $file_tpl, $cat);
			else $content .= '<li><span class="placeholder">&nbsp;</span></li>'."\n";
			$content .= "</ul>\n";
		}			
		$content .= "</li>\n";
	}
	
	$files =  WPFB_File::GetFiles2(array('file_category' => $root_cat ? $root_cat->GetId() : 0), WPFB_Core::GetOpt('hide_inaccessible'), WPFB_Core::GetFileListSortSql((WPFB_Core::GetOpt('file_browser_file_sort_dir')?'>':'<').WPFB_Core::GetOpt('file_browser_file_sort_by')));
	foreach($files as $file)
			$content .= '<li id="wpfb-file-'.$file->file_id.'"><span>'.$file->GenTpl($file_tpl, 'ajax')."</span></li>\n";
}

// used when retrieving a multi select tpl var
static function ParseSelOpts($opt_name, $sel_tags, $uris=false)
{
	$outarr = array();
	$opts = explode("\n", WPFB_Core::GetOpt($opt_name));	
	if(!is_array($sel_tags))
		$sel_tags = explode('|', $sel_tags);
	
	for($i = 0; $i < count($opts); $i++)
	{
		$opt = explode('|', trim($opts[$i]));
		if(in_array($opt[1], $sel_tags)) {
			$o = esc_html(ltrim($opt[0], '*'));;
			if($uris && isset($opt[2]))
				$o = '<a href="' . esc_attr($opt[2]) . '" target="_blank">' . $o . '</a>';
			$outarr[] = $o;
		}
	}	
	return implode(', ', $outarr);
}

static function FormatFilesize($file_size) {
	static $wpfb_dec_size_format;
	if(!isset($wpfb_dec_size_format)) $wpfb_dec_size_format = WPFB_Core::GetOpt('decimal_size_format');
	if($wpfb_dec_size_format) {
		if($file_size <= 1000) {
			$unit = 'B';
		} elseif($file_size < 1000000) {
			$file_size /= 1000;
			$unit = 'KB';
		} elseif($file_size < 1000000000) {
			$file_size /= 1000000;
			$unit = 'MB';
		} else {
			$file_size /= 1000000000;
			$unit = 'GB';
		}
	} else {
		if($file_size <= 1024) {
			$unit = 'B';
		} elseif($file_size < 1048576) {
			$file_size /= 1024;
			$unit = 'KiB';
		} elseif($file_size < 1073741824) {
			$file_size /= 1048576;
			$unit = 'MiB';
		} else {
			$file_size /= 1073741824;
			$unit = 'GiB';
		}
	}
	
	return sprintf('%01.1f %s', $file_size, $unit);
}

static function Filename2Title($ft, $remove_ext=true)
{
	if($remove_ext) {
		$p = strrpos($ft, '.');
		if($p !== false && $p != 0)
			$ft = substr($ft, 0, $p);
	}
	$ft = preg_replace('/\.([^0-9])/', ' $1', $ft);
	$ft = str_replace('_', ' ', $ft);
	$ft = ucwords($ft);
	return trim($ft);
}


static function CatSelTree($args=null, $root_cat_id = 0, $depth = 0)
{
	static $s_sel, $s_ex, $s_nol, $s_count;
	
	if(!empty($args)) {
		if(is_array($args)) {
			$s_sel = empty($args['selected']) ? 0 : intval($args['selected']);
			$s_ex = empty($args['exclude']) ? 0 : intval($args['exclude']);
			$s_nol = empty($args['none_label']) ? 0 : $args['none_label'];
			$s_count = !empty($args['file_count']);
		} else {
			$s_sel = intval($args);
			$s_ex = 0;
			$s_nol = null;
			$s_count = false;
		}
	}
	
	$out = '';
	if($root_cat_id <= 0)
	{
		$out .= '<option value="0"'.((0==$s_sel)?' selected="selected"':'').' style="font-style:italic;">' .(empty($s_nol) ? __('None'/*def*/) : $s_nol) . ($s_count?' ('.WPFB_File::GetNumFiles(0).')':'').'</option>';
		$cats = &WPFB_Category::GetCats();
		foreach($cats as $c) {
			if($c->cat_parent <= 0 && $c->cat_id != $s_ex)
				$out .= self::CatSelTree(null, $c->cat_id, 0);	
		}
	} else {
		$cat = &WPFB_Category::GetCat($root_cat_id);	
		$out .= '<option value="' . $root_cat_id . '"' . (($root_cat_id == $s_sel) ? ' selected="selected"' : '') . '>' . str_repeat('&nbsp;&nbsp; ', $depth) . esc_html($cat->cat_name).($s_count?' ('.$cat->cat_num_files.')':'').'</option>';

		if(isset($cat->cat_childs)) {
			foreach($cat->cat_childs as $c) {
				if($c->cat_id != $s_ex)
					$out .= self::CatSelTree(null, $c->cat_id, $depth + 1);
			}
		}
	}
	return $out;
}


static function InitFileTreeView($id=null, $root=0)
{
	static $tv_plugin_loaded = false;
	
	WPFB_Core::$load_js = true;
	
	if(!$tv_plugin_loaded) {
		if($id == null) {
			wp_enqueue_script('jquery-treeview-async');
			wp_enqueue_style('jquery-treeview');
		} else { // when id is set, assume that this was called inside the content, so have to print scripts here 
			wp_print_scripts('jquery-treeview-async');
			wp_print_styles('jquery-treeview');
		}
		$tv_plugin_loaded = true;
	}
	
	if(is_object($root)) $root = $root->GetId();
	
	if($id != null) {
	?>
<script type="text/javascript">
//<![CDATA[
jQuery(document).ready(function(){jQuery("#<?php echo $id ?>").treeview({url: "<?php echo WPFB_PLUGIN_URI."wpfb-ajax.php" ?>",
ajax:{data:{action:"tree",type:"browser",base:<?php echo intval($root); ?>},type:"post",complete:function(){if(typeof(wpfb_setupLinks)=='function')wpfb_setupLinks();}},
animated: "medium"});});
//]]>
</script>
<?php
	}
}

/*
static function JSCatUrlsTable() {
	global $wpfb_cat_urls;
	
	$nocat = new WPFB_Category();
	$wpfb_cat_urls[0] = $nocat->get_url();
	
	$cats = &WPFB_Category::GetCats();
	foreach($cats as $c) {	
		$wpfb_cat_urls[(int)$c->cat_id] = $c->get_url();
	}
}
*/

static function GeneratePage($title, $content) {
	self::$page_content = $content;
	self::$page_title = $title;
	add_filter('the_posts',array(__CLASS__,'GeneratePagePostFilter'),9,2);
	add_filter('edit_post_link', array('WPFB_Core', 'Nothing')); // hide edit link	
}

static function GeneratePagePostFilter() {
	global $wp_query;	
	$now = current_time('mysql');
	
	$posts[] = $wp_query->queried_object =
		(object)array(
			'ID' => '0',
			'post_author' => '1',
			'post_date' => $now,
			'post_date_gmt' => $now,
			'post_content' => self::$page_content,
			'post_title' => self::$page_title,
			'post_excerpt' => '',
			'post_status' => 'publish',
			'comment_status' => 'closed',
			'ping_status' => 'closed',
			'post_password' => '',
			'post_name' => $_SERVER['REQUEST_URI'],
			'to_ping' => '',
			'pinged' => '',
			'post_modified' => $now,
			'post_modified_gmt' => $now,
			'post_content_filtered' => '',
			'post_parent' => '0',
			'menu_order' => '0',
			'post_type' => 'post',
			'post_mime_type' => '',
			'post_category' => '0',
			'comment_count' => '0',
			'filter' => 'raw'
		);
		
	// Make WP believe this is a real page, with no comments attached
	$wp_query->is_page = true;
	$wp_query->is_single = false;
	$wp_query->is_home = false;
	$wp_query->comments = false;

	// Discard 404 errors thrown by other checks
	unset($wp_query->query["error"]);
	$wp_query->query_vars["error"]="";
	$wp_query->is_404=false;
		
	// Seems like WP adds its own HTML formatting code to the content, we don't need that here
	remove_filter('the_content','wpautop');
		
	return $posts;
}

static function RolesDropDown($selected_roles=array()) {
	global $wp_roles;
	$all_roles = $wp_roles->roles;
	foreach ( $all_roles as $role => $details ) {
		$name = translate_user_role($details['name']);
		echo "\n\t<option ".(in_array($role, $selected_roles)?"selected='selected'":"")." value='" . esc_attr($role) . "'>$name</option>";
	} 
}

static function RoleNames($roles, $fmt_string=false) {
	global $wp_roles;
	$names = array();
	foreach($roles as $role)
		$names[$role] = translate_user_role($wp_roles->roles[$role]['name']);
	return $fmt_string ? (empty($names) ? ("<i>".__('Everyone',WPFB)."</i>") : join(', ',$names)) : $names;
}
}