<?php
/**
 * Plugin Name:     Ultimate Member - Limit Profile Visits
 * Description:     Extension to Ultimate Member to limit the subscribed user to certain amount of profile views.
 * Version:         1.4.0 
 * Requires PHP:    7.4
 * Author:          Miss Veronica
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI:      https://github.com/MissVeronica?tab=repositories
 * Text Domain:     ultimate-member
 * Domain Path:     /languages
 * UM version:      2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( 'UM' ) ) return;


class UM_Limit_Profile_Visits {

    public $limited_roles;
    public $limited_unpaid;
    public $suffix;
    public $products;
    public $local_date_fmt;

    public $allow_revisit = true;
    public $revisit_hours = false;
    public $revisit_counts = false;

    public function __construct() {

        if ( is_admin()) {

            register_activation_hook( __FILE__,       array( $this, 'create_plugin_database_table' ));

            add_filter( 'um_settings_structure',      array( $this, 'um_settings_structure_limit_custom_visit' ), 10, 1 );
            add_filter( 'manage_users_columns',       array( $this, 'manage_users_columns_limit_custom_visit' ));
            add_filter( 'manage_users_custom_column', array( $this, 'manage_users_custom_column_limit_custom_visit' ), 10, 3 );

        } else {

            $this->limited_roles = UM()->options()->get( 'um_limit_visit_role_paid' );
            $this->limited_unpaid = UM()->options()->get( 'um_limit_visit_role_unpaid' );
            $this->suffix = UM()->options()->get( 'um_limit_visit_role_suffix' );
            $this->products = explode( ',', UM()->options()->get( 'um_limit_visit_user_products' ) );
            $this->local_date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

            add_action( 'template_redirect', array( $this, 'main_limit_custom_visit_profile' ), 99999 );
        }
    }

    public function create_plugin_database_table() {

        global $wpdb;

        if ( $wpdb->get_var( "show tables like '{$wpdb->prefix}custom_visited_profiles'" ) != 
                                                 $wpdb->prefix . 'custom_visited_profiles' ) {

            if ( ! function_exists( 'dbDelta' ) ) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            if ( $wpdb->has_cap( 'collation' ) ) {
                $collate = $wpdb->get_charset_collate();
            } else $collate = '';

            $schema = "
            CREATE TABLE {$wpdb->prefix}custom_visited_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id integer,
                visited_user_id integer,
                visited_date datetime DEFAULT CURRENT_TIMESTAMP,
                primary key (id)
            ) $collate;";

            dbDelta( $schema );
        }
    }

    public function main_limit_custom_visit_profile() {

        $user_id = get_current_user_id();
        $role = get_role( UM()->roles()->get_priority_user_role( $user_id ) );        

        if( ! $this->limit_user_role( $user_id, $role )) return;

        if( um_is_core_page( "account" ) ) {
            $this->enable_account_status_tab();
        }

        if ( ! um_is_myprofile() && um_is_core_page( "user" ) ) {

            $visiting_user_id = um_get_requested_user();
            $limit = $this->count_orders_with_limit_attribute( $user_id );
            $revisits = $this->count_revisits( $user_id, $visiting_user_id );

            if ( $revisits == 0 ) { // new profile visit
                $this->user_redirect_or_continue( $user_id, $visiting_user_id, $limit, $role );
            }
        }
    }

    public function limit_user_role( $user_id, $role ) {

        if( empty( $user_id ) || empty( $role )) return false;

        $limit_user = true;

        if ( $this->limited_unpaid ) {

            if ( empty( $this->suffix )) {
                array_push( $this->limited_roles, UM()->options()->get( 'um_limit_visit_role_limit' ));

            } else {

                $downgrade = array();
                foreach ( $this->limited_roles as $gold_role ) {
                    array_push( $downgrade, $gold_role . $this->suffix );
                }

                $this->limited_roles = array_merge( $this->limited_roles, $downgrade );
            }
        }

        if ( ! in_array(  $role->name, $this->limited_roles )) $limit_user = false;
        if( empty( $this->products )) $limit_user = false;

        return $limit_user;
    }

    public function save_user_visit( $user_id, $visiting_user_id ) {

        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'custom_visited_profiles', 
                                            array( 'user_id'         => $user_id, 
                                                   'visited_user_id' => $visiting_user_id ) );

    }

    public function update_user_meta_cache( $user_id, $meta_key, $meta_value ) {

        if ( um_user( $meta_key ) != $meta_value ) {
            update_user_meta( $user_id, $meta_key, $meta_value );
            UM()->user()->remove_cache( $user_id );
            um_fetch_user( $user_id );
        }
    }

    public function user_redirect_or_continue( $user_id, $visiting_user_id, $limit, $role ) {

        $total_visited = (int)um_user( 'um_total_visited_profiles' );  // get_user_meta( $user_id, 'um_total_visited_profiles', true );

        if ( empty( $total_visited )) $total_visited = 0;
        $total_visited++;

        if ( $total_visited > $limit ) { // this visit will be past the limit (or time limit???)
            $this->limit_visit_user_redirect_exit( $user_id, $role );
        }

        $this->update_user_meta_cache( $user_id, 'um_total_visited_profiles', $total_visited );

        if ( $this->allow_revisit ) {
            $this->save_user_visit( $user_id, $visiting_user_id );
        } 
    }

    public function count_revisits( $user_id, $visiting_user_id ) {

        global $wpdb;

        if ( $this->allow_revisit ) {

            $select =  "SELECT count(*) AS views FROM {$wpdb->prefix}custom_visited_profiles 
                        WHERE user_id = %d  
                        AND visited_user_id = %d
                        AND visited_date >= %s";

            $date_limit = '2000-01-01 00:00:00';

            if ( $this->revisit_hours ) {
                $date_limit = date( 'Y-m-d H:i:s', time() - 3600 * $this->revisit_hours );
            }

            $revisits = $wpdb->get_results( $wpdb->prepare( $select, $user_id, $visiting_user_id, $date_limit ) );
            $revisits = (int)$revisits[0]->views;

            if ( $this->revisit_counts ) {

            }

        } else $revisits = 0;

        return $revisits;
    }

    public function limit_visit_user_redirect_exit( $user_id, $role ) {

        $redirect_limit = UM()->options()->get( 'um_limit_visit_user_redirect' );
        if ( empty( $redirect_limit )) {
            $redirect_limit = home_url();
        }

        $role_limit = false;

        if ( ! empty( $this->suffix )) {
            if ( ! strpos( $role->name, $this->suffix )) {
                $role_limit = $role->name . $this->suffix;

            } else {

                if ( $this->limited_unpaid ) {

                    if( ! empty( UM()->options()->get( 'um_limit_visit_downgrade_user_redirect' ))) {
                        $redirect_limit = UM()->options()->get( 'um_limit_visit_downgrade_user_redirect' );
                    } else {
                        $redirect_limit = um_user_profile_url();
                    }
                }
            }

        } else {
            
            $role_limit = UM()->options()->get( 'um_limit_visit_role_limit' );

            if ( $role->name == $role_limit && $this->limited_unpaid ) {

                if( ! empty( UM()->options()->get( 'um_limit_visit_downgrade_user_redirect' ))) {
                    $redirect_limit = UM()->options()->get( 'um_limit_visit_downgrade_user_redirect' );
                } else {
                    $redirect_limit = um_user_profile_url();
                }
            } 
        }

        if ( $role_limit && $role_limit != $role->name ) {

            if ( in_array( $role_limit, UM()->roles()->get_all_user_roles( $user_id ))) {
                UM()->roles()->remove_role( $user_id, $role->name );

            } else {

                UM()->roles()->set_role( $user_id, sanitize_key( $role_limit ));
            }

            UM()->user()->remove_cache( $user_id );
            um_fetch_user( $user_id );
        }

        wp_redirect( esc_url( $redirect_limit )); 
        exit;
    }

    public function count_orders_with_limit_attribute( $user_id ) {

        if ( function_exists( 'wc_get_order' )) {

            $customer_orders = get_posts( array(        // GET USER ORDERS (COMPLETED + PROCESSING)
                'numberposts' => -1,
                'meta_key'    => '_customer_user',
                'meta_value'  => $user_id,
                'post_type'   => wc_get_order_types(),
                'post_status' => array_keys( wc_get_is_paid_statuses() ),
            ) );

            if ( ! empty( $customer_orders ) && is_array( $customer_orders )) {

                $limit = 0;

                foreach ( $customer_orders as $customer_order ) {
                    $order = wc_get_order( $customer_order->ID );

                    foreach ( $order->get_items() as $item ) {
                        if ( in_array( $item->get_product_id(), $this->products ) ) {

                            $prod = new WC_Product( $item->get_product_id() );
                            if ( ! empty( $prod->get_attribute( 'um_view_profile_limit' ) ) && 
                                   is_numeric( $prod->get_attribute( 'um_view_profile_limit' ) )) {

                                $limit += (int)$item->get_quantity() * absint( $prod->get_attribute( 'um_view_profile_limit' ));
                            }
                        }
                    }
                }

                $this->update_user_meta_cache( $user_id, 'um_view_profile_limit', $limit );

                return $limit;
            }
        }

        return (int)um_user( 'um_view_profile_limit' );
    }

    public function enable_account_status_tab() {

        $total_visited = (int) um_user( 'um_total_visited_profiles' );  
            
        if ( ! empty( $total_visited ) && $total_visited > 0 ) {

            add_filter( 'um_account_content_hook_limit_custom_visit', array( $this, 'um_account_content_hook_limit_custom_visit' ));
            add_filter( 'um_account_page_default_tabs_hook',          array( $this, 'um_limit_custom_visit_account' ), 100 );
        }
    }

    public function um_limit_custom_visit_account( $tabs ) {

        $tabs[500]['limit_custom_visit'] = array( 
                                        'icon'        => 'um-faicon-users',
                                        'title'       => __( 'Status my profile visits', 'ultimate-member' ),
                                        'custom'      => true,
                                        'show_button' => false,
                                        );

        return $tabs;
    }

    public function um_account_content_hook_limit_custom_visit( $output ) {

        global $current_user;
        global $wpdb;

        if ( ! function_exists( 'wc_get_orders' )) {
            return '<div class="um-field">' . __( 'WooCommerce not active.', 'ultimate-member' ) . '</div>';
        }

        if ( empty( $this->products )) {
            return '<div class="um-field">' . __( 'No WooCommerce limit product IDs defined.', 'ultimate-member' ) . '</div>';
        }

        $limit = $this->count_orders_with_limit_attribute( $current_user->ID );

        $output .= '<div class="um-field">
                    <div>' . sprintf( __( 'Total visits %d and the current limit is %d visits', 'ultimate-member' ), 
                                            um_user( 'um_total_visited_profiles' ), 
                                            um_user( 'um_view_profile_limit' )) . '</div>                           
                    <div>' . sprintf( __( 'Current User Role is %s', 'ultimate-member' ), UM()->roles()->get_role_name( um_user( 'role' ) )) . '</div>';

        $customer_orders = wc_get_orders( array( 'customer_id' => $current_user->ID,
                                                 'limit'       => 10,
                                                 'orderby'     => 'date',
                                                 'order'       => 'DESC',
                                                 'status'      => array( 'wc-processing', 'wc-completed' ),
                                                 'return'      => 'ids' ) ); 
   
        $output .= '<h4>' .  __( 'Order history', 'ultimate-member' ) . '</h4>';

        if ( ! empty( $customer_orders )) {            
            $output .= '<div style="display: table; width: 98%;">
                            <div style="display: table-row; width: 100%;">
                                <div style="display: table-cell;  padding-right: 5px;">' . __( 'Date', 'ultimate-member' ) . '</div>
                                <div style="display: table-cell;  text-align: center;">' . __( 'Order', 'ultimate-member' ) . '</div>
                                <div style="display: table-cell;  text-align: center;">' . __( 'Quantity', 'ultimate-member' ) . '</div>
                                <div style="display: table-cell;  text-align: center;">' . __( 'Limit', 'ultimate-member' ) . '</div>
                                <div style="display: table-cell;  text-align: left;">' . __( 'Product', 'ultimate-member' ) . '</div> 
                            </div>';

            foreach ( $customer_orders as $customer_order ) {

                $order = new WC_Order( $customer_order );
                foreach ( $order->get_items() as $item ) {

                    if ( in_array( $item->get_product_id(), $this->products ) ) {

                        $prod = new WC_Product( $item->get_product_id() );
                        if ( ! empty( $prod->get_attribute( 'um_view_profile_limit' ) ) && 
                               is_numeric( $prod->get_attribute( 'um_view_profile_limit' ) )) {

                            $limit = absint( $prod->get_attribute( 'um_view_profile_limit' ));

                            if ( ! empty( $order->get_date_completed() )) $myDateTime = new DateTime( $order->get_date_completed());
                            else $myDateTime = new DateTime( $order->get_date_created());
    
                            $time_ago = sprintf( __( '%s ago', 'ultimate-member' ), human_time_diff( $myDateTime->getTimestamp(), time()) );

                            $output .= '<div style="display: table-row; width: 100%;">
                                        <div style="display: table-cell;  text-align: left;" title="' . $time_ago . '">' . esc_attr( $myDateTime->format( $this->local_date_fmt )) . '</div>
                                        <div style="display: table-cell;  text-align: center;">' . esc_attr( $customer_order ) . '</div>
                                        <div style="display: table-cell;  text-align: center;">' . esc_attr( $item->get_quantity()) . '</div>
                                        <div style="display: table-cell;  text-align: center;">' . esc_attr( $limit ) . '</div>
                                        <div style="display: table-cell;  text-align: left;" class="um-field-value"><a href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) )) . '" target="shop">' . esc_attr( $item->get_name()) . '</a></div>
                                        </div>';
                        }
                    }
                }
            }

            $output .= '</div>';

        } else $output .= '<div>' . __( 'No orders found', 'ultimate-member' ) . '</div>';

        $visits = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}custom_visited_profiles 
                                                                        WHERE user_id = %d  
                                                                        ORDER BY visited_date DESC LIMIT %d", $current_user->ID, 12 ) );

        $output .= '<h4>' .  __( 'Visit history', 'ultimate-member' ) . '</h4>';

        if ( ! empty( $visits )) {            
            $output .= '<div style="display: table; width: 100%;">
                            <style>img.hover_img:hover,img.hover_img:focus {width: 120px; height: 120px;}</style>
                            <div style="display: table-row; width: 100%;">
                                <div style="display: table-cell; text-align:left;">' . __( 'Date', 'ultimate-member' ) . '</div>
                                <div style="display: table-cell; text-align:left; padding-left:10px;">' . __( 'Visited', 'ultimate-member' ) . '</div>
                                <div style="display: table-cell; text-align:left; width:120px">' . __( 'Photo', 'ultimate-member' ) . '</div>
                            </div>';

            foreach ( $visits as $visit ) {
                um_fetch_user( $visit->visited_user_id );
                $time_ago = sprintf( __( '%s ago', 'ultimate-member' ), human_time_diff( strtotime( $visit->visited_date ), current_time( 'timestamp' )) );

                if ( ! empty( um_profile( 'profile_photo' ))) {
                    $profile_photo = '<a href="' . esc_url( um_user_profile_url() ) . '" target="profile">
                                        <img class="hover_img" src="' . esc_url( UM()->uploader()->get_upload_base_url() . um_user( 'ID' ) . "/" . um_profile( 'profile_photo' )) . '" width="40px" heght="40px">
                                      </a>';
                } else {

                    $profile_photo = '';
                }

                $output .= '<div style="display: table-row; width: 100%;">
                                <div style="display:table-cell; text-align:left;" title="' . $time_ago . '">' . esc_attr( date_i18n( $this->local_date_fmt, strtotime( $visit->visited_date ) ) ) . '</div>
                                <div style="display:table-cell; text-align:left; padding-left:10px;" class="um-field-value">
                                    <a href="' . esc_url( um_user_profile_url() ) . '" target="profile">' . esc_attr( um_user( 'display_name' ) ) . '</a>
                                </div>
                                <div style="display:table-cell; text-align:left; width:120px">' . $profile_photo . '</div>
                            </div>';
            }

            $output .= '</div>';

        } else  $output .= '<div>' . __( 'No visits found', 'ultimate-member' ) . '</div>';

        $output .= '</div>';

        return $output;
    }

    public function um_settings_structure_limit_custom_visit( $settings_structure ) {

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_user_products',
                'type'          => 'text',
                'label'         => __( 'Limit Profile Visits - WooCommerce Product IDs', 'ultimate-member' ),
                'size'          => 'medium',
                'tooltip'       => __( 'Comma separated WooCommerce product IDs where there is an attribute with name "um_view_profile_limit" and its value is an integer number of visits allowed.', 'ultimate-member' )
                );

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_role_paid',
                'type'          => 'select',
                'multi'         => true,
                'options'       => UM()->roles()->get_roles(),
                'label'         => __( 'Limit Profile Visits - Paid User Role', 'ultimate-member' ),
                'size'          => 'small',
                'tooltip'       => __( 'Paid User Role when profile is active after purchase.', 'ultimate-member' )
                );

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_user_redirect',
                'type'          => 'text',
                'label'         => __( 'Limit Profile Visits - Redirect URL', 'ultimate-member' ),
                'size'          => 'medium',
                'tooltip'       => __( 'Redirect to URL or /page when profile visits equals user limit (empty field = Homepage).', 'ultimate-member' )
                );

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_role_limit',
                'type'          => 'select',
                'options'       => UM()->roles()->get_roles(),
                'label'         => __( 'Limit Profile Visits - Downgrade to single Role', 'ultimate-member' ),
                'size'          => 'small',
                'tooltip'       => __( 'Downgrade to this Role when profile visits equals user limit.', 'ultimate-member' )
                );

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_role_suffix',
                'type'          => 'text',
                'label'         => __( 'Limit Profile Visits - Downgrade Role Suffix', 'ultimate-member' ),
                'size'          => 'medium',
                'tooltip'       => __( 'Downgrade to the Role ID with this suffix to the Paid Role when profile visits equals user limit. Empty field use single Role selection.', 'ultimate-member' )
                );

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_role_unpaid',
                'type'          => 'checkbox',
                'label'         => __( 'Limit Profile Visits - Downgraded Role Allow Access', 'ultimate-member' ),
                'size'          => 'medium',
                'tooltip'       => __( 'Downgraded Role allow access to already paid views.', 'ultimate-member' )
                );

        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_downgrade_user_redirect',
                'type'          => 'text',
                'label'         => __( 'Limit Profile Visits - Redirect URL for downgraded user', 'ultimate-member' ),
                'size'          => 'medium',
                'tooltip'       => __( 'Redirect to URL or /page when downgraded profile visits unpaid profiles (empty field = User own Profile Page).', 'ultimate-member' )
                );

/*
        $settings_structure['access']['sections']['other']['fields'][] = array(
                'id'            => 'um_limit_visit_user_revisit',
                'type'          => 'checkbox',
                'label'         => __( 'Limit Profile Visits - Revisits are not allowed', 'ultimate-member' ),
                'tooltip'       => __( 'Click if not allowed to revisit profiles', 'ultimate-member' )
                );
*/
        return $settings_structure;
    }

    public function manage_users_columns_limit_custom_visit( $columns ) {

        $columns['um_total_visited_profiles'] = __( 'Views', 'ultimate-member' );
        $columns['um_view_profile_limit'] = __( 'Limit', 'ultimate-member' );

        return $columns;
    }

    public function manage_users_custom_column_limit_custom_visit( $value, $column_name, $user_id ) {

        if ( $column_name == 'um_total_visited_profiles' ) {

            um_fetch_user( $user_id );
            $value = um_user( 'um_total_visited_profiles' );    
            if( empty( $value )) {
                $value = __( 'Never', 'ultimate-member' );
            } 
        }

        if ( $column_name == 'um_view_profile_limit' ) {

            um_fetch_user( $user_id );
            $value = um_user( 'um_view_profile_limit' );
            if( empty( $value )) {
                $value = '-';
            } 
        }

        return $value;
    }

}

new UM_Limit_Profile_Visits();
