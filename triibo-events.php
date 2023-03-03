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

if ( !in_array( "woocommerce/woocommerce.php", apply_filters( "active_plugins", get_option( "active_plugins" ) ) ) ) return;

class Triibo_Events
{
	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = "1.0.0";

	/**
	 * Plugin domain.
	 *
	 * @var string
	 */
	const DOMAIN  = "triibo_events";

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * WC Logger.
	 *
	 * @var wc_get_logger|WC_Logger
	 */
	protected $log;

	/**
	 * Triibo Api Services - Gateway.
	 *
	 * @var Triibo_Api_Gateway
	 */
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

	/**
	 * Construct
	 */
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

	/**
	 * adds 'Eventos' submenu to Triibo Services main menu
	 */
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

	/**
	 * Template for submenu
	 */
	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/templates/admin-page.php";
	}

	/**
	 * Link to plugin settings
	 */
	public function add_btn()
	{
        $url  = esc_url( admin_url( "admin.php?page=" . self::DOMAIN . "-settings" ) );
		$text = __( "Configurações", self::DOMAIN );
		$link = "<a href='{$url}' >{$text}</a>";

		echo "<p>Triibo Eventos | {$link}</p>";
	}

	/**
	 * Add this plugin's dependency in the Triibo Api Services settings
	 */
	public function add_info_dependency()
	{
		echo "<p> - Triibo Eventos</p>";
	}


	/**
	 * Valid if is a sub-order and sends event for Triibo
	 */
	public function order_status_change( $order_id, $old_status, $new_status, $order )
	{
		$total          = $order->get_total();
		$shipp          = $order->get_shipping_total();

		$original_order = ( $order->get_parent_id() == 0 ) ? true : false;
		$is_processing  = ( $new_status == "processing"  ) ? true : false;
		$total_gt       = ( $total >= 50                 ) ? true : false;

		if ( $original_order && $is_processing && $total_gt )
		{
			$user_id   = $order->get_user_id();
			$triibo_id = get_user_meta( $user_id, "triiboId_phone", true );

			$subtotal  = $total - $shipp;

			if ( !empty( $triibo_id ) )
			{
				$triibo_id = preg_replace( "/\D/", "", $triibo_id );
				$response  = $this->api->credit_points( $triibo_id, $total );

				if ( $response[ "success" ] && isset( $response[ "data" ] ) && $response[ "data" ][ "success" ] )
				{
					$movement_id = $response[ "data" ][ "movementID" ];
					$note        = "Créditos adicionados a conta Triibo\nConta: {$triibo_id}\nMovimentação ID: {$movement_id}";
				}
				elseif ( $response[ "success" ] && isset( $response[ "data" ] ) && !$response[ "data" ][ "success" ] )
				{
					$note        = "Falha ao adicionar créditos a conta Triibo\nConta: {$triibo_id}\nErro: API";
				}
				else
				{
					$note        = "Falha ao adicionar créditos a conta Triibo\nConta: {$triibo_id}\nErro: MKT";
				}

				$order->add_order_note( $note );
				$order->save();

				$this->log( json_encode( [ "triibo_id" => $triibo_id, "response" => $response ] ) );
			}
			else
			{
				$this->log( "Order: #{$order_id} | User: {$user_id} | without an account linked to Triibo" );
			}
		}
	}

    /**
     * Register log
     *
     * @param string $data
     */
    public function log( string $data )
    {
        $this->log->add( self::DOMAIN, $data );
    }
}

add_action( "plugins_loaded", array( "Triibo_Events", "get_instance" ), 222 );
