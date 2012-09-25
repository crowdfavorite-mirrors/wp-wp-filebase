<?php
class WPFB_AdminGuiManage {
static function Display()
{
	global $wpdb, $user_ID;
	wpfb_loadclass('File', 'Category', 'Admin', 'Output');
	
	$_POST = stripslashes_deep($_POST);
	$_GET = stripslashes_deep($_GET);	
	$action = (!empty($_POST['action']) ? $_POST['action'] : (!empty($_GET['action']) ? $_GET['action'] : ''));
	$clean_uri = remove_query_arg(array('message', 'action', 'file_id', 'cat_id', 'deltpl', 'hash_sync', 'doit', 'ids', 'files', 'cats', 'batch_sync' /* , 's'*/)); // keep search keyword	
	
	
	// switch simple/extended form
	if(isset($_GET['exform'])) {
		$exform = (!empty($_GET['exform']) && $_GET['exform'] == 1);
		update_user_option($user_ID, WPFB_OPT_NAME . '_exform', $exform); 
	} else
		$exform = (bool)get_user_option(WPFB_OPT_NAME . '_exform');
		
	if(!empty($_GET['wpfb-hide-how-start']))
		update_user_option($user_ID, WPFB_OPT_NAME . '_hide_how_start', 1);		
	$show_how_start = !(bool)get_user_option(WPFB_OPT_NAME . '_hide_how_start');	

	WPFB_Admin::PrintFlattrHead();
	?>
	<script type="text/javascript">	
	/* Liking/Donate Bar */
	if(typeof(jQuery) != 'undefined') {
		jQuery(document).ready(function(){
			if(getUserSetting("wpfilebase_hidesuprow",false) == 1) {
				jQuery('#wpfb-liking').hide();
				jQuery('#wpfb-liking-toggle').addClass('closed');	
			}	
			jQuery('#wpfb-liking-toggle').click(function(){
				jQuery('#wpfb-liking').slideToggle();
				jQuery(this).toggleClass('closed');
				setUserSetting("wpfilebase_hidesuprow", 1-getUserSetting("wpfilebase_hidesuprow",false), 0);
			});	
		});
	}
	</script>	
	<div class="wrap">
	<h2><?php echo WPFB_PLUGIN_NAME; ?></h2>
	
	<?php

		
	if($show_how_start)
		wpfb_call('AdminHowToStart', 'Display');
		
	if(!empty($_GET['action']))
			echo '<p><a href="' . $clean_uri . '" class="button">' . __('Go back'/*def*/) . '</a></p>';
	
	switch($action)
	{
		default:
			$clean_uri = remove_query_arg('pagenum', $clean_uri);
			
				$upload_dir = WPFB_Core::UploadDir();
				$upload_dir_rel = str_replace(ABSPATH, '', $upload_dir);
				$chmod_cmd = "CHMOD ".WPFB_PERM_DIR." ".$upload_dir_rel;
				if(!is_dir($upload_dir)) {
					$result = WPFB_Admin::Mkdir($upload_dir);
					if($result['error'])
						$error_msg = sprintf(__('The upload directory <code>%s</code> does not exists. It could not be created automatically because the directory <code>%s</code> is not writable. Please create <code>%s</code> and make it writable for the webserver by executing the following FTP command: <code>%s</code>', WPFB), $upload_dir_rel, str_replace(ABSPATH, '', $result['parent']), $upload_dir_rel, $chmod_cmd);
					else
						wpfb_call('Setup','ProtectUploadPath');
				} elseif(!is_writable($upload_dir)) {
					$error_msg = sprintf(__('The upload directory <code>%s</code> is not writable. Please make it writable for PHP by executing the follwing FTP command: <code>%s</code>', WPFB), $upload_dir_rel, $chmod_cmd);
				}
				
				if(!empty($error_msg)) echo '<div class="error default-password-nag"><p>'.$error_msg.'</p></div>';				
					if(WPFB_Core::GetOpt('tag_conv_req')) {
					echo '<div class="updated"><p><a href="'.add_query_arg('action', 'convert-tags').'">';
					_e('WP-Filebase content tags must be converted',WPFB);
					echo '</a></p></div><div style="clear:both;"></div>';
				}
				
				/*
				wpfb_loadclass('Config');
				if(!WPFB_Config::IsWritable()) {
					echo '<div class="updated"><p>';
					printf(__('The config file %s is not writable or could not be created. Please create the file and make it writable for the webserver.',WPFB), WPFB_Config::$file);
					echo '</p></div><div style="clear:both;"></div>';
				}
				*/
		?>
	<?php
	if(self::PluginHasBeenUsedAWhile()) { ?>		
<div id="wpfb-support-col">
<div id="wpfb-liking-toggle"></div>
<h3><?php _e('Like this plugin?',WPFB) ?></h3>
<div id="wpfb-liking">
	<div style="text-align: center;"><iframe src="http://www.facebook.com/plugins/like.php?href=http%3A%2F%2Fwordpress.org%2Fextend%2Fplugins%2Fwp-filebase%2F&amp;send=false&amp;layout=button_count&amp;width=150&amp;show_faces=false&amp;action=like&amp;colorscheme=light&amp;font&amp;height=21" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:140px; height:21px; display:inline-block; text-align:center;" <?php echo ' allowTransparency="true"'; ?>></iframe></div>
	<p>Please <a href="http://wordpress.org/extend/plugins/wp-filebase/">give it a good rating</a>, or even consider a donation using PayPal or Flattr to support the developer of WP-Filebase:</p> 
	<div style="text-align: center;">	
	<?php WPFB_Admin::PrintPayPalButton() ?>
	<?php WPFB_Admin::PrintFlattrButton() ?>
	</div>
</div>
</div>
<?php }

?>

<div id="col-container">
	<div id="col-right">
		<div class="col-wrap">
			<h3><?php _e('Traffic', WPFB); ?></h3>
			<table class="form-table">
			<?php
				$traffic_stats = WPFB_Core::GetTraffic();					
				$limit_day = (WPFB_Core::GetOpt('traffic_day') * 1048576);
				$limit_month = (WPFB_Core::GetOpt('traffic_month') * 1073741824);
			?>
			<tr>
				<th scope="row"><?php _e('Today', WPFB); ?></th>
				<td><?php
					if($limit_day > 0)
						self::ProgressBar($traffic_stats['today'] / $limit_day, WPFB_Output::FormatFilesize($traffic_stats['today']) . '/' . WPFB_Output::FormatFilesize($limit_day));
					else
						echo WPFB_Output::FormatFilesize($traffic_stats['today']);
				?></td>
			</tr>
			<tr>
				<th scope="row"><?php _e('This Month', WPFB); ?></th>
				<td><?php
					if($limit_month > 0)
						self::ProgressBar($traffic_stats['month'] / $limit_month, WPFB_Output::FormatFilesize($traffic_stats['month']) . '/' . WPFB_Output::FormatFilesize($limit_month));
					else
						echo WPFB_Output::FormatFilesize($traffic_stats['month']);
				?></td>
			</tr>
			<tr>
				<th scope="row"><?php _e('Total File Size', WPFB); ?></th>
				<td><?php echo WPFB_Output::FormatFilesize($wpdb->get_var("SELECT SUM(file_size) FROM $wpdb->wpfilebase_files")) ?></td>
			</tr>	
			</table>
</div>
</div><!-- /col-right -->
			
<div id="col-left">
<div class="col-wrap">

			<h3><?php _e('Statistics', WPFB); ?></h3>
			<table class="form-table">
			<tr>
				<th scope="row"><?php _e('Number of Files', WPFB); ?></th>
				<td><?php echo WPFB_File::GetNumFiles() ?></td>
			</tr>
			<tr>
				<th scope="row"><?php _e('Number of Categories', WPFB); ?></th>
				<td><?php echo WPFB_Category::GetNumCats() ?></td>
			</tr>
			<tr>
				<th scope="row"><?php _e('Total Downloads', WPFB); ?></th>
				<td><?php echo $wpdb->get_var("SELECT SUM(file_hits) FROM $wpdb->wpfilebase_files") ?></td>
			</tr>
			</table>
</div>
</div><!-- /col-left -->

</div><!-- /col-container -->

<h2><?php _e('Tools'); ?></h2>
<p><a href="<?php echo add_query_arg(
				array('action' => 'sync',
				)); ?>" class="button"><?php _e('Sync Filebase',WPFB)?></a> &nbsp; <?php _e('Synchronises the database with the file system. Use this to add FTP-uploaded files.',WPFB) ?></p>
<p><a href="<?php echo add_query_arg('action', 'convert-tags') ?>" class="button"><?php _e('Convert old Tags',WPFB)?></a> &nbsp; <?php printf(__('Convert tags from versions earlier than %s.',WPFB), '0.2.0') ?></p>
<!--  <p><a href="<?php echo add_query_arg('action', 'add-urls') ?>" class="button"><?php _e('Add multiple URLs',WPFB)?></a> &nbsp; <?php _e('Add multiple remote files at once.', WPFB); ?></p>
-->

			<?php WPFB_Admin::PrintForm('file', null, array('exform' => $exform)) ?>
			
		<?php
			if(!$show_how_start) // display how start here if its hidden
				wpfb_call('AdminHowToStart', 'Display');
		?>
			
			<h2><?php _e('About'); ?></h2>
			<p>
			<?php echo WPFB_PLUGIN_NAME . ' ' . WPFB_VERSION ?> by Fabian Schlieper <a href="http://fabi.me/">
			<?php if(strpos($_SERVER['SERVER_PROTOCOL'], 'HTTPS') === false) { ?><img src="http://fabi.me/misc/wpfb_icon.gif?lang=<?php if(defined('WPLANG')) {echo WPLANG;} ?>" alt="" /><?php } ?> fabi.me</a><br/>
			Includes the great file analyzer <a href="http://www.getid3.org/">getID3()</a> by James Heinrich
			</p><?php
			break;
			
	case 'convert-tags':
		?><h2><?php _e('Tag Conversion'); ?></h2><?php
		if(empty($_REQUEST['doit'])) {
			echo '<div class="updated"><p>';
			_e('<strong>Important:</strong> before updating, please <a href="http://codex.wordpress.org/WordPress_Backups">backup your database and files</a>. For help with updates, visit the <a href="http://codex.wordpress.org/Updating_WordPress">Updating WordPress</a> Codex page.');
			echo '</p></div>';
			echo '<p><a href="' . add_query_arg('doit',1) . '" class="button">' . __('Continue') . '</a></p>';
			break;
		}
		$result = wpfb_call('Setup', 'ConvertOldTags');
		?>
		<p><?php printf(__('%d Tags in %d Posts has been converted.'), $result['n_tags'], count($result['tags'])) ?></p>
		<ul>
		<?php
		if(!empty($result['tags'])) foreach($result['tags'] as $post_title => $tags) {
			echo "<li><strong>".esc_html($post_title)."</strong><ul>";
			foreach($tags as $old => $new) {
				echo "<li>$old =&gt; $new</li>";
			}
			echo "</ul></li>";
		}		
		?>
		</ul>
		<?php
		if(!empty($result['errors'])) { ?>	
		<h2><?php _e('Errors'); ?></h2>
		<ul><?php foreach($result['errors'] as $post_title => $err) echo "<li><strong>".esc_html($post_title).": </strong> ".esc_html($err)."<ul>"; ?></ul>		
		<?php
		}
		$opts = WPFB_Core::GetOpt();
		unset($opts['tag_conv_req']);
		update_option(WPFB_OPT_NAME, $opts);		
		
		break; // convert-tags
		
		
		case 'del':
				if(!empty($_REQUEST['files'])) {
				$ids = explode(',', $_REQUEST['files']);
				$nd = 0;
				foreach($ids as $id) {
					$id = intval($id);					
					if(($file=WPFB_File::GetFile($id))!=null) {
						$file->Remove(true);
						$nd++;
					}
				}
				WPFB_File::UpdateTags();		
				
				echo '<div id="message" class="updated fade"><p>'.sprintf(__('%d Files removed'), $nd).'</p></div>';
			}
			if(!empty($_REQUEST['cats'])) {
				$ids = explode(',', $_REQUEST['cats']);
				$nd = 0;
				foreach($ids as $id) {
					$id = intval($id);					
					if(($cat=WPFB_Category::GetCat($id))!=null) {
						$cat->delete();
						$nd++;
					}
				}		
				
				echo '<div id="message" class="updated fade"><p>'.sprintf(__('%d Categories removed'), $nd).'</p></div>';
			}
			
		case 'sync':
			echo '<h2>'.__('Synchronisation').'</h2>';
			wpfb_loadclass('Sync');
			$result = WPFB_Sync::Sync(!empty($_GET['hash_sync']), true);
			if(!is_null($result))
				WPFB_Sync::PrintResult($result);

		
			if(empty($_GET['hash_sync']))
				echo '<p><a href="' . add_query_arg('hash_sync',1) . '" class="button">' . __('Complete file sync', WPFB) . '</a> ' . __('Checks files for changes, so more reliable but might take much longer. Do this if you uploaded/changed files with FTP.', WPFB) . '</p>';			
			
		break; // sync
	} // switch	
	?>
</div> <!-- wrap -->
<?php
}

static function ProgressBar($progress, $label)
{
	$progress = round(100 * $progress);
	echo "<div class='wpfilebase-progress'><div class='progress'><div class='bar' style='width: $progress%'></div></div><div class='label'><strong>$progress %</strong> ($label)</div></div>";
}

static function PluginHasBeenUsedAWhile()
{
	global $wpdb;
	if(WPFB_File::GetNumFiles() < 5) return false;
	$first_file_time = mysql2date('U',$wpdb->get_var("SELECT file_date FROM $wpdb->wpfilebase_files ORDER BY file_date ASC LIMIT 1"));
	return ($first_file_time > 1 && (time()-$first_file_time) > (86400 * 4)); // 4 days	
}
}
