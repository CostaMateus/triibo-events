<?php
/**
 * Plugin Name:          #Triibo_Eventos
 * Description:          Dispara eventos para a Triibo dada certas condições
 * Author:               Mateus Costa
 * Author URI:           https://costamateus.com.br/
 * Version:              1.0.0
 * Text Domain:          triibo_events
 * RequiresPHP:          7.4
 * RequiresWP:           5.9
 * WC requires at least: 5.8
 * WC tested up to:      6.3
 *
 * @package Triibo_Events
 */
defined( "ABSPATH" ) || exit;

// define( "TRIIBO_NOTIFICATIONS_PLUGIN_FILE", dirname( __FILE__ ) );
// define( "TRIIBO_NOTIFICATIONS_PLUGIN_DIR", plugin_dir_url( __FILE__ ) );

if ( ! class_exists( "Triibo_Events" ) )
{
	include_once dirname( __FILE__ ) . "/includes/class-triibo-events.php";

	// init plugin
	add_action( "plugins_loaded", [ "Triibo_Events", "init" ] );
}
