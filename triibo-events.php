<?php
/**
 * Plugin Name:          #Triibo_Eventos
 * Description:          Dispara eventos para a Triibo dada certas condições. Dependências: Triibo API Services e WooCommerce.
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

if ( ! in_array( "woocommerce/woocommerce.php", apply_filters( "active_plugins", get_option( "active_plugins" ) ) ) ) return;

class Triibo_Events
{
	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = "1.0.0";

	const DOMAIN  = "triibo_events";

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	protected $log;
	protected $api;

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
    {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) self::$instance = new self;
		return self::$instance;
	}

	private function __construct()
    {
		if ( class_exists( "Triibo_Api_Services" ) )
		{
			add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ $this, "plugin_action_links" ] );

			add_action( "admin_menu",                          [ $this, "add_submenu_page"    ], 11    );
			add_action( "triibo_api_service_add_button",       [ $this, "add_btn"             ], 11    );
			add_action( "triibo_api_service_list_plugin_gate", [ $this, "add_info_dependency" ],       );

			add_action( "woocommerce_order_status_changed",    [ $this, "order_status_change" ], 10, 4 );

			$this->log = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
			$this->api = new Triibo_Api_Gateway();
		}
		else
		{
			add_action( "admin_notices", [ $this, "triibo_api_services_missing_notice" ] );
		}

	}

	/**
	 * Triibo-Api-Service missing notice.
	 */
	public static function triibo_api_services_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-triibo_api_services.php";
	}

	/**
	 * Action links.
	 *
	 * @param 	array $links
	 * @return 	array
	 */
	public static function plugin_action_links( $links )
    {
        $url            = esc_url( admin_url( "admin.php?page=" . self::DOMAIN . "-settings" ) );
        $text           = __( "Configurações", self::DOMAIN );
		$plugin_links   = [];
		$plugin_links[] = "<a href='{$url}' >{$text}</a>";

		return array_merge( $plugin_links, $links );
	}

	public function add_submenu_page()
	{
		add_submenu_page(
			Triibo_Api_Services::get_name(),
			"Triibo Eventos",
			"Eventos",
			"manage_options",
			self::DOMAIN . "-settings",
			[ $this, "display_admin_menu_settings" ]
		);
	}

	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/templates/admin-page.php";
	}

	public function add_btn()
	{
        $url  = esc_url( admin_url( "admin.php?page=" . self::DOMAIN . "-settings" ) );
		$text = __( "Configurações", self::DOMAIN );
		$link = "<a href='{$url}' >{$text}</a>";

		echo "<p>Triibo Eventos | {$link}</p>";
	}

	public function add_info_dependency()
	{
		echo "<p> - Triibo Eventos</p>";
	}


	/**
	 * Valid if is a sub-order and sends event for Triibo
	 */
	public function order_status_change( $order_id, $old_status, $new_status, $order )
	{
		$sub_order = empty( $order->get_meta( "has_sub_order" ) ) ? false : true;

		if ( !$sub_order )
		{
			if ( $new_status == "completed" )
			{
				$total = $order->get_total();

				if ( $total >= 50 )
				{
					$round     = round( $total );

					$user_id   = $order->get_user_id();
					$user_meta = get_user_meta( $user_id, true );

					if ( isset( $user_meta[ "triiboId_phone" ] ) )
					{
						$uid      = reset( $user_meta[ "triiboId_phone" ] );
						$response = $this->api->credit_points( $uid, $round );

						$this->log( json_encode( $response ) );
					}
					else
					{
						$order_id = $order->get_order_number();
						$this->log( "Order: #{$order_id} | User: {$user_id} | without an account linked to Triibo" );
					}
				}
			}
		}
	}

    /**
     * Register log
     *
     * @param string $data
     * @return void
     */
    public function log( string $data )
    {
        $this->log->add( self::DOMAIN, $data );
    }
}

add_action( "plugins_loaded", array( "Triibo_Events", "get_instance" ), 222 );
