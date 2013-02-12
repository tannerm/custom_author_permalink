<?php
/*
Plugin Name: Custom Author Rewrite
Plugin URI: http://freshmuse.com
Description: Rewrite Author Permalink
Version: 1.0
Author: FreshMuse
Author URI: http://freshmuse.com
*/

// Instantiate the DisplayNameAuthorPermaLink class
new DisplayNameAuthorPermaLink();

class DisplayNameAuthorPermaLink {
	
	function __construct() {

		add_action( 'init', array(&$this, 'author_rewrite_setup') );
		add_action('profile_update', array(&$this, 'update_user'), 10, 4);
		
		add_filter('author_link', array(&$this, 'change_author_permalinks'), 10, 3);
	}
	
  /**
   * Function User Updated
   * 
   * @called via profile_update action
   *
   * Update user's nicename to match the one editable in the S2Member
   * s2member_custom_fields field in the wp_usermeta table. This value is sent
   * over the $_POST['ws_plugin__s2member_profile_permalink'] variable. First
   * we make sure that the nicename needs to be updated, if it does, sanitize
   * the variable, check the query for duplicates, update the user and update
   * the s2member_custom_fields with the sanitized, unduplicated version.
   *
   * @param $user_id stores the id of the user being updated
   * @param $old_user_data stores the pre updated user object
   *
   * @return none
   *
   * @author Tanner Moushey
   **/
  function update_user( $user_id, $old_user_data ){

    $new_user_data = new WP_User( $user_id );    
    
    // if the nicename is the same as the username, update the nicename to the Display Name
    if ( $_POST['ws_plugin__s2member_profile_permalink'] === $new_user_data->user_login )
      $_POST['ws_plugin__s2member_profile_permalink'] = $_POST['ws_plugin__s2member_profile_display_name'];
      
    // if the S2Member profile permalink field is not found, return
    if ( ! $profile_permalink = $_POST['ws_plugin__s2member_profile_permalink'] )
      return;
    
    // if $_POST proposed permalink via $profile_permalink is the same as the user_nicename, return
    if ( $profile_permalink === $new_user_data->user_nicename )
      return;
    
    // sanitize proposed permalink
    $profile_permalink = sanitize_title($profile_permalink);
    
    // variables for the nicename check
    $user_nicename = $profile_permalink;
    $user_login = $new_user_data->user_login;
    GLOBAL $wpdb;
    
    /**** taken from wp_insert_user() function 
          Checks for the existance of the proposed
          nicename and appends a suffix if one
          already exists. */
    $user_nicename_check = $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_nicename = %s AND user_login != %s LIMIT 1" , $user_nicename, $user_login));

  	if ( $user_nicename_check ) {
  		$suffix = 2;
  		while ($user_nicename_check) {
  			$alt_user_nicename = $user_nicename . "-$suffix";
  			$user_nicename_check = $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_nicename = %s AND user_login != %s LIMIT 1" , $alt_user_nicename, $user_login));
  			$suffix++;
  		}
  		$user_nicename = $alt_user_nicename;
  	}
  	/*********/
  	
  	// Update the $_POST variable to eliminate endless loop 
  	$_POST['ws_plugin__s2member_profile_permalink'] = $user_nicename;
  	
  	// update the user_nicename
    wp_update_user( array( 'ID' => $user_id, 'user_nicename' => $user_nicename ) );
		
		// add action to update the S2Member permalink variable to the same
		// as the user_nicename
		add_action( 'ws_plugin__s2member_after_handle_profile_modifications', array(&$this, 'update_permalink') );

  }
  
  /**
   * update_permalink() function
   *
   * @called via ws_plugin__s2member_after_handle_profile_modifications action
   * 
   * @param $variable, stores all the information you could ever want... really
   *
   * @return void
   * @author Tanner Moushey
   **/
  function update_permalink( $variable ){
    $user_id = $variable['user_id'];
    $custom_fields = get_user_option( 's2member_custom_fields', $user_id );
		$custom_fields['permalink'] = $_POST['ws_plugin__s2member_profile_permalink'];
		
    update_user_option( $user_id, "s2member_custom_fields", $custom_fields );

  }
  
  /**
   * Function author_rewrite_setup()
   *
   * Builds an array of business types from each of the users, adds a rewrite tag for those
   * business types, and rewrites the author permalink structure. Flush the rewrite rules if
   * we are in the admin panel or a $_POST variable is detected.
   *
   * @author Tanner Moushey
   **/
	function author_rewrite_setup(){
	  
		global $wp_rewrite;
	  $i = 1;
	  $businessTypes = array();
	  
		foreach ( get_users() as $user ) {
			
			$businessType = get_user_meta($user->ID, 'business_type', true);
			
		  // Build array of business types to include in rewrite rule, don't include if type is empty
		  // or null or already in array
			if ( $businessType && !in_array($businessType, $businessTypes) && $businessType != 'null' ){
				$businessTypes[] = $businessType;
			}
						
		}
		
		// Add default tag for unspecified businesses
		array_push( $businessTypes, 'business' ); 
		
		// Create / update rewrite tage with business types
    add_rewrite_tag( '%business_type%', '(' . implode( '|', $businessTypes ) . ')' );
    
    // Rewrite author_base
		$wp_rewrite->author_base = 'profile/%business_type%';
    $wp_rewrite->author_structure = $wp_rewrite->author_base.'/%author%';

    // only flush rules if the $_POST or wp-admin is detected
    if ( $_POST || is_admin() ) $wp_rewrite->flush_rules();
		
  }
	
	/**
	 * Function change_author_permalinks()
	 *
	 * Replace %business_type% string in the $link variable with the authors business type.
	 * If the author does not have a pre-defined business type, return 'business';
	 *
	 * @param $link is the original author permalink string
	 * @param $author_id is the author id
	 * @param $author_nicename is the author's nicename which we manage in $this->update_user()
	 *
	 * @return updated $link with %business_type% replaced with authors business type
	 * @author Tanner Moushey
	 **/
	function change_author_permalinks( $link, $author_id, $author_nicename ) {

		$businessType = get_user_meta($author_id, 'business_type', true);
		
		// if there is a business type and it doesn't equal "null" set the tag to that business type,
		// otherwise set that tag to "business"
		if ( $businessType && $businessType != "null" ){
			$tag_rewrite = $businessType;
		} else{
			$tag_rewrite = 'business';
		}
		
		// Replace the %business_type% string in the author permalink with the $businessType defined
		// earlier.
		$link = str_replace ( '%business_type%', $tag_rewrite, $link );
		
		return $link;

	}
	
}

?>