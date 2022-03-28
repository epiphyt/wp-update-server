<?php
/**
 * Class Epiphyt_Server.
 * 
 * @author		Matthias Kittsteiner
 * @license		GPL2
 */
class Epiphyt_Server extends Wpup_UpdateServer {
	/**
	 * The WooCommerce API URLs.
	 * 
	 * @var string[] The API URLs
	 */
	public $woo_servers = [
		'https://epiph.yt/wocommerce/?wc-api=software-api',
		'https://epiph.yt/en/wocommerce/?wc-api=software-api',
	];
	
	public function __construct($serverUrl = null, $serverDirectory = null) {
		parent::__construct( $serverUrl, $serverDirectory );
		
		$this->enableIpAnonymization();
	}
	
	/**
	 * Check for license key.
	 * 
	 * @param object $request
	 */
	protected function checkAuthorization( $request ) {
		parent::checkAuthorization( $request );
		
		// prevent download if the user doesn't have a valid license.
		$email = $request->param( 'email' );
		$home_url = $request->param( 'platform' );
		$license_key = $request->param( 'license_key' );
		$product_id = $request->param( 'product_id' );
		$software_version = $request->param( 'installed_version' );
		
		if ( $request->action === 'download' && ! ( $license_key && $this->isValidLicense( $email, $home_url, $license_key, $product_id, $request, $software_version ) ) ) {
			if ( empty( $license_key ) ) {
				$message = 'You must provide a license key to download this plugin.';
			}
			else {
				$message = 'Sorry, your license is not valid.';
			}
			
			$this->exitWithError( $message, 403 );
		}
	}
	
	/**
	 * Add license key as URL param if there is a valid license.
	 * 
	 * @param array $meta
	 * @param \Wpup_Request $request
	 * @return array
	 */
	protected function filterMetadata( $meta, $request ) {
		$meta = parent::filterMetadata( $meta, $request );
		
		$email = $request->param( 'license_email' );
		$home_url = $request->param( 'platform' );
		$license_key = $request->param( 'license_key' );
		$product_id = $request->param( 'product_id' );
		$software_version = $request->param( 'installed_version' );
		
		// only include the download URL if the license is valid
		if ( $license_key && $this->isValidLicense( $email, $home_url, $license_key, $product_id, $request, $software_version ) ) {
			// append required fields to the download URL
			$args = [
				'email' => $email,
				'license_key' => $license_key,
				'platform' => $home_url,
				'product_id' => $product_id,
			];
			$meta['download_url'] = self::addQueryArg( $args, $meta['download_url'] );
		}
		
		return $meta;
	}
	
	/**
	 * Check if license is valid.
	 * 
	 * @param string $email
	 * @param string $home_url
	 * @param string $license_key
	 * @param string $product_id
	 * @param \Wpup_Request $request
	 * @param string $software_version
	 * @return bool
	 */
	protected function isValidLicense( $email, $home_url, $license_key, $product_id, $request, $software_version = '1.0' ) {
		$data = [
			'email' => $email,
			'license_key' => $license_key,
			'product_id' => $product_id,
			'request' => 'check',
			'software_version' => ( ! empty( $software_version ) ? $software_version : '1.0' ),
		];
		
		$response = $this->get_api_data( $data );
		
		// return false if there is no data
		if ( ! is_object( $response ) || ! $response->success ) {
			return false;
		}
		
		// check platform
		if ( $home_url && ! in_array( $home_url, array_column( $response->activations, 'activation_platform' ) ) ) {
			return false;
		}
		
		// check software version
		$is_valid = true;
		
		foreach ( $response->activations as $activation ) {
			if ( version_compare( $this->remove_patch_version( $activation->software_version ), $this->remove_patch_version( $request->package->getMetadata()['version'] ), '>=' ) ) {
				return true;
			}
			
			$is_valid = false;
		}
		
		return $is_valid;
	}
	
	/**
	 * Use cURL to get data from any URL.
	 * 
	 * @param array $data
	 * @return mixed|string
	 */
	private function get_api_data( $data = [] ) {
		$responses = [];
		
		foreach ( $this->woo_servers as $url ) {
			$responses[ $url ] = $this->get_single_api_data( $url, $data );
		}
		
		foreach ( $responses as $response ) {
			if ( is_object( $response ) && is_array( $response->activations ) ) {
				return $response;
			}
		}
		
		return reset( $responses );
	}
	
	/**
	 * Use cURL to get data from any URL.
	 * 
	 * @param string $url
	 * @param array $data
	 * @return mixed|string
	 */
	private function get_single_api_data( $url, $data = [] ) {
		$curl = curl_init();
		$header = [ 'cache-control: no-cache' ];
		
		// add params to URL
		// there needs to be already a param
		// otherwise, add ? for the first item
		foreach ( $data as $key => $param ) {
			$url .= '&' . $key . '=' . urlencode( $param );
		}
		
		curl_setopt_array( $curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => $header,
			
		] );
		
		$response = curl_exec( $curl );
		$error = curl_error( $curl );
		
		curl_close( $curl );
		
		if ( ! empty( $error ) ) {
			return $error;
		}
		
		if ( self::isJson( $response ) ) {
			$response = json_decode( $response );
		}
		
		return $response;
	}
	
	/**
	 * Check if given string is a JSON.
	 * 
	 * @param string $maybe_json
	 * @return bool
	 */
	private static function isJson( $maybe_json ) {
		// try decoding the string
		json_decode( $maybe_json );
		
		// return true if there was no error on previous decode
		// otherwise false
		return (bool) ( json_last_error() == JSON_ERROR_NONE );
	}
	
	/**
	 * Remove the 1.1.x to be 1.1.
	 * 
	 * @param $version
	 * @return false|string
	 */
	private function remove_patch_version( $version ) {
	    $dot_position = strrpos( $version, '.' );
	    
	    if ( $dot_position > 2 ) {
	        return substr( $version, 0, $dot_position );
	    }
	    
	    return $version;
	}
}
