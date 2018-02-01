<?php
/**
 * @package PublishPress
 * @author PublishPress
 *
 * Copyright (c) 2018 PublishPress
 *
 * ------------------------------------------------------------------------------
 * Based on Edit Flow
 * Author: Daniel Bachhuber, Scott Bressler, Mohammad Jangda, Automattic, and
 * others
 * Copyright (c) 2009-2016 Mohammad Jangda, Daniel Bachhuber, et al.
 * ------------------------------------------------------------------------------
 *
 * This file is part of PublishPress
 *
 * PublishPress is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PublishPress is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PublishPress.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * class PP_Content_Overview
 * This class displays a budgeting system for an editorial desk's publishing workflow.
 *
 * @author sbressler
 */
class PP_Content_Overview extends PP_Module
{
    /**
     * [$taxonomy_used description]
     * @var string
     */
    public $taxonomy_used = 'category';

    /**
     * [$module description]
     * @var [type]
     */
    public $module;

    /**
     * [$num_columns description]
     * @var integer
     */
    public $num_columns = 0;

    /**
     * [$max_num_columns description]
     * @var [type]
     */
    public $max_num_columns;

    /**
     * [$no_matching_posts description]
     * @var boolean
     */
    public $no_matching_posts = true;

    /**
     * [$terms description]
     * @var array
     */
    public $terms = array();

    /**
     * [$user_filters description]
     * @var [type]
     */
    public $user_filters;

    /**
     * Screen id
     */
    const SCREEN_ID = 'dashboard_page_content-overview';

    /**
     * Usermeta key prefix
     */
    const USERMETA_KEY_PREFIX = 'PP_Content_Overview_';

    /**
     * Default number of columns
     */
    const DEFAULT_NUM_COLUMNS = 1;

    /**
     * Register the module with PublishPress but don't do anything else
     */
    public function __construct() {
        $this->module_url = $this->get_module_url( __FILE__ );

        // Register the module with PublishPress
        $args = array(
            'title'                => __( 'Content Overview', 'publishpress' ),
            'short_description'    => __( 'Click here for a single screen that shows the publication status of all your content.', 'publishpress' ),
            'extended_description' => __( 'Use the content overview to see how content on your site is progressing. Filter by specific categories or date ranges to see details about each post in progress.', 'publishpress' ),
            'module_url'           => $this->module_url,
            'icon_class'           => 'dashicons dashicons-list-view',
            'slug'                 => 'content-overview',
            'default_options'      => array(
                'enabled' => 'on',
            ),
            'configure_page_cb' => false,
            'autoload'          => false,
            'add_menu'          => true,
            'page_link'         => admin_url( 'admin.php?page=content-overview' ),
        );

        $this->module = PublishPress()->register_module( 'content_overview', $args );
    }

    /**
     * Initialize the rest of the stuff in the class if the module is active
     */
    public function init() {
        $view_content_overview_cap = apply_filters( 'pp_view_content_overview_cap', 'pp_view_content_overview' );
        if ( ! current_user_can( $view_content_overview_cap ) ) {
            return;
        }

        $this->num_columns     = $this->get_num_columns();
        $this->max_num_columns = apply_filters( 'PP_Content_Overview_max_num_columns', 3 );

        // Filter to allow users to pick a taxonomy other than 'category' for sorting their posts
        $this->taxonomy_used = apply_filters( 'PP_Content_Overview_taxonomy_used', $this->taxonomy_used );

        add_action( 'admin_init', array( $this, 'handle_form_date_range_change' ) );

        include_once PUBLISHPRESS_ROOT . '/common/php/' . 'screen-options.php';

        if ( function_exists( 'add_screen_options_panel' ) ) {
            add_screen_options_panel(
                self::USERMETA_KEY_PREFIX . 'screen_columns',
                __( 'Screen Layout', 'publishpress' ),
                array( $this, 'print_column_prefs' ),
                self::SCREEN_ID,
                array( $this, 'save_column_prefs' ),
                true
            );
        }

        // Register the columns of data appearing on every term. This is hooked into admin_init
        // so other PublishPress modules can register their filters if needed
        add_action( 'admin_init', array( $this, 'register_term_columns' ) );

        add_action( 'publishpress_admin_menu', array( $this, 'action_admin_menu' ) );

        // Load necessary scripts and stylesheets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'action_enqueue_admin_styles' ) );
    }

    /**
     * Give users the appropriate permissions to view the content overview the first time the module is loaded
     *
     * @since 0.7
     */
    public function install() {

    }

    /**
     * Upgrade our data in case we need to
     *
     * @since 0.7
     */
    public function upgrade($previous_version) {
        global $publishpress;

        // Upgrade path to v0.7
        if ( version_compare( $previous_version, '0.7', '<' ) ) {
            // Migrate whether the content overview was enabled or not and clean up old option
            if ( $enabled = get_option( 'publishpress_content_overview_enabled' ) ) {
                $enabled = 'on';
            } else {
                $enabled = 'off';
            }
            $publishpress->update_module_option( $this->module->name, 'enabled', $enabled );
            delete_option( 'publishpress_content_overview_enabled' );

            // Technically we've run this code before so we don't want to auto-install new data
            $publishpress->update_module_option( $this->module->name, 'loaded_once', true );
        }
    }

    /**
     * Include the content overview link in the admin menu.
     *
     * @uses add_submenu_page()
     */
    public function action_admin_menu() {
        add_submenu_page(
                'pp-calendar',
                esc_html__( 'Content Overview', 'publishpress' ),
                esc_html__( 'Content Overview', 'publishpress' ),
                apply_filters( 'pp_view_content_overview_cap', 'pp_view_calendar' ),
                'pp-content-overview',
                array( $this, 'render_admin_page' )
            );
    }

    /**
     * Enqueue necessary admin scripts only on the content overview page.
     *
     * @uses enqueue_admin_script()
     */
    public function enqueue_admin_scripts() {
        global $pagenow;

        // Only load calendar styles on the calendar page
        if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && $_GET['page'] === 'pp-content-overview' ) {
            $num_columns = $this->get_num_columns();
            echo '<script type="text/javascript"> var PP_Content_Overview_number_of_columns="' . esc_js( $this->num_columns ) . '";</script>';

            $this->enqueue_datepicker_resources();
            wp_enqueue_script( 'publishpress-content_overview', $this->module_url . 'lib/content-overview.js', array( 'publishpress-date_picker' ), PUBLISHPRESS_VERSION, true );
        }
    }

    /**
     * Enqueue a screen and print stylesheet for the content overview.
     */
    public function action_enqueue_admin_styles() {
        global $pagenow;

        // Only load calendar styles on the calendar page
        if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && $_GET['page'] === 'pp-content-overview' ) {
            wp_enqueue_style( 'pp-admin-css', PUBLISHPRESS_URL . 'common/css/publishpress-admin.css', false, PUBLISHPRESS_VERSION, 'screen' );
            wp_enqueue_style( 'publishpress-content_overview-styles', $this->module_url . 'lib/content-overview.css', false, PUBLISHPRESS_VERSION, 'screen' );
            wp_enqueue_style( 'publishpress-content_overview-print-styles', $this->module_url . 'lib/content-overview-print.css', false, PUBLISHPRESS_VERSION, 'print' );
        }
    }

    /**
     * Register the columns of information that appear for each term module.
     * Modeled after how WP_List_Table works, but focused on hooks instead of OOP extending
     *
     * @since 0.7
     */
    public function register_term_columns() {
        $term_columns = array(
            'title'         => __( 'Title', 'publishpress' ),
            'status'        => __( 'Status', 'publishpress' ),
            'author'        => __( 'Author', 'publishpress' ),
            'post_date'     => __( 'Post Date', 'publishpress' ),
            'post_modified' => __( 'Last Modified', 'publishpress' ),
        );

        $term_columns       = apply_filters( 'PP_Content_Overview_term_columns', $term_columns );
        $this->term_columns = $term_columns;
    }

    /**
     * Handle a form submission to change the user's date range on the budget
     *
     * @since 0.7
     */
    public function handle_form_date_range_change() {
        if (
            ! isset(
                $_POST['pp-content-overview-range-submit'],
                $_POST['pp-content-overview-number-days'],
                $_POST['pp-content-overview-start-date']
            )
        ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['nonce'], 'change-date' ) ) {
            wp_die( $this->module->messages['nonce-failed'] );
        }

        $current_user                = wp_get_current_user();
        $user_filters                = $this->get_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', true );
        $user_filters['start_date']  = date( 'Y-m-d', strtotime( $_POST['pp-content-overview-start-date'] ) );
        $user_filters['number_days'] = (int) $_POST['pp-content-overview-number-days'];

        if ( $user_filters['number_days'] <= 1 ) {
            $user_filters['number_days'] = 1;
        }

        $this->update_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', $user_filters );
        wp_redirect( menu_page_url( 'pp-content-overview', false ) );

        exit;
    }

    /**
     * Get the number of columns to show on the content overview
     */
    public function get_num_columns() {
        if ( empty( $this->num_columns ) ) {
            $current_user      = wp_get_current_user();
            $this->num_columns = $this->get_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'screen_columns', true );
            // If usermeta didn't have a value already, use a default value and insert into DB
            if ( empty( $this->num_columns ) ) {
                $this->num_columns = self::DEFAULT_NUM_COLUMNS;
                $this->save_column_prefs( array( self::USERMETA_KEY_PREFIX . 'screen_columns' => $this->num_columns ) );
            }
        }

        return $this->num_columns;
    }

    /**
     * Print column number preferences for screen options
     */
    public function print_column_prefs() {
        $return_val = __( 'Number of Columns: ', 'publishpress' );

        for ( $i = 1; $i <= $this->max_num_columns; ++$i ) {
            $return_val .= "<label><input type='radio' name='" . esc_attr( self::USERMETA_KEY_PREFIX ) . "screen_columns' value='" . esc_attr( $i ) . "' " . checked( $this->get_num_columns(), $i, false ) . " />&nbsp;" . esc_attr( $i ) . "</label>\n";
        }

        return $return_val;
    }

    /**
     * Save the current user's preference for number of columns.
     */
    public function save_column_prefs($posted_fields) {
        $key               = self::USERMETA_KEY_PREFIX . 'screen_columns';
        $this->num_columns = (int) $posted_fields[$key];

        $current_user = wp_get_current_user();
        $this->update_user_meta( $current_user->ID, $key, $this->num_columns );
    }

    /**
     * Create the content overview view. This calls lots of other methods to do its work. This will
     * ouput any messages, create the table navigation, then print the columns based on
     * get_num_columns(), which will in turn print the stories themselves.
     */
    public function render_admin_page() {
        global $publishpress;

        // Update the current user's filters with the variables set in $_GET
        $this->user_filters = $this->update_user_filters();

        if ( ! empty( $this->user_filters[ 'cat' ] ) ) {
            $terms   = array();
            $terms[] = get_term( $this->user_filters[ 'cat' ], $this->taxonomy_used );
        } else {
            // Get all of the terms from the taxonomy, regardless whether there are published posts
            $args = array(
                'orderby'    => 'name',
                'order'      => 'asc',
                'hide_empty' => 0,
                'parent'     => 0,
            );
            $terms = get_terms( $this->taxonomy_used, $args );
        }
        $this->terms = apply_filters( 'PP_Content_Overview_filter_terms', $terms ); // allow for reordering or any other filtering of terms

        $description = sprintf( '%s <span class="time-range">%s</span>', __( 'Content Overview', 'publishpress' ), $this->content_overview_time_range() );
        $publishpress->settings->print_default_header( $publishpress->modules->content_overview, $description );

        ?>
        <div class="wrap" id="pp-content-overview-wrap">
            <?php $this->print_messages(); ?>
            <?php $this->table_navigation(); ?>

            <div class="metabox-holder">
            <?php
                // Handle the calculation of terms to postbox-containers
                $terms_per_container = ceil(count($terms) / $this->num_columns);
                $term_index = 0;

                // Show just one column if we've filtered to one term
                if ( count( $this->terms ) == 1 ) {
                    $this->num_columns = 1;
                }

                for ( $i = 1; $i <= $this->num_columns; $i++ ) {
                    echo '<div class="postbox-container" style="width:' . ( 100 / $this->num_columns ) . '%;">';
                    for ( $j = 0; $j < $terms_per_container; $j++ ) {
                        if ( isset( $this->terms[ $term_index ] ) ) {
                            $this->print_term( $this->terms[ $term_index ] );
                        }
                        $term_index++;

                    }

                    echo '</div>';
                }
                ?>
            </div>
        </div>
        <br clear="all">
        <?php

        $publishpress->settings->print_default_footer( $publishpress->modules->content_overview );
    }

    /**
     * Allow the user to define the date range in a new and exciting way
     *
     * @since 0.7
     */
    public function content_overview_time_range() {
        $output = '<form method="POST" action="' . menu_page_url( 'pp-content-overview', false ) . '">';

        $start_date_value = '<input type="text" id="pp-content-overview-start-date" name="pp-content-overview-start-date"'
            . ' size="10" class="date-pick" value="'
            . esc_attr( date_i18n( get_option( 'date_format' ), strtotime( $this->user_filters['start_date'] ) ) ) . '" /><span class="form-value">';

        $start_date_value .= esc_html( date_i18n( get_option( 'date_format' ), strtotime( $this->user_filters['start_date'] ) ) );
        $start_date_value .= '</span>';

        $number_days_value = '<input type="text" id="pp-content-overview-number-days" name="pp-content-overview-number-days"'
            . ' size="3" maxlength="3" value="'
            . esc_attr( $this->user_filters['number_days'] ) . '" /><span class="form-value">' . esc_html( $this->user_filters['number_days'] )
            . '</span>';

        $output .= sprintf( _x( 'starting %1$s showing %2$s %3$s', '%1$s = start date, %2$s = number of days, %3$s = translation of \'Days\'', 'publishpress'), $start_date_value, $number_days_value, _n( 'day', 'days', $this->user_filters['number_days'], 'publishpress' ) );
        $output .= '&nbsp;&nbsp;<span class="change-date-buttons">';
        $output .= '<input id="pp-content-overview-range-submit" name="pp-content-overview-range-submit" type="submit"';
        $output .= ' class="button button-primary hidden" value="' . __( 'Change', 'publishpress' ) . '" />';
        $output .= '&nbsp;';
        $output .= '<a class="change-date-cancel hidden" href="#">' . __( 'Cancel', 'publishpress' ) . '</a>';
        $output .= '<a class="change-date" href="#">' . __( 'Change', 'publishpress' ) . '</a>';
        $output .= wp_nonce_field( 'change-date', 'nonce', 'change-date-nonce', false );
        $output .= '</span></form>';

        return $output;
    }

    /**
     * Get all of the posts for a given term based on filters
     *
     * @param object $term The term we're getting posts for
     * @return array $term_posts An array of post objects for the term
     */
    public function get_posts_for_term($term, $args = null) {
        $defaults = array(
            'post_status'    => null,
            'author'         => null,
            'posts_per_page' => apply_filters( 'PP_Content_Overview_max_query', 200 ),
        );
        $args = array_merge( $defaults, $args );

        // Filter to the term and any children if it's hierarchical
        $arg_terms = array(
            $term->term_id,
        );

        $arg_terms         = array_merge( $arg_terms, get_term_children( $term->term_id, $this->taxonomy_used ) );
        $args['tax_query'] = array(
            array(
                'taxonomy' => $this->taxonomy_used,
                'field'    => 'id',
                'terms'    => $arg_terms,
                'operator' => 'IN',
            ),
        );

        // Unpublished as a status is just an array of everything but 'publish'
        if ( $args['post_status'] == 'unpublish' ) {
            $args['post_status'] = '';
            $post_statuses       = $this->get_post_statuses();

            foreach ( $post_statuses as $post_status ) {
                $args['post_status'] .= $post_status->slug . ', ';
            }

            $args['post_status'] = rtrim( $args['post_status'], ', ' );

            // Optional filter to include scheduled content as unpublished
            if ( apply_filters( 'pp_show_scheduled_as_unpublished', false ) ) {
                $args['post_status'] .= ', future';
            }
        }

        // Filter by post_author if it's set
        if ( $args['author'] === '0' ) {
            unset( $args['author'] );
        }

        // Filter for an end user to implement any of their own query args
        $args = apply_filters( 'PP_Content_Overview_posts_query_args', $args );

        add_filter( 'posts_where', array( $this, 'posts_where_range' ) );
        $term_posts_query_results = new WP_Query( $args );
        remove_filter( 'posts_where', array( $this, 'posts_where_range' ) );

        $term_posts = array();
        while ( $term_posts_query_results->have_posts() ) {
            $term_posts_query_results->the_post();

            global $post;

            $term_posts[] = $post;
        }

        return $term_posts;
    }

    /**
     * Filter the WP_Query so we can get a range of posts
     *
     * @param string $where The original WHERE SQL query string
     * @return string $where Our modified WHERE query string
     */
    public function posts_where_range( $where = '' ) {
        global $wpdb;

        $beginning_date = date( 'Y-m-d', strtotime( $this->user_filters['start_date'] ) );
        $end_day        = $this->user_filters['number_days'];
        $ending_date    = date( "Y-m-d", strtotime( "+" . $end_day . " days", strtotime( $beginning_date ) ) );
        $where          = $where . $wpdb->prepare( " AND ($wpdb->posts.post_date >= %s AND $wpdb->posts.post_date < %s)", $beginning_date, $ending_date );

        return $where;
    }

    /**
     * Prints the stories in a single term in the content overview.
     *
     * @param object $term The term to print.
     */
    public function print_term( $term ) {
        global $wpdb;

        $posts = $this->get_posts_for_term( $term, $this->user_filters );

        if ( ! empty( $posts ) ) {
            // Don't display the message for $no_matching_posts
            $this->no_matching_posts = false;
        }

        ?>
        <div class="postbox<?php echo ( ! empty( $posts ) ) ? ' postbox-has-posts' : ''; ?>">
            <div class="handlediv" title="<?php _e( 'Click to toggle', 'publishpress' );?>">
            <br /></div>
            <h3 class='hndle'><span><?php echo esc_html( $term->name ); ?></span></h3>
            <div class="inside">
                <?php if ( ! empty( $posts ) ) : ?>
                <table class="widefat post fixed content-overview" cellspacing="0">
                    <thead>
                        <tr>
                            <?php foreach ( (array) $this->term_columns as $key => $name ): ?>
                                <th scope="col" id="<?php echo esc_attr( sanitize_key( $key ) ); ?>" class="manage-column column-<?php echo esc_attr( sanitize_key( $key ) ); ?>" ><?php echo esc_html( $name ); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tfoot></tfoot>
                    <tbody>
                    <?php
                        foreach ( $posts as $post ) {
                            $this->print_post( $post, $term );
                        }
                    ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="message info">
                        <p><?php _e('There are no posts for this term in the range or filter specified.', 'publishpress'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Prints a single post within a term in the content overview.
     *
     * @param object $post The post to print.
     * @param object $parent_term The top-level term to which this post belongs.
     */
    public function print_post( $post, $parent_term ) {
        ?>
        <tr id='post-<?php echo esc_attr( $post->ID ); ?>' class='alternate' valign="top">
            <?php foreach ( (array) $this->term_columns as $key => $name ) {
                echo '<td>';
                if ( method_exists( $this, 'term_column_' . $key ) ) {
                    $method = 'term_column_' . $key;
                    echo $this->$method($post, $parent_term);
                } else {
                    echo $this->term_column_default( $post, $key, $parent_term );
                }

                echo '</td>';
            }
        ?>
        </tr>
        <?php
    }

    /**
     * Default callback for producing the HTML for a term column's single post value
     * Includes a filter other modules can hook into
     *
     * @since 0.7
     *
     * @param object $post The post we're displaying
     * @param string $column_name Name of the column, as registered with register_term_columns
     * @param object $parent_term The parent term for the term column
     * @return string $output Output value for the term column
     */
    public function term_column_default($post, $column_name, $parent_term) {

        // Hook for other modules to get data into columns
        $column_value = null;
        $column_value = apply_filters( 'PP_Content_Overview_term_column_value', $column_name, $post, $parent_term );
        if ( ! is_null( $column_value ) && $column_value != $column_name ) {
            return $column_value;
        }

        switch ( $column_name ) {
            case 'status':
                $status_name = $this->get_post_status_friendly_name( $post->post_status );
                return $status_name;
                break;
            case 'author':
                $post_author = get_userdata( $post->post_author );
                return $post_author->display_name;
                break;
            case 'post_date':
                $output = get_the_time( get_option( 'date_format' ), $post->ID ) . '<br />';
                $output .= get_the_time( get_option( 'time_format' ), $post->ID );
                return $output;
                break;
            case 'post_modified':
                $modified_time_gmt = strtotime( $post->post_modified_gmt . " GMT" );
                return $this->timesince( $modified_time_gmt );
                break;
            default:
                break;
        }
    }

    /**
     * Prepare the data for the title term column
     *
     * @since 0.7
     */
    public function term_column_title( $post, $parent_term ) {
        $post_title = _draft_or_post_title( $post->ID );

        $post_type_object = get_post_type_object( $post->post_type );
        $can_edit_post    = current_user_can( $post_type_object->cap->edit_post, $post->ID );
        if ( $can_edit_post ) {
            $output = '<strong><a href="' . get_edit_post_link( $post->ID ) . '">' . esc_html( $post_title ) . '</a></strong>';
        } else {
            $output = '<strong>' . esc_html( $post_title ) . '</strong>';
        }

        // Edit or Trash or View
        $output .= '<div class="row-actions">';
        $item_actions = array();

        if ( $can_edit_post ) {
            $item_actions['edit'] = '<a title="' . __( 'Edit this post', 'publishpress' ) . '" href="' . get_edit_post_link( $post->ID ) . '">' . __( 'Edit', 'publishpress' ) . '</a>';
        }

        if ( EMPTY_TRASH_DAYS > 0 && current_user_can( $post_type_object->cap->delete_post, $post->ID ) ) {
            $item_actions['trash'] = '<a class="submitdelete" title="' . __( 'Move this item to the Trash', 'publishpress' ) . '" href="' . get_delete_post_link( $post->ID ) . '">' . __( 'Trash', 'publishpress' ) . '</a>';
        }

        // Display a View or a Preview link depending on whether the post has been published or not
        if ( in_array( $post->post_status, array( 'publish' ) ) ) {
            $item_actions['view'] = '<a href="' . get_permalink( $post->ID ) . '" title="' . esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'publishpress' ), $post_title ) ) . '" rel="permalink">' . __( 'View', 'publishpress' ) . '</a>';
        } elseif ( $can_edit_post ) {
            $item_actions['previewpost'] = '<a href="' . esc_url( apply_filters( 'preview_post_link', add_query_arg( 'preview', 'true', get_permalink( $post->ID ) ), $post ) ) . '" title="' . esc_attr( sprintf( __( 'Preview &#8220;%s&#8221;', 'publishpress' ), $post_title ) ) . '" rel="permalink">' . __( 'Preview', 'publishpress' ) . '</a>';
        }

        $item_actions = apply_filters( 'PP_Content_Overview_item_actions', $item_actions, $post->ID );
        if ( count( $item_actions ) ) {
            $output .= '<div class="row-actions">';
            $html = '';
            foreach ( $item_actions as $class => $item_action ) {
                $html .= '<span class="' . esc_attr( $class ) . '">' . $item_action . '</span> | ';
            }
            $output .= rtrim( $html, '| ' );
            $output .= '</div>';
        }

        return $output;
    }

    /**
     * Print any messages that should appear based on the action performed
     */
    public function print_messages() {
        if ( isset( $_GET['trashed'] ) || isset( $_GET['untrashed'] ) ) {
            echo '<div id="trashed-message" class="updated"><p>';

            // Following mostly stolen from edit.php

            if ( isset( $_GET['trashed'] ) && (int) $_GET['trashed'] ) {
                printf( _n( 'Item moved to the trash.', '%d items moved to the trash.', $_GET['trashed'] ), number_format_i18n( $_GET['trashed'] ) );
                $ids = isset( $_GET['ids'] ) ? $_GET['ids'] : 0;
                echo ' <a href="' . esc_url( wp_nonce_url( "edit.php?post_type=post&doaction=undo&action=untrash&ids=$ids", "bulk-posts" ) ) . '">' . __( 'Undo', 'publishpress' ) . '</a><br />';
                unset( $_GET[ 'trashed' ] );
            }

            if ( isset( $_GET['untrashed'] ) && (int) $_GET['untrashed'] ) {
                printf( _n( 'Item restored from the Trash.', '%d items restored from the Trash.', $_GET['untrashed']), number_format_i18n( $_GET['untrashed'] ) );
                unset( $_GET['undeleted'] );
            }

            echo '</p></div>';
        }
    }

    /**
     * Print the table navigation and filter controls, using the current user's filters if any are set.
     */
    public function table_navigation() {
        ?>
        <div class="tablenav" id="pp-content-overview-tablenav">
            <div class="alignleft actions">
                <form method="GET" id="pp-content-filters">
                    <input type="hidden" name="page" value="pp-content-overview"/>
                    <?php
                        foreach ( $this->content_overview_filters() as $select_id => $select_name ) {
                            echo $this->content_overview_filter_options( $select_id, $select_name, $this->user_filters );
                        }
                    ?>
                </form>

                <form method="GET" id="pp-content-filters-hidden">
                    <input type="hidden" name="page" value="pp-content-overview"/>
                    <input type="hidden" name="post_status" value=""/>
                    <input type="hidden" name="cat" value=""/>
                    <input type="hidden" name="author" value=""/>
                    <?php
                    foreach ( $this->content_overview_filters() as $select_id => $select_name ) {
                        echo '<input type="hidden" name="' . $select_name . '" value="" />';
                    }
                    ?>
                    <input type="submit" id="post-query-clear" value="<?php _e( 'Reset', 'publishpress' ); ?>" class="button-secondary button" />
                </form>
            </div><!-- /alignleft actions -->

            <div class="print-box" style="float:right; margin-right: 30px;"><!-- Print link -->
                <a href="#" id="print_link"><span class="pp-icon pp-icon-print"></span>&nbsp;<?php _e( 'Print', 'publishpress' ); ?></a>
            </div>
            <div class="clear"></div>
        </div><!-- /tablenav -->
        <?php
    }

    /**
     * Update the current user's filters for content overview display with the filters in $_GET. The filters
     * in $_GET take precedence over the current users filters if they exist.
     */
    public function update_user_filters() {
        $current_user = wp_get_current_user();

        $user_filters = array(
            'post_status' => $this->filter_get_param( 'post_status' ),
            'cat'         => $this->filter_get_param( 'cat' ),
            'author'      => $this->filter_get_param( 'author' ),
            'start_date'  => $this->filter_get_param( 'start_date' ),
            'number_days' => $this->filter_get_param( 'number_days' )
        );

        $current_user_filters = array();
        $current_user_filters = $this->get_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', true );

        // If any of the $_GET vars are missing, then use the current user filter
        foreach ( $user_filters as $key => $value ) {
            if ( is_null( $value ) && !empty( $current_user_filters[$key] ) ) {
                $user_filters[$key] = $current_user_filters[$key];
            }
        }

        if ( ! $user_filters['start_date'] ) {
            $user_filters['start_date'] = date( 'Y-m-d' );
        }

        if ( ! $user_filters['number_days'] ) {
            $user_filters['number_days'] = 10;
        }

        $user_filters = apply_filters( 'PP_Content_Overview_filter_values', $user_filters, $current_user_filters );

        $this->update_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', $user_filters );

        return $user_filters;
    }

    /**
     * Get the filters for the current user for the content overview display, or insert the default
     * filters if not already set.
     *
     * @return array The filters for the current user, or the default filters if the current user has none.
     */
    public function get_user_filters() {
        $current_user = wp_get_current_user();
        $user_filters = array();
        $user_filters = $this->get_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', true );

        // If usermeta didn't have filters already, insert defaults into DB
        if ( empty( $user_filters ) ) {
            $user_filters = $this->update_user_filters();
        }

        return $user_filters;
    }

    /**
     *
     * @param string $param The parameter to look for in $_GET
     * @return null if the parameter is not set in $_GET, empty string if the parameter is empty in $_GET,
     *         or a sanitized version of the parameter from $_GET if set and not empty
     */
    public function filter_get_param($param) {
        // Sure, this could be done in one line. But we're cooler than that: let's make it more readable!
        if ( ! isset( $_GET[$param] ) ) {
            return null;
        } elseif ( empty( $_GET[$param] ) ) {
            return '';
        }

        return sanitize_key($_GET[$param]);
    }

    public function content_overview_filters() {
        $select_filter_names = array();

        $select_filter_names['post_status'] = 'post_status';
        $select_filter_names['cat']         = 'cat';
        $select_filter_names['author']      = 'author';

        return apply_filters( 'PP_Content_Overview_filter_names', $select_filter_names );
    }

    public function content_overview_filter_options( $select_id, $select_name, $filters ) {
        switch ( $select_id ) {
            case 'post_status':
            $post_statuses = $this->get_post_statuses();
            ?>
                <select id="post_status" name="post_status"><!-- Status selectors -->
                    <option value=""><?php _e( 'View all statuses', 'publishpress' ); ?></option>
                        <?php
                        foreach ( $post_statuses as $post_status ) {
                            echo "<option value='" . esc_attr( $post_status->slug ) . "' " . selected( $post_status->slug, $filters['post_status'] ) . ">" . esc_html( $post_status->name ) . "</option>";
                        }
                        ?>
                </select>
            <?php
            break;
            case 'cat':
                // Borrowed from wp-admin/edit.php
                if ( taxonomy_exists( 'category' ) ) {
                    $category_dropdown_args = array(
                        'show_option_all' => __( 'View all categories', 'publishpress' ),
                        'hide_empty'      => 0,
                        'hierarchical'    => 1,
                        'show_count'      => 0,
                        'orderby'         => 'name',
                        'selected'        => $this->user_filters['cat']
                    );
                    wp_dropdown_categories( $category_dropdown_args );
                }
            break;
            case 'author':
                $users_dropdown_args = array(
                    'show_option_all' => __( 'View all users', 'publishpress' ),
                    'name'            => 'author',
                    'selected'        => $this->user_filters['author'],
                    'who'             => 'authors',
                );
                $users_dropdown_args = apply_filters( 'PP_Content_Overview_users_dropdown_args', $users_dropdown_args );
                wp_dropdown_users( $users_dropdown_args );
            break;
            default:
                do_action( 'PP_Content_Overview_filter_display', $select_id, $select_name, $filters );
            break;
        }
    }
}
