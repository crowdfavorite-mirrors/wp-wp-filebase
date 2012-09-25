<?php
class WPFB_AdminLite {
static function InitClass()
{
	wp_enqueue_style(WPFB.'-admin', WPFB_PLUGIN_URI.'wp-filebase-admin.css', array(), WPFB_VERSION, 'all' );
	
	if (isset($_GET['page']))
	{
		$page = $_GET['page'];
		if($page == 'wpfilebase_files') {
			wp_enqueue_script( 'postbox' );
			wp_enqueue_style('dashboard');
		} elseif($page == 'wpfilebase' && isset($_GET['action']) && $_GET['action'] == 'sync') {
			do_action('wpfilebase_sync');
			wp_die("Filebase synced.");
		}
	}
	
	
	wp_register_widget_control(WPFB_PLUGIN_NAME, "[DEPRECATED]".WPFB_PLUGIN_NAME .' '. __('File list'), array(__CLASS__, 'WidgetFileListControl'), array('description' => __('DEPRECATED', WPFB)));
	
	add_action('admin_print_scripts', array('WPFB_AdminLite', 'PrintCKEditorPlugin'));
	
	self::CheckChangedVer();
	
}

static function SetupMenu()
{
	$pm_tag = WPFB_OPT_NAME.'_manage';
	
	add_menu_page(WPFB_PLUGIN_NAME, WPFB_PLUGIN_NAME, 'manage_categories', $pm_tag, array(__CLASS__, 'DisplayManagePage'), WPFB_PLUGIN_URI.'images/admin_menu_icon.png' /*, $position*/ );
	
	$menu_entries = array(
		array('tit'=>'Files',						'tag'=>'files',	'fnc'=>'DisplayFilesPage',	'desc'=>'View uploaded files and edit them',													'cap'=>'upload_files'),
		array('tit'=>__('Categories'/*def*/),		'tag'=>'cats',	'fnc'=>'DisplayCatsPage',	'desc'=>'Manage existing categories and add new ones.',											'cap'=>'manage_categories'),
		//array('tit'=>'Sync Filebase', 'hide'=>true, 'tag'=>'sync',	'fnc'=>'DisplaySyncPage',	'desc'=>'Synchronises the database with the file system. Use this to add FTP-uploaded files.',	'cap'=>'upload_files'),
		array('tit'=>'Edit Stylesheet',				'tag'=>'css',	'fnc'=>'DisplayStylePage',	'desc'=>'Edit the CSS for the file template',													'cap'=>'edit_themes'),
		array('tit'=>'Manage Templates',			'tag'=>'tpls',	'fnc'=>'DisplayTplsPage',	'desc'=>'Edit custom file list templates',														'cap'=>'edit_themes'),
		array('tit'=>__('Settings'),				'tag'=>'sets',	'fnc'=>'DisplaySettingsPage','desc'=>'Change Settings',													'cap'=>'manage_options'),
		array('tit'=>'Donate &amp; Feature Request','tag'=>'sup',	'fnc'=>'DisplaySupportPage','desc'=>'If you like this plugin and want to support my work, please donate. You can also post your ideas making the plugin better.', 'cap'=>'manage_options'),
	);
	
	foreach($menu_entries as $me)
	{		
		$callback = array(__CLASS__, $me['fnc']);
		add_submenu_page($pm_tag, WPFB_PLUGIN_NAME.' - '.__($me['tit'], WPFB), empty($me['hide'])?__($me['tit'], WPFB):null, empty($me['cap'])?'read':$me['cap'], WPFB_OPT_NAME.'_'.$me['tag'], $callback);
	}
}

static function DisplayManagePage(){wpfb_call('AdminGuiManage', 'Display');}


static function DisplayFilesPage(){wpfb_call('AdminGuiFiles', 'Display');}
static function DisplayCatsPage(){wpfb_call('AdminGuiCats', 'Display');}
//static function DisplaySyncPage(){wpfb_call('AdminGuiSync', 'Display');}
static function DisplayStylePage(){wpfb_call('AdminGuiCss', 'Display');}
static function DisplayTplsPage(){wpfb_call('AdminGuiTpls', 'Display');}
static function DisplaySettingsPage(){wpfb_call('AdminGuiSettings', 'Display');}
static function DisplaySupportPage(){wpfb_call('AdminGuiSupport', 'Display');}

static function McePlugins($plugins) {
	$plugins['wpfilebase'] = WPFB_PLUGIN_URI . 'tinymce/editor_plugin.js';
	return $plugins;
}

static function MceButtons($buttons) {
	array_push($buttons, 'separator', 'wpfbInsertTag');
	return $buttons;
}

static function WidgetFileListControl()
{
	WPFB_Core::LoadLang();
	wpfb_loadclass('Widget');
	WPFB_Widget::FileListCntrl();
}

private static function CheckChangedVer()
{
	$ver = wpfb_call('Core', 'GetOpt', 'version');
	if($ver != WPFB_VERSION) {
		wpfb_loadclass('Setup');
		WPFB_Setup::OnActivateOrVerChange($ver);
	}
}

static function JsRedirect($url) {
	echo '<script type="text/javascript"> window.location = "',$url,'"; </script><h1><a href="',$url,'">',$url,'</a></h1>'; 
}

static function PrintCKEditorPlugin() {
	if(has_filter('ckeditor_external_plugins') === false) return;	
	?>
<script type="text/javascript">
//<![CDATA[
	/* CKEditor Plugin */
	if(typeof(ckeditorSettings) == 'object') {
		ckeditorSettings.externalPlugins.wpfilebase = ajaxurl+'/../../wp-content/plugins/wp-filebase/extras/ckeditor/';
		ckeditorSettings.additionalButtons.push(["WPFilebase"]);
	}
//]]>
</script>
	<?php
}
}