<?php

/**
 * Copyright (C) 2016 Biziant LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('_SENTRY') or die;

sentryload('objects.config');

final class SentryCrypto
{
	/**
	 * Default encryption settings
	 * 
	 * @var array
	 */
	static private $config = array(
		'digest_alg'       => 'sha512'
	,	'private_key_bits' => 4096
	,	'private_key_type' => OPENSSL_KEYTYPE_RSA
	);
	
	/**
	 * Client private key
	 * 
	 * @var string
	 * 
	 * @since 1.0.0
	 */
	private $privateKey = null;
	
	/**
	 * Client public key
	 * 
	 * @var string
	 * 
	 * @since 1.0.0
	 */
	private $publicKey = null;
	
	/**
	 * Private constructor
	 * 
	 * @param string $publicKey
	 * @param string $privateKey
	 * 
	 * @see SentryCrypto::getInstance
	 * 
	 * @since 1.0.0
	 */
	private function __construct($publicKey = null, $privateKey = null)
	{
		$this->publicKey  = $publicKey;
		$this->privateKey = $privateKey;
	}
	
	/**
	 * Decrypts data
	 * 
	 * @param string $encrypted
	 * @return mixed
	 * 
	 * @since 1.0.0
	 */
	public function decrypt($encrypted)
	{
		$decrypted = null;
		if (empty($this->privateKey))
		{
			throw new UnexpectedValueException('No private key');
		}
		openssl_private_decrypt($encrypted, $decrypted, $this->privateKey);
		return json_decode($decrypted);
	}
	
	/**
	 * json encodes then encrypts data
	 * 
	 * @param mixed $data
	 * @return string
	 * 
	 * @since 1.0.0
	 */
	public function encrypt($data)
	{
		$encrypted = null;
		if (empty($this->publicKey))
		{
			throw new UnexpectedValueException('No public key');
		}
		openssl_public_encrypt(json_encode($data), $encrypted, $this->publicKey);
		return $encrypted;
	}
	
	/**
	 * Generate new keys
	 * 
	 * @return \stdClass
	 * 
	 * @since 1.0.0
	 */
	static public function generateKeys()
	{
		$result = false;
		if (function_exists('openssl_pkey_new'))
		{
			$result = new stdClass();
			$result->privateKey = false;
			$result->publicKey = false;
			$keys = openssl_pkey_new(static::$config);
			openssl_pkey_export($keys, $result->privateKey);
			$publicKey = openssl_pkey_get_details($keys);
			$result->publicKey = $publicKey["key"];
		}
		return $result;
	}
	
	/**
	 * Getter for instance
	 * 
	 * @param string $publicKey
	 * @param string $privateKey
	 * @return \SentryCrypto
	 * 
	 * @since 1.0.0
	 */
	static public function getInstance($publicKey = null, $privateKey = null)
	{
		return new SentryCrypto($publicKey, $privateKey);
	}
	
	static public function getClientInstance()
	{
		// init
		$result = false;
		// pull keypath from config
		$config = SentryConfig::getInstance();
		$keypath = $config->get('key_path', null);
		$keybase = $config->get('client_key_base', 'id_sentry_rsa');
		SentryLog::_("Checking key_path '$keypath' for '$keybase' keys");
		// check keypath
		if (!is_null($keypath) && is_dir($keypath))
		{
			$privateKey = null;
			$publicKey = null;
			// great, we have a keypath - load from keypath?
			$privkeyFile = realpath($keypath . '/' . basename("$keybase"));
			$pubkeyFile = realpath($keypath . '/' . basename("$keybase.pub"));
			if (file_exists($privkeyFile))
			{
				$privateKey = file_get_contents($privkeyFile);
			}
			if (file_exists($pubkeyFile))
			{
				$publicKey = file_get_contents($pubkeyFile);
			}
			// if either is null, we have a problem :(
			if (!(empty($privateKey) || empty($publicKey)))
			{
				// send back the instance
				$result = SentryCrypto::getInstance($publicKey, $privateKey);
			}
		}
		return $result;
	}
	
	public function getPublicKey()
	{
		return $this->publicKey;
	}
	
	static public function getServerInstance()
	{
		// init
		$result = false;
		// pull keypath from config
		$config = SentryConfig::getInstance();
		$keypath = $config->get('key_path', null);
		// check keypath
		if (!is_null($keypath) && is_dir($keypath))
		{
			$publicKey = null;
			// great, we have a keypath - load from keypath?
			$keybase = $config->get('server_key_base', 'id_sentryserver_rsa');
			$pubkeyFile = realpath($keybase . '/' . basename("$keybase.pub"));
			if (is_dir($keybase) && file_exists($pubkeyFile))
			{
				$publicKey = file_get_contents($pubkeyFile);
			}
			if (!empty($publicKey))
			{
				// send back the instance
				$result = SentryCrypto::getInstance($publicKey);
			}
		}
		return $result;
	}
	
	public function hasPrivateKey()
	{
		return !empty($this->privateKey);
	}
	
	public function hasPublicKey()
	{
		return !empty($this->publicKey);
	}
}
