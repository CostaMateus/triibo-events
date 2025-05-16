<?php

/**
 * Plugin Name: 			#Triibo_Eventos
 * Plugin URI: 				https://triibo.com.br
 * Description: 			Dispara eventos para a Triibo dada certas condições. Dependências: Triibo API Services, WooCommerce e WooCommerce Subscription.
 * Author: 					Mateus Costa
 * Author URI: 				https://costamateus.com.br/
 * Version: 				1.12.0
 * Text Domain: 			triibo_events
 * Requires at least: 		6.4
 * Requires PHP: 			7.4
 * WC requires at least: 	8.0
 * WC tested up to: 		8.2
 *
 * @package Triibo_Events
 *
 * @version 1.12.0
 */
defined( "ABSPATH" ) || exit;

if ( !in_array( "woocommerce/woocommerce.php", apply_filters( "active_plugins", get_option( "active_plugins" ) ) ) ) return;

class Triibo_Events
{
	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const VERSION = "1.12.0";

	/**
	 * Plugin domain.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const DOMAIN = "triibo_events";

	/**
	 * Subscription Triibo VIP.
	 *
	 * @since 1.6.0 	Added new plans
	 * @since 1.5.0 	Added env
	 * @since 1.3.0
	 *
	 * @var array
	 */
	const PLANS = [
		"hml" => [
			816851 => "Assinatura_Triibo_Vip",
			816853 => "Assinatura_Triibo_Vip_Plano_2",
			816854 => "Assinatura_Triibo_Vip_Plano_3"
		],
		"prd" => [
			// product_id => event_atv
			939930 => "Assinatura_Triibo_Vip",
			957852 => "Assinatura_Triibo_Vip_Plano_2",
			957855 => "Assinatura_Triibo_Vip_Plano_3",
			981120 => "Assinatura_Triibo_Vip_Plano_4", // trigger 'SEU_CLUBE'
			981121 => "Assinatura_Triibo_Vip_Plano_5",
			981122 => "Assinatura_Triibo_Vip_Plano_6",
		]
	];

	/**
	 * Orgs according to the env.
	 *
	 * @since 1.5.2 	Added 'id' and 'name' indexes
	 * @since 1.5.0
	 *
	 * @var array
	 */
	const ORGS = [
		"hml" => [
			"add" => [
				// vip
				"id"   => "-Ngta5dXbnSxCJravalh",
				"name" => "Triibo V.I.P",
			],
			"del" => [
				// normal
				"id"   => "mlmtgPFBctdnJeJ9Bz4i",
				"name" => "Triibo",
			]
		],
		"prd" => [
			"add" => [
				// vip
				"id"   => "-NVUhxocgIAf_vgFmYvx",
				"name" => "Triibo V.I.P",
			],
			"del" => [
				// normal
				"id"   => "triibo",
				"name" => "Triibo",
			]
		]
	];

	/**
	 * @since 1.10.0 	Added product 981120
	 * @since 1.8.0
	 *
	 * @var array
	 */
	const SEU_CLUBE = [
		"hml" => [
			114308, // assinatura teste
		],
		"prd" => [
			// Assinatura_Triibo_Vip_Plano_4
			981120,

			// Club do Golfista
			986463,

			// Morro Experience
			986464,

			// Projeto Pedalar
			987475,

			// Conexão Feminina - mensal
			1022143,

			// Conexão Feminina - anual
			// 1022146,
		],
	];

	/**
	 * Instance of this class.
	 *
	 * @since 1.0.0
	 *
	 * @var null|Triibo_Events
	 */
	protected static ?Triibo_Events $instance = null;

	/**
	 * WC Logger.
	 *
	 * @since 1.0.0
	 *
	 * @var null|WC_Logger
	 */
	protected ?WC_Logger $log = null;

	/**
	 * Triibo Api Services - Gateway.
	 *
	 * @since 1.0.0
	 *
	 * @var null|Triibo_Api_Gateway
	 */
	protected ?Triibo_Api_Gateway $api_gate = null;

	/**
	 * Triibo Api Services - Node.
	 *
	 * @since 1.8.0
	 *
	 * @var null|Triibo_Api_Node
	 */
	protected ?Triibo_Api_Node $api_node = null;

	/**
	 * Construct.
	 *
	 * @since 1.8.0 	Added Triibo_Api_Node
	 * @since 1.7.0 	Added HPOS compatibility declaration
	 * @since 1.5.0 	Added var $env
	 * @since 1.4.0 	Added plugin change history
	 * @since 1.0.0
	 */
	private function __construct()
    {
		if ( class_exists( "Triibo_Api_Services" ) )
		{
			/**
			 * @since 1.7.0
			 */
			if ( class_exists( "WooCommerce" ) )
				add_action( "before_woocommerce_init", [ $this, "setup_hpos_compatibility" ] );

			add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ $this, "plugin_action_links" ] );

			/**
			 * @since 1.4.0
			 */
			add_filter( "plugin_row_meta", [ $this, "plugin_row_meta" ], 10, 3 );

			add_action( "admin_menu",                          [ $this, "add_submenu_page"    ], 11    );
			add_action( "triibo_api_service_add_button",       [ $this, "add_btn"             ], 11    );
			add_action( "triibo_api_service_list_plugin_gate", [ $this, "add_info_dependency" ],       );
			add_action( "triibo_api_service_list_plugin_node", [ $this, "add_info_dependency" ]        );

			add_action( "woocommerce_order_status_changed",                  [ $this, "order_status_change"  ], 10, 4 );
			add_action( "woocommerce_subscription_status_cancelled",         [ $this, "subscription_ended"   ], 10, 1 );
			add_action( "woocommerce_subscription_renewal_payment_complete", [ $this, "subscription_renewal" ], 10, 2 );

			/**
			 * @since 1.11.0
			 */
			add_action( "woocommerce_subscription_status_expired",           [ $this, "subscription_ended"   ], 10, 1 );

			$this->log      = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
			$this->api_gate = new Triibo_Api_Gateway();

			/**
			 * @since 1.8.0
			 */
			$this->api_node = new Triibo_Api_Node();
		}
		else
		{
			add_action( "admin_notices", [ $this, "triibo_api_services_missing_notice" ] );
		}
	}

	/**
	 * Get environment.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	private function get_env() : string
	{
		return ( get_option( option: "home" ) === "https://marketplace.triibo.com.br" ) ? "prd" : "hml";
	}

	/**
	 * Call event.
	 *
	 * @since 1.3.2 	Added return value.
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function call_event( $event, &$order, $triibo_id, $total ) : array
	{
		$response = $this->api_gate->credit_points( triibo_id: $triibo_id, value: $total, event: $event );

		/**
		 * Added event field to log.
		 * @since 	1.2.0
		 */
		$this->log( "info", print_r( [ "event" => $event, "triibo_id" => $triibo_id, "response" => $response ], true ) );
		$this->add_note( $order, $triibo_id, $event, $response );

		return $response;
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since 1.0.0
	 *
	 * @return self 	A single instance of this class.
	 */
	public static function get_instance() : self
    {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance )
			self::$instance = new self;

		return self::$instance;
	}

	/**
	 * Triibo-Api-Service missing notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function triibo_api_services_missing_notice() : void
	{
		include_once dirname( path: __FILE__ ) . "/includes/admin/views/html-notice-missing-triibo_api_services.php";
	}

	/**
	 * Action links.
	 *
	 * @since 1.0.0
	 *
	 * @param array 	$links
	 *
	 * @return array
	 */
	public static function plugin_action_links( array $links ) : array
    {
        $url            = esc_url( url: admin_url( path: "admin.php?page=" . self::DOMAIN . "-settings" ) );
        $text           = __( "Configurações", self::DOMAIN );
		$plugin_links   = [];
		$plugin_links[] = "<a href='{$url}' >{$text}</a>";

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Setup WooCommerce HPOS compatibility.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function setup_hpos_compatibility() : void
	{
		if ( defined( "WC_VERSION" ) && version_compare( WC_VERSION, "7.1", "<" ) )
			return;

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) )
		{
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				feature_id            : "custom_order_tables",
				plugin_file           : __FILE__,
				positive_compatibility: true
			);
		}
	}

	/**
	 * Add link to changelog modal.
	 *
	 * @since 1.4.0
	 *
	 * @param array 	$plugin_meta
	 * @param string 	$plugin_file
	 * @param array 	$plugin_data
	 *
	 * @return array
	 */
	public function plugin_row_meta( array $plugin_meta, string $plugin_file, array $plugin_data ) : array
	{
		$path = path_join( base: WP_PLUGIN_DIR, path: $plugin_file );

		if ( DIRECTORY_SEPARATOR == "\\" )
			$path = str_replace( "/", "\\", $path );

        if ( __FILE__ === $path )
		{
            $url = plugins_url( path: "readme.txt", plugin: __FILE__ );

            $plugin_meta[] = sprintf(
                "<a href='%s' class='thickbox open-plugin-details-modal' aria-label='%s' data-title='%s'>%s</a>",
                add_query_arg( "TB_iframe", "true", $url ),
                esc_attr( text: sprintf( __( "More information about %s" ), $plugin_data[ "Name" ] ) ),
                esc_attr( text: $plugin_data[ "Name" ] ),
                __( text: "Histórico de alterações" )
            );
        }

        return $plugin_meta;
	}

	/**
	 * adds 'Eventos' submenu to Triibo Services main menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_submenu_page() : void
	{
		add_submenu_page(
			parent_slug: Triibo_Api_Services::get_name(),
			page_title : "Triibo Eventos",
			menu_title : "Eventos",
			capability : "manage_options",
			menu_slug  : self::DOMAIN . "-settings",
			callback   : [ $this, "display_admin_menu_settings" ]
		);
	}

	/**
	 * Template for submenu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function display_admin_menu_settings() : void
	{
		include_once dirname( path: __FILE__ ) . "/templates/admin-page.php";
	}

	/**
	 * Link to plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_btn() : void
	{
        $url  = esc_url( url: admin_url( path: "admin.php?page=" . self::DOMAIN . "-settings" ) );
		$text = __( "Configurações", self::DOMAIN );
		$link = "<a href='{$url}' >{$text}</a>";

		echo "<p>Triibo Eventos | {$link}</p>";
	}

	/**
	 * Add this plugin's dependency in the Triibo Api Services settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_info_dependency() : void
	{
		echo "<p> - Triibo Eventos</p>";
	}

	/**
	 * Check if the order is an original order.
	 *
	 * @since 1.13.0
	 *
	 * @param object 	$order
	 *
	 * @return boolean
	 */
	private function is_original_order( object $order ) : bool
	{
		return $order->get_parent_id() == 0;
	}

	/**
	 * Get user phone number.
	 *
	 * @since 1.13.0
	 *
	 * @param int 	$user_id
	 *
	 * @return string
	 */
	private function get_user_phone( int $user_id ) : string
	{
		$triibo_phone = get_user_meta( user_id: $user_id, key: "_triibo_phone", single: true );

		if ( empty( $triibo_phone ) )
			$triibo_phone = get_user_meta( user_id: $user_id, key: "triiboId_phone", single: true );

		return preg_replace( "/\D/", "", $triibo_phone );
	}

	/**
	 * Log user without account.
	 *
	 * @since 1.13.0
	 *
	 * @param int $order_id
	 * @param int $user_id
	 *
	 * @return void
	 */
	private function log_user_without_account( $order_id, $user_id ) : void
	{
		$this->log( "info", "Order: #{$order_id} | User: {$user_id} | without an account linked to Triibo" );
	}

	/**
	 * Check if the order is a processing subscription.
	 *
	 * @since 1.13.0
	 *
	 * @param int 		$order_id
	 * @param string 	$new_status
	 *
	 * @return boolean
	 */
	private function is_processing_subscription( int $order_id, string $new_status ) : bool
	{
		return $new_status === "processing" && wcs_order_contains_subscription( order: $order_id );
	}

	/**
	 * Check if the order is a completed purchase.
	 *
	 * @since 1.13.0
	 *
	 * @param int 		$order_id
	 * @param string 	$new_status
	 *
	 * @return boolean
	 */
	private function is_completed_purchase( int $order_id, string $new_status ) : bool
	{
		return $new_status === "completed" && !wcs_order_contains_subscription( order: $order_id );
	}

	/**
	 * Check if the user has already executed the event.
	 *
	 * @since 1.13.0
	 *
	 * @param int 		$user_id
	 * @param string 	$meta_key
	 *
	 * @return boolean
	 */
	private function has_already_executed_event( int $user_id, string $meta_key ) : bool
	{
		return ! empty( get_user_meta( user_id: $user_id, key: $meta_key, single: true ) );
	}

	/**
	 * Mark the event as executed.
	 *
	 * @since 1.13.0
	 *
	 * @param int 		$user_id
	 * @param string 	$meta_key
	 *
	 * @return void
	 */
	private function mark_event_executed( int $user_id, string $meta_key ) : void
	{
		add_user_meta( user_id: $user_id, meta_key: $meta_key, meta_value: true, unique: true );
	}

	/**
	 * Process VIP event.
	 *
	 * @param object 	$order
	 * @param int 		$user_id
	 * @param int 		$order_id
	 * @param string 	$triibo_id
	 * @param float 	$total
	 *
	 * @return void
	 */
	private function process_vip_event( object $order, int $user_id, int $order_id, string $triibo_id, float $total ) : void
	{
		foreach ( $order->get_items() as $item )
		{
			$product_id = $item->get_product_id();

			if ( isset( self::PLANS[ $this->get_env() ][ $product_id ] ) )
			{
				$has_cp  = $this->has_already_executed_event( $user_id, "has_cp_{$order_id}"  );
				$has_atv = $this->has_already_executed_event( $user_id, "has_atv_{$order_id}" );

				if ( !$has_cp )
				{
					$response = $this->compra_marketplace( $order, $triibo_id, $total );

					if ( $response[ "success" ] )
						$this->mark_event_executed( $user_id, "has_cp_{$order_id}" );
				}
				else
				{
					$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Compra_Marketplace'" );
				}

				if ( !$has_atv )
				{
					$event    = self::PLANS[ $this->get_env() ][ $product_id ];
					$response = $this->assinatura_tribo_vip( $order, $triibo_id, 1, $event );

					if ( $response[ "success" ] )
					{
						$this->mark_event_executed( $user_id, "has_atv_{$order_id}" );
						$this->update_user_orgs( $user_id, $order, $event );
					}
				}
				else
				{
					$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Assinatura_Triibo_Vip'" );
				}

				if ( !in_array( $product_id, self::SEU_CLUBE[ $this->get_env() ] ) )
				{
					$order->save();
					break;
				}
			}
		}
	}

	/**
	 * Process Seu Clube event.
	 *
	 * @since 1.13.0
	 *
	 * @param object 	$order
	 * @param int 		$user_id
	 * @param int 		$order_id
	 * @param string 	$triibo_id
	 *
	 * @return void
	 */
	private function process_seu_clube_event( object $order, int $user_id, int $order_id, string $triibo_id ) : void
	{
		foreach ( $order->get_items() as $item )
		{
			$product_id = $item->get_product_id();

			if ( in_array( $product_id, self::SEU_CLUBE[ $this->get_env() ] ) )
			{
				$has_seu_clube = $this->has_already_executed_event( $user_id, "has_seu_clube_{$order_id}" );

				if ( $has_seu_clube )
				{
					$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Seu_Clube'" );
					break;
				}

				$token = $this->api_node->get_token( user_id: $user_id );

				if ( is_null( $token ) )
				{
					$this->log( "error", "Order: #{$order_id} | {$user_id} | {$triibo_id}, unable to generate Node API token", true );
					break;
				}

				$this->mark_event_executed( $user_id, "has_seu_clube_{$order_id}" );
				$this->add_user_subscription( $user_id, $order, $product_id, $token );

				$order->save();
				break;
			}
		}
	}

	/**
	 * Process normal purchase events.
	 *
	 * #since 1.13.0
	 *
	 * @param object 	$order
	 * @param int 		$user_id
	 * @param int 		$order_id
	 * @param string 	$triibo_id
	 * @param float 	$total
	 *
	 * @return void
	 */
	private function process_normal_purchase( object $order, int $user_id, int $order_id, string $triibo_id, float $total ) : void
	{
		$has_cp = $this->has_already_executed_event( user_id: $user_id, meta_key: "has_cp_{$order_id}" );

		if ( $has_cp )
		{
			$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_id}, user has already been credited with 'Compra_Marketplace'" );
			return;
		}

		$response = $this->compra_marketplace( $order, $triibo_id, $total );

		if ( $response[ "success" ] )
			$this->mark_event_executed( $user_id, "has_cp_{$order_id}" );

		$order->save();
	}

	/**
	 * Valid if is a sub-order and sends event for Triibo.
	 *
	 * @since 1.13.0 	Refactored
	 * @since 1.1.0 	Rebuilt method to work with different statuses
	 * @since 1.0.0
	 *
	 * @param int 		$order_id
	 * @param string 	$old_status
	 * @param string 	$new_status
	 * @param object 	$order
	 *
	 * @return void
	 */
	public function order_status_change( int $order_id, string $old_status, string $new_status, object $order ) : void
	{
		if ( ! $this->is_original_order( $order ) )
			return;

		$user_id   = $order->get_user_id();
		$triibo_id = $this->get_user_phone( user_id: $user_id );

		if ( empty( $triibo_id ) )
		{
			$this->log_user_without_account( order_id: $order_id, user_id: $user_id );
			return;
		}

		if ( $this->is_processing_subscription( order_id: $order_id, new_status: $new_status ) )
		{
			$this->process_vip_event(
				order    : $order,
				user_id  : $user_id,
				order_id : $order_id,
				triibo_id: $triibo_id,
				total    : $order->get_total()
			);

			$this->process_seu_clube_event(
				order    : $order,
				user_id  : $user_id,
				order_id : $order_id,
				triibo_id: $triibo_id
			);

			return;
		}

		if ( $this->is_completed_purchase( order_id: $order_id, new_status: $new_status ) )
		{
			$this->process_normal_purchase(
				order    : $order,
				user_id  : $user_id,
				order_id : $order_id,
				triibo_id: $triibo_id,
				total    : $order->get_total()
			);

			return;
		}
	}

	/**
	 * Process subscription cancellation/expiration.
	 *
	 * @since 1.11.0 	Renamed to `subscription_ended` from `subscription_status_cancelled`
	 * @since 1.8.0
	 *
	 * @param object 	$subscription
	 *
	 * @return void
	 */
	public function subscription_ended( object $subscription ) : void
	{
		$subscription_id = $subscription->get_id();
		$user_id         = $subscription->get_user_id();

		foreach ( $subscription->get_items() as $item_id => $item )
		{
			$product_id = $item->get_product_id();

			$this->log( "info", "Canceling subscription #{$subscription_id}, user_id #{$user_id}" );

			// Seu Clube.
			if ( in_array( $product_id, self::SEU_CLUBE[ $this->get_env() ] ) )
			{
				$token = $this->api_node->get_token( user_id: $user_id );

				if ( is_null( $token ) )
				{
					$this->log( "error", "Subscription: #{$subscription_id} | {$user_id}, unable to generate Node API token", true );
					break;
				}

				$this->del_user_subscription( $user_id, $subscription, $product_id, $token );

				$subscription->save();
				break;
			}
		}
	}

	/**
	 * Triggered events when subscription renewal payment is confirmed.
	 * Similar to order_status_change().
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function subscription_renewal( $subscription, $order ) : void
	{
		$total          = $order->get_total();
		$order_id       = $order->get_id();
		$original_order = ( $order->get_parent_id() == 0 ) ? true : false;

		$user_id        = $order->get_user_id();
		$triibo_phone   = get_user_meta( $user_id, "_triibo_phone", true );
		$triibo_phone   = $triibo_phone ?: get_user_meta( $user_id, "triiboId_phone", true );
		$triibo_phone   = preg_replace( "/\D/", "", $triibo_phone );

		$has_cp         = get_user_meta( $user_id, "has_cp_{$order_id}",  true );
		$has_atv        = get_user_meta( $user_id, "has_atv_{$order_id}", true );

		if ( $original_order )
		{
			if ( !empty( $triibo_phone ) )
			{
				// assinatura
				foreach ( $order->get_items() as $item_id => $item )
				{
					// Triibo VIP
					if ( isset( self::PLANS[ $this->get_env() ][ $item->get_product_id() ] ) )
					{
						if ( empty( $has_cp ) )
						{
							/**
							 * Receives response from the API, if successful, adds the metadata to not execute the event again.
							 * @since 	1.3.2
							 */
							$response = $this->compra_marketplace( $order, $triibo_phone, $total );

							if ( $response[ "success" ] )
								add_user_meta( $user_id, "has_cp_{$order_id}", true, true );
						}
						else
						{
							$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_phone}, user has already been credited with 'Compra_Marketplace'" );
						}

						if ( empty( $has_atv ) )
						{
							$event    = self::PLANS[ $this->get_env() ][ $item->get_product_id() ];

							/**
							 * Receives response from the API, if successful, adds the metadata to not execute the event again.
							 * @since 	1.3.2
							 */
							$response = $this->assinatura_tribo_vip( $order, $triibo_phone, 1, $event );

							if ( $response[ "success" ] )
								add_user_meta( $user_id, "has_atv_{$order_id}", true, true );
						}
						else
						{
							$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_phone}, user has already been credited with 'Assinatura_Triibo_Vip'" );
						}

						$order->save();
						break;
					}
				}
			}
			else
			{
				$this->log( "info", "Order: #{$order_id} | User: {$user_id} | without an account linked to Triibo" );
			}
		}
	}

	/**
	 * Call event Compra_Marketplace.
	 *
	 * @deprecated 1.13.0 	Use 'call_event()' instead
	 *
	 * @since 1.3.2 	Added return value
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function compra_marketplace( &$order, $triibo_id, $total ) : array
	{
		$event = "Compra_Marketplace";

		return $this->call_event( $order, $event, $triibo_id, $total );
	}

	/**
	 * Call events: Assinatura_Triibo_Vip or Assinatura_Triibo_VIP_Plano_2 or Assinatura_Triibo_VIP_Plano_3.
	 *
	 * @deprecated 1.13.0 	Use call_event() instead
	 *
	 * @since 1.3.2 	Added return value
	 * @since 1.3.0 	Added parameter $event
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function assinatura_tribo_vip( &$order, $triibo_id, $total, $event ) : array
	{
		return $this->call_event( $order, $event, $triibo_id, $total );
	}

	/**
	 * Add note to order.
	 *
	 * @since 1.0.4
	 *
	 * @param object 	$order
	 * @param string 	$triibo_id
	 * @param string 	$event
	 * @param array 	$response
	 *
	 * @return void
	 */
	private function add_note( object &$order, string $triibo_id, string $event, array $response ) : void
	{
		$note = "Evento: {$event}";

		if ( $response[ "success" ] && isset( $response[ "data" ] ) && $response[ "data" ][ "success" ] )
		{
			$movement_id  = $response[ "data" ][ "movementID" ];
			$note        .= "\nCréditos/cupons adicionados a conta Triibo\nConta: {$triibo_id}\nMovimentação ID: {$movement_id}";
		}
		elseif ( $response[ "success" ] && isset( $response[ "data" ] ) && !$response[ "data" ][ "success" ] )
		{
			$note        .= "\nFalha ao adicionar créditos/cupons a conta Triibo\nConta: {$triibo_id}\nErro: API";
		}
		elseif ( !$response[ "success" ] && isset( $response[ "event" ] ) )
		{
			$note        .= "\nEvento não suportado\nConta: {$triibo_id}\nErro: MKT";
		}
		else
		{
			$note        .= "\nFalha ao adicionar créditos/cupons a conta Triibo\nConta: {$triibo_id}\nErro: MKT";
		}

		$order->add_order_note( note: $note );
	}

    /**
	 * Register logs.
	 *
	 * @since 1.10.1 	Fix log
	 * @since 1.9.0 	Changing to the new woocommerce standard
	 * 					Added params $level and $error
	 * 					Renamed param $data to $message
	 * @since 1.0.0
	 *
	 * @param string 	$level
	 * @param string 	$message
	 * @param bool 		$is_error
	 *
	 * @return void
     */
    public function log( string $level, string $message, bool $is_error = false ) : void
	{
		$logger  = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();

		$context = [ "source" => self::DOMAIN ];

		if ( $is_error || $this->get_env() === "hml" )
			$logger->$level( $message, $context );
	}

	/**
	 * Update user orgs.
	 *
	 * @since 1.5.2 	Added $order and $event parameters
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function update_user_orgs( $user_id, &$order, $event ) : void
	{
		$note     = "Evento: {$event}";
		$org      = self::ORGS[ $this->get_env() ];

		$id_add   = $org[ "add" ][ "id"   ];
		$name_add = $org[ "add" ][ "name" ];

		$id_del   = $org[ "del" ][ "id"   ];
		$name_del = $org[ "del" ][ "name" ];

		$uid      = get_user_meta( $user_id, "_triibo_id", true );

		/**
		 * Add org to user.
		 * Triibo VIP.
		 */
		$response = $this->api_gate->add_org( uid: $uid, org_id: $id_add );

		if ( !$response[ "success" ] )
		{
			$this->log( "error", print_r( [ "uid" => $uid, "response" => $response ], true ), true );

			$note .= "\n\nFalha ao adicionar organização ({$name_add}) ao usuário";
			$note .= "\n\nConta: {$uid}\nErro: API";

			$order->add_order_note( $note );

			return;
		}

		$note    .= "\n\nOrganização ($name_add) adicionada com sucesso";

		/**
		 * Remove org from user.
		 * Triibo.
		 */
		$response = $this->api_gate->del_org( uid: $uid, org_id: $id_del );

		if ( !$response[ "success" ] )
		{
			$this->log( "error", print_r( [ "uid" => $uid, "response" => $response ], true ), true );

			$note .= "\n\nFalha ao remover organização ({$name_del}) do usuário";
			$note .= "\n\nConta: {$uid}\nErro: API";

			$order->add_order_note( $note );

			return;
		}

		$note    .= "\n\nOrganização ($name_del) removida com sucesso";
		$note    .= "\n\nConta: {$uid}";

		$order->add_order_note( $note );
	}

	/**
	 * Add user subscription Seu Clube.
	 *
	 * @deprecated 1.13.0 	Use 'toggle_user_subscription()' instead
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	private function add_user_subscription( $user_id, &$order, $product_id, $token ) : void
	{
		$note     = "Evento: Assinatura Seu Clube";

		$uid      = get_user_meta( $user_id, "_triibo_id", true );

		$params   = [
			"productId" => ( string ) $product_id,
			"uid"       => $uid
		];

		$response = $this->api_node->add_subscription( token: $token, params: $params );

		if ( !$response[ "success" ] )
		{
			$this->log( "error", print_r( [ "uid" => $uid, "response" => $response ], true ), true );

			$note .= "\n\nFalha ao adicionar Assinatura Seu Clube ao usuário";
			$note .= "\n\nConta: {$uid}\nErro: API";

			$order->add_order_note( $note );

			return;
		}

		$note    .= "\n\nAssinatura Seu Clube adicionada com sucesso";
		$note    .= "\n\nConta: {$uid}";

		$order->add_order_note( $note );
	}

	/**
	 * Delete user subscription Seu Clube.
	 *
	 * @deprecated 1.13.0 	Use 'toggle_user_subscription()' instead
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	private function del_user_subscription( $user_id, &$subscription, $product_id, $token ) : void
	{
		$note     = "Evento: Assinatura Seu Clube";

		$uid      = get_user_meta( $user_id, "_triibo_id", true );

		$params   = [
			"productId" => ( string ) $product_id,
			"uid"       => $uid
		];

		$response = $this->api_node->del_subscription( token: $token, params: $params );

		if ( !$response[ "success" ] )
		{
			$this->log( "error", print_r( [ "uid" => $uid, "response" => $response ], true ), true );

			$note .= "\n\nFalha ao cancelar Assinatura Seu Clube do usuário";
			$note .= "\n\nConta: {$uid}\nErro: API";

			$subscription->add_order_note( $note );

			return;
		}

		$note    .= "\n\nAssinatura Seu Clube cancelada com sucesso";
		$note    .= "\n\nConta: {$uid}";

		$subscription->add_order_note( $note );
	}

	/**
	 * Expose valid events via filter to other plugins.
	 *
	 * @since 1.12.0
	 *
	 * @return array
	 */
	public static function get_valid_events() : array
	{
		$events = [];

		foreach ( self::PLANS as $env )
			foreach ( $env as $event )
				$events[] = $event;

		return array_unique( $events );
	}
}

/**
 * Load Triibo Events.
 *
 * @since 1.0.0
 */
add_action(
	hook_name: "plugins_loaded",
	callback : [ "Triibo_Events", "get_instance" ],
	priority : 222
);

/**
 * Filter to get valid events.
 *
 * @since 1.12.0
 *
 * @return array
 */
add_filter(
	hook_name: "triibo_events_valid_events",
	callback : function( $events )
	{
		return Triibo_Events::get_valid_events();
	}
);
