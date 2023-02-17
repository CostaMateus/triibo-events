<?php
/**
 * Missing Triibo API Services notice.
 *
 * @package Triibo_Events/Admin/Notices
 */
defined( "ABSPATH" ) || exit;

$is_installed = false;

if ( function_exists( "get_plugins" ) )
{
	$all_plugins  = get_plugins();
	$key          = "triibo-api-services/triibo-api-services.php";
	$is_installed = array_key_exists($key, $all_plugins);
}
?>

<div class="error">
	<p>
		<strong>
			<?php esc_html_e( "#Triibo_Eventos", Triibo_Events::DOMAIN ); ?>
		</strong>
		<?php esc_html_e( "depende da última versão do 'Triibo API Services' para funcionar!", Triibo_Events::DOMAIN ); ?>
	</p>

	<?php if ( $is_installed && current_user_can( "install_plugins" ) ) : ?>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( self_admin_url( "plugins.php?action=activate&plugin=triibo-api-services/triibo-api-services.php&plugin_status=active" ), "activate-plugin_triibo-api-services/triibo-api-services.php" ) ); ?>" class="button button-primary">
				<?php esc_html_e( "Ative 'Triibo API Services'", Triibo_Events::DOMAIN ); ?>
			</a>
		</p>
	<?php else : ?>
		<p>
			<?php esc_html_e( "Instale 'Triibo API Services'", Triibo_Events::DOMAIN ); ?>
		</p>
	<?php endif; ?>
</div>
