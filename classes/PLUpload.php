<?php

class WPFB_PLUpload
{
	function GetAjaxAuthData($json=false)
	{
		$dat = array(
			"auth_cookie" => (is_ssl() ? $_COOKIE[SECURE_AUTH_COOKIE] : $_COOKIE[AUTH_COOKIE]),
			"logged_in_cookie" => $_COOKIE[LOGGED_IN_COOKIE],
			"_wpnonce" => wp_create_nonce(WPFB.'-async-upload')
		);
		return $json ? trim(json_encode($dat),'{}') : $dat;
	}
	
	function Scripts()
	{		
		wp_print_scripts('plupload-handlers');
		?>
<script type="text/javascript">
//<![CDATA[
		
function fileQueued(fileObj) {
	jQuery('#file-upload-progress').show().html('<div class="progress"><div class="percent">0%</div><div class="bar" style="width: 30px"></div></div><div class="filename original"> ' + fileObj.name + '</div>');

	jQuery('.progress', '#file-upload-progress').show();
	jQuery('.filename', '#file-upload-progress').show();

	jQuery("#media-upload-error").empty();
	jQuery('.upload-flash-bypass').hide();
	
	jQuery('#file-submit').prop('disabled', true);
	jQuery('#cancel-upload').show().prop('disabled', false);

	 // delete already uploaded temp file	
	if(jQuery('#file_flash_upload').val() != '0') {
		jQuery.ajax({type: 'POST', async: true, url:"<?php echo esc_attr( WPFB_PLUGIN_URI.'wpfb-async-upload.php' ); ?>",
		data: {<?php echo self::GetAjaxAuthData(true) ?> , "delupload":jQuery('#file_flash_upload').val()},
		success: (function(data){})
		});
		jQuery('#file_flash_upload').val(0);
	}
}

function uploadProgress(up, file) {
	var item = jQuery('#file-upload-progress');
	jQuery('.bar', item).width( (200 * file.loaded) / file.size );
	jQuery('.percent', item).html( file.percent + '%' );

	if ( file.percent == 100 ) {
		item.html('<strong class="crunching">' + '<?php _e('File %s uploaded.', WPFB) ?>'.replace(/%s/g, file.name) + '</strong>');
	}
}

function wpFileError(fileObj, message) {
	jQuery('#media-upload-error').show().html(message);
	jQuery('.upload-flash-bypass').show();
	jQuery("#file-upload-progress").hide().empty();
	jQuery('#cancel-upload').hide().prop('disabled', true);
}


function uploadError(fileObj, errorCode, message, uploader) {
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

global $is_IE, $is_opera;
	
$upload_size_unit = $max_upload_size = wp_max_upload_size();
$sizes = array( 'KB', 'MB', 'GB' );

for ( $u = -1; $upload_size_unit > 1024 && $u < count( $sizes ) - 1; $u++ ) {
	$upload_size_unit /= 1024;
}

if ( $u < 0 ) {
	$upload_size_unit = 0;
	$u = 0;
} else {
	$upload_size_unit = (int) $upload_size_unit;
}
		
do_action('pre-upload-ui');

$plupload_init = array(
	'runtimes' => 'html5,silverlight,flash,html4',
	'browse_button' => 'plupload-browse-button',
	'container' => 'plupload-upload-ui',
	'drop_element' => 'drag-drop-area',
	'file_data_name' => 'async-upload',
	'multiple_queues' => false,
	'max_file_size' => $max_upload_size.'b',
	'url' => WPFB_PLUGIN_URI.'wpfb-async-upload.php',
	'flash_swf_url' => includes_url('js/plupload/plupload.flash.swf'),
	'silverlight_xap_url' => includes_url('js/plupload/plupload.silverlight.xap'),
	'filters' => array( array('title' => __( 'Allowed Files' ), 'extensions' => '*') ),
	'multipart' => true,
	'urlstream_upload' => true,
	'multipart_params' => self::GetAjaxAuthData()
);

$plupload_init = apply_filters( 'plupload_init', $plupload_init );

?>

<script type="text/javascript">
var resize_height = 1024, resize_width = 1024, // this is for img resizing (not used here!)
wpUploaderInit = <?php echo json_encode($plupload_init); ?>;
</script>

<input type="hidden" id="file_flash_upload" name="file_flash_upload" value="0" />

<div id="plupload-upload-ui" class="hide-if-no-js">
<?php do_action('pre-plupload-upload-ui'); // hook change, old name: 'pre-flash-upload-ui' ?>
<div id="drag-drop-area">
	<div class="drag-drop-inside">
	<p class="drag-drop-info"><?php _e('Drop files here'); ?> - <?php _ex('or', 'Uploader: Drop files here - or - Select Files'); ?> - <span class="drag-drop-buttons"><input id="plupload-browse-button" type="button" value="<?php esc_attr_e('Select Files'); ?>" class="button" /></span></p>
	</div>
</div>
<?php do_action('post-plupload-upload-ui'); // hook change, old name: 'post-flash-upload-ui' ?>
</div>

<?php
if ( ($is_IE || $is_opera) && $max_upload_size > 100 * 1024 * 1024 ) { ?>
	<span class="big-file-warning"><?php _e('Your browser has some limitations uploading large files with the multi-file uploader. Please use the browser uploader for files over 100MB.'); ?></span>
<?php }
?>
	<div id="media-upload-error"></div>
	<div id="file-upload-progress" class="media-item" style="width: auto;"></div>
<?php
//do_action('post-upload-ui');
}
} 