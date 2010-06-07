<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2009, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_oauth\extensions\service;

/**
 * Oauth service class for handling requests/response to consumers and from providers
 *
 *
 */
class Oauth extends \lithium\net\http\Service {

	protected $_autoConfig = array('classes' => 'merge');

	/**
	 * Fully-namespaced class references
	 *
	 * @var array
	 */
	protected $_classes = array(
		'media'    => '\lithium\net\http\Media',
		'request'  => '\lithium\net\http\Request',
		'response' => '\lithium\net\http\Response',
		'socket'   => '\lithium\net\socket\Context',
	);

	/**
	 * Constructor
	 *
	 * @param array $config
	 *              - host: the oauth domain
	 *              - oauth_consumer_key: key from oauth service provider
	 *              - oauth_consumer_secret: secret from oauth service provider
	 *              - oauth_consumer_key: key from oauth service provider
	 *              - authorize: path to authorize  url
	 *              - request_token: path to request token url
	 *              - access_token: path to access token url
	 */
	public function __construct($config = array()) {
		$defaults = array(
			'host' => 'localhost',
			'port' => 80,
			'authorize' => '/oauth/authorize',
			'authenticate' => '/oauth/authenticate',
			'request' => '/oauth/request_token',
			'access' => '/oauth/access_token',
			'oauth_consumer_key' => 'key',
			'oauth_consumer_secret' => 'secret'
		);
		$config += $defaults;

		parent::__construct($config);
	}

	/**
	 * Initialize classes to be used
	 *
	 * @return void
	 */
	public function _init() {
		parent::_init();
		$config = $this->_config;

		if (!empty($config['proxy'])) {
			$config['host'] = $config['proxy'];
		}
	}

	/**
	 * If a key is set returns the value of that key
	 * Without a key it will return config array
	 *
	 * @param string $key eg `oauth_consumer_key`
	 * @return void
	 */
	public function config($key = null) {
		if (isset($this->_config[$key])) {
			return $this->_config[$key];
		}
		if ($key !== null) {
			return $key;
		}
		return $this->_config;
	}

	/**
	 * Send request with the given options and data. The token should be part of the options.
	 *
	 * @param string $method
	 * @param string $url
	 * @param array $data encoded for the request
	 * @param array $options oauth parameters
	 *              - headers : send parameters in the header. (default: true)
	 *              - realm : the realm to authenticate. (default: app directory name)
	 * @return void
	 */
	public function send($method, $path = null, $data = array(), array $options = array()) {
		$defaults = array(
			'headers' => true, 
			'realm' => basename(LITHIUM_APP_PATH), 
			'method' => $method, 
			'port' => $this->_config['port']
		);
		$options += $defaults;
		$url = $this->config($path);
		$oauth = $this->sign($options + compact('data', 'url'));

		if ($options['headers']) {
			$auth = 'OAuth realm="' . $options['realm'] . '",';
			foreach ($oauth as $key => $val) {
				$auth .= $key . '="' . rawurlencode($val) . '",';
			}
			$options['headers'] = array('Authorization' => $auth);
			$oauth = array();
		} 
		$data += $oauth;

		$response = parent::send($method, $url, $data, $options);

		if (strpos($response, 'oauth_token=') !== false) {
			return $this->_decode($response);
		}
		return $response;
	}

	/**
	 * A utility method to return a authorize or authenticate url for redirect
	 *
	 * @param string $url
	 * @return void
	 */
	public function url($url = null, $token = array()) {
		$url = $url ? $this->config($url) : null;

		if (!empty($token['oauth_token'])) {
			$url = "{$url}?oauth_token={$token['oauth_token']}";
		}

		return "http://"  . str_replace('//', '/', "{$this->_config['host']}:{$this->_config['port']}/{$url}");
	}

	/**
	 * Sign the request
	 *
	 * @param string $options
	 *               - method: POST
	 *               - url: url of request
	 *               - oauth_signature_method: HMAC-SHA1
	 *               - secret: config['oauth_consumer_secret']
	 *               - params: extra params for to sign request
	 *               - data: post data for request
	 *               - token: array with keys oauth_token, oauth_token_secret
	 * @return void
	 */
	public function sign($options = array()) {
		$defaults = array(
			'url' => '', 'method' => 'POST',
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_consumer_secret' => $this->_config['oauth_consumer_secret'],
			'params' => array(), 'data' => array(),
			'token' => array('oauth_token' => null, 'oauth_token_secret' => null),
		);
		$options += $defaults;
		$params = $this->_params((array) $options['params'] + (array) $options['token']);
		$base = $this->_base($options['method'], $options['url'], ($params + (array) $options['data']));

		$key = join("&", array(
			rawurlencode($options['oauth_consumer_secret']),
			rawurlencode($options['token']['oauth_token_secret'])
		));
		switch ($options['oauth_signature_method']) {
			case 'HMAC-SHA1':
				$signature = base64_encode(hash_hmac('sha1', $base, $key, true));
			break;
			default:
				return $options['token']['oauth_token'];
			break;
		}
		$params['oauth_signature'] = $signature;
		return $params;
	}

	/**
	 * undocumented function
	 *
	 * @param string $method
	 * @param string $url
	 * @param string $params
	 * @return void
	 */
	protected function _base($method, $url, $params) {
		uksort($params, 'strcmp');
		$query = array();
		array_walk($params, function ($value, $key) use (&$query){
			$query[] = $key . '=' . rawurlencode($value);
		});
		$path = $this->url($url);
		return join("&", array(strtoupper($method), rawurlencode($path), rawurlencode(join('&', $query))));
	}

	/**
	 * Handles Oauth specific parameters to ensure they have correct values and order.
	 *
	 * @param string $params
	 * @return array $params
	 */
	protected function _params($params = array()) {
		$defaults =  array(
			'oauth_consumer_key' => 'key',
			'oauth_nonce' => sha1(time() . mt_rand()),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => time(),
			'oauth_token' => '',
			'oauth_version' => '1.0'
		);
		$result = array();

		foreach ($defaults as $key => $value) {
			if (isset($params[$key])) {
				$result[$key] = $params[$key];
				continue;
			}
			if (isset($this->_config[$key])) {
				$result[$key] = $this->_config[$key];
				continue;
			}
			if ($value) {
				$result[$key] = $value;
			}
		}
		ksort($result);
		return $result;
	}

	/**
	 * Decodes the response.
	 *
	 * @param string $path
	 * @return void
	 */
	protected function _decode($query = null) {
		$token = array();
		$result = array_filter(explode('&', $query), function ($value) use (&$token) {
			$parts = explode("=", $value);
			if (count($parts) > 1) {
				$token[rawurldecode($parts[0])] = rawurldecode($parts[1]);
			}
			return false;
		});
		return $token;
	}
}
?>