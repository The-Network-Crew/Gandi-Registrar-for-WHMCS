<?php

namespace WHMCS\Module\Registrar\Gandi;

use WHMCS\Module\Registrar\Gandi\SpecialFields;

class domainAPI {
	private $endPoint;
	private $apiKey;
	private $sharingId;
	private $registrar = GANDI_REGISTRAR_PRODUCT_NAME;
	private static $cache = [];

	public function __construct( $apiKey, $sharingId = '' ) {
		$this->endPoint  = 'https://api.gandi.net/v5';
		$this->apiKey    = $apiKey;
		$this->sharingId = $sharingId;
	}

	public function invalidateCache( $scope = 'all' ) {
		if ( 'all' === $scope ) {
			self::$cache = [];
		} else {
			$cache       = self::$cache;
			self::$cache = [];
			foreach ( $cache as $k => $v ) {
				if ( ! str_contains( $k, $scope ) ) {
					self::$cache[ $k ] = $v;
				}
			}
		}
		if ( GANDI_LOG_CACHE ) {
			logModuleCall( $this->registrar, __FUNCTION__, 'scope: ' . $scope, 'done' );
		}
	}

	public function getDomainInfo( $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	public function getTransferInfo( $domain ) {
		$url      = "{$this->endPoint}/domain/transferin/{$domain}";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	public function registerDomain( $domain, $contacts, $nameservers, $period, $additionalfields, $template ) {
		$url          = "{$this->endPoint}/domain/domains";
		$params       = [
			'fqdn'        => $domain,
			'duration'    => $period,
			'owner'       => (object) $this->generateOwner( $contacts, $additionalfields ),
			'template_id' => $template
		];
		$domainextras = SpecialFields::getDomain( $additionalfields );
		if ( 0 < count( $domainextras ) ) {
			$params['extra_parameters'] = (object) $domainextras;
		}
		if ( 0 < count( $nameservers ) ) {
			foreach ( $nameservers as $k => $v ) {
				if ( ! $v ) {
					unset( $nameservers[ $k ] );
				}
			}
			if ( 0 < count( $nameservers ) ) {
				$params['nameservers'] = $nameservers;
			}
		}
		$response = $this->sendOrGetCached( $url, 'POST', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $params, $response );

		return $response;
	}

	public function transferDomain( $domain, $contacts, $nameservers, $period, $authCode, $additionalfields, $template ) {
		$url          = "{$this->endPoint}/domain/transferin";
		$params       = [
			'fqdn'     => $domain,
			'duration' => $period,
			'owner'    => (object) $this->generateOwner( $contacts, $additionalfields ),
			'template_id' => $template,
			'authinfo' => $authCode
		];
		$domainextras = SpecialFields::getDomain( $additionalfields );
		if ( 0 < count( $domainextras ) ) {
			$params['extra_parameters'] = $domainextras;
		}
		if ( 0 < count( $nameservers ) ) {
			foreach ( $nameservers as $k => $v ) {
				if ( ! $v ) {
					unset( $nameservers[ $k ] );
				}
			}
			if ( 0 < count( $nameservers ) ) {
				$params['nameservers'] = $nameservers;
			}
		}
		$response = $this->sendOrGetCached( $url, 'POST', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $params, $response );

		return $response;
	}

	private function generatePassword( $length = 8 ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$count = mb_strlen( $chars );
		for ( $i = 0, $result = ''; $i < $length; $i ++ ) {
			$index  = rand( 0, $count - 1 );
			$result .= mb_substr( $chars, $index, 1 );
		}

		return $result;
	}

	private function generateOwner( $contacts, $additionalfields ) {
		$languages_mapping = [
			'english'  => 'en',
			'spanish'  => 'es',
			'french'   => 'fr',
			'japanese' => 'ja',
			'chinese'  => 'zh-hant',
		];
		$legaltype         = ( empty( $contacts['owner']['orgname'] ) ? 'individual' : 'company' );
		if ( array_key_exists( 'x-gandi-legal-type', $additionalfields ) && 'unknown' !== $additionalfields['x-gandi-legal-type'] ) {
			$legaltype = $additionalfields['x-gandi-legal-type'];
		}
		$owner         = [
			'country'         => empty( $contacts['owner']['countrycode'] ) ? 'FR' : $contacts['owner']['countrycode'],
			'email'           => empty( $contacts['owner']['email'] ) ? 'unknown@example.com' : $contacts['owner']['email'],
			'family'          => empty( $contacts['owner']['lastname'] ) ? 'unspecified' : $contacts['owner']['lastname'],
			'given'           => empty( $contacts['owner']['firstname'] ) ? 'unspecified' : $contacts['owner']['firstname'],
			'streetaddr'      => empty( $contacts['owner']['address'] ) ? 'unspecified' : $contacts['owner']['address'],
			'orgname'         => empty( $contacts['owner']['orgname'] ) ? 'unspecified' : $contacts['owner']['orgname'],
			'type'            => $legaltype,
			'data_obfuscated' => true,  // Forces ID Protect
			'mail_obfuscated' => true,  // Forces ID Protect
		];
		if ( ! empty( $contacts['owner']['city'] ) ) {
			$owner['city'] = $contacts['owner']['city'];
		}
		if ( ! empty( $contacts['owner']['postcode'] ) ) {
			$owner['zip'] = $contacts['owner']['postcode'];
		}
		if ( ! empty( $contacts['owner']['phonenumberformatted'] ) ) {
			$owner['phone'] = $contacts['owner']['phonenumberformatted'];
		}
		$contactextras = SpecialFields::getContact( $additionalfields );
		if ( 0 < count( $contactextras ) ) {
			$owner['extra_parameters'] = $contactextras;
		}
		if ( in_array( $owner['country'], [
			'GF',
			'PF',
			'TF',
			'GP',
			'MQ',
			'YT',
			'NC',
			'RE',
			'PM',
			'MF',
			'BL',
			'WF'
		] ) ) {
			//$owner['state']   = 'FR-' . $owner['country'];
			$owner['country'] = 'FR';
		}
		if ( isset( $languages_mapping[ $contacts['owner']['language'] ] ) ) {
			$owner['lang'] = $languages_mapping[ $contacts['owner']['language'] ];
		}

		return $owner;
	}

	/*
	*
	* Check if a domain is available to register
	*
	* @param string $domain
	* @return string
	*
	*/
	public function getDomainAvailability( string $domain ) {
		$url      = "{$this->endPoint}/domain/check?name={$domain}";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response->products[0]->status;
	}

	/*
    *
    * Lock/unlock the domain
    *
    * @param string $domain
	* @param bool $locked
    * @return array
    *
    */
	public function setLockDomain( string $domain, bool $locked ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/status";
		$params   = [
			'clientTransferProhibited' => $locked
		];
		$response = $this->sendOrGetCached( $url, 'PATCH', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
    *
    * Resend reachability mail for contact validation
    *
    * @param string $domain
    * @return array
    *
    */
	public function resendReachabilityMail( string $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/reachability";
		$params   = [
			'action' => 'resend'
		];
		$response = $this->sendOrGetCached( $url, 'PATCH', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* Return the list of domains available in the reseller account
	*
	* @return array
	*
	*/
	public function getDomainList() {
		$url      = "{$this->endPoint}/domain/domains";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, '<null>', $response );

		return $response;
	}

	/*
	*
	* Return the list of  domain contacts
	*
	* @param string $domain
	* @return array
	*
	*/
	public function getDomainContacts( string $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/contacts";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}


	/*
	*
	* Return the domain nameservers
	*
	* @param string $domain
	* @return array
	*
	*/
	public function getDomainNameservers( string $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/nameservers";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* Renew a domain
	*
	* @param string $domain
	* @param int $period
	* @return array
	*
	*/
	public function renewDomain( string $domain, $period = 1 ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/renew";
		$params   = [
			'duration' => $period
		];
		$response = $this->sendOrGetCached( $url, 'POST', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}


	/*
	*
	* Update domain nameservers
	*
	* @param string $domain
	* @param array $nameservers
	* @return array
	*
	*/
	public function updateDomainNameservers( string $domain, array $nameservers ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/nameservers";
		$params   = [
			'nameservers' => $nameservers
		];
		$response = $this->sendOrGetCached( $url, 'PUT', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}


	/*
	*
	* Update domain contacts
	*
	* @param string $domain
	* @param array $contacts
	* @return array
	*
	*/
	public function updateDomainContacts( string $domain, array $contacts ) {
		$url                      = "{$this->endPoint}/domain/domains/{$domain}/contacts";
		$admin                    = (object) $contacts['Admin'];
		$admin->data_obfuscated   = true;
		$admin->mail_obfuscated   = true;
		$tech                     = (object) $contacts['Technical'];
		$tech->data_obfuscated    = true;
		$tech->mail_obfuscated    = true;
		$billing                  = (object) $contacts['Billing'];
		$billing->data_obfuscated = true;
		$billing->mail_obfuscated = true;
		$params                   = [
			'admin' => $admin,
			'bill'  => $billing,
			'tech'  => $tech
		];
		$response                 = $this->sendOrGetCached( $url, 'PATCH', $params );
		logModuleCall( $this->registrar, __FUNCTION__, [ $domain, $params ], $response );

		return $response;
	}


	/*
	 *
	 * Register nameserver
	 *
	 * @param string $domain
	 * @param string $name
	 * @param string $ip
	 * @return array
	 *
	 */
	public function registerNameserver( string $domain, string $name, string $ip ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/hosts";
		$params   = [
			'name' => $name,
			'ips'  => [ $ip ]
		];
		$response = $this->sendOrGetCached( $url, 'POST', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* Delete nameserver
	*
	* @param string $domain
	* @param string name
	* @return array
	*
	*/
	public function deleteNameserver( string $domain, string $name ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/hosts/{$name}";
		$response = $this->sendOrGetCached( $url, 'DELETE', [] );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* Update nameserver
	*
	* @param string $domain
	* @param string $name
	* @param string $ip
	* @return array
	*
	*/
	public function updateNameserver( string $domain, string $name, string $ip ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/hosts/{$name}";
		$params   = [
			'ips' => [ $ip ]
		];
		$response = $this->sendOrGetCached( $url, 'PUT', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
     *
     * Return the LiveDNS info.
     *
     * @param string $domain
     * @return array
     *
     */
	public function getLiveDNSInfo( string $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/livedns";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* Enable LiveDNS.
	*
	* @param string $domain
	* @return array
	*
	*/
	public function enableLiveDNS( string $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/livedns";
		$response = $this->sendOrGetCached( $url, 'POST' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* List organizations
	*
	* @return array
	*
	*/
	public function getOrganizations() {
		$url      = "{$this->endPoint}/organization/organizations";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, '<null>', $response );

		return $response;
	}

	/*
	*
	* Get TLD prices
	*
	* Available actions: add,create,remove,release,activate,deactivate,renew,restore,transfer,change_owner,transfer_reseller,update,delete
	*
	* @return array
	*
	*/
	public function getTLDPrices( $action ) {
		$url      = "{$this->endPoint}/billing/price/domain?processes={$action}";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $action, $response );

		return $response;
	}

	/*
	*
	* Get TLD prices
	*
	* Available actions: add,create,remove,release,activate,deactivate,renew,restore,transfer,change_owner,transfer_reseller,update,delete
	*
	* @return array
	*
	*/
	public function getTemplates() {
		$url      = "{$this->endPoint}/template/templates";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, '<null>', $response );

		return $response;
	}

	/*
	*
	* Get DNSSEC keys.
	*
	* @param string $domain
	* @return array
	*
	*/
	public function getDNSSEC( string $domain ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/dnskeys";
		$response = $this->sendOrGetCached( $url, 'GET' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	/*
	*
	* Get DNSSEC keys.
	*
	* @param string $domain
	* @return array
	*
	*/
	public function setDNSSEC( $domain, $pkey, $algorithm = 13, $type = 'zsk' ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/dnskeys";
		$params   = [
			'keys' => [
				[
					'algorithm'  => $algorithm,
					'public_key' => $pkey,
					'type'       => $type,
				],
			]
		];
		$response = $this->sendOrGetCached( $url, 'PUT', $params );
		logModuleCall( $this->registrar, __FUNCTION__, $params, $response );

		return $response;
	}

	/*
	*
	* Delete a DNSSEC keys.
	*
	* @param string $domain
	* @return array
	*
	*/
	public function deleteDNSSEC( $domain, $key_id ) {
		$url      = "{$this->endPoint}/domain/domains/{$domain}/dnskeys/{$key_id}";
		$response = $this->sendOrGetCached( $url, 'DELETE' );
		logModuleCall( $this->registrar, __FUNCTION__, $domain, $response );

		return $response;
	}

	private function sendOrGetCached( $url, $method = 'GET', $post = [], $timeout = 30 ) {
		if ( 'GET' !== $method ) {
			return $this->sendRequest( $url, $method, $post, $timeout );
		}
		if ( ! array_key_exists( $url, self::$cache ) ) {
			self::$cache[ $url ] = $this->sendRequest( $url, $method, $post, $timeout );
		}

		return self::$cache[ $url ];
	}

	private function sendRequest( $url, $method = 'GET', $body = [], $timeout = 30 ) {
		if ( $this->sharingId ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . 'sharing_id=' . $this->sharingId;
		}
		$curl = curl_init();
		curl_setopt_array( $curl, [
			CURLOPT_PORT           => '0',
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => [
				"authorization: Apikey {$this->apiKey}",
				"content-type: application/json"
			],
			CURLOPT_USERAGENT      => 'WHMCS/1.3'
		] );
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ] ) ) {
			curl_setopt_array( $curl, [ CURLOPT_CUSTOMREQUEST => $method ] );
		}
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ] ) && 0 < count( $body ) ) {
			curl_setopt_array( $curl, [ CURLOPT_POSTFIELDS => json_encode( $body ) ] );
		}
		$response = json_decode( curl_exec( $curl ) );
		curl_close( $curl );
		if ( GANDI_LOG_CACHE ) {
			logModuleCall( $this->registrar, __FUNCTION__, 'HTTP ' . curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ) . ' / ' . $method . ' ' . $url, 'cached: no' );
		}

		return $response;
	}
}
