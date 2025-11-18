<?php
/**
 * Plugin Name: Courses
 * Description: A plugin to manage courses
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
add_action('init', 'courses_register_course_post_type');
function courses_register_course_post_type() {
    $labels = array(
        'name'               => 'Courses',
        'singular_name'      => 'Course',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Course',
        'edit_item'          => 'Edit Course',
        'new_item'           => 'New Course',
        'view_item'          => 'View Course',
        'search_items'       => 'Search Courses',
        'not_found'          => 'No courses found',
        'not_found_in_trash' => 'No courses found in trash',
        'menu_name'          => 'Courses',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'has_archive'        => true,
        'rewrite'            => array('slug' => 'course'),
        'show_in_rest'       => true,
        'supports'           => array('title', 'editor', 'thumbnail'),
    );

    register_post_type('course', $args);
}

/**
 * Register course_category taxonomy
 */
add_action( 'init', 'courses_register_course_taxonomy' );
function courses_register_course_taxonomy() {
    $labels = array(
        'name'          => 'Course Categories',
        'singular_name' => 'Course Category',
        'search_items'  => 'Search Categories',
        'all_items'     => 'All Categories',
        'edit_item'     => 'Edit Category',
        'update_item'   => 'Update Category',
        'add_new_item'  => 'Add New Category',
        'new_item_name' => 'New Category Name',
        'menu_name'     => 'Categories',
    );

    register_taxonomy( 'course_category', array( 'course' ), array(
        'hierarchical'      => true,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'public'            => true,
        'rewrite'           => array( 'slug' => 'course-category' ),
    ) );
}

/**
 * Activation / Deactivation: flush rewrite rules
 */
function courses_activate() {
    courses_register_course_post_type();
    courses_register_course_taxonomy();
    flush_rewrite_rules();
}
function courses_deactivate() {
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'courses_activate' );
register_deactivation_hook( __FILE__, 'courses_deactivate' );

/**
 * Metaboxes: duration and level
 */
add_action( 'add_meta_boxes', 'courses_add_meta_boxes' );
function courses_add_meta_boxes() {
    add_meta_box( 'courses_details', 'Course Details', 'courses_render_details_metabox', 'course', 'side', 'default' );
}
function courses_render_details_metabox( $post ) {
    wp_nonce_field( 'courses_save_meta', 'courses_meta_nonce' );
    $duration = get_post_meta( $post->ID, '_courses_duration', true );
    $level    = get_post_meta( $post->ID, '_courses_level', true );

    echo '<p><label for="courses_duration">Duration (hours)</label>';
    echo '<input type="number" id="courses_duration" name="courses_duration" value="' . esc_attr( $duration ) . '" class="widefat" /></p>';

    echo '<p><label for="courses_level">Level</label>';
    echo '<select id="courses_level" name="courses_level" class="widefat">';
    $options = array( '' => '— Select —', 'beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced' );
    foreach ( $options as $val => $label ) {
        echo '<option value="' . esc_attr( $val ) . '"' . selected( $level, $val, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select></p>';
}
add_action( 'save_post', 'courses_save_meta' );
function courses_save_meta( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! isset( $_POST['courses_meta_nonce'] ) || ! wp_verify_nonce( $_POST['courses_meta_nonce'], 'courses_save_meta' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( isset( $_POST['courses_duration'] ) ) {
        update_post_meta( $post_id, '_courses_duration', sanitize_text_field( wp_unslash( $_POST['courses_duration'] ) ) );
    } else {
        delete_post_meta( $post_id, '_courses_duration' );
    }
    if ( isset( $_POST['courses_level'] ) ) {
        update_post_meta( $post_id, '_courses_level', sanitize_text_field( wp_unslash( $_POST['courses_level'] ) ) );
    } else {
        delete_post_meta( $post_id, '_courses_level' );
    }
}

/**
 * Shortcode: [courses_list posts_per_page="5"]
 */
add_shortcode( 'courses_list', 'courses_list_shortcode' );
function courses_list_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'posts_per_page' => 5 ), $atts, 'courses_list' );
    $q = new WP_Query( array(
        'post_type'      => 'course',
        'posts_per_page' => intval( $atts['posts_per_page'] ),
    ) );

    if ( ! $q->have_posts() ) {
        return '<p>No courses found.</p>';
    }

    $out = '<ul class="courses-list">';
    while ( $q->have_posts() ) {
        $q->the_post();
        $duration   = get_post_meta( get_the_ID(), '_courses_duration', true );
        $level      = get_post_meta( get_the_ID(), '_courses_level', true );
        $categories = get_the_term_list( get_the_ID(), 'course_category', '', ', ', '' );

        $out .= '<li>';
        $out .= '<a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
        if ( $duration ) {
            $out .= ' — ' . esc_html( $duration ) . 'h';
        }
        if ( $level ) {
            $out .= ' (' . esc_html( ucfirst( $level ) ) . ')';
        }
        if ( $categories ) {
            $out .= '<div class="course-cats">' . $categories . '</div>';
        }
        $out .= '</li>';
    }
    wp_reset_postdata();
    $out .= '</ul>';

    return $out;
}

/**
 * Expose meta in REST API
 */
add_action( 'rest_api_init', 'courses_register_rest_fields' );
function courses_register_rest_fields() {
    register_rest_field(
        'course',
        'duration',
        array(
            'get_callback' => function ( $object ) {
                return get_post_meta( $object['id'], '_courses_duration', true );
            },
            'schema'       => array( 'type' => 'string' ),
        )
    );
    register_rest_field(
        'course',
        'level',
        array(
            'get_callback' => function ( $object ) {
                return get_post_meta( $object['id'], '_courses_level', true );
            },
            'schema'       => array( 'type' => 'string' ),
        )
    );
}

/**
 * Admin columns for course list
 */
add_filter( 'manage_course_posts_columns', 'courses_set_custom_columns' );
function courses_set_custom_columns( $columns ) {
    $columns['duration'] = 'Duration';
    $columns['level']    = 'Level';
    return $columns;
}
add_action( 'manage_course_posts_custom_column', 'courses_custom_column', 10, 2 );
function courses_custom_column( $column, $post_id ) {
    if ( 'duration' === $column ) {
        echo esc_html( get_post_meta( $post_id, '_courses_duration', true ) );
    }
    if ( 'level' === $column ) {
        echo esc_html( get_post_meta( $post_id, '_courses_level', true ) );
    }
}