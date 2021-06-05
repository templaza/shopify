<?php

/**
 * @package   Shopify Framework
 * @author    Shopify Styles https://www.shopifystyles.com
 * @copyright Copyright (C) 2011 - 2021 TemPlaza.
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU/GPLv2 or Later
 */

namespace Shopify;

defined('_JEXEC') or die;

use PHPShopify\ShopifySDK;
use PHPShopify\AuthHelper;

abstract class Framework
{
    protected static $config = array();
    protected static $shopify = null;

    public function __construct() {
        self::$config = array(
            'ShopUrl' => '', //Your shop URL
            'ApiKey' => '', //Your  API Key
            'SharedSecret' => '', //Your  API Password
            'AccessToken' => '',
            'AppName' => '',
            'ShopId' => 0
        );
    }

    public static function init($config = array(), $scopes = '', $redirectUrl = '')
    {
        define('_SHOPIFYLIBRARY', 1); // define Shopify
        $app            =   \JFactory::getApplication('site');
        $get_token      =   $app -> input -> get('get_token',0);
        $host           =   $app -> input -> get('host','');
        self::getConfig($config);
        ShopifySDK::config(self::$config);
        if ($host && AuthHelper::verifyShopifyRequest() && self::getToken() && $get_token!=1) {
            self::$shopify = ShopifySDK::config(self::$config);
        } else {
            if ($get_token == 0) {
                if ($scopes && $redirectUrl) {
                    AuthHelper::createAuthRequest($scopes, $redirectUrl);
                    die();
                } else {
                    return false;
                }
            } else {
                self::$config['AccessToken'] = AuthHelper::getAccessToken();
                self::$shopify = ShopifySDK::config(self::$config);
                self::triggerEvent('onBeforeShopifySave', [&self::$config, &self::$shopify]);
                self::storeShop();
                self::getToken();
                self::triggerEvent('onAfterShopifySave', [&self::$config, &self::$shopify]);
                $app->redirect('https://'.base64_decode($host).'/apps/');
            }
        }
        return true;
    }
    public static function getShopify($config = array()) {
        self::getConfig($config);
        if (self::getToken()) {
            self::$shopify = ShopifySDK::config(self::$config);
            return self::$shopify;
        }
        return false;
    }
    public static function getConfig($config = array()) {
        self::$config   =   array_merge(self::$config, $config);
        return self::$config;
    }
    public static function checkAuthorized($config = array('ShopUrl' => '', 'AppName' => '')) {
        $db     =   \JFactory::getDbo();
        $db->setQuery('SELECT valid_ip FROM #__tz_shopify_shops WHERE shopify_app='.$db->quote($config['AppName']).' AND shopify_domain='.$db->quote($config['ShopUrl']));

        if ($valid_ip = $db->loadResult()) {
            if ($valid_ip == self::get_client_ip()) {
                return true;
            }
        }
        return false;
    }
    public static function getShopID($config = array('ShopUrl' => '', 'AppName' => '')) {
        $db     =   \JFactory::getDbo();
        $db->setQuery('SELECT id FROM #__tz_shopify_shops WHERE shopify_app='.$db->quote($config['AppName']).' AND shopify_domain='.$db->quote($config['ShopUrl']));

        if ($shop_id = $db->loadResult()) {
            return $shop_id;
        }
        return false;
    }
    public static function getToken() {
        if (!self::checkShopifyShop()) {
            return false;
        }
        $db     =   \JFactory::getDbo();
        $db->setQuery('SELECT * FROM #__tz_shopify_shops WHERE shopify_app='.$db->quote(self::$config['AppName']).' AND shopify_domain='.$db->quote(self::$config['ShopUrl']));
        if ($row = $db->loadObject()) {
            if ($row->shopify_token) {
                self::$config['AccessToken'] = $row -> shopify_token;
                self::$config['ShopId'] = $row -> id;
                $db->setQuery('UPDATE #__tz_shopify_shops SET `valid_ip`='.$db->quote(self::get_client_ip()).' WHERE shopify_app='.$db->quote(self::$config['AppName']).' AND shopify_domain='.$db->quote(self::$config['ShopUrl']))->execute();
                return $row->shopify_token;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    public static function storeShop () {
        if (!self::checkShopifyShop()) {
            return false;
        }
        $db     =   \JFactory::getDbo();
        $db->setQuery('SELECT * FROM #__tz_shopify_shops WHERE shopify_app='.$db->quote(self::$config['AppName']).' AND shopify_domain='.$db->quote(self::$config['ShopUrl']));
        if ($db->loadResult()) {
            $db->setQuery('UPDATE #__tz_shopify_shops SET `shopify_token`='.$db->quote(self::$config['AccessToken']).', `valid_ip`='.$db->quote(self::get_client_ip()).', `modified`='.$db->quote(date('Y-m-d H:i:s')).' WHERE shopify_app='.$db->quote(self::$config['AppName']).' AND shopify_domain='.$db->quote(self::$config['ShopUrl']))->execute();
        } else {
            $db->setQuery('INSERT INTO #__tz_shopify_shops(`shopify_domain`,`shopify_token`,`shopify_app`,`valid_ip`,`created`,`modified`) VALUES ('.$db->quote(self::$config['ShopUrl']).','.$db->quote(self::$config['AccessToken']).','.$db->quote(self::$config['AppName']).','.$db->quote(self::get_client_ip()).','.$db->quote(date('Y-m-d H:i:s')).','.$db->quote(date('Y-m-d H:i:s')).')')->execute();
        }
        return true;
    }
    private static function checkShopifyShop () {
        $db     =   \JFactory::getDbo();
        $results = $db->setQuery('SHOW TABLES')->loadColumn();
        $prefix = $db->getPrefix();
        $shopDB   =   true;
        if (!array_search($prefix.'tz_shopify_shops',$results,true)) {
            $shopDB = self::createShopifyShop();
        }
        return $shopDB;
    }

    private static function createShopifyShop () {
        $db     =   \JFactory::getDbo();
        $db->setQuery('CREATE TABLE IF NOT EXISTS `#__tz_shopify_shops` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shopify_domain` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `shopify_token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shopify_app` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid_ip` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
        return $db->execute();
    }

    // Function to get the client IP address
    private static function get_client_ip() {
        $ipaddress = '';
        if (getenv('HTTP_CLIENT_IP'))
            $ipaddress = getenv('HTTP_CLIENT_IP');
        else if(getenv('HTTP_X_FORWARDED_FOR'))
            $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
        else if(getenv('HTTP_X_FORWARDED'))
            $ipaddress = getenv('HTTP_X_FORWARDED');
        else if(getenv('HTTP_FORWARDED_FOR'))
            $ipaddress = getenv('HTTP_FORWARDED_FOR');
        else if(getenv('HTTP_FORWARDED'))
            $ipaddress = getenv('HTTP_FORWARDED');
        else if(getenv('REMOTE_ADDR'))
            $ipaddress = getenv('REMOTE_ADDR');
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    }
    public static function triggerEvent($name, $data = [])
    {
        \JPluginHelper::importPlugin('shopify');
        \JFactory::getApplication()->triggerEvent($name, $data);
    }
}