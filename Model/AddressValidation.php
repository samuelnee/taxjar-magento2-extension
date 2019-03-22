<?php
/**
 * Taxjar_SalesTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Taxjar
 * @package    Taxjar_SalesTax
 * @copyright  Copyright (c) 2017 TaxJar. TaxJar is a trademark of TPS Unlimited, Inc. (http://www.taxjar.com)
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxjar\SalesTax\Model;

use Taxjar\SalesTax\Api\AddressValidationInterface;
use Taxjar\SalesTax\Model\Configuration as TaxjarConfig;

class AddressValidation implements AddressValidationInterface
{
    /**
     * @var \Taxjar\SalesTax\Model\Client $client
     */
    protected $client;

    /**
     * @var \Taxjar\SalesTax\Model\Logger $logger
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionFactory;

    /**
     * @var \Magento\Directory\Model\CountryFactory
     */
    protected $countryFactory;

    /**
     * @param ClientFactory $clientFactory
     * @param Logger $logger
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     */
    public function __construct(
        \Taxjar\SalesTax\Model\ClientFactory $clientFactory,
        \Taxjar\SalesTax\Model\Logger $logger,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory
    ) {
        $this->logger = $logger->setFilename(TaxjarConfig::TAXJAR_ADDRVALIDATION_LOG);
        $this->client = $clientFactory->create();
        $this->client->showResponseErrors(true);
        $this->scopeConfig = $scopeConfig;
        $this->regionFactory = $regionFactory;
        $this->countryFactory = $countryFactory;
    }

    /**
     * Endpoint that accepts an address and returns suggestions to improve it's accuracy
     *
     * @param null $street0
     * @param null $street1
     * @param null $city
     * @param null $region
     * @param null $country
     * @param null $postcode
     * @return array|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validateAddress(
        $street0 = null,
        $street1 = null,
        $city = null,
        $region = null,
        $country = null,
        $postcode = null
    ) {
        $errorResponse = ['error' => true, 'error_msg' => 'Unable to validate your address.'];

        // Ensure address validation is enabled
        if (!$this->canValidateAddress()) {
            return $errorResponse;
        }

        // Format the address to match the endpoint's naming convention
        $addr = $this->formatInput([
            'street0' => $street0,
            'street1' => $street1,
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'postcode' => $postcode
        ]);

        // Validate address data locally
        if ($this->validateInput($addr) === false) {
            return $errorResponse;
        }

        $results = [];
        $original = [
            'street' => [$street0],
            'city' => $city,
            'regionCode' => $this->getRegionById($region)->getCode(),
            'regionId' => $region,
            'countryId' => $country,
            'postcode' => $postcode
        ];

        $results[] = ['id' => 0, 'address' => $original, 'changes' => $original];

        // Send address to Taxjar for validation
        $response = $this->validateWithTaxjar($addr);

        // Respond with address suggestions (if any)
        if ($response !== false && isset($response['addresses']) && is_array($response['addresses'])) {
            foreach ($response['addresses'] as $id => $address) {
                $result = $this->highlightChanges($original, $address, ++$id);
                if (!empty($result)) {
                    $results[] = $result;
                } else {
                    $results[0]['address']['custom_attributes']['suggestedAddress'] = true;
                }
            }
        }

        return $results;
    }

    /**
     * Return if address validation is currently enabled
     *
     * @return bool|mixed
     */
    protected function canValidateAddress()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $validateAddress = $this->scopeConfig->getValue(TaxjarConfig::TAXJAR_ADDRESS_VALIDATION, $storeScope);

        return (bool)$validateAddress;
    }

    /**
     * Format the input to match the API's expectations
     *
     * @param $addr
     * @return mixed
     */
    protected function formatInput($addr)
    {
        if (isset($addr['region']) && is_numeric($addr['region'])) {
            $region = $this->getRegionById($addr['region']);
            $addr['state'] = $region->getCode();
            unset($addr['region']);
        }

        $street = [];
        if (isset($addr['street0']) || array_key_exists('street0', $addr)) {
            if (!is_null($addr['street0'])) {
                $street[] = $addr['street0'];
            }
            unset($addr['street0']);
        }
        if (isset($addr['street1']) || array_key_exists('street1', $addr)) {
            if (!is_null($addr['street1'])) {
                $street[] = $addr['street1'];
            }
            unset($addr['street1']);
        }
        if (isset($addr['street2']) || array_key_exists('street1', $addr)) {
            if (!is_null($addr['street2'])) {
                $street[] = $addr['street2'];
            }
            unset($addr['street2']);
        }
        $addr['street'] = implode(", ", $street);

        if (isset($addr['postcode']) || array_key_exists('postcode', $addr)) {
            if (!is_null($addr['postcode'])) {
                $addr['zip'] = $addr['postcode'];
            }
            unset($addr['postcode']);
        }

        return $addr;
    }

    /**
     * Ensure the address is eligible for validation
     *
     * @param $addr
     * @return bool
     */
    protected function validateInput($addr)
    {
        if (empty($addr) || !is_array($addr)) {
            return false;
        }

        // Only US addresses can be validated
        if (empty($addr['country']) || $addr['country'] !== 'US') {
            return false;
        }

        // Minimum of street and city/state or zip must be provided
        if (empty($addr['street']) && ((empty($addr['city']) && empty($addr['state'])) || empty($addr['postcode']))) {
            return false;
        }

        return $addr;
    }

    /**
     * Post the request to the TaxJar API and handle any responses
     *
     * @param $data
     * @return array|bool|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateWithTaxjar($data)
    {
        try {
            $response = $this->client->postResource('addressValidation', $data);
            $this->logger->log(implode("\n", $response));
            $response = $this->formatResponse($response);
        } catch (\Exception $e) {
            $msg = json_decode($e->getMessage());
            switch ($msg->status) {
                case 404:  // no suggested addresses found
                    $response = false;
                    $this->logger->log($e->getMessage());
                    break;
                default:
                    $this->logger->log($e->getMessage());
                    $response = false;
            }
        }
        return $response;
    }

    /**
     * Format the response to match Magento's expectations
     *
     * @param $response
     * @return mixed
     */
    protected function formatResponse($response)
    {
        if (isset($response['addresses']) && is_array($response['addresses'])) {
            foreach ($response['addresses'] as $k => $address) {

                $region = $this->getRegionByCode($address['state'], $address['country']);
                $response['addresses'][$k] = [
                    'street' => [$address['street']],
                    'city' => $address['city'],
                    'regionCode' => $address['state'],
                    'regionId' => $region->getId(),
                    'countryId' => $address['country'],
                    'postcode' => $address['zip'],
                    'custom_attributes' => [
                        'suggestedAddress' => true
                    ]
                ];
            }
        }

        return $response;
    }

    /**
     * Calculate the difference between two addresses and wrap the changes in HTML for highlighting
     *
     * @param array $original
     * @param array $new
     * @param $id
     * @return array
     */
    protected function highlightChanges($original, $new, $id)
    {
        $changes = $new;
        $changesMade = false;

        foreach ($original as $k => $v) {
            if (isset($new[$k]) && $original[$k] !== $new[$k]) {
                $changesMade = true;
                if (is_array($original[$k])) {
                    $changes[$k][0] = $this->htmlDiff($original[$k][0], $new[$k][0]);
                } else {
                    $changes[$k] = $this->htmlDiff($original[$k], $new[$k]);
                }
            }
        }

        if ($changesMade) {
            return ['id' => $id, 'address' => $new, 'changes' => $changes];
        }

        return [];
    }

    /**
     * Paul's Simple Diff Algorithm v 0.1
     * https://github.com/paulgb/simplediff
     *
     * @param array $old
     * @param array $new
     * @return array
     */
    function simplediff($old, $new)
    {
        $matrix = [];
        $maxlen = 0;
        $oMax = 0;
        $nMax = 0;

        foreach ($old as $oIndex => $oValue) {
            foreach (array_keys($new, $oValue) as $nIndex) {
                $matrix[$oIndex][$nIndex] = isset($matrix[$oIndex - 1][$nIndex - 1]) ?
                    $matrix[$oIndex - 1][$nIndex - 1] + 1 : 1;
                if ($matrix[$oIndex][$nIndex] > $maxlen) {
                    $maxlen = $matrix[$oIndex][$nIndex];
                    $oMax = $oIndex + 1 - $maxlen;
                    $nMax = $nIndex + 1 - $maxlen;
                }
            }
        }

        if ($maxlen == 0) {
            return [['d' => $old, 'i' => $new]];
        }

        return array_merge(
            $this->simplediff(array_slice($old, 0, $oMax), array_slice($new, 0, $nMax)),
            array_slice($new, $nMax, $maxlen),
            $this->simplediff(array_slice($old, $oMax + $maxlen), array_slice($new, $nMax + $maxlen))
        );
    }

    /**
     * Wrap differences between two strings in html
     * https://github.com/paulgb/simplediff
     *
     * @param $old
     * @param $new
     * @return string
     */
    function htmlDiff($old, $new)
    {
        $ret = '';
        $pattern = "/[\s]+/";

        // Explode $old and $new into arrays based on whitespace
        $simplediff = $this->simplediff(preg_split($pattern, $old), preg_split($pattern, $new));

        // Wrap each difference in a span for highlighting
        foreach ($simplediff as $diff) {
            if (is_array($diff)) {
                $ret .= (!empty($diff['i']) ? '<span class="suggested-address-diff">' .
                    implode(' ', $diff['i']) . '</span>' : '');
            } else {
                $ret .= $diff . ' ';
            }
        }

        return $ret;
    }

    /**
     * @param $regionId
     * @return \Magento\Directory\Model\Region
     */
    protected function getRegionById($regionId)
    {
        /** @var \Magento\Directory\Model\Region $region */
        $region = $this->regionFactory->create();
        $region->load($regionId);
        return $region;
    }

    /**
     * @param $regionCode
     * @param $countryId
     * @return \Magento\Directory\Model\Region
     */
    protected function getRegionByCode($regionCode, $countryId)
    {
        /** @var \Magento\Directory\Model\Region $region */
        $region = $this->regionFactory->create();
        $region->loadByCode($regionCode, $countryId);
        return $region;
    }

    /**
     * @param $countryId
     * @return \Magento\Directory\Model\Country
     */
    protected function getCountryById($countryId)
    {
        $country = $this->countryFactory->create();
        $country->load($countryId);
        return $country;
    }
}
