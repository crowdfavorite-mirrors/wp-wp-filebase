<?php
class WPFB_AdminGuiSettings {
static function Display()
{
	global $wpdb;

	wpfb_loadclass('Admin', 'Output');
	WPFB_Core::PrintJS(); // prints wpfbConf.ajurl

	if(!current_user_can('manage_options'))
		wp_die(__('Cheatin&#8217; uh?'));
	
	// nonce and referer check (security)
	if(!empty($_POST) && (!wp_verify_nonce($_POST['wpfb-nonce'],'wpfb-update-settings') || !check_admin_referer('wpfb-update-settings','wpfb-nonce')))
		wp_die(__('Cheatin&#8217; uh?'));
	
	$post = stripslashes_deep($_POST);
	
	$action = ( !empty($post['action']) ? $post['action'] : ( !empty($_GET['action']) ? $_GET['action'] : '' ) );
	$messages = array();
	$errors = array();
	
	$options = get_option(WPFB_OPT_NAME);
	$option_fields = WPFB_Admin::SettingsSchema();
	
	if(isset($post['reset']))
	{		
		wpfb_loadclass('Setup');
		WPFB_Setup::ResetOptions();
		$messages += WPFB_Admin::SettingsUpdated($options, get_option(WPFB_OPT_NAME));
		$messages[] = __('Settings reseted.', WPFB);		
		$options = get_option(WPFB_OPT_NAME);
	}
	elseif(isset($post['submit']))
	{		
		// cleanup
		foreach($option_fields as $opt_tag => $opt_data)
		{
			if(isset($post[$opt_tag]))
			{
				if(!is_array($post[$opt_tag]))		
					$post[$opt_tag] = trim($post[$opt_tag]);
				
				switch($opt_data['type'])
				{
					case 'number':
						$post[$opt_tag] = intval($post[$opt_tag]);
						break;
					case 'select':
						// check if value is in options array, if not set to default
						if(!in_array($post[$opt_tag], array_keys($opt_data['options'])))
							$post[$opt_tag] = $opt_data['default'];
						break;
						
					case 'roles':
						$post[$opt_tag] = array_values(array_filter($post[$opt_tag]));
						break;
					
					case 'cat':
						$post[$opt_tag] = (empty($post[$opt_tag]) || is_null($cat = WPFB_Category::GetCat($post[$opt_tag]))) ? 0 : intval($post[$opt_tag]);
						break;
				}						
			}
		}
		
		$post['upload_path'] = str_replace(ABSPATH, '', $post['upload_path']);
		$options['upload_path'] = str_replace(ABSPATH, '', $options['upload_path']);
		
		$post['download_base'] = trim($post['download_base'], '/');
		if(WPFB_Admin::WPCacheRejectUri($post['download_base'] . '/', $options['download_base'] . '/'))
			$messages[] = sprintf(__('/%s/ added to rejected URIs list of WP Super Cache.', WPFB), $post['download_base']);
		
		$tpl_file = ($post['template_file']);
		$tpl_cat = ($post['template_cat']);
		if(!empty($tpl_file) && (empty($options['template_file_parsed']) || $tpl_file != $options['template_file']))
		{
			wpfb_loadclass('TplLib');
			$tpl_file = WPFB_TplLib::Parse($tpl_file);
			$result = WPFB_TplLib::Check($tpl_file);
			
			if(!$result['error']) {
				$options['template_file_parsed'] = $tpl_file;
				$messages[] = __('File template successfully parsed.', WPFB);
			} else {
				$errors[] = sprintf(__('Could not parse template: error (%s) in line %s.', WPFB), $result['msg'], $result['line']);
			}
		}
		
		if(!empty($tpl_cat) && (empty($options['template_cat_parsed']) || $tpl_cat != $options['template_cat']))
		{
			wpfb_loadclass('TplLib');
			$tpl_cat = WPFB_TplLib::Parse($tpl_cat);
			$result = WPFB_TplLib::Check($tpl_cat);
			
			if(!$result['error']) {
				$options['template_cat_parsed'] = $tpl_cat;
				$messages[] = __('Category template successfully parsed.', WPFB);
			} else {
				$errors[] = sprintf(__('Could not parse template: error (%s) in line %s.', WPFB), $result['msg'], $result['line']);
			}
		}
		
		// save options
		foreach($option_fields as $opt_tag => $opt_data)
		{
			$val = isset($post[$opt_tag]) ? $post[$opt_tag] : '';
			$options[$opt_tag] = ($val);
		}
		
		// make sure a short tag exists, if not append one
		$select_opts = array('languages', 'platforms', 'licenses', 'requirements', 'custom_fields');
		foreach($select_opts as $opt_tag) {
			if(empty($options[$opt_tag])) {
				$options[$opt_tag] = '';
				continue;
			}
			$lines = explode("\n", $options[$opt_tag]);
			$lines2 = array();
			for($i = 0; $i < count($lines); $i++) {
				$lines[$i] = str_replace('||','|',trim($lines[$i], "|\r"));
				if(empty($lines[$i]) || $lines[$i] == '|')	continue;
				$pos = strpos($lines[$i], '|');
				if($pos <= 0) $lines[$i] .= '|'.str_replace(array(' ','|'),'',strtolower(substr($lines[$i], 0, min(8, strlen($lines[$i])))));
				$lines2[] = $lines[$i];
			}
			$options[$opt_tag] = implode("\n", $lines2);
		}
		
		$old_options = get_option(WPFB_OPT_NAME);
		update_option(WPFB_OPT_NAME, $options);
		
		$messages += WPFB_Admin::SettingsUpdated($old_options, $options);
		
		if(count($errors) == 0)
			$messages[] = __('Settings updated.', WPFB);
		
		//refresh any description which can contain opt values
		$option_fields = WPFB_Admin::SettingsSchema();
	}
	
	if(WPFB_Core::GetOpt('allow_srv_script_upload'))
		$messages[] = __('WARNING: Script upload enabled!', WPFB);
		
	$upload_path = WPFB_Core::GetOpt('upload_path');
	if(!empty($old_options) && path_is_absolute($upload_path) && !path_is_absolute($old_options['upload_path']))
	{
		$rel_path  = str_replace('\\','/',$upload_path);
		$rel_path = substr($rel_path, strpos($rel_path, '/')+1);
		$messages[] = __(sprintf('NOTICE: The upload path <code>%s</code> is rooted to the filesystem. You should remove the leading slash if you want to use a folder inside your Wordpress directory (i.e: <code>%s</code>)', $upload_path, $rel_path), WPFB);
	}
	
	WPFB_Admin::FlushRewriteRules();
	
	$action_uri = admin_url('admin.php') . '?page=' . $_GET['page'] . '&amp;updated=true';

	if (!empty($messages)) :
	$message = '';
	foreach($messages as $msg)
		$message .= '<p>' . $msg . '</p>';
?>
<div id="message" class="updated fade"><?php echo $message; ?></div>
<?php
	endif;

	if (!empty($errors)) : 
	$error = '';
	foreach($errors as $err)
		$error .= '<p>' . $err . '</p>';
?>
<div id="message" class="error fade"><?php echo $error; ?></div>
<?php endif; ?>
<script type="text/javascript">
/* Option tabs */
jQuery(document).ready( function() {
	try { jQuery('#wpfb-tabs').tabs(); }
	catch(ex) {}
	/*if(typeof(CKEDITOR) != 'undefined') {
		CKEDITOR.plugins.addExternal('wpfilebase', ajaxurl+'/../../wp-content/plugins/wp-filebase/extras/ckeditor/');
		alert( ajaxurl+'/../../wp-content/plugins/wp-filebase/extras/ckeditor/');
	}*/
});
</script>

<div class="wrap">
<h2><?php echo WPFB_PLUGIN_NAME; echo ' '; _e("Settings"/*def*/); ?></h2>

<form method="post" action="<?php echo $action_uri; ?>" name="wpfilebase-options">
	<?php wp_nonce_field('wpfb-update-settings', 'wpfb-nonce'); ?>
	<p class="submit">
	<input type="submit" name="submit" value="<?php _e('Save Changes'/*def*/) ?>" class="button-primary" />
	</p>
	<?php
	
	$misc_tags = array('hide_links','base_auto_thumb','cron_sync','fext_blacklist','disable_id3','search_id3','thumbnail_path','use_path_tags','no_name_formatting');
	if(function_exists('wp_admin_bar_render'))
		$misc_tags[] = 'admin_bar';
	
	$option_categories = array(
		__('Common', WPFB)					=> array('upload_path','search_integration' /*'cat_drop_down'*/),
		__('Display', WPFB)					=> array('file_date_format','thumbnail_size','auto_attach_files', 'attach_loop','attach_pos', 'filelist_sorting', 'filelist_sorting_dir', 'filelist_num', /* TODO: remove? 'parse_tags_rss',*/ 'decimal_size_format'),
		__('File Browser',WPFB)				=> array('file_browser_post_id','file_browser_cat_sort_by','file_browser_cat_sort_dir','file_browser_file_sort_by','file_browser_file_sort_dir','file_browser_fbc', 'late_script_loading','disable_footer_credits','footer_credits_style'),
		__('Download', WPFB)				=> array(
												'disable_permalinks', 'download_base', 'force_download', 'range_download', 'http_nocache', 'ignore_admin_dls', 'accept_empty_referers','allowed_referers','dl_destroy_session'),
		__('Form Presets', WPFB)			=> array('default_author','default_roles', 'default_cat', 'languages', 'platforms', 'licenses', 'requirements', 'custom_fields'),
		__('Limits', WPFB)					=> array('bitrate_unregistered', 'bitrate_registered', 'traffic_day', 'traffic_month', 'traffic_exceeded_msg', 'file_offline_msg', 'daily_user_limits', 'daily_limit_subscriber', 'daily_limit_contributor', 'daily_limit_author', 'daily_limit_editor', 'daily_limit_exceeded_msg'),
		__('Security', WPFB)				=> array('allow_srv_script_upload', 'frontend_upload', 'hide_inaccessible', 'inaccessible_msg', 'inaccessible_redirect', 'login_redirect_src', 'protect_upload_path', 'private_files'),
		__('Templates and Scripts', WPFB)	=> array('template_file', 'template_cat', 'dlclick_js'),
		__('Misc')							=> $misc_tags,
	);
	?>
	<div id="wpfb-tabs">
		<ul class="wpfb-tab-menu">
			<?php foreach ( $option_categories as $key => $val ) {
				echo '<li><a href="#'.sanitize_title($key).'">'.esc_html($key).'</a></li>';
			} ?>
		</ul>
	<?php
	$page_option_list = '';	
	$n = 0;
	foreach($option_categories as $opt_cat => $opt_cat_fields) {
		//echo "\n".'<h3>'.$opt_cat.'</h3>';	
		echo "\n\n".'<div id="'. sanitize_title($opt_cat) .'" class="wpfilebase-opttab"><table class="form-table">';
		foreach($opt_cat_fields as $opt_tag)
		{
			
			$field_data = $option_fields[$opt_tag];
			$opt_val = $options[$opt_tag];
			echo "\n".'<tr valign="top">'."\n".'<th scope="row">' . $field_data['title']. '</th>'."\n".'<td>';
			$style_class = '';
			if(!empty($field_data['class']))
				$style_class .= ' class="'.$field_data['class'].'"';
			if(!empty($field_data['style']))
				$style_class .= ' style="'.$field_data['style'].'"';
			switch($field_data['type'])
			{
				case 'text':
				case 'number':
				case 'checkbox':
					echo '<input name="' . $opt_tag . '" type="' . $field_data['type'] . '" id="' . $opt_tag . '"';
					echo ((!empty($field_data['class'])) ? ' class="' . $field_data['class'] . '"' : '');
					if($field_data['type'] == 'checkbox') {
						echo ' value="1" ';
						checked('1', $opt_val);
					} elseif($field_data['type'] == 'number')
						echo ' value="' . intval($opt_val) . '" size="5" style="text-align: right"';
					else {
						echo ' value="' . esc_attr($opt_val) . '"';
						if(isset($field_data['size']))
							echo ' size="' . (int)$field_data['size'] . '"';
					}
					echo $style_class . ' />';
					break;
					
				case 'textarea':
					$code_edit = (strpos($opt_tag, 'template_') !== false || (isset($field_data['class']) && strpos($field_data['class'], 'code') !== false));
					$nowrap = !empty($field_data['nowrap']);
					echo '<textarea name="' . $opt_tag . '" id="' . $opt_tag . '"';
					if($nowrap || $code_edit) {
						echo ' cols="100" wrap="off" style="width: 100%;' . ($code_edit ?  'font-size: 9px;' : '') . '"';
					} else
						echo ' cols="50"';
					echo ' rows="' . ($code_edit ? 20 : 5) . '"';
					echo $style_class;
					echo '>';
					echo esc_html($opt_val);
					echo '</textarea>';
					break;
				case 'select':
					echo '<select name="' . $opt_tag . '" id="' . $opt_tag . '">';
					foreach($field_data['options'] as $opt_v => $opt_n)
						echo '<option value="' . esc_attr($opt_v) . '"' . (($opt_v == $opt_val) ? ' selected="selected" ' : '') . $style_class . '>' . (!is_numeric($opt_v) ? (esc_html($opt_v) . ': ') : '') . esc_html($opt_n) . '</option>';
					echo '</select>';
					break;
					
				case 'roles':
					WPFB_Admin::RolesCheckList($opt_tag, $opt_val);
					break;
					
				case 'cat':
					echo "<select name='$opt_tag' id='$opt_tag'>";
					echo WPFB_Output::CatSelTree(array('selected'=>$opt_val));
					echo "</select>";
					break;
			}
			
			if(!empty($field_data['unit']))
				echo ' ' . $field_data['unit'];
				
			if(!empty($field_data['desc']))
				echo "\n".'<br />' . str_replace('%value%', is_array($opt_val) ? join(', ', $opt_val) : $opt_val, $field_data['desc']);
			echo "\n</td>\n</tr>";		
			$page_option_list .= $opt_tag . ',';
		}
		
		echo '</table></div>'."\n";
	}
	?>
</div> <!--wpfilebase-opttabs-->
	<input type="hidden" name="action" value="update" />
	<input type="hidden" name="page_options" value="<?php echo $page_option_list; ?>" />
	<p class="submit">
	<input type="submit" name="submit" value="<?php _e('Save Changes') ?>" class="button-primary" />
	<input type="submit" name="reset" value="<?php _e('Restore Default Settings', WPFB) ?>" onclick="return confirm('<?php _e('All settings (including default file and category template) will be set to default values. Continue?', WPFB); ?>')" class="button delete" style="float: right;" />
	</p>
</form>
</div>	<!-- wrap -->	
<?php
}
}
