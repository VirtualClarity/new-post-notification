<?php
/**
 * Plugin Name: New Post Notification
 * Plugin URI:  https://github.com/VirtualClarity/new-post-notification
 * Description: Notifies users if a new post has been published. Subscribers can also decide which categories they would like to be notified of changes to.
 * Version:     1.0.11
 * Author:      Jan Eichhorn / Virtual Clarity
 * Author URI:  http://www.virtualclarity.com
 * License:     GPLv2
 */

// load textdomain
load_plugin_textdomain('npn_plugin', false, basename( dirname( __FILE__ ) ) . '/languages' );

// Do something when a post gets published //
add_filter ( 'publish_post', 'npn_notify' );

function npn_notify($post_ID) {

  	// get the Userdata //
  	$meta_query = new WP_Query( array( 'meta_key' => 'npn_post_notify', 'meta_value' => '1' ) );
  	$args = array(
  		'meta_query' => $meta_query,
  		);
  	$users = get_users( );

    // get the postobject //
    $postobject = get_post($post_ID);
    $postcontent = get_post_field('post_content', $post_ID);
    $postthumb = get_the_post_thumbnail( $post_ID, 'medium');

    // Use HTML-Mails
    add_filter('wp_mail_content_type',create_function('', 'return "text/html"; '));

    // Go through the users and check the access //
	foreach ($users as $user)
	{
		// send Mail if User activated Notification and there was no notification before.
		$notify_on = (get_the_author_meta('npn_mailnotify', $user->ID) == '0' ? false : true);	// if they have turned it off don't send
													// if they have not specified or turned it on do send
		error_log($user->user_login.": Notify is set to $notify_on");

		// Check if category is chosen by user
		$user_cats = get_user_meta($user->ID, 'npn_mailnotify_category');
		$cat_chosen = false;
		if(array_key_exists(0, $user_cats))
		{
			error_log($user->user_login.": User has chosen categories");
			if ($user_cats[0]=='')
			{
		        	$cat_chosen = true;
		      	}
			else
			{
				foreach ($postobject->post_category as $postcats)
				{
					if (in_array($postcats,explode(',',$user_cats[0]))) $cat_chosen = true;
				}
			}
		}
		else
		{
			error_log($user->user_login.": User has not chosen any categories");
			$cat_chosen = true;			// If they haven't chosen categories, they get all of them
		}

		// Send email if conditions are correct
		if ($cat_chosen==true)
		{
			error_log($user->user_login.": One or more post categories are selected for notification");
	 		if($notify_on AND get_post_meta( $post_ID, 'npn_notified', true) != '1')
			{			
				$headers = NULL;
				if(NULL !== get_option('npn_from_email'))
				{
					$from_string = 'from: ';
					if(NULL !== get_option('npn_from_name'))
					{
						$from_string = $from_string.get_option('npn_from_name').' ';
					}
					$from_string = $from_string.'<'.get_option('npn_from_email').'>';
					$headers = array($from_string);
				}
				
				if(NULL !== get_option('npn_debug_mode'))
				{
					error_log("DEBUG: Pretending to send email notification for ".$postobject->post_title." to ".$user->data->user_email);
					continue;
				}

				error_log("Sending email notification for ".$postobject->post_title." to ".$user->data->user_email);
        		$sent = wp_mail( $user->data->user_email, __('New Post','npn_plugin').': '.$postobject->post_title,	npn_generate_mail_content($postobject,$postcontent,$postthumb,$user->ID), $headers);
				if($sent==true)
				{
					error_log($user->user_login.": Sent email");
				}
				else
				{
					error_log($user->user_login.": Failed to send email");
				}
			}
			else
			{
				error_log($user->user_login.": Notify is off or post already notified");
			}
		}
		else
		{
			error_log($user->user_login.": No post categories are not selected for notification");
		}
	}
     
     // Use default plain
     add_filter('wp_mail_content_type',create_function('', 'return "text/plain"; '));
     
    update_post_meta($post_ID, 'npn_notified', '1', true);
    return $post_ID;
}

function npn_get_group_name($groupID){
    global $wpdb;
    $groupname = $wpdb->get_results( "SELECT object_id FROM ".$wpdb->prefix."uam_accessgroup_to_object WHERE group_id = ".$groupID." AND object_type = 'role' ");
    return $groupname[0]->object_id;
}

function npn_generate_mail_content($postobject,$postcontent,$postthumb,$userid){
    $userdata = get_userdata($userid);
    $authordata = get_userdata($postobject->post_author);
    $mailcontent = __('Hello','npn_plugin').' '.$userdata->first_name.',<br>';
    $mailcontent .= $authordata->first_name.' '.$authordata->last_name.' '.__('published a new post','npn_plugin').' '.__('at','npn_plugin').' '.get_option('blogname').':<br>';
    $mailcontent .= '<h2><a href="'.$postobject->guid.'&refferer=mailnotify&uid='.$userid.'">'.$postobject->post_title.'</a></h2>'.implode(' ', array_slice(explode(' ', $postcontent), 0, 40)).' <a href="'.$postobject->guid.'&refferer=mailnotify&uid='.$userid.'">[...]</a>';
    $mailcontent .= '<br><br><small>'.__('To stop these notifications, go to your ','npn_plugin').' <a href="'.get_bloginfo('wpurl').'/wp-admin/profile.php">'.__('profile','npn_plugin').'</a>. '.__('You can also choose which categeries you intereseted in.','npn_plugin').'</small>';

    return $mailcontent;
}

// Settings in Profile //

function npn_add_custom_user_profile_fields( $user )
{
	$notify_setting = get_the_author_meta('npn_mailnotify', $user->ID);
	switch($notify_setting)
	{
		case "":				// default, user has not previously made a choice
			$checked = 'checked';
			break;
		case "0";				// user switched it off
			$checked = '';
			break;
		case "1";				// user switched it on
			$checked = 'checked';
			break;
	}
  $categories = get_categories( array('hide_empty'=>0, 'order_by'=>'name') );
  $user_cats = get_user_meta($user->ID, 'npn_mailnotify_category');
?>
	<h3><?php _e('Notifications','npn_plugin'); ?></h3>

	<table class="form-table">
		<tr>
			<th>
				<label for="npn_mailnotify"><?php _e('Email Subscription','npn_plugin'); ?></label>
            </th>
			<td>
				<input type="checkbox" name="npn_mailnotify" id="npn_mailnotify" value="1" <?php echo $checked; ?>/>
				<span class="description"><?php _e('Notify me via email if a new post is published. ','npn_plugin'); echo(' '); _e('If you don\'t want to get all the stuff, choose your categories below. ','npn_plugin'); echo(' '); _e('Choosing none means getting all. ','npn_plugin'); ?></span>
			</td>
		</tr>
        <?php
        foreach ($categories as $category) {
          $category_checked='';
          if (array_key_exists(0, $user_cats) && in_array($category->cat_ID,explode(',',$user_cats[0]))) $category_checked='checked';
        ?>
        </tr>
            <th>
				<label for="npn_mailnotify_category_<?php echo($category->name); ?>"><?php echo($category->name); ?></label>
            </th>
            <td>
                <input type="checkbox" name="npn_mailnotify_category[]" id="npn_mailnotify_category_<?php echo($category->name); ?>" value="<?php echo($category->cat_ID); ?>" <?php echo $category_checked; ?>/>
                <span class="description"><?php echo($category->description); ?></span>
            </td>
        </tr>
        <?php
        }
        ?>
	</table>
<?php }

function npn_save_custom_user_profile_fields( $user_id ) {

	if ( !current_user_can( 'edit_user', $user_id ) )
		return FALSE;
    // Notify the Administrator if anybody activates or deactivates the nofifications.
    $user = get_userdata($user_id);
    $usermeta = get_user_meta($user_id, 'npn_mailnotify');

    if(isset($_POST['npn_mailnotify'])){
      update_user_meta( $user_id, 'npn_mailnotify', $_POST['npn_mailnotify']);
      if ($_POST['npn_mailnotify']=='1' AND $usermeta[0] !='1') wp_mail(get_option('admin_email'),$user->first_name.' '.__('activated subscription to posts.','npn_plugin'),$user->first_name.' '.$user->last_name);
      if ($_POST['npn_mailnotify']!='1' AND $usermeta[0] =='1') wp_mail(get_option('admin_email'),$user->first_name.' '.__('deactivated subscription to posts.','npn_plugin'),$user->first_name.' '.$user->last_name);
    }
    else
    {
      update_user_meta( $user_id, 'npn_mailnotify', '0');
      if ($usermeta[0] =='1') wp_mail(get_option('admin_email'),$user->first_name.' '.__('deactivated subscription to posts.','npn_plugin'),$user->first_name.' '.$user->last_name);
    }
        
    if(isset($_POST['npn_mailnotify_category'])){
      update_user_meta( $user_id, 'npn_mailnotify_category', implode(',',$_POST['npn_mailnotify_category']));
    }
    else
    {
      update_user_meta( $user_id, 'npn_mailnotify_category', '');
    }
}

add_action( 'show_user_profile', 'npn_add_custom_user_profile_fields' );
add_action( 'edit_user_profile', 'npn_add_custom_user_profile_fields' );

add_action( 'personal_options_update', 'npn_save_custom_user_profile_fields' );
add_action( 'edit_user_profile_update', 'npn_save_custom_user_profile_fields' );

// adds mailnotify abo when user registeres //
add_action('user_register', 'npn_defaultnotify');

function npn_defaultnotify($user_id) {
    add_user_meta( $user_id, 'npn_mailnotify', '1' );
}

// adds extra column in user_table
add_filter('manage_users_columns', 'npn_add_mailnotify_column');
function npn_add_mailnotify_column($columns) {
    $columns['npn_mailnotify'] = __('Mail subscription','npn_plugin');
    return $columns;
}

add_action('manage_users_custom_column',  'npn_add_mailnotify_column_content', 10, 3);
function npn_add_mailnotify_column_content($value, $column_name, $user_id)
{
	$user = get_userdata( $user_id );
	if ( 'npn_mailnotify' == $column_name )
	{
	        $mailstatus = get_user_meta($user_id, 'npn_mailnotify');
	        $user_cats = get_user_meta($user->ID, 'npn_mailnotify_category');
		if ($mailstatus[0]=='1')
		{
			$user_cats = explode(',',$user_cats[0]);
		        $out = '';
		        foreach ($user_cats as $category)
			{
				$out .= get_cat_name($category).', ';        
		        }
		        if ($out == ', ') {return __('All categories','npn_plugin');} else return $out;    
		}
		else if ($mailstatus[0]=='')
		{
			return __('All categories (defaulted)', 'npn_plugin');
		}
		else 
		{
			return __('not active','npn_plugin');
	      	}
	}
	return $value;
}

add_filter('wp_mail_failed', 'print_mail_error');
function print_mail_error($exception)
{
	$code = $exception->get_error_code();
	error_log($code." ".$exception->get_error_message($code)." ".$exception->get_error_data($code));
}

/*
 * Add a Settings page for this Plugin.
 */
add_action('admin_menu', 'npn_create_menu');
function npn_create_menu()
{
    add_options_page( 'New Post Notification Settings', 'New Post Notification', 'manage_options', 'npnsettings', 'npn_settings_page');
}
/*
 * Function to display the settings page.
 */
function npn_settings_page()
{
?>
<div>
<h2>New Post Notification Settings</h2>
Options relating to the New Post Notification plugin.
<form action="options.php" method="post">
<?php settings_fields('npn_settings'); ?>
<?php do_settings_sections('npn_settings_page'); ?>
<input name="Submit" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" />
</form></div>
<?php
}
/*
 * Register the custom options for this plugin.
 */
add_action( 'admin_init', 'npn_register_settings' );
function npn_register_settings()
{
    //register settings
    register_setting( 'npn_settings', 'npn_from_name' );
	add_settings_section('npn_settings_main', '', 'npn_renderMainSettings', 'npn_settings_page');
	add_settings_field('npn_from_name', 'From Name', 'npn_renderFromName', 'npn_settings_page', 'npn_settings_main');
	add_settings_field('npn_from_email', 'From Email', 'npn_renderFromEmail', 'npn_settings_page', 'npn_settings_main');
	add_settings_field('npn_debug_mode', 'Debug Mode', 'npn_renderDebugMode', 'npn_settings_page', 'npn_settings_main');
}

function npn_renderMainSettings() {};

function npn_renderFromName() {
	$name= get_option('npn_from_name');
	
	echo "<input id='plugin_text_string' name='npn_from_name' size='80' type='text' value='{$name}' /><br/>
The name that email notifications will appear to have come from such as 'My Site'. NOT SANITISED, so don't put rubbish in here.";
}

function npn_renderFromEmail() {
	$email= get_option('npn_from_email');
	
	echo "<input id='plugin_text_string' name='npn_from_email' size='80' type='text' value='{$email}' /><br/>
The email address that email notifications will appear to have come from such as 'newpost@mysite.com'. NOT SANITISED, so dont put rubbish in here.";
}

function npn_renderDebugMode() {
	$debug = get_option('npn_debug_mode');
	error_log($debug);
	echo "<input type='checkbox' name='npn_debug_mode' value='1'".checked($debug)." /><br/>
If this is enabled, all the logic for sending email is followed but no email is actually sent. See debug.log for lots of output describing who would have been sent an email.";
}

/* Not yet active.
// activate subscription to all users when first activating the plugin
register_activation_hook(__FILE__,'npn_activate_subscription');

function npn_activate_subscription(){
    $users = get_users( );

    foreach ($users as $user){
        $subscription_status = get_user_meta($user->ID,'npn_mailnotify');
        if ($subscription_status[0] != "0") add_user_meta($user->ID,'npn_mailnotify',"1");
    }

}

*/


?>
