<?php
class WPFB_Core {
static $load_js = false;
static $file_browser_search = false;
//static $options = null;

static function InitClass()
{
	global $wp_query, $wpfb_post_url_cache;
	$wpfb_post_url_cache = array();

	if(defined('WPFB_SIMPLE_LOAD')) return;
	
	WPFB_Core::LoadLang();	

	add_action('parse_query', array(__CLASS__, 'ParseQuery')); // search
	add_action('wp_enqueue_scripts', array(__CLASS__, 'EnqueueScripts'));
	add_action('wp_footer', array(__CLASS__, 'Footer'));	
	add_action('generate_rewrite_rules', array(__CLASS__, 'GenRewriteRules'));
	add_action('wp_dashboard_setup', array(__CLASS__, 'AdminDashboardSetup'));	
	add_action(WPFB.'_cron', array(__CLASS__, 'Cron'));
	add_action('wpfilebase_sync', array(__CLASS__, 'Sync')); // for Developers: New wp-filebase actions
	
	add_shortcode('wpfilebase', array(__CLASS__, 'ShortCode'));
	
	// for attachments and file browser
	add_filter('the_content',	array(__CLASS__, 'ContentFilter'), 10); // must be lower than 11 (before do_shortcode) and after wpautop (>9)
	add_filter('ext2type', array(__CLASS__, 'Ext2TypeFilter'));
	add_filter('wp_get_attachment_url', array(__CLASS__, 'GetAttachmentUrlFilter'));
	add_filter('get_attached_file', array(__CLASS__, 'GetAttachedFileFilter'));
	
	// register treeview stuff
	//wp_register_script('jquery-cookie', WPFB_PLUGIN_URI.'extras/jquery/jquery.cookie.js', array('jquery'));
	wp_register_script('jquery-treeview', WPFB_PLUGIN_URI.'extras/jquery/treeview/jquery.treeview.js', array('jquery'), WPFB_VERSION);
	wp_register_script('jquery-treeview-edit', WPFB_PLUGIN_URI.'extras/jquery/treeview/jquery.treeview.edit.js', array('jquery-treeview'), WPFB_VERSION);
	wp_register_script('jquery-treeview-async', WPFB_PLUGIN_URI.'extras/jquery/treeview/jquery.treeview.async.js', array('jquery-treeview-edit'), WPFB_VERSION);
	wp_register_style('jquery-treeview', WPFB_PLUGIN_URI.'extras/jquery/treeview/jquery.treeview.css', array(), WPFB_VERSION);
		

	wp_register_script(WPFB, WPFB_PLUGIN_URI.'js/common.js', array('jquery'), WPFB_VERSION); // cond loading (see Footer)
	$upload_path = WPFB_Core::GetOpt('upload_path');
	if(path_is_absolute($upload_path)) $upload_path = '';
	wp_enqueue_style(WPFB, WPFB_PLUGIN_URI."wp-filebase_css.php?rp=$upload_path", array(), WPFB_VERSION, 'all');
	
	// widgets
	wp_register_sidebar_widget(WPFB_PLUGIN_NAME, WPFB_PLUGIN_NAME .' '. __('File list', WPFB), array(__CLASS__, 'FileWidget'), array('description' => __('Lists the latest or most popular files', WPFB)));
	wp_register_sidebar_widget(WPFB_PLUGIN_NAME.'_cats', "[DEPRECATED]".WPFB_PLUGIN_NAME.' ' . __('Category list', WPFB), array(__CLASS__, 'CatWidget'), array('description' => __('Simple listing of file categories', WPFB)));
	//wp_register_sidebar_widget(WPFB_PLUGIN_NAME.'_upload', WPFB_PLUGIN_NAME.' ' . __('File upload', WPFB), array(__CLASS__, 'UploadWidget'), array('description' => __('Supplies a form for uploading files', WPFB)));
	
	if((is_admin() && !empty($_GET['page']) && strpos($_GET['page'], 'wpfilebase_') !== false) || defined('WPFB_EDITOR_PLUGIN'))
		wpfb_loadclass('Admin');
	
	// live admin
	if(current_user_can('upload_files') && !is_admin()) {
		wp_enqueue_script(WPFB.'-live-admin', WPFB_PLUGIN_URI.'js/live-admin.js', array('jquery'), WPFB_VERSION);
		if(self::GetOpt('admin_bar'))
			add_action( 'admin_bar_menu', array(__CLASS__, 'AdminBar'), 80 );
		if(self::GetOpt('file_context_menu')) {
			wp_enqueue_script('jquery-contextmenu', WPFB_PLUGIN_URI.'extras/jquery/contextmenu/jquery.contextmenu.js', array('jquery'));
			wp_enqueue_style('jquery-contextmenu', WPFB_PLUGIN_URI.'extras/jquery/contextmenu/jquery.contextmenu.css', array(), WPFB_VERSION);
		}
	}
		
	// for admin
	if (current_user_can('edit_posts') || current_user_can('edit_pages'))
		self::MceAddBtns();
		
	self::DownloadRedirect();
	
	if(current_user_can('upload_files')) {
		if(!empty($_GET['wpfb_upload_file']) || !empty($_GET['wpfb_add_cat'])) {
			wpfb_call('Admin', empty($_GET['wpfb_upload_file'])?'ProcessWidgetAddCat':'ProcessWidgetUpload');
		}
	}
}

static function Nothing() { return ''; }

static function ParseQuery(&$query)
{
	// conditional loading of the search hooks
	global $wp_query;
	if (!empty($wp_query->query_vars['s']) && self::GetOpt('search_integration'))
		wpfb_loadclass('Search');
	if(!empty($_GET['wpfb_s']))
		WPFB_Core::$file_browser_search = true;		
	add_filter('the_excerpt',	array(__CLASS__, 'SearchExcerptFilter'), 10); // must be lower than 11 (before do_shortcode) and after wpautop (>9)
}

static function AdminInit() { 
	wpfb_loadclass('AdminLite');
	if(!empty($_GET['page']) && strpos($_GET['page'], 'wpfilebase_') !== false)
		wpfb_loadclass('Admin');
}
static function AdminMenu() {wpfb_call('AdminLite', 'SetupMenu');}

static function LoadLang() {
	static $loaded = false;
	if(!$loaded) {
		$lang_dir = basename(WPFB_PLUGIN_ROOT).'/languages';
		load_plugin_textdomain(WPFB, 'wp-content/plugins/'.$lang_dir, $lang_dir);
		$loaded = true;
	}
}

static function GetOpt($name = null) {
	$options = get_option(WPFB_OPT_NAME);
	if(empty($name)) return $options;
	elseif(isset($options[$name])) return $options[$name];
	return null;
}

static function FileWidget($args) { return wpfb_call('Widget', 'FileList', $args); }
static function CatWidget($args) { return wpfb_call('Widget', 'CatList', $args); }
//static function UploadWidget($args) { return wpfb_call('Widget', 'Upload', $args); } // uses new class-style widgets

static function DownloadRedirect()
{
	global $wpdb;
	$file = null;
	
	if(!empty($_GET['wpfb_dl'])) {
		wpfb_loadclass('File');
		$file = WPFB_File::GetFile((int)$_GET['wpfb_dl']);
		@ob_end_clean(); // FIX: clean the OB so any output before the actual download is truncated (OB is started in wp-filebase.php)
	} else {
		$base = WPFB_Core::GetOpt('download_base');
		if(!$base || is_admin()) return;
		$dl_url_path = parse_url(home_url($base.'/'), PHP_URL_PATH);
		$pos = strpos($_SERVER['REQUEST_URI'], $dl_url_path);
		if($pos !== false && $pos == 0) {
			$filepath = trim(substr($_SERVER['REQUEST_URI'], strlen($dl_url_path)), '/');
			if(!empty($filepath)) {
				wpfb_loadclass('File','Category');
				$file = WPFB_File::GetByPath($filepath);
				if(empty($file)) $file = WPFB_File::GetByPath(urldecode($filepath));
			}
		}
	}
	
	if(!empty($file) && is_object($file) && !empty($file->is_file)) {
		$file->Download();		
		exit;
	} else {
		// no download, a normal request: set site visited coockie to disable referer check
		if(empty($_COOKIE[WPFB_OPT_NAME])) {
			@setcookie(WPFB_OPT_NAME, '1');
			$_COOKIE[WPFB_OPT_NAME] = '1';
		}
	}
}

static function Ext2TypeFilter($arr) {
	$arr['interactive'][] = 'exe';
	$arr['interactive'][] = 'msi';
	return $arr;
}

/*
// conditionally loading
add_filter('the_posts', 'wpfilebase_posts_filter');
function wpfilebase_posts_filter($posts) {
	global $id, $wpfb_loaded_output;
	if(!empty($wpfb_loaded_output) || empty($posts))
		return $posts;
	$fb_id = WPFB_Core::GetOpt('file_browser_post_id');
	if($id > 0 && $id == $fb_id) {
		wpfilebase_load_output_scripts();
	} else {		
		foreach($posts as $post) {
		if(strpos($post->post_content, '[filebase') !== false || $post->id == $fb_id) {
				wpfilebase_load_output_scripts();
				break;
			}
		}
	}
	return $posts;
} */

function SearchExcerptFilter($content)
{
	global $id;
	
	// replace file browser post content with search results
	if(WPFB_Core::$file_browser_search && $id == WPFB_Core::GetOpt('file_browser_post_id'))
	{
		wpfb_loadclass('Search','File','Category');
		$content = '';
		WPFB_Search::FileSearchContent($content);
	}
	
	return $content;
}

function ContentFilter($content)
{
	global $id, $wpfb_fb, $post;
	
	if(!WPFB_Core::GetOpt('parse_tags_rss') && is_feed())
		return $content;	
		
	// all tags start with '[filebase'
	/*
	if(strpos($content, '[filebase') !== false)
	{
		wpfb_loadclass('Output');
		WPFB_Output::wpfilebase_parse_content_tags($content);
	}
	*/
	
	if(!empty($post) && is_object($post) && !post_password_required())
	{
		$single = is_single() || is_page();
		
		if($single && $post->ID == WPFB_Core::GetOpt('file_browser_post_id')) {
			$wpfb_fb = true;
			wpfb_loadclass('Output', 'File', 'Category');
			WPFB_Output::FileBrowser($content, 0, empty($_GET['wpfb_cat']) ? 0 : intval($_GET['wpfb_cat']));
		}
	
		if(self::GetOpt('auto_attach_files') && ($single || self::GetOpt('attach_loop'))) {
			wpfb_loadclass('Output');			
			if(WPFB_Core::GetOpt('attach_pos') == 0)
				$content = WPFB_Output::PostAttachments(true) . $content;
			else
				$content .= WPFB_Output::PostAttachments(true);
		}
		
		// TODO: file resulst are generated twice, 2nd time in the_excerpt filter (SearchExcerptFilter)
		// some themes do not use excerpts in search resulsts!!
		// replace file browser post content with search results
		if(WPFB_Core::$file_browser_search && $id == WPFB_Core::GetOpt('file_browser_post_id'))
		{
			wpfb_loadclass('Search','File','Category');
			$content = '';
			WPFB_Search::FileSearchContent($content);
		}
	}

    return $content;
}

static function ShortCode($atts, $content=null) {
	wpfb_loadclass('Output');
	return WPFB_Output::ProcessShortCode(shortcode_atts(array(
		'tag' => 'list', // file, fileurl, attachments
		'id' => -1,
		'path' => null,
		'tpl' => null,
		'sort' => null,
		'showcats' => false,
		'num' => 0,
		'pagenav' => 1,
		'linktext' => null
	), $atts), $content);
}


static function Footer() {
	global $wpfb_fb; // filebrowser loaded?
	
	// TODO: use enque and no cond loading ?
	if(!empty(self::$load_js)) {
		self::PrintJS();
	}
	
	if(!empty($wpfb_fb) && !WPFB_Core::GetOpt('disable_footer_credits')) {
		echo '<div id="wpfb-credits" name="wpfb-credits" style="'.esc_attr(WPFB_Core::GetOpt('footer_credits_style')).'">';
		printf(__('<a href="%s" title="Wordpress Download Manager Plugin" style="color:inherit;font-size:inherit;">Downloads served by WP-Filebase</a>',WPFB),'http://fabi.me/wordpress-plugins/wp-filebase-file-download-manager/');
		echo '</div>';
	}
}


static function GenRewriteRules() {
    global $wp_rewrite;
	$fb_pid = intval(WPFB_Core::GetOpt('file_browser_post_id'));
	if($fb_pid > 0) {
		$is_page = (get_post_type($fb_pid) == 'page');
		$redirect = 'index.php?'.($is_page?'page_id':'p')."=$fb_pid";
		$base = trim(substr(get_permalink($fb_pid), strlen(home_url())), '/');
		$pattern = "$base/(.+)$";
		$wp_rewrite->rules = array($pattern => $redirect) + $wp_rewrite->rules;
	}
}

/*// removed, no need of adding the query vars
static function QueryVarsFilter($qvars){
	$qvars[] = 'wpfb_cat_path';
	$qvars[] = 'wpfb_cat';
	$qvars[] = 'wpfb_dl';
    return $qvars;
} */

static function MceAddBtns() {
	add_filter('mce_external_plugins', array('WPFB_Core', 'McePlugins'));
	add_filter('mce_buttons', array('WPFB_Core', 'MceButtons'));
}

static function McePlugins($plugins) { wpfb_loadclass('AdminLite'); return WPFB_AdminLite::McePlugins($plugins); }
static function MceButtons($buttons) { wpfb_loadclass('AdminLite'); return WPFB_AdminLite::MceButtons($buttons); }

static function UpdateOption($name, $value = null) {
	$options = get_option(WPFB_OPT_NAME);
	$options[$name] = $value;
	update_option(WPFB_OPT_NAME, $options);
}

static function UploadDir() {
	static $upload_path = '';
	if(empty($upload_path)) { // cache
		$upload_path = WPFB_Core::GetOpt('upload_path');
		if (empty($upload_path)) $upload_path = 'wp-content/uploads/filebase';
		$upload_path = path_join(ABSPATH, $upload_path);
	}
	return $upload_path;
}

static function ThumbDir() {
	$thumb_path = trim(WPFB_Core::GetOpt('thumbnail_path'));
	if (empty($thumb_path)) return self::UploadDir();
	return path_join(ABSPATH, $thumb_path);
}

static function GetPermalinkBase() {
	return trailingslashit(get_option('home')).trailingslashit(WPFB_Core::GetOpt('download_base'));	
}

static function GetPostUrl($id) {
	global $wpfb_post_url_cache;
	$id = intval($id);
	if(isset($wpfb_post_url_cache[$id]))
		return $wpfb_post_url_cache[$id];
	return ($wpfb_post_url_cache[$id] = get_permalink($id));
}

static function GetTraffic()
{
	$traffic = WPFB_Core::GetOpt('traffic_stats');
	$time = intval($traffic['time']);
	$year = intval(date('Y', $time));
	$month = intval(date('m', $time));
	$day = intval(date('z', $time));
	
	$same_year = ($year == intval(date('Y')));
	if(!$same_year || $month != intval(date('m')))
		$traffic['month'] = 0;
	if(!$same_year || $day != intval(date('z')))
		$traffic['today'] = 0;
		
	return $traffic;
}

static function UserLevel2Role($level)
{
	if($level >= 8) return 'administrator';
	if($level >= 5)	return 'editor';
	if($level >= 2)	return 'author';
	if($level >= 1)	return 'contributor';
	if($level >= 0)	return 'subscriber';
	return null;
}

static function UserRole2Level($role)
{
	switch($role) {
	case 'administrator': return 8;
	case 'editor': return 5;
	case 'author': return 2;
	case 'contributor': return 1;
	case 'subscriber': return 0;
	default: return -1;
	}
}

static function GetFileListSortSql($sort=null, $attach_order=false)
{
	global $wpdb;
	static $fields;
	
	if(empty($fields)) {
		$fields = array_merge(array(
		'file_id','file_name','file_size','file_date','file_path','file_display_name','file_hits',
		'file_description','file_version','file_author','file_license',
		'file_category','file_category_name','file_post_id','file_attach_order',
		'file_added_by','file_hits','file_last_dl_time'), array_keys(WPFB_Core::GetCustomFields(true)));
	}
	
	if(!empty($_REQUEST['wpfb_file_sort']))
		$sort = $_REQUEST['wpfb_file_sort'];
	elseif(empty($sort))
		$sort = WPFB_Core::GetOpt('filelist_sorting');
	
	$sort = str_replace(array('&gt;','&lt;'), array('>','<'), $sort);
	
	$desc = WPFB_Core::GetOpt('filelist_sorting_dir');
	if($sort{0} == '<') {
		$desc = false;
		$sort = substr($sort,1);
	} elseif($sort{0} == '>') {
		$desc = true;
		$sort = substr($sort,1);
	}
	
	if(!in_array($sort, $fields)) $sort = WPFB_Core::GetOpt('filelist_sorting');
	
	$sort = $wpdb->escape($sort);
	$sortdir = $desc ? 'DESC' : 'ASC';	
	return $attach_order ? "file_attach_order ASC, `$sort` $sortdir" : "`$sort` $sortdir";
}

static function EnqueueScripts()
{
	global $wp_query;
	
	if(!empty($wp_query->queried_object_id) && $wp_query->queried_object_id == WPFB_Core::GetOpt('file_browser_post_id'))
		wpfb_call('Output', 'InitFileTreeView'); // this loads the scripts required for file trees (this fixes the wp_print_scripts bug caused by other plugins, but only for the file browser page)	
}

static function PrintJS() {
	wp_print_scripts(WPFB);
	
	$context_menu = current_user_can('upload_files') && self::GetOpt('file_context_menu') && !defined('WPFB_EDITOR_PLUGIN');
	
	$conf = array(
		'ql'=>1, // querylinks with jQuery
		'hl'=> (int)self::GetOpt('hide_links'), // hide links
		'pl'=>(self::GetOpt('disable_permalinks') ? 0 : (int)!!get_option('permalink_structure')), // permlinks
		'hu'=> trailingslashit(home_url()),// home url
		'db'=> self::GetOpt('download_base'),// urlbase
		'fb'=> self::GetPostUrl(self::GetOpt('file_browser_post_id')),
		'cm'=>(int)$context_menu,
		'ajurl'=>WPFB_PLUGIN_URI.'wpfb-ajax.php'
	);
	
	if($context_menu) {
		$conf['fileEditUrl'] = admin_url("admin.php?page=wpfilebase_files&action=editfile&file_id=");
		
		//wp_print_scripts('jquery-contextmenu');
		//wp_print_styles	('jquery-contextmenu');
	}
	
	$js = WPFB_Core::GetOpt('dlclick_js');
	if(empty($js)) $js = '';
	
	echo "<script type=\"text/javascript\">\n//<![CDATA[\n",'wpfbConf=',json_encode($conf),';';
	
	//if(!empty($wpfb_file_paths)) echo 'wpfbFPaths=',json_encode($wpfb_file_paths),';';
	//else echo 'wpfbFPaths={};';
	
	//if(!empty($wpfb_cat_urls)) echo 'wpfbCPaths=',json_encode($wpfb_cat_urls),';',"\n";
	//else echo 'wpfbCPaths={};',"\n";
	
	if($context_menu) {
		echo
"wpfbContextMenu=[
	{'",__('Edit'),"':{onclick:wpfb_menuEdit,icon:'".WPFB_PLUGIN_URI."extras/jquery/contextmenu/page_white_edit.png'}, },
	jQuery.contextMenu.separator,
	{'",__('Delete'),"':{onclick:wpfb_menuDel,icon:'".WPFB_PLUGIN_URI."extras/jquery/contextmenu/delete_icon.gif'}}
];\n";
		
	}
	
	echo "function wpfb_ondl(file_id,file_url,file_path){ {$js} }";	
	echo "\n//]]>\n</script>\n";
}

// gets custom template list or single if tag specified
static function GetFileTpls($tag=null) {
	if($tag == 'default') return self::GetOpt('template_file');
	$tpls = get_option(WPFB_OPT_NAME.'_tpls_file');
	return empty($tag) ? $tpls : $tpls[$tag];
}

static function GetCatTpls($tag=null) {
	if($tag == 'default') return self::GetOpt('template_cat');
	$tpls = get_option(WPFB_OPT_NAME.'_tpls_cat');
	return empty($tag) ? $tpls : $tpls[$tag];
}

static function GetTpls($type, $tag=null) { return ($type == 'cat') ? self::GetCatTpls($tag) : self::GetFileTpls($tag);}

static function SetFileTpls($tpls) { return is_array($tpls) ? update_option(WPFB_OPT_NAME.'_tpls_file', $tpls) : false; }
static function SetCatTpls($tpls) { return is_array($tpls) ? update_option(WPFB_OPT_NAME.'_tpls_cat', $tpls) : false; }

static function GetParsedTpl($type, $tag) {
	if(empty($tag)) return null;
	if($tag == 'default') return self::GetOpt("template_{$type}_parsed");
	$on = WPFB_OPT_NAME.'_ptpls_'.$type;
	$ptpls = get_option($on);
	if(empty($ptpls)) {
		$ptpls = wpfb_call('TplLib','Parse',self::GetTpls($type));
		update_option($on, $ptpls);
	}
	return empty($ptpls[$tag]) ? null : $ptpls[$tag];
}

static function AdminDashboardSetup() {
	if(current_user_can('upload_files')) {
		wpfb_loadclass('Admin');
		wp_add_dashboard_widget('wpfb-add-file-widget', WPFB_PLUGIN_NAME.': '.__('Add File', WPFB), array('WPFB_Admin', 'AddFileWidget'));
	}	
}

static function AdminBar() {
	global $wp_admin_bar;
	
	$wp_admin_bar->add_menu(array('id' => WPFB, 'title' => WPFB_PLUGIN_NAME, 'href' => admin_url('admin.php?page=wpfilebase_manage')));
	
	$wp_admin_bar->add_menu(array('parent' => WPFB, 'id' => WPFB.'-add-file', 'title' => __('Add File', WPFB), 'href' => admin_url('admin.php?page=wpfilebase_files#addfile')));
	
	$current_object = get_queried_object();
	if ( !empty($current_object) && !empty($current_object->post_type) && $current_object->ID > 0) {
		$link = WPFB_PLUGIN_URI.'editor_plugin.php?manage_attachments=1&amp;post_id='.$current_object->ID;
		$wp_admin_bar->add_menu( array( 'parent' => WPFB, 'id' => WPFB.'-attachments', 'title' => __('Manage attachments', WPFB), 'href' => $link,
		'meta' => array('onclick' => 'window.open("'.$link.'", "wpfb-manage-attachments", "width=680,height=400,menubar=no,location=no,resizable=no,status=no,toolbar=no,scrollbars=yes");return false;')));
	}
	
	$wp_admin_bar->add_menu(array('parent' => WPFB, 'id' => WPFB.'-add-file', 'title' => __('Sync Filebase', WPFB), 'href' => admin_url('admin.php?page=wpfilebase_manage&action=sync')));
	
	$wp_admin_bar->add_menu(array('parent' => WPFB, 'id' => WPFB.'-toggle-context-menu', 'title' => __(self::GetOpt('file_context_menu')?'Disable file context menu':'Enable file context menu', WPFB), 'href' => 'javascript:;',
	'meta' => array('onclick' => 'return wpfb_toggleContextMenu();')));
	
}

static function Sync() { return wpfb_call('Admin', 'Sync'); }

static function Cron() {
	if(self::GetOpt('cron_sync'))
		self::Sync();
}

static function GetMaxUlSize() {
	$val = ini_get('upload_max_filesize');
    if (is_numeric($val))
        return $val;

	$val_len = strlen($val);
	$max_bytes = substr($val, 0, $val_len - 1);
	$unit = strtolower(substr($val, $val_len - 1));
	switch($unit) {
		case 'k':
			$max_bytes *= 1024;
			break;
		case 'm':
			$max_bytes *= 1048576;
			break;
		case 'g':
			$max_bytes *= 1073741824;
			break;
	}
	return $max_bytes;
}

public static function GetCustomFields($full_field_names=false) {
	$custom_fields = explode("\n",WPFB_Core::GetOpt('custom_fields'));
	$arr = array();
	if(empty($custom_fields[0])) return array();
	foreach($custom_fields as $cf) {
		$cfa = explode("|", $cf);
		$arr[$full_field_names?('file_custom_'.trim($cfa[1])):trim($cfa[1])] = $cfa[0];
	}
	return $arr;
}

static function GetAttachedFileFilter($file) {
	if($file{0} == '/' && strpos($file, WPFB.'/') == 1)
		$file = substr_replace($file, self::UploadDir(), 0, strlen(WPFB) + 1);
	return $file;
}

static function GetAttachmentUrlFilter($url)  {
	if(($p=strpos($url, '//'.WPFB.'/')) != false) {
		$path = substr($url, $p + strlen(WPFB) + 3);
		wpfb_loadclass('File','Category');
		if(!is_null($file = WPFB_File::GetByPath($path)))
			$url = $file->GetUrl();
	}
	
	return $url;
}

/*
static function LoadOptsDirect() {
	global $wpdb;
	$opts = $wpdb->get_var("SELECT option_value FROM $wpdb->options WHERE option_name = '".WPFB_OPT_NAME."' LIMIT 1");
	return (self::$options = empty($opts) ? array() : (array)$opts);
}
*/
static function GetCustomCssPath($path=null) {
	if(empty($path)) {
		$path = self::UploadDir();
	} else {
		$path = ABSPATH .'/'.trim(str_replace('\\','/',str_replace('..','', $path)),'/');
		if(!@is_dir($path)) return null;
	}
	$path .= "/_wp-filebase.css";
	return $path; 
}
}