<?php

//Plugin Name: ATD Reporting Tools
//Plugin URI:  
//Description: This describes my plugin in a short sentence
//Version:     0.1
//Author:      Eric Berkow
//Author URI:  
//License:     GPL2
//License URI: 
//Domain Path: /languages


/* Log the unenroll fired from updating the WP post.
 * LD saves each GROUP as a post, so enrolling or
 * unenrolling a user in a GROUP, we need to log that action.
 * We should probably try to keep complete enroll/unenroll
 * data for each user.
 * 
 * @param int $user_id = the user to log the group change
 * @param string $mvalue = meta key 'learndash_group_users_'
 * @param int $metaid = ID of the recored being deleted in the user_meta table
 */

global $atd_enroll_log_version;
$atd_enroll_log_version = '0.1';

//generate DB table to store the data we need
function install_atd_enroll_table() {
    global $wpdb;
    global $atd_enroll_log_version;

    $table_name = $wpdb->prefix . 'atd_enroll_data';
    $char_collate = $wpdb->get_charset_collate();
    $generate_sql = " CREATE TABLE `$table_name` (`enroll_id` int(11) NOT NULL AUTO_INCREMENT,"
            . " `user_id` int(11) DEFAULT NULL,"
            . " `course_name` varchar(250) DEFAULT NULL,"
            . "`event_type` varchar(250) DEFAULT NULL,"
            . "`created_date` int(11) DEFAULT NULL,"
            . "`enroll_from_group` int(11) DEFAULT NULL,"
            . "`enrolled_from_group` varchar(250) DEFAULT NULL,"
            . "PRIMARY KEY (`enroll_id`))  $char_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($generate_sql);
    add_option('atd_enroll_log_version', $atd_enroll_log_version);
}

register_activation_hook(__FILE__, 'install_atd_enroll_table');

/* function to convert dates to MST
 * @param string $refined_date = SQL compliant date/time string  
 */

function date_denver() {
    $now = new DateTime;
    $atd_timezone = new DateTimeZone('America/Denver');
    $now->setTimezone($atd_timezone);
    $refined_date = $now->format('Y-m-d H:i:s');
    return $refined_date;
}

function at_get_ld_cname($course_id) {
    $course = get_post($course_id);
    $name = $course->post_title;
    return $name;
}

/* action added to add_user_meta
 * @param string $type= type of course enrollment
 * returns false if neither needed string is being added to meta data
 */

function at_check_course_enroll($user_id, $metakey, $metavalue) {
    $group = 'learndash_group_users_*';
    $user = 'course_*_access_from';

    $enroll_data['user'] = $user_id;
    if (fnmatch($user, $metakey)) {
        $enroll_data['event_type'] = 'indiv_course_enroll';
        $enroll_data['courses'][] = at_course_group_name($metakey, 1);
    } elseif (fnmatch($group, $metakey)) {
        $enroll_data['event_type'] = 'group_enroll';
        $enroll_data['group_name'] = at_course_group_name($metakey, 3);
        $group_courses = learndash_group_enrolled_courses($metavalue);
        foreach ($group_courses as $g) {
            $enroll_data['courses'][] = at_get_ld_cname($g);
        }
    }
    error_log('enroll data=' . print_r($enroll_data, TRUE));

    at_log_data($enroll_data);
}

add_action('add_user_meta', 'at_check_course_enroll', 10, 4);

function at_check_course_unenroll($metaid, $user_id, $metakey, $metaval) {
    $type = false;
    $group = 'learndash_group_users_*';
    $user = 'course_*_access_from';
    $unenroll_data['user'] = $user_id;
    if (fnmatch($user, $metakey)) {
        $unenroll_data['event_type'] = 'indiv_course_unenroll';
        $unenroll_data['courses'][] = at_course_group_name($metakey, 1);
    } elseif (fnmatch($group, $metakey)) {
        $unenroll_data['event_type'] = 'group_unenroll';
        $unenroll_data['group_name'] = at_course_group_name($metakey, 3);
        $gid = at_split_ld_group($metakey, 3);
        $group_courses = learndash_group_enrolled_courses($gid);
        foreach ($group_courses as $c) {
            $unenroll_data['courses'][] = at_get_ld_cname($c);
        }
    }
    at_log_data($unenroll_data);
    error_log('unenroll data=' . print_r($unenroll_data, TRUE));
}

add_action('delete_user_meta', 'at_check_course_unenroll', 10, 4);

function at_split_ld_group($ld_group_metakey, $index) {
    //index = 3 for groups, 1 for user
    $break = explode('_', $ld_group_metakey);
    $group_id = $break[$index];
    return $group_id;
}

function at_course_group_name($metakey, $index) {
    $break = explode('_', $metakey);
    $id = $break[$index];
    $posts = get_post($id);
    $name = $posts->post_title;
    return $name;
}

function group_course_enroll($a, $group_id) {
    //check that this is not an autosave, and that the post update is coming from LD
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
//    if (!wp_verify_nonce($_POST['learndash_groups_nonce'])) {
//        return;
//    }
    
    
if (isset($_POST['learndash_group_enroll_course'])) {
        $pd = $_POST;
        $group_id = $pd['post_ID'];
        $group_name = $pd['post_title'];
        $enrolled_course[] = $pd['learndash_group_enroll_course'];
        foreach($enrolled_course as $e){
            $course = get_post($e);
            $cname = $course->post_title;
            error_log('course name ', $cname);
        }
        error_log('group id '.$group_id);
        error_log('group name '.$group_name);
        error_log('enrolled_course '.print_r($enrolled_course, TRUE));
    }
}

add_action('update_post_meta', 'group_course_enroll', 10, 2);

function at_log_data($data) {
    global $wpdb;
    $table = $wpdb->prefix . 'atd_enroll_data';
    $courses = $data['courses'];
    $new_entry = array();
    foreach ($courses as $c) {
        $new_entry['user_id'] = $data['user'];
        $new_entry['event_type'] = $data['event_type'];
        $new_entry['created_date'] = date_denver();
        $new_entry['course_name'] = $c;

        if (isset($data['group_name'])) {
            $new_entry['enroll_from_group'] = 1;
            $new_entry['enrolled_from_group'] = $data['group_name'];
        }
        $wpdb->insert($table, $new_entry);
    }
}
