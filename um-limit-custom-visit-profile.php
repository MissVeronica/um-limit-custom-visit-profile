<?php
/**
 * Plugin Name:     Ultimate Member - Limit Profile Visits
 * Description:     Extension to Ultimate Member to limit the subscribed user to certain amount of profile views.
 * Version:         0.10.0 
 * Requires PHP:    7.4
 * Author:          Miss Veronica
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI:      https://github.com/MissVeronica
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

    public function __construct() {

        if ( is_admin()) {

            register_activation_hook( __FILE__,       array( $this, 'create_plugin_database_table' ));

            add_filter( 'um_settings_structure',      array( $this, 'um_settings_structure_limit_custom_visit' ), 10, 2 );
            add_filter( 'manage_users_columns',       array( $this, 'manage_users_columns_limit_custom_visit' ));
            add_filter( 'manage_users_custom_column', array( $this, 'manage_users_custom_column_limit_custom_visit' ), 10, 3 );

        } else {

            $this->limited_roles = UM()->options()->get( 'um_limit_visit_role_paid' );
            $this->limited_unpaid = UM()->options()->get( 'um_limit_visit_role_unpaid' );
            $this->suffix = UM()->options()->get( 'um_limit_visit_role_suffix' );
            $this->products = explode( ',', UM()->options()->get( 'um_limit_visit_user_products' ) );

            add_action( 'template_redirect', array( $this, 'um_limit_custom_visit_profile' ), 99999 );
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

    public function um_limit_custom_visit_profile() {

        $user_id = get_current_user_id();
        $role = get_role( UM()->roles()->get_priority_user_role( $user_id) );

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

        if ( ! in_array(  $role->name, $this->limited_roles )) return;

        if( empty( $this->products )) return;

        add_filter( 'um_account_content_hook_limit_custom_visit', array( $this, 'um_account_content_hook_limit_custom_visit' ));
        add_filter( 'um_account_page_default_tabs_hook',          array( $this, 'um_limit_custom_visit_account' ), 100 );

        if ( ! um_is_myprofile() && um_is_core_page( "user" ) ) {

            global $wpdb;

            $visiting_user_id = um_get_requested_user();
            
            $allow_revisit = true;
            $revisit_hours = false;
            $revisit_counts = false;

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
                                if ( is_numeric( $prod->get_attribute( 'um_view_profile_limit' ) )) {
                                    $limit += absint( $prod->get_attribute( 'um_view_profile_limit' ));
                                }
                            }
                        }
                    }
                }

                if ( um_user( 'um_view_profile_limit' ) != $limit ) {

                    update_user_meta( $user_id, 'um_view_profile_limit', $limit );
                    UM()->user()->remove_cache( $user_id );
                    um_fetch_user( $user_id );
                }

            } else {

                $limit = um_user( 'um_view_profile_limit' );
            }

            if ( $allow_revisit ) {

                $select =  "SELECT count(*) AS revisits FROM {$wpdb->prefix}custom_visited_profiles 
                            WHERE user_id = %d  
                            AND visited_user_id = %d
                            AND visited_date >= %s";

                $date_limit = '2000-01-01 00:00:00';

                if ( $revisit_hours ) {
                    $date_limit = date( 'Y-m-d H:i:s', time() - 3600*$revisit_hours );
                }

                $revisits = $wpdb->get_results( $wpdb->prepare( $select, $user_id, $visiting_user_id, $date_limit ) );
                $revisits = (int)$revisits[0]->revisits;

                if ( $revisit_counts ) {
                    //do { $revisits = $revisits - $revisit_counts; } while ( $revisits >= 0 );
                }

            } else $revisits = 0;

            if ( $revisits == 0 ) { // new profile visit

                $total_visited = (int)um_user( 'um_total_visited_profiles' );  // get_user_meta( $user_id, 'um_total_visited_profiles', true );

                if ( empty( $total_visited )) $total_visited = 0;
                $total_visited++;

                if ( $total_visited > $limit ) { // this visit will be past the limit

                    $redirect_limit = UM()->options()->get( 'um_limit_visit_user_redirect' );
                    if ( empty( $redirect_limit )) {
                        $redirect_limit = home_url();
                    }

                    $role = get_role( UM()->roles()->get_priority_user_role( $user_id) );
                    $role_limit = false;

                    if ( ! empty( $this->suffix )) {
                        if ( ! strpos( $role->name, $this->suffix )) {
                            $role_limit = $role->name . $this->suffix;

                        } else {

                            if( $this->limited_unpaid ) {
                                $redirect_limit = um_user_profile_url();
                            }
                        }
 
                    } else {
                        
                        $role_limit = UM()->options()->get( 'um_limit_visit_role_limit' );

                        if( $role->name == $role_limit && $this->limited_unpaid ) {
                            $redirect_limit = um_user_profile_url();
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

                update_user_meta( $user_id, 'um_total_visited_profiles', $total_visited );
                UM()->user()->remove_cache( $user_id );
                um_fetch_user( $user_id );

                if ( $allow_revisit ) {
                    $wpdb->insert( $wpdb->prefix . 'custom_visited_profiles', 
                                                        array( 'user_id'         => $user_id, 
                                                               'visited_user_id' => $visiting_user_id ) );
                } 
            }
        }
    }

    public function um_limit_custom_visit_account( $tabs ) {

        $total_visited = (int) um_user( 'um_total_visited_profiles' );  //get_user_meta( get_current_user_id(), 'um_total_visited_profiles', true );
        if ( ! empty( $total_visited )) {

            $tabs[800]['limit_custom_visit']['icon']        = 'um-faicon-pencil';
            $tabs[800]['limit_custom_visit']['title']       = 'Status my profile visits';
            $tabs[800]['limit_custom_visit']['custom']      = true;
            $tabs[800]['limit_custom_visit']['show_button'] = false;
        }

        return $tabs;
    }

    public function um_account_content_hook_limit_custom_visit( $output ) {

        global $current_user;
        global $woocommerce;
        global $wpdb;

        if ( ! function_exists( 'wc_get_orders' )) {
            return '<div class="um-field">WooCommerce not active.</div>';
        }

        if( empty( $this->products )) {
            return '<div class="um-field">No WooCommerce limit product IDs defined.</div>';
        }

        $output .= '<div class="um-field">
                    <div>Total visits ' . esc_attr( um_user( 'um_total_visited_profiles' )) . '
                         Visits limit ' . esc_attr( um_user( 'um_view_profile_limit' )) . '</div>
                    <div>User Role ' . esc_attr( UM()->roles()->get_role_name( um_user( 'role' ) )) . '</div>';

        $customer_orders = wc_get_orders( array( 'customer_id' => $current_user->ID,
                                                 'limit'       => 10,
                                                 'orderby'     => 'date',
                                                 'order'       => 'DESC',
                                                 'status'      => array( 'wc-processing', 'wc-completed'),
                                                 'return'      => 'ids' ) );    

        if ( ! empty( $customer_orders )) {

            $output .= '<h4>Order history</h4>
                        <div style="display: table; width: 98%;">
                            <div style="display: table-row; width: 100%;">
                                <div style="display: table-cell;  padding-right: 5px;" title="order date">Date</div>
                                <div style="display: table-cell;  text-align: center;" title="order number">Order</div>
                                <div style="display: table-cell;  text-align: center;" title="quantity">Quantity</div>
                                <div style="display: table-cell;  text-align: center;" title="visits limit">Limit</div>
                                <div style="display: table-cell;  text-align: left;"   title="product name">Product</div> 
                            </div>';

            foreach ( $customer_orders as $customer_order ) {

                $order = new WC_Order( $customer_order );
                foreach ( $order->get_items() as $item ) {

                    if ( in_array( $item->get_product_id(), $this->products ) ) {
                        if ( ! empty( $order->get_date_completed() )) $myDateTime = new DateTime( $order->get_date_completed());
                        else $myDateTime = new DateTime( $order->get_date_created());

                        $prod = new WC_Product( $item->get_product_id() );
                        $limit = (int)$item->get_quantity() * absint( $prod->get_attribute( 'um_view_profile_limit' ));

                        $output .= '<div style="display: table-row; width: 100%;">
                                    <div style="display: table-cell;  text-align: left;"   title="order date">' . esc_attr( $myDateTime->format( 'Y-m-d H:i' )) . '</div>
                                    <div style="display: table-cell;  text-align: center;" title="order number">' . esc_attr( $customer_order ) . '</div>
                                    <div style="display: table-cell;  text-align: center;" title="quantity">' . esc_attr( $item->get_quantity()) . '</div>
                                    <div style="display: table-cell;  text-align: center;" title="visit limit">' . esc_attr( $limit ) . '</div>
                                    <div style="display: table-cell;  text-align: left;"   title="product link"><a href="' . get_permalink( wc_get_page_id( 'shop' ) ) . '" target="_blank">' . esc_attr( $item->get_name()) . '</a></div>
                                    </div>';
                    }
                }
            }

            $output .= '</div>';

        } else $output .= '<div>No orders found</div>';

        $visits = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}custom_visited_profiles 
                                                                        WHERE user_id = %d  
                                                                        ORDER BY visited_date DESC LIMIT 12", $current_user->ID ) );
        $output .= '<h4>Visit history</h4>';

        if ( ! empty( $visits )) {
            
            $output .= '<div style="display: table; width: 90%;">
                            <style>img.hoverimg:hover,img.hoverimg:focus {width: 120px; height: 120px;}</style>';

            foreach ( $visits as $visit ) {

                um_fetch_user( $visit->visited_user_id );

                if( !empty( um_profile( 'profile_photo' ))) {

                    $profile_photo = '<a href="' . esc_url( um_user_profile_url() ) . '" target="_blank">
                                      <img class="hoverimg" src="' . UM()->uploader()->get_upload_base_url() . um_user( 'ID' ) . "/" . um_profile( 'profile_photo' ) . '" width="40" heght="40">
                                      </a>';
                } else {

                    $profile_photo = '';
                }

                $output .= '<div style="display: table-row; width: 100%;">
                                <div style="display: table-cell; text-align: left;" title="date">' . esc_attr( $visit->visited_date ) . '</div>
                                <div style="display: table-cell; text-align: left; padding-left: 10px;" title="display name">
                                <a href="' . esc_url( um_user_profile_url() ) . '" target="_blank">' . esc_attr( um_user( 'display_name' ) ) . '</a></div>
                                <div style="display: table-cell;width:120px">' . $profile_photo . '</div>
                            </div>';
            }

            $output .= '</div>';

        } else  $output .= '<div>No visits found</div>';

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
                $value = 'Never';
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
