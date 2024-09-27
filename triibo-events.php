<?php
/**
 * Plugin Name: 			#Triibo_Eventos
 * Plugin URI: 				https://triibo.com.br
 * Description: 			Dispara eventos para a Triibo dada certas condições. Dependências: Triibo API Services, WooCommerce e WooCommerce Subscription.
 * Author: 					Mateus Costa
 * Author URI: 				https://costamateus.com.br/
 * Version: 				1.9.0
 * Text Domain: 			triibo_events
 * Requires at least: 		6.4
 * Requires PHP: 			7.4
 * WC requires at least: 	8.0
 * WC tested up to: 		8.2
 *
 * @package 	Triibo_Events
 * @version 	1.9.0
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
	const VERSION = "1.9.0";

	/**
	 * Plugin domain.
	 *
	 * @since 	1.0.0
	 * @var 	string
	 */
	const DOMAIN  = "triibo_events";

	/**
	 * Subscription Triibo VIP.
	 *
	 * @since 	1.6.0 Added new plans.
	 * @since 	1.5.0 Added env.
	 * @since 	1.3.0
	 * @var 	array
	 */
	const PLANS   = [
		"hml" => [
			816851 => "Assinatura_Triibo_Vip",
			816853 => "Assinatura_Triibo_Vip_Plano_2",
			816854 => "Assinatura_Triibo_Vip_Plano_3"
		],
		"prd" => [
			939930 => "Assinatura_Triibo_Vip",         // product_id => event_ATV Plano 1
			957852 => "Assinatura_Triibo_Vip_Plano_2", // product_id => event_ATV Plano 2
			957855 => "Assinatura_Triibo_Vip_Plano_3", // product_id => event_ATV Plano 3

			// New plans
			981120 => "Assinatura_Triibo_Vip_Plano_4",
			981121 => "Assinatura_Triibo_Vip_Plano_5",
			981122 => "Assinatura_Triibo_Vip_Plano_6",
		]
	];

	/**
	 * Orgs according to the env.
	 *
	 * @since 	1.5.2 	Added 'id' and 'name' indexes.
	 * @since 	1.5.0
	 * @var 	array
	 */
	const ORGS    = [
		"hml" => [
			"add" => [
				"id"   => "-Ngta5dXbnSxCJravalh", // vip
				"name" => "Triibo V.I.P",
			],
			"del" => [
				"id"   => "mlmtgPFBctdnJeJ9Bz4i", // normal
				"name" => "Triibo",
			]
		],
		"prd" => [
			"add" => [
				"id"   => "-NVUhxocgIAf_vgFmYvx", // vip
				"name" => "Triibo V.I.P",
			],
			"del" => [
				"id"   => "triibo",               // normal
				"name" => "Triibo",
			]
		]
	];

	/**
	 * @since 	1.8.0
	 * @var 	array
	 */
	const SEU_CLUBE = [
		"hml" => [
			114308, // assinatura teste
		],
		"prd" => [
			986463, // Club do Golfista
			986464, // Morro Experience
		],
	];

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
	 * Triibo Api Services - Node.
	 *
	 * @since 	1.8.0
	 * @var 	Triibo_Api_Node
	 */
	protected $api_node;

	/**
	 * Environment.
	 *
	 * @var 	string
	 */
	protected $env;

	/**
	 * Construct.
	 *
	 * @since 	1.8.0 	Added Triibo_Api_Node.
	 * @since 	1.7.0 	Added HPOS compatibility declaration.
	 * @since 	1.5.0 	Added var $env.
	 * @since 	1.4.0 	Added plugin change history.
	 * @since 	1.0.0
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

			add_action( "woocommerce_order_status_changed",                  [ $this, "order_status_change"           ], 10, 4 );
			add_action( "woocommerce_subscription_status_cancelled",         [ $this, "subscription_status_cancelled" ], 10, 1 );
			add_action( "woocommerce_subscription_renewal_payment_complete", [ $this, "subscription_renewal"          ], 10, 2 );

			$this->log      = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
			$this->api      = new Triibo_Api_Gateway();

			/**
			 * @since 1.5.0
			 */
			$this->env      = $this->get_env();

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
	 * @since 	1.5.0
	 * @return 	string 	hml|prd
	 */
	private function get_env()
	{
		return ( get_option( "home" ) === "https://marketplace.triibo.com.br" )
				? "prd" : "hml";
	}

	/**
	 * Call event.
	 *
	 * @since 	1.3.2 	Added return value.
	 * @since 	1.1.0
	 * @return 	array
	 */
	private function call_event( $event, &$order, $triibo_id, $total )
	{
		$response = $this->api->credit_points( $triibo_id, $total, $event );

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
	 * Setup WooCommerce HPOS compatibility.
	 *
	 * @since 	1.7.0
	 * @return 	void
	 */
	public function setup_hpos_compatibility() : void
	{
		if ( defined( "WC_VERSION" ) && version_compare( WC_VERSION, "7.1", "<" ) )
			return;

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) )
		{
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				"custom_order_tables",
				__FILE__,
				true
			);
		}
	}

	/**
	 * Add link to changelog modal.
	 *
	 * @since 	1.4.0
	 * @param 	array 	$plugin_meta
	 * @param 	string 	$plugin_file
	 * @param 	array 	$plugin_data
	 * @return 	array
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data )
	{
		$path = path_join( WP_PLUGIN_DIR, $plugin_file );

		if ( DIRECTORY_SEPARATOR == "\\" )
			$path = str_replace( "/", "\\", $path );

        if ( __FILE__ === $path )
		{
            $url = plugins_url( "readme.txt", __FILE__ );

            $plugin_meta[] = sprintf(
                "<a href='%s' class='thickbox open-plugin-details-modal' aria-label='%s' data-title='%s'>%s</a>",
                add_query_arg( "TB_iframe", "true", $url ),
                esc_attr( sprintf( __( "More information about %s" ), $plugin_data[ "Name" ] ) ),
                esc_attr( $plugin_data[ "Name" ] ),
                __( "Histórico de alterações" )
            );
        }

        return $plugin_meta;
	}

	/**
	 * adds 'Eventos' submenu to Triibo Services main menu.
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
	 * Template for submenu.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/templates/admin-page.php";
	}

	/**
	 * Link to plugin settings.
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
	 * Add this plugin's dependency in the Triibo Api Services settings.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function add_info_dependency()
	{
		echo "<p> - Triibo Eventos</p>";
	}

	/**
	 * Valid if is a sub-order and sends event for Triibo.
	 *
	 * @since 	1.1.0 	Rebuilt method to work with different statuses.
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
		$triibo_phone   = get_user_meta( $user_id, "_triibo_phone", true );
		$triibo_phone   = $triibo_phone ?: get_user_meta( $user_id, "triiboId_phone", true );
		$triibo_phone   = preg_replace( "/\D/", "", $triibo_phone );

		if ( $original_order )
		{
			if ( !empty( $triibo_phone ) )
			{
				/**
				 * Checking whether the Compra_Marketplace event has already been executed.
				 * @since 	1.0.3
				 */
				$has_cp = get_user_meta( $user_id, "has_cp_{$order_id}", true );

				// Subscription.
				if ( $is_processing && wcs_order_contains_subscription( $order_id ) )
				{
					foreach ( $order->get_items() as $item_id => $item )
					{
						$product_id = $item->get_product_id();

						// Triibo VIP.
						if ( isset( self::PLANS[ $this->env ][ $product_id ] ) )
						{
							/**
							 * Checking if the Assinatura_Triibo_Vip event has already been executed.
							 * @since 	1.1.0
							 */
							$has_atv = get_user_meta( $user_id, "has_atv_{$order_id}", true );

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
								$event = self::PLANS[ $this->env ][ $product_id ];

								/**
								 * Receives response from the API, if successful, adds the metadata to not execute the event again.
								 * @since 	1.3.2
								 */
								$response = $this->assinatura_tribo_vip( $order, $triibo_phone, 1, $event );

								if ( $response[ "success" ] )
								{
									add_user_meta( $user_id, "has_atv_{$order_id}", true, true );

									/**
									 * @since 1.5.0
									 */
									$this->update_user_orgs( $user_id, $order, $event );
								}
							}
							else
							{
								$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_phone}, user has already been credited with 'Assinatura_Triibo_Vip'" );
							}

							$order->save();
							break;
						}

						// Seu Clube.
						if ( in_array( $product_id, self::SEU_CLUBE[ $this->env ] ) )
						{
							/**
							 * Checking if the Seu_Clube event has already been executed.
							 * @since 	1.8.0
							 */
							$has_seu_clube = get_user_meta( $user_id, "has_seu_clube_{$order_id}", true );

							if ( $has_seu_clube )
							{
								$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_phone}, user has already been credited with 'Seu_Clube'" );
								break;
							}

							$token      = $this->api_node->get_token( $user_id );

							if ( is_null( $token ) )
							{
								$this->log( "error", "Order: #{$order_id} | {$user_id} | {$triibo_phone}, unable to generate Node API token", true );
								break;
							}

							add_user_meta( $user_id, "has_seu_clube_{$order_id}", true, true );

							$this->add_user_subscription( $user_id, $order, $product_id, $token );

							$order->save();
							break;
						}
					}
				}

				// Normal purchase.
				if ( $is_completed && !wcs_order_contains_subscription( $order_id ) )
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

						$order->save();
					}
					else
					{
						$this->log( "info", "Order: #{$order_id} | {$user_id} | {$triibo_phone}, user has already been credited with 'Compra_Marketplace'" );
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
	 * Process subscription cancellation.
	 *
	 * @since 	1.8.0
	 * @param 	object 	$subscription
	 * @return 	void
	 */
	public function subscription_status_cancelled( $subscription )
	{
		$subscription_id = $subscription->get_id();
		$user_id         = $subscription->get_user_id();

		foreach ( $subscription->get_items() as $item_id => $item )
		{
			$product_id = $item->get_product_id();

			$this->log( "info", "Canceling subscription #{$subscription_id}, user_id #{$user_id}" );

			// Seu Clube.
			if ( in_array( $product_id, self::SEU_CLUBE[ $this->env ] ) )
			{
				$token = $this->api_node->get_token( $user_id );

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
	 * @since 	1.2.0
	 * @return 	void
	 */
	public function subscription_renewal( $subscription, $order )
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
					if ( isset( self::PLANS[ $this->env ][ $item->get_product_id() ] ) )
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
							$event    = self::PLANS[ $this->env ][ $item->get_product_id() ];

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
	 * @since 	1.3.2 	Added return value.
	 * @since 	1.1.0
	 * @return 	array
	 */
	public function compra_marketplace( &$order, $triibo_id, $total )
	{
		$event = "Compra_Marketplace";

		return $this->call_event( $event, $order, $triibo_id, $total );
	}

	/**
	 * Call events: Assinatura_Triibo_Vip or Assinatura_Triibo_VIP_Plano_2 or Assinatura_Triibo_VIP_Plano_3.
	 *
	 * @since 	1.3.2 	Added return value.
	 * @since 	1.3.0 	Added parameter $event.
	 * @since 	1.1.0
	 * @return 	array
	 */
	public function assinatura_tribo_vip( &$order, $triibo_id, $total = 1, $event )
	{
		return $this->call_event( $event, $order, $triibo_id, $total );
	}

	/**
	 * Add note to order.
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

		$order->add_order_note( $note );
	}

    /**
	 * Register logs.
	 *
	 * @since 	1.9.0 	Changing to the new woocommerce standard.
	 * 					Added params $level and $error.
	 * 					Renamed param $data to $message.
	 * @since 	1.0.0
	 * @param 	string 	$level
	 * @param 	string 	$message
	 * @param 	bool 	$is_error
	 * @return 	void
     */
    public function log( string $level, string $message, bool $is_error = false )
	{
		$logger  = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();

		$context = [ "source" => self::DOMAIN ];

		if ( $is_error || $this->get_env === "hml" )
			$logger->$level( $message, $context );
	}

	/**
	 * Update user orgs.
	 *
	 * @since 	1.5.2 	Added $order and $event parameters.
	 * @since 	1.5.0
	 * @return 	void
	 */
	public function update_user_orgs( $user_id, &$order, $event )
	{
		$note     = "Evento: {$event}";
		$org      = self::ORGS[ $this->env ];

		$id_add   = $org[ "add" ][ "id"   ];
		$name_add = $org[ "add" ][ "name" ];

		$id_del   = $org[ "del" ][ "id"   ];
		$name_del = $org[ "del" ][ "name" ];

		$uid      = get_user_meta( $user_id, "_triibo_id", true );

		/**
		 * Add org to user.
		 * Triibo VIP.
		 */
		$response = $this->api->add_org( $uid, $id_add );

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
		$response = $this->api->del_org( $uid, $id_del );

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
	 * @since 	1.8.0
	 * @return 	void
	 */
	public function add_user_subscription( $user_id, &$order, $product_id, $token )
	{
		$note     = "Evento: Assinatura Seu Clube";

		$uid      = get_user_meta( $user_id, "_triibo_id", true );

		$params   = [
			"productId" => ( string ) $product_id,
			"uid"       => $uid
		];

		$response = $this->api_node->add_subscription( $token, $params );

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
	 * @since 	1.8.0
	 * @return 	void
	 */
	public function del_user_subscription( $user_id, &$subscription, $product_id, $token )
	{
		$note     = "Evento: Assinatura Seu Clube";

		$uid      = get_user_meta( $user_id, "_triibo_id", true );

		$params   = [
			"productId" => ( string ) $product_id,
			"uid"       => $uid
		];

		$response = $this->api_node->del_subscription( $token, $params );

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
}

add_action( "plugins_loaded", array( "Triibo_Events", "get_instance" ), 222 );
