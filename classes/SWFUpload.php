<?php

class WPFB_SWFUpload
{
	function GetAjaxAuthData()
	{
		return trim(json_encode(array(
			"auth_cookie" => (is_ssl() ? $_COOKIE[SECURE_AUTH_COOKIE] : $_COOKIE[AUTH_COOKIE]),
			"logged_in_cookie" => $_COOKIE[LOGGED_IN_COOKIE],
			"_wpnonce" => wp_create_nonce(WPFB.'-async-upload')
		)), '{}');
	}
	function Scripts()
	{
		wp_print_scripts('swfupload-all');
		wp_print_scripts('swfupload-handlers');
		

		?>
		
<script type="text/javascript">
//<![CDATA[

function fileQueued(fileObj) {
	jQuery('#file-upload-progress').show().html('<div class="progress"><div class="bar"></div></div><div class="filename original"><span class="percent"></span> ' + fileObj.name + '</div>');
	jQuery('.progress', '#file-upload-progress').show();
	jQuery('.filename', '#file-upload-progress').show();

	jQuery("#media-upload-error").empty();
	jQuery('.upload-flash-bypass').hide();
	
	jQuery('#file-submit').prop('disabled', true);
	jQuery('#cancel-upload').show().prop('disabled', false);

	 // delete already uploaded temp file	
	if(jQuery('#file_flash_upload').val() != '0') {
		jQuery.ajax({type: 'POST', async: true, url:"<?php echo esc_attr( WPFB_PLUGIN_URI.'wpfb-async-upload.php' ); ?>",
		data: {<?php echo self::GetAjaxAuthData() ?> , delupload:jQuery('#file_flash_upload').val()},
		success: (function(data){})
		});
		jQuery('#file_flash_upload').val(0);
	}
}

function uploadProgress(fileObj, bytesDone, bytesTotal) {
	var w = jQuery('#file-upload-progress').width() - 2, item = jQuery('#file-upload-progress');
	jQuery('.bar', item).width( w * bytesDone / bytesTotal );
	jQuery('.percent', item).html( Math.ceil(bytesDone / bytesTotal * 100) + '%' );

	if ( bytesDone == bytesTotal ) {
		jQuery('.bar', item).html('<strong class="crunching">' + '<?php _e('File %s uploaded.', WPFB) ?>'.replace(/%s/g, fileObj.name) + '</strong>');
		jQuery('.filename', '#file-upload-progress').hide();
	}
}

function wpFileError(fileObj, message) {
	jQuery('#media-upload-error').show().html(message);
	jQuery('.upload-flash-bypass').show();
	jQuery("#file-upload-progress").hide().empty();
	jQuery('#cancel-upload').hide().prop('disabled', true);
}


function uploadError(fileObj, errorCode, message) {
	wpFileError(fileObj, "Error "+errorCode+": "+message);
}

function uploadSuccess(fileObj, serverData) {
	// if async-upload returned an error message, place it in the media item div and return
	if ( serverData.match('media-upload-error') ) {
		wpFileError(fileObj, serverData);
		return;
	}
	jQuery('#file_flash_upload').val(serverData);
	jQuery('#file-submit').prop('disabled', false);
}

function uploadComplete(fileObj) {
	jQuery('#cancel-upload').hide().prop('disabled', true);
}
	
//]]>
</script>
<?php
	}
	
	function Display($form_url) {

// #8545. wmode=transparent cannot be used with SWFUpload


$upload_image_path = get_user_option( 'admin_color' );
if ( 'classic' != $upload_image_path )
	$upload_image_path = 'fresh';
$upload_image_path = admin_url( 'images/upload-' . $upload_image_path . '.png?ver=20101205' );
?>
<script type="text/javascript">
//<![CDATA[
var swfu;
SWFUpload.onload = function() {
	var settings = {
			button_text: '<span class="button"><?php _e('Select Files'); ?><\/span>',
			button_text_style: '.button { text-align: center; font-weight: bold; font-family:"Lucida Grande",Verdana,Arial,"Bitstream Vera Sans",sans-serif; font-size: 11px; text-shadow: 0 1px 0 #FFFFFF; color:#464646; }',
			button_height: "23",
			button_width: "132",
			button_text_top_padding: 3,
			button_image_url: '<?php echo $upload_image_path; ?>',
			button_placeholder_id: "flash-browse-button",
			upload_url : "<?php echo esc_attr( WPFB_PLUGIN_URI.'wpfb-async-upload.php' ); ?>",
			flash_url : "<?php echo includes_url('js/swfupload/swfupload.swf'); ?>",
			file_post_name: "async-upload",
			file_types: "<?php echo apply_filters('upload_file_glob', '*.*'); ?>",
			post_params : { <?php echo self::GetAjaxAuthData(); ?> },
			file_size_limit : "<?php echo wp_max_upload_size(); ?>b",
			file_queue_limit: 1,
			
			file_dialog_start_handler : (function(){}),
			
			file_queued_handler : fileQueued,
			//upload_start_handler : uploadStart,
			upload_progress_handler : uploadProgress,
			upload_error_handler : uploadError,
			upload_success_handler : uploadSuccess,
			upload_complete_handler : uploadComplete,
			
			file_queue_error_handler : fileQueueError,
			file_dialog_complete_handler : fileDialogComplete,
			
			swfupload_pre_load_handler: swfuploadPreLoad,
			swfupload_load_failed_handler: swfuploadLoadFailed,
			
			custom_settings : {
				degraded_element_id : "html-upload-ui", // id of the element displayed when swfupload is unavailable
				swfupload_element_id : "flash-upload-ui" // id of the element displayed when swfupload is available
			},
			debug: !!<?php echo (int)WP_DEBUG; ?>
		};
		swfu = new SWFUpload(settings);
};
//]]>
</script>


<?php do_action('pre-flash-upload-ui'); ?>
	<div>
	<input type="hidden" id="file_flash_upload" name="file_flash_upload" value="0" />
	<div id="flash-browse-button"></div>
	<span><input id="cancel-upload" disabled="disabled" onclick="cancelUpload()" type="button" value="<?php esc_attr_e('Cancel Upload'); ?>" class="button" /></span>
	</div>
	<div id="media-upload-error"></div>
	<div id="file-upload-progress" class="media-item" style="width: auto;"></div>
<?php
	do_action('post-flash-upload-ui');
	}
	
	
	function ProcessUpload()
	{
	}
} 