<?php
class WPFB_AdminGuiCss {
static function Display()
{
	global $wpdb, $user_ID;
	
	wpfb_loadclass('Admin', 'Output');
	
	$_POST = stripslashes_deep($_POST);
	$_GET = stripslashes_deep($_GET);
	
	$action = (!empty($_POST['action']) ? $_POST['action'] : (!empty($_GET['action']) ? $_GET['action'] : ''));
	$clean_uri = remove_query_arg(array('message', 'action', 'file_id', 'cat_id', 'deltpl', 'hash_sync' /* , 's'*/)); // keep search keyword
	
	?>
	<div class="wrap">
	<?php
	
	$upload_path = WPFB_Core::GetOpt('upload_path');
	if(path_is_absolute($upload_path))
	{
		echo '<div class="updated fade"><p>';
		printf(__('Custom CSS does only work if upload path is relative to WordPress path. It is currently set to <code>%s</code>, so <b>custom CSS will not work!</b>',WPFB), $upload_path);
		echo "</p></div>";
	}
	
	switch($action)
	{		
		default:
			if(!current_user_can('edit_themes'))
				wp_die(__('Cheatin&#8217; uh?'));
		
			$css_path_edit = WPFB_Core::GetCustomCssPath();
			$css_path_default = WPFB_PLUGIN_ROOT . 'wp-filebase.css';
			
			$exists = file_exists($css_path_edit) && is_file($css_path_edit);
			if( ($exists && !is_writable($css_path_edit)) || (!$exists && !is_writable(dirname($css_path_edit))) ) {
				?><div class="error default-password-nag"><p><?php printf(__('%s is not writable!', WPFB), $css_path_edit) ?></p></div><?php
				break;
			}
			
			if(!empty($_POST['restore_default'])) {
				@unlink($css_path_edit);
				$exists = false;				
			} elseif(!empty($_POST['submit']) && !empty($_POST['newcontent'])) {
				// write
				$newcontent = stripslashes($_POST['newcontent']);
				$exists = (file_put_contents($css_path_edit, $newcontent) !== false);
			}

			$fpath = $exists ? $css_path_edit : $css_path_default;
			$content = esc_html(file_get_contents($fpath));
			?>
<form name="csseditor" id="csseditor" action="<?php echo $clean_uri ?>&amp;action=edit_css" method="post">
		 <div><textarea cols="70" rows="25" name="newcontent" id="newcontent" tabindex="1" class="codepress css" style="width: 98%;"><?php echo $content ?></textarea>
		 <input type="hidden" name="action" value="edit_css" />
		<p class="submit">
		<?php echo "<input type='submit' name='submit' class='button-primary' value='" . esc_attr__('Update File', WPFB) . "' tabindex='2' />" ?>
		<?php if($exists) { echo "<input type='submit' name='restore_default' class='button' onclick=\"return confirm('Sure?')\" value='" . esc_attr__('Restore Default', WPFB) . "' tabindex='3' />"; } ?>
		</p>
		</div>
</form>
<?php
		break; // edit_css
	}	
	?>
</div> <!-- wrap -->
<?php
}
}
