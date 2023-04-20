<?php
/**
 * Plugin Name:          #Triibo_Eventos
 * Description:          Dispara eventos para a Triibo dada certas condições. Dependências: Triibo API Services e WooCommerce.
 * Author:               Mateus Costa
 * Author URI:           https://costamateus.com.br/
 * Version:              1.1.0
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
	 * @since 	1.0.0
	 * @var 	string
	 */
	const VERSION = "1.1.0";

	/**
	 * Plugin domain.
	 *
	 * @since 	1.0.0
	 * @var 	string
	 */
	const DOMAIN  = "triibo_events";

	/**
	 * Instance of this class.
	 *
	 * @since 	1.0.0
	 * @var 	object
	 */
	protected static $instance = null;

	/**
	 * WC Logger.
	 *
	 * @since 	1.0.0
	 * @var 	wc_get_logger|WC_Logger
	 */
	protected $log;

	/**
	 * Triibo Api Services - Gateway.
	 *
	 * @since 	1.0.0
	 * @var 	Triibo_Api_Gateway
	 */
	protected $api;

	/**
	 * Construct
	 *
	 * @since 	1.0.0
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
	 * Return an instance of this class.
	 *
	 * @since 	1.0.0
	 * @return 	object A single instance of this class.
	 */
	public static function get_instance()
    {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Triibo-Api-Service missing notice.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public static function triibo_api_services_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-triibo_api_services.php";
	}

	/**
	 * Action links.
	 *
	 * @since 	1.0.0
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
	 *
	 * @since 	1.0.0
	 * @return 	void
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
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/templates/admin-page.php";
	}

	/**
	 * Link to plugin settings
	 *
	 * @since 	1.0.0
	 * @return 	void
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
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function add_info_dependency()
	{
		echo "<p> - Triibo Eventos</p>";
	}

	/**
	 * Valid if is a sub-order and sends event for Triibo
	 *
	 * @since 	1.1.0 Rebuilt method to work with different statuses
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function order_status_change( $order_id, $old_status, $new_status, $order )
	{
		$total          = $order->get_total();
		$original_order = ( $order->get_parent_id() == 0 ) ? true : false;
		$is_processing  = ( $new_status == "processing"  ) ? true : false;
		$is_completed   = ( $new_status == "completed"   ) ? true : false;

		$user_id        = $order->get_user_id();
		$triibo_id      = get_user_meta( $user_id, "triiboId_phone", true );
		$triibo_id      = preg_replace( "/\D/", "", $triibo_id );

		/**
		 * Verificação se o evento Compra_Marketplace já foi executado
		 * @since 	1.0.3
		 */
		$has_cp  = get_user_meta( $user_id, "has_cp_{$order_id}", true );

		/**
		 * Verificação se o evento Assinatura_Triibo_Vip já foi executado
		 * @since 	1.1.0
		 */
		$has_atv = get_user_meta( $user_id, "has_atv_{$order_id}", true );

		if ( $original_order )
		{
			if ( !empty( $triibo_id ) )
			{
				// assinatura
				if ( wcs_order_contains_subscription( $order_id ) && $is_processing )
				{
					foreach ( $order->get_items() as $item_id => $item )
					{
						// Triibo VIP
						if ( $item->get_product_id() == 939930 )
						{
							if ( empty( $has_cp ) )
							{
								$this->compra_marketplace( $order, $triibo_id, $total );
								add_user_meta( $user_id, "has_cp_{$order_id}", true, true );
							}
							else
							{
								$this->log( "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Compra_Marketplace'" );
							}

							if ( empty( $has_atv ) )
							{
								$this->assinatura_tribo_vip( $order, $triibo_id, 1 );
								add_user_meta( $user_id, "has_atv_{$order_id}", true, true );
							}
							else
							{
								$this->log( "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Assinatura_Triibo_Vip'" );
							}

							$order->save();
							break;
						}
					}
				}
				// compra normal
				elseif ( $is_completed )
				{
					if ( empty( $has_cp ) )
					{
						$this->compra_marketplace( $order, $triibo_id, $total );
						add_user_meta( $user_id, "has_cp_{$order_id}", true, true );
						$order->save();
					}
					else
					{
						$this->log( "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Compra_Marketplace'" );
					}
				}
			}
			else
			{
				$this->log( "Order: #{$order_id} | User: {$user_id} | without an account linked to Triibo" );
			}
		}
	}

	/**
	 * Call event Compra_Marketplace
	 *
	 * @since 	1.1.0
	 * @return 	void
	 */
	public function compra_marketplace( &$order, $triibo_id, $total )
	{
		$event = "Compra_Marketplace";
		$this->call_event( $event, $order, $triibo_id, $total );
	}

	/**
	 * Call event Assinatura_Triibo_Vip
	 *
	 * @since 	1.1.0
	 * @return 	void
	 */
	public function assinatura_tribo_vip( &$order, $triibo_id, $total = 1 )
	{
		$event = "Assinatura_Triibo_Vip";
		$this->call_event( $event, $order, $triibo_id, $total );
	}

	/**
	 * Call event
	 *
	 * @since 	1.1.0
	 * @return 	void
	 */
	private function call_event( $event, &$order, $triibo_id, $total )
	{
		$response = $this->api->credit_points( $triibo_id, $total, $event );

		$this->log( json_encode( [ "triibo_id" => $triibo_id, "response" => $response ] ) );
		$this->add_note( $order, $triibo_id, $event, $response );
	}

	/**
	 * Add note to order
	 *
	 * @since 	1.0.4
	 * @return 	void
	 */
	public function add_note( &$order, $triibo_id, $event, $response )
	{
		$note = "Evento: {$event}";

		if ( $response[ "success" ] && isset( $response[ "data" ] ) && $response[ "data" ][ "success" ] )
		{
			$movement_id  = $response[ "data" ][ "movementID" ];
			$note        .= "\nCréditos/cupons adicionados a conta Triibo\nConta: {$triibo_id}\nMovimentação ID: {$movement_id}";
		}
		elseif ( $response[ "success" ] && isset( $response[ "data" ] ) && !$response[ "data" ][ "success" ] )
		{
			$note        .= "Falha ao adicionar créditos/cupons a conta Triibo\nConta: {$triibo_id}\nErro: API";
		}
		elseif ( !$response[ "success" ] && isset( $response[ "event" ] ) )
		{
			$note        .= "Evento não suportado\nConta: {$triibo_id}\nErro: MKT";
		}
		else
		{
			$note        .= "Falha ao adicionar créditos/cupons a conta Triibo\nConta: {$triibo_id}\nErro: MKT";
		}

		$order->add_order_note( $note );
	}

    /**
     * Register log
     *
	 * @since 	1.0.0
     * @param 	string $data
	 * @return 	void
     */
    public function log( string $data )
    {
        $this->log->add( self::DOMAIN, $data );
    }
}

add_action( "plugins_loaded", array( "Triibo_Events", "get_instance" ), 222 );
