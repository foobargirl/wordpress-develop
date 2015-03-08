<?php

/**
 * Tests for the WP_Customize_Widgets class.
 *
 * @group customize
 */
class Tests_WP_Customize_Widgets extends WP_UnitTestCase {

	/**
	 * @var WP_Customize_Manager
	 */
	protected $manager;

	protected $twentyfifteen_sidebars_widgets;
	protected $twentythirteen_sidebars_widgets;

	function setUp() {
		parent::setUp();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new WP_Customize_Manager();
		$this->manager = $GLOBALS['wp_customize'];

		unset( $GLOBALS['_wp_sidebars_widgets'] ); // clear out cache set by wp_get_sidebars_widgets()
		$sidebars_widgets = wp_get_sidebars_widgets();
		$this->assertEqualSets( array( 'wp_inactive_widgets', 'sidebar-1' ), array_keys( wp_get_sidebars_widgets() ) );
		$initial_sidebar_1 = array(
			'search-2',
			'recent-posts-2',
			'recent-comments-2',
			'archives-2',
			'categories-2',
			'meta-2',
		);
		$this->assertEquals( $initial_sidebar_1, $sidebars_widgets['sidebar-1'] );
		$this->assertArrayHasKey( 2, get_option( 'widget_search' ) );
		$widget_categories = get_option( 'widget_categories' );
		$this->assertArrayHasKey( 2, $widget_categories );
		$this->assertEquals( '', $widget_categories[2]['title'] );

		remove_action( 'after_setup_theme', 'twentyfifteen_setup' ); // @todo We should not be including a theme anyway

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	function tearDown() {
		parent::tearDown();
		$this->manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
	}

	function set_customized_post_data( $customized ) {
		$_POST['customized'] = wp_slash( wp_json_encode( $customized ) );
	}

	function do_customize_boot_actions() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		do_action( 'setup_theme' );
		$_REQUEST['nonce'] = wp_create_nonce( 'preview-customize_' . $this->manager->theme()->get_stylesheet() );
		do_action( 'after_setup_theme' );
		do_action( 'init' );
		do_action( 'wp_loaded' );
		do_action( 'wp', $GLOBALS['wp'] );
	}

	/**
	 * Test WP_Customize_Widgets::__construct()
	 */
	function test_construct() {
		$this->assertInstanceOf( 'WP_Customize_Widgets', $this->manager->widgets );
		$this->assertEquals( $this->manager, $this->manager->widgets->manager );
	}

	/**
	 * Test WP_Customize_Widgets::register_settings()
	 *
	 * @ticket 30988
	 */
	function test_register_settings() {

		$raw_widget_customized = array(
			'widget_categories[2]' => array(
				'title' => 'Taxonomies Brand New Value',
				'count' => 0,
				'hierarchical' => 0,
				'dropdown' => 0,
			),
			'widget_search[3]' => array(
				'title' => 'Not as good as Google!',
			),
		);
		$customized = array();
		foreach ( $raw_widget_customized as $setting_id => $instance ) {
			$customized[ $setting_id ] = $this->manager->widgets->sanitize_widget_js_instance( $instance );
		}

		$this->set_customized_post_data( $customized );
		$this->do_customize_boot_actions();
		$this->assertTrue( is_customize_preview() );

		$this->assertNotEmpty( $this->manager->get_setting( 'widget_categories[2]' ), 'Expected setting for pre-existing widget category-2, being customized.' );
		$this->assertNotEmpty( $this->manager->get_setting( 'widget_search[2]' ), 'Expected setting for pre-existing widget search-2, not being customized.' );
		$this->assertNotEmpty( $this->manager->get_setting( 'widget_search[3]' ), 'Expected dynamic setting for non-existing widget search-3, being customized.' );

		$widget_categories = get_option( 'widget_categories' );
		$this->assertEquals( $raw_widget_customized['widget_categories[2]'], $widget_categories[2], 'Expected $wp_customize->get_setting(widget_categories[2])->preview() to have been called.' );
	}

	/**
	 * @param string $new_theme Stylesheet
	 *
	 * @throws Exception
	 */
	function prepare_theme_switch_state( $new_theme ) {
		$old_theme = get_stylesheet();
		if ( 'twentyfifteen' !== $old_theme ) {
			throw new Exception( 'Currently expecting initial theme to be twentyfifteen.' );
		}
		if ( $new_theme === $old_theme ) {
			throw new Exception( 'A different theme must be supplied than the current theme.' );
		}

		// Make sure the new theme and the old theme are both among allowed themes
		update_site_option( 'allowedthemes', array_fill_keys( array( $new_theme, $old_theme ), true ) );
		$this->assertTrue( wp_get_theme( $old_theme )->is_allowed(), "Expected old theme $old_theme to be allowed." );
		$this->assertTrue( wp_get_theme( $new_theme )->is_allowed(), "Expected new theme $new_theme to be allowed" );

		// Set the sidebars_widgets theme mods for both the old theme and the new theme
		$this->twentyfifteen_sidebars_widgets = wp_get_sidebars_widgets();
		$this->twentythirteen_sidebars_widgets = $this->twentyfifteen_sidebars_widgets;
		$this->twentythirteen_sidebars_widgets['sidebar-2'] = array();
		for ( $i = 0; $i < 3; $i += 1 ) {
			$this->twentythirteen_sidebars_widgets['sidebar-2'][] = array_pop( $this->twentythirteen_sidebars_widgets['sidebar-1'] );
		}
		$this->twentythirteen_sidebars_widgets['sidebar-1'] = array_reverse( $this->twentythirteen_sidebars_widgets['sidebar-1'] );
		update_option( 'theme_mods_twentythirteen', array(
			'sidebars_widgets' => array(
				'time' => time() - 3600,
				'data' => $this->twentythirteen_sidebars_widgets,
			),
		) );

		$this->switch_theme_and_check_switched( $new_theme );
		$sidebars_widgets = get_option( 'sidebars_widgets' );
		$this->assertEquals( $this->twentythirteen_sidebars_widgets['sidebar-1'], $sidebars_widgets['sidebar-1'] );
		$this->assertEquals( $this->twentythirteen_sidebars_widgets['sidebar-2'], $sidebars_widgets['sidebar-2'] );

		$this->switch_theme_and_check_switched( $old_theme );
		$sidebars_widgets = get_option( 'sidebars_widgets' );
		$this->assertEquals( $this->twentyfifteen_sidebars_widgets['sidebar-1'], $sidebars_widgets['sidebar-1'] );
	}

	/**
	 * @param string $new_theme Stylesheet
	 *
	 * @throws Exception
	 */
	function switch_theme_and_check_switched( $new_theme ) {
		switch_theme( $new_theme );
		$this->setup_sidebars_for_theme_switch( $new_theme );
		check_theme_switched();
	}

	/**
	 * Emulate loading the theme's functions.php
	 *
	 * @param string $new_theme stylesheet
	 *
	 * @throws Exception
	 */
	function setup_sidebars_for_theme_switch( $new_theme ) {
		if ( 'twentythirteen' === $new_theme ) {
			register_sidebar( array(
				'name'          => __( 'Secondary Widget Area', 'twentythirteen' ),
				'id'            => 'sidebar-2',
				'description'   => __( 'Appears on posts and pages in the sidebar.', 'twentythirteen' ),
				'before_widget' => '<aside id="%1$s" class="widget %2$s">',
				'after_widget'  => '</aside>',
				'before_title'  => '<h3 class="widget-title">',
				'after_title'   => '</h3>',
			) );
		} else if ( 'twentyfifteen' === $new_theme ) {
			unregister_sidebar( 'sidebar-2' );
		} else {
			throw new Exception( 'Only twentyfifteen and twentythirteen are supported.' );
		}
	}

	function test_override_sidebars_widgets_for_non_theme_switch() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->markTestSkipped( 'The WP_Customize_Widgets::override_sidebars_widgets_for_theme_switch() method short-circuits if DOING_AJAX.' );
		}
		$this->do_customize_boot_actions();
		$this->assertEmpty( $this->manager->get_setting( 'old_sidebars_widgets_data' ) );
	}

	/**
	 * Test WP_Customize_Widgets::override_sidebars_widgets_for_theme_switch()
	 *
	 * @todo A lot of this should be in a test for WP_Customize_Manager::setup_theme()
	 */
	function test_override_sidebars_widgets_for_theme_switch() {
		global $wpdb;

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->markTestSkipped( 'The WP_Customize_Widgets::override_sidebars_widgets_for_theme_switch() method short-circuits if DOING_AJAX.' );
		}
		$start_time = time();
		$old_theme = get_stylesheet();
		$new_theme = 'twentythirteen';
		$this->assertNotEquals( $new_theme, $old_theme );

		$initial_sidebars_widgets = get_option( 'sidebars_widgets' );
		$this->prepare_theme_switch_state( $new_theme );
		$checked_sidebars_widgets = get_option( 'sidebars_widgets' );
		$this->assertEquals( $initial_sidebars_widgets['sidebar-1'], $checked_sidebars_widgets['sidebar-1'], 'Expected sidebar-1 to be back in initial state after round-trip theme switch.' );
		$this->assertArrayNotHasKey( 'orphaned_widgets_1', $checked_sidebars_widgets );
		$this->assertArrayNotHasKey( 'sidebar-2', $checked_sidebars_widgets );

		// Initialize the Customizer with a preview for the new theme
		$_REQUEST['theme'] = $new_theme;
		add_action( 'start_previewing_theme', array( $this, 'set_up_sidebars_for_theme_preview' ) );
		$this->do_customize_boot_actions();
		$this->assertEquals( $new_theme, $this->manager->get_stylesheet() );

		$sidebars_widgets = get_option( 'sidebars_widgets' );
		$this->assertArrayNotHasKey( 'orphaned_widgets_1', $sidebars_widgets );
		$this->assertEquals( $this->twentythirteen_sidebars_widgets['sidebar-1'], $sidebars_widgets['sidebar-1'] );
		$this->assertEquals( $this->twentythirteen_sidebars_widgets['sidebar-2'], $sidebars_widgets['sidebar-2'] );
		unset( $sidebars_widgets['array_version'] );
		$this->assertEquals( $sidebars_widgets, wp_get_sidebars_widgets() );

		$db_sidebars_widgets = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM $wpdb->options WHERE option_name = 'sidebars_widgets'" ) );
		$this->assertEquals( $db_sidebars_widgets['sidebar-1'], $initial_sidebars_widgets['sidebar-1'] );
		$this->assertArrayNotHasKey( 'sidebar-2', $db_sidebars_widgets );

		$old_sidebars_widgets_setting = $this->manager->get_setting( 'old_sidebars_widgets_data' );
		$this->assertNotEmpty( $old_sidebars_widgets_setting );
		$old_sidebars_widgets_value = $old_sidebars_widgets_setting->value();
		$this->assertNotEmpty( $old_sidebars_widgets_value );
		$this->assertEquals( $this->twentyfifteen_sidebars_widgets['sidebar-1'], $old_sidebars_widgets_value['sidebar-1'] );
		$this->assertArrayNotHasKey( 'sidebar-2', $old_sidebars_widgets_value['sidebar-1'] );

		$expected_dirty_settings = array( 'sidebars_widgets[sidebar-1]', 'sidebars_widgets[sidebar-2]', 'old_sidebars_widgets_data' );
		foreach ( $expected_dirty_settings as $dirty_setting_id ) {
			$this->assertTrue( $this->manager->get_setting( $dirty_setting_id )->dirty, "Expected setting $dirty_setting_id to be dirty." );
		}

		// Now save the customizer, and remove preview filters which interfere with saving
		foreach ( $this->manager->settings() as $setting ) {
			if ( $setting->dirty ) {
				$this->manager->set_post_value( $setting->id, $setting->value() );
				$id_base = preg_replace( '/\[.*/', '', $setting->id );
				if ( 'theme_mod' === $setting->type ) {
					remove_filter( "theme_mod_{$id_base}", array( $setting, '_preview_filter' ) );
				} else if ( 'option' === $setting->type ) {
					remove_filter( "pre_option_{$id_base}", array( $setting, '_preview_filter' ) );
					remove_filter( "option_{$id_base}", array( $setting, '_preview_filter' ) );
					remove_filter( "default_option_{$id_base}", array( $setting, '_preview_filter' ) );
				}
			}
		}
		remove_filter( 'option_sidebars_widgets', array( $this->manager->widgets, 'filter_option_sidebars_widgets_for_theme_switch' ), 1 );
		$response = $this->manager->save( array( 'check_ajax_referer' => false, 'send_json' => false ) );
		$this->assertInternalType( 'array', $response ); // and not a WP_Error

		// Ensure that the new theme's sidebars have been saved as expected
		$db_sidebars_widgets = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM $wpdb->options WHERE option_name = 'sidebars_widgets'" ) );
		$this->assertEquals( $this->twentythirteen_sidebars_widgets['sidebar-1'], $db_sidebars_widgets['sidebar-1'] );
		$this->assertEquals( $this->twentythirteen_sidebars_widgets['sidebar-2'], $db_sidebars_widgets['sidebar-2'] );

		// Ensure that the old theme's sidebars have been placed in that theme's widgets_sidebars theme mod
		$old_theme_sidebars_widgets_theme_mod = get_option( "theme_mods_{$old_theme}" );
		$this->assertEquals( $old_sidebars_widgets_value, $old_theme_sidebars_widgets_theme_mod['sidebars_widgets']['data'] );
		$this->assertGreaterThanOrEqual( $old_theme_sidebars_widgets_theme_mod['sidebars_widgets']['time'], $start_time );
		$new_theme_mods = get_option( "theme_mods_{$new_theme}" );
		$this->assertEmpty( $new_theme_mods['sidebars_widgets'], "Expected new theme's sidebars_widgets theme mod to be gone." );
	}

	/**
	 * @param WP_Customize_Manager $wp_customize
	 */
	function set_up_sidebars_for_theme_preview( $wp_customize ) {
		$this->setup_sidebars_for_theme_switch( $wp_customize->get_stylesheet() );
	}

	/**
	 * Test WP_Customize_Widgets::get_setting_args()
	 */
	function test_get_setting_args() {

		add_filter( 'widget_customizer_setting_args', array( $this, 'filter_widget_customizer_setting_args' ), 10, 2 );

		$default_args = array(
			'type' => 'option',
			'capability' => 'edit_theme_options',
			'transport' => 'refresh',
			'default' => array(),
			'sanitize_callback' => array( $this->manager->widgets, 'sanitize_widget_instance' ),
			'sanitize_js_callback' => array( $this->manager->widgets, 'sanitize_widget_js_instance' ),
		);

		$args = $this->manager->widgets->get_setting_args( 'widget_foo[2]' );
		foreach ( $default_args as $key => $default_value ) {
			$this->assertEquals( $default_value, $args[ $key ] );
		}
		$this->assertEquals( 'WIDGET_FOO[2]', $args['uppercase_id_set_by_filter'] );

		$override_args = array(
			'type' => 'theme_mod',
			'capability' => 'edit_posts',
			'transport' => 'postMessage',
			'default' => array( 'title' => 'asd' ),
			'sanitize_callback' => '__return_empty_array',
			'sanitize_js_callback' => '__return_empty_array',
		);
		$args = $this->manager->widgets->get_setting_args( 'widget_bar[3]', $override_args );
		foreach ( $override_args as $key => $override_value ) {
			$this->assertEquals( $override_value, $args[ $key ] );
		}
		$this->assertEquals( 'WIDGET_BAR[3]', $args['uppercase_id_set_by_filter'] );

		$default_args = array(
			'type' => 'option',
			'capability' => 'edit_theme_options',
			'transport' => 'refresh',
			'default' => array(),
			'sanitize_callback' => array( $this->manager->widgets, 'sanitize_sidebar_widgets' ),
			'sanitize_js_callback' => array( $this->manager->widgets, 'sanitize_sidebar_widgets_js_instance' ),
		);
		$args = $this->manager->widgets->get_setting_args( 'sidebars_widgets[sidebar-1]' );
		foreach ( $default_args as $key => $default_value ) {
			$this->assertEquals( $default_value, $args[ $key ] );
		}
		$this->assertEquals( 'SIDEBARS_WIDGETS[SIDEBAR-1]', $args['uppercase_id_set_by_filter'] );

		$override_args = array(
			'type' => 'theme_mod',
			'capability' => 'edit_posts',
			'transport' => 'postMessage',
			'default' => array( 'title' => 'asd' ),
			'sanitize_callback' => '__return_empty_array',
			'sanitize_js_callback' => '__return_empty_array',
		);
		$args = $this->manager->widgets->get_setting_args( 'sidebars_widgets[sidebar-2]', $override_args );
		foreach ( $override_args as $key => $override_value ) {
			$this->assertEquals( $override_value, $args[ $key ] );
		}
		$this->assertEquals( 'SIDEBARS_WIDGETS[SIDEBAR-2]', $args['uppercase_id_set_by_filter'] );
	}

	function filter_widget_customizer_setting_args( $args, $id ) {
		$args['uppercase_id_set_by_filter'] = strtoupper( $id );
		return $args;
	}

	/**
	 * Test WP_Customize_Widgets::sanitize_widget_js_instance() and WP_Customize_Widgets::sanitize_widget_instance()
	 */
	function test_sanitize_widget_js_instance() {
		$this->do_customize_boot_actions();

		$new_categories_instance = array(
			'title' => 'Taxonomies Brand New Value',
			'count' => '1',
			'hierarchical' => '1',
			'dropdown' => '1',
		);

		$sanitized_for_js = $this->manager->widgets->sanitize_widget_js_instance( $new_categories_instance );
		$this->assertArrayHasKey( 'encoded_serialized_instance', $sanitized_for_js );
		$this->assertTrue( is_serialized( base64_decode( $sanitized_for_js['encoded_serialized_instance'] ), true ) );
		$this->assertEquals( $new_categories_instance['title'], $sanitized_for_js['title'] );
		$this->assertTrue( $sanitized_for_js['is_widget_customizer_js_value'] );
		$this->assertArrayHasKey( 'instance_hash_key', $sanitized_for_js );

		$corrupted_sanitized_for_js = $sanitized_for_js;
		$corrupted_sanitized_for_js['encoded_serialized_instance'] = base64_encode( serialize( array( 'title' => 'EVIL' ) ) );
		$this->assertNull( $this->manager->widgets->sanitize_widget_instance( $corrupted_sanitized_for_js ), 'Expected sanitize_widget_instance to reject corrupted data.' );

		$unsanitized_from_js = $this->manager->widgets->sanitize_widget_instance( $sanitized_for_js );
		$this->assertEquals( $unsanitized_from_js, $new_categories_instance );
	}
}
