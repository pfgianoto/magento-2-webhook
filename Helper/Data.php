<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Webhook
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Webhook\Helper;

use Exception;
use Liquid\Template;
use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Mageplaza\Core\Helper\AbstractData as CoreHelper;
use Mageplaza\Webhook\Block\Adminhtml\LiquidFilters;
use Mageplaza\Webhook\Model\Config\Source\Authentication;
use Mageplaza\Webhook\Model\Config\Source\HookType;
use Mageplaza\Webhook\Model\Config\Source\Schedule;
use Mageplaza\Webhook\Model\Config\Source\Status;
use Mageplaza\Webhook\Model\HistoryFactory;
use Mageplaza\Webhook\Model\HookFactory;
use Mageplaza\Webhook\Model\ResourceModel\Hook\Collection;
use Zend_Http_Response;

/**
 * Class Data
 * @package Mageplaza\Webhook\Helper
 */
class Data extends CoreHelper
{
    const CONFIG_MODULE_PATH = 'mp_webhook';

    /**
     * @var LiquidFilters
     */
    protected $liquidFilters;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * @var HookFactory
     */
    protected $hookFactory;

    /**
     * @var HistoryFactory
     */
    protected $historyFactory;

    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customer;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param ObjectManagerInterface $objectManager
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $backendUrl
     * @param TransportBuilder $transportBuilder
     * @param CurlFactory $curlFactory
     * @param LiquidFilters $liquidFilters
     * @param HookFactory $hookFactory
     * @param HistoryFactory $historyFactory
     * @param CustomerRepositoryInterface $customer
     */
    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager,
        UrlInterface $backendUrl,
        TransportBuilder $transportBuilder,
        CurlFactory $curlFactory,
        LiquidFilters $liquidFilters,
        HookFactory $hookFactory,
        HistoryFactory $historyFactory,
        CustomerRepositoryInterface $customer
    ) {
        $this->liquidFilters    = $liquidFilters;
        $this->curlFactory      = $curlFactory;
        $this->hookFactory      = $hookFactory;
        $this->historyFactory   = $historyFactory;
        $this->transportBuilder = $transportBuilder;
        $this->backendUrl       = $backendUrl;
        $this->customer         = $customer;

        parent::__construct($context, $objectManager, $storeManager);
    }

    /**
     * @param $item
     *
     * @return int
     * @throws NoSuchEntityException
     */
    public function getItemStore($item)
    {
        if (method_exists($item, 'getData')) {
            return $item->getData('store_id') ?: $this->storeManager->getStore()->getId();
        }

        return $this->storeManager->getStore()->getId();
    }

    /**
     * @param $item
     * @param $hookType
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function send($item, $hookType)
    {
        if (!$this->isEnabled()) {
            return;
        }

        /** @var Collection $hookCollection */
        $hookCollection = $this->hookFactory->create()->getCollection()
            ->addFieldToFilter('hook_type', $hookType)
            ->addFieldToFilter('status', 1)
            ->addFieldToFilter('store_ids', [
                ['finset' => Store::DEFAULT_STORE_ID],
                ['finset' => $this->getItemStore($item)]
            ])
            ->setOrder('priority', 'ASC');
        $isSendMail     = $this->getConfigGeneral('alert_enabled');
        $sendTo = '';
		$configValue = $this->getConfigGeneral('send_to');

		if ($configValue !== null) {
			$sendTo = is_string($configValue) ? explode(',', $configValue) : '';
		}
        foreach ($hookCollection as $hook) {
            if ($hook->getHookType() === HookType::ORDER) {
                $statusItem  = $item->getStatus();
                $orderStatus = explode(',', $hook->getOrderStatus());
                if (!in_array($statusItem, $orderStatus, true)) {
                    continue;
                }
            }
            $history = $this->historyFactory->create();
            $data    = [
                'hook_id'     => $hook->getId(),
                'hook_name'   => $hook->getName(),
                'store_ids'   => $hook->getStoreIds(),
                'hook_type'   => $hook->getHookType(),
                'priority'    => $hook->getPriority(),
                'payload_url' => $this->generateLiquidTemplate($item, $hook->getPayloadUrl()),
                'body'        => $this->generateLiquidTemplate($item, $hook->getBody())
            ];
            $history->addData($data);
            try {
                $result = $this->sendHttpRequestFromHook($hook, $item);
                $history->setResponse(isset($result['response']) ? $result['response'] : '');
            } catch (Exception $e) {
                $result = [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
            if ($result['success'] === true) {
                $history->setStatus(Status::SUCCESS);
            } else {
                $history->setStatus(Status::ERROR)
                    ->setMessage($result['message']);
                if ($isSendMail) {
                    $this->sendMail(
                        $sendTo,
                        __('Something went wrong while sending %1 hook', $hook->getName()),
                        $this->getConfigGeneral('email_template'),
                        $this->getStoreId()
                    );
                }
            }

            $history->save();
        }
    }

    /**
     * @param $hook
     * @param bool $item
     * @param bool $log
     *
     * @return array
     */
    public function sendHttpRequestFromHook($hook, $item = false, $log = false)
    {
        $url            = $log ? $log->getPayloadUrl() : $this->generateLiquidTemplate($item, $hook->getPayloadUrl());
        $authentication = $hook->getAuthentication();
        $method         = $hook->getMethod();
        $username       = $hook->getUsername();
        $password       = $hook->getPassword();
        if ($authentication === Authentication::BASIC) {
            $authentication = $this->getBasicAuthHeader($username, $password);
        } elseif ($authentication === Authentication::DIGEST) {
            $authentication = $this->getDigestAuthHeader(
                $url,
                $method,
                $username,
                $hook->getRealm(),
                $password,
                $hook->getNonce(),
                $hook->getAlgorithm(),
                $hook->getQop(),
                $hook->getNonceCount(),
                $hook->getClientNonce(),
                $hook->getOpaque()
            );
        }

        $body        = $log ? $log->getBody() : $this->generateLiquidTemplate($item, $hook->getBody());
        $headers     = $hook->getHeaders();
        $contentType = $hook->getContentType();

        return $this->sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method);
    }

    /**
     * @param $item
     * @param $templateHtml
     *
     * @return string
     */
    public function generateLiquidTemplate($item, $templateHtml)
    {
        try {
            $template       = new Template;
            $filtersMethods = $this->liquidFilters->getFiltersMethods();

            $template->registerFilter($this->liquidFilters);
            $template->parse($templateHtml, $filtersMethods);

            if ($item instanceof Product) {
                $item->setStockItem(null);
            }
            $item->setData('cart_url', $this->_getUrl('checkout/cart', ['_secure' => true]));

			if ($item instanceof \Magento\Sales\Model\Order) {
				$payment = $item->getPayment();
				$paymentMethod = $payment->getMethodInstance();
				$paymentMethodName = $paymentMethod->getTitle(); // Obtém o nome da forma de pagamento

				$item->setData('payment_method_name', $paymentMethodName);
				
				foreach ($item->getAllVisibleItems() as $it) {
                    $product = $it->getProduct();
                    $it->setData('product_url', $product->getUrlModel()->getUrl($product));
					
                    if ($it->getImageUrl() == null) {
                        $product = $this->createObject(\Magento\Catalog\Api\ProductRepositoryInterfaceFactory::class)
                        ->create()->getById($it->getProductId());
                        $it->setData('image_url', $product->getImage());
                    }					
					
					$productPrice = $product->getPrice();

					// Formata o preço com ponto como separador decimal
					$formattedPrice = number_format($productPrice, 2, '.', '');

					// Define o preço formatado no objeto do item
					$it->setData('product_price_formatted', $formattedPrice);
			
				}
				
					// Obtém o total e subtotal do pedido
					$orderTotal = $item->getGrandTotal();
					$orderSubtotal = $item->getSubtotal();

					// Formata o total e subtotal com ponto como separador decimal
					$formattedOrderTotal = number_format($orderTotal, 2, '.', '');
					$formattedOrderSubtotal = number_format($orderSubtotal, 2, '.', '');

					// Define o total e subtotal formatado no objeto do pedido
					$item->setData('order_total_formatted', $formattedOrderTotal);
					$item->setData('order_subtotal_formatted', $formattedOrderSubtotal);				
					
					// Recupera o ID do cliente do pedido
					$customerId = $item->getCustomerId();

					if ($customerId) {
						$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
						$customerRepository = $objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
						
						try {
							$customer = $customerRepository->getById($customerId);
							$taxvat = $customer->getTaxvat();
							$item->setData('customer_taxvat', $taxvat);
						} catch (\Exception $e) {
							// Trate qualquer exceção, se necessário
						}
					}
			}
			
			
			$trackingCodes = '';

			// Verificar se o item tem informações de rastreamento e retorna código de rastreio
			if ($item instanceof \Magento\Sales\Model\Order\Shipment) {
				
				// Obter o pedido associado à entrega
				$order = $item->getOrder();
				
				$tracks = $item->getTracks();
				foreach ($tracks as $track) {
					$trackingCodes .= $track->getTrackNumber() . ', ';
				}
				
				$trackingCodes = rtrim($trackingCodes, ', ');
				$item->setData('tracking_codes', $trackingCodes);
				
				$orderIncrementId = $order->getIncrementId();
				$item->setData('order_increment_id', $orderIncrementId);
			
				// Obtém o total e subtotal do pedido associado à entrega
				$orderTotal = $order->getGrandTotal();
				$orderSubtotal = $order->getSubtotal();

				// Formata o total e subtotal com ponto como separador decimal
				$formattedOrderTotal2 = number_format($orderTotal, 2, '.', '');
				$formattedOrderSubtotal2 = number_format($orderSubtotal, 2, '.', '');

				// Define o total e subtotal formatado no objeto da entrega
				$item->setData('order_total_formatted_ship', $formattedOrderTotal2);
				$item->setData('order_subtotal_formatted_ship', $formattedOrderSubtotal2);			
			
				// Verifica se o pedido tem um cliente associado
				if ($order->getCustomerId()) {
					$customerId = $order->getCustomerId();

					// Obter o objeto do cliente através do CustomerRepositoryInterface
					$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
					$customerRepository = $objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
					
					try {
						$customer = $customerRepository->getById($customerId);
						$taxvat = $customer->getTaxvat();
						// O valor de $taxvat contém o número de identificação fiscal do cliente
						$item->setData('customer_taxvat_ship', $taxvat);
					} catch (\Exception $e) {
						// Trate qualquer exceção, se necessário
					}
				}
				
				
					// Obtém a data de criação do pedido
					$orderCreatedAt = $order->getCreatedAt();

					// Formata a data de criação no formato desejado (ano-mês-dia hora:minuto:segundo)
					$formattedOrderCreatedAt = date('Y-m-d H:i:s', strtotime($orderCreatedAt));

					// Define a data de criação formatada no objeto da entrega
					$item->setData('order_created_at', $formattedOrderCreatedAt);
			
			
			}
			
			if ($item instanceof \Magento\Sales\Model\Order\Invoice) {
				
				// Obter o pedido associado à entrega
				$order = $item->getOrder();
				
			
				$orderIncrementId = $order->getIncrementId();
				$item->setData('order_increment_id', $orderIncrementId);
			
				// Obtém o total e subtotal do pedido associado à entrega
				$orderTotal = $order->getGrandTotal();
				$orderSubtotal = $order->getSubtotal();

				// Formata o total e subtotal com ponto como separador decimal
				$formattedOrderTotal2 = number_format($orderTotal, 2, '.', '');
				$formattedOrderSubtotal2 = number_format($orderSubtotal, 2, '.', '');

				// Define o total e subtotal formatado no objeto da entrega
				$item->setData('order_total_formatted_ship', $formattedOrderTotal2);
				$item->setData('order_subtotal_formatted_ship', $formattedOrderSubtotal2);			
			
				// Verifica se o pedido tem um cliente associado
				if ($order->getCustomerId()) {
					$customerId = $order->getCustomerId();

					// Obter o objeto do cliente através do CustomerRepositoryInterface
					$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
					$customerRepository = $objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
					
					try {
						$customer = $customerRepository->getById($customerId);
						$taxvat = $customer->getTaxvat();
						// O valor de $taxvat contém o número de identificação fiscal do cliente
						$item->setData('customer_taxvat_ship', $taxvat);
					} catch (\Exception $e) {
						// Trate qualquer exceção, se necessário
					}
				}
				
				
					// Obtém a data de criação do pedido
					$orderCreatedAt = $order->getCreatedAt();

					// Formata a data de criação no formato desejado (ano-mês-dia hora:minuto:segundo)
					$formattedOrderCreatedAt = date('Y-m-d H:i:s', strtotime($orderCreatedAt));

					// Define a data de criação formatada no objeto da entrega
					$item->setData('order_created_at', $formattedOrderCreatedAt);
			
			
			}

			/*if ($item instanceof \Magento\Sales\Model\Order) {
				$trackingCodes = [];

				// Itera pelos envios (shipments) associados ao pedido
				foreach ($item->getShipmentsCollection() as $shipment) {
					// Itera pelos códigos de rastreamento associados ao envio
					foreach ($shipment->getTracksCollection() as $track) {
						$trackingCodes[] = $track->getTrackNumber(); // Adiciona os códigos de rastreamento ao array
					}
				}

				$item->setData('tracking_codes', $trackingCodes);
			}*/

            if ($item instanceof \Magento\Quote\Model\Quote) {
                foreach ($item->getAllVisibleItems() as $it) {
                    $product = $it->getProduct();
                    $it->setData('product_url', $product->getUrlModel()->getUrl($product));
                    if ($it->getImageUrl() == null) {
                        $product = $this->createObject(\Magento\Catalog\Api\ProductRepositoryInterfaceFactory::class)
                        ->create()->getById($it->getProductId());
                        $it->setData('image_url', $product->getImage());
                    }
                    // $it->setData('product_url', $product->getProductUrl());
                    // $it->setData('image_url', $product->getImage());
                    // $it->setData('small_image', $product->getSmallImage());
                    // $it->setData('attribute_set_id', $product->getAtributeSetId());
                    // $it->setData('climate', $product->getClimate());
                    // $it->setData('created_at', $product->getCreatedAt());
                    // $it->setData('description', $product->getDescription());
                    // $it->setData('entity_id', $product->getEntityId());
                    // $it->setData('has_options', $product->getHasOptions());
                    // $it->setData('is_salable', $product->getIsSalable());
                    // $it->setData('name', $product->getName());
                    // $it->setData('price', $product->getPrice());
                    // $it->setData('sku', $product->getSku());
                    // $it->setData('status', $product->getStatus());
                    // $it->setData('thumbnail', $product->getThumbnail());
                    // $it->setData('type_id', $product->getTypeId());
                    // $it->setData('updated_at', $product->getUpdatedAt());
                    // $it->setData('url_key', $product->getUrlKey());
                    // $it->setData('visibility', $product->getVisibility());

                    $item->setData('items', $item->getAllVisibleItems());
                }
				
				// Verifica se o carrinho tem um cliente associado
				if ($item->getCustomerId()) {
					$customerId = $item->getCustomerId();

					// Obter o objeto do cliente através do CustomerRepositoryInterface
					$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
					$customerRepository = $objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
					
					try {
						$customer = $customerRepository->getById($customerId);
						$cellphone = $customer->getCustomAttribute('cellphone');
						// O valor de $taxvat contém o número de identificação fiscal do cliente
						$item->setData('customer_cellphone_cart', $cellphone);
					} catch (\Exception $e) {
						// Trate qualquer exceção, se necessário
					}
				}
				
				// Obtém o total e subtotal do pedido
					$cartTotal = $item->getGrandTotal();
					$cartSubtotal = $item->getSubtotal();

					// Formata o total e subtotal com ponto como separador decimal
					$formattedCartTotal = number_format($cartTotal, 2, '.', '');
					$formattedCartSubtotal = number_format($cartSubtotal, 2, '.', '');

					// Define o total e subtotal formatado no objeto do pedido
					$item->setData('cart_total_formatted', $formattedCartTotal);
					$item->setData('cart_subtotal_formatted', $formattedCartSubtotal);
            }

            if ($item->getShippingAddress()) {
				
				$shippingAddress = $item->getShippingAddress();
				$street = $shippingAddress->getStreet();
				$formattedStreet = implode(', ', $street);
				
				$item->setData('formatted_shipping_street', $formattedStreet);
				
                $item->setData('shippingAddress', $item->getShippingAddress()->getData());
            }

            if ($item->getBillingAddress()) {
				
				$billingAddress = $item->getBillingAddress();
				$street = $billingAddress->getStreet();
				$formattedStreet = implode(', ', $street);
				
				$item->setData('formatted_billing_street', $formattedStreet);
				
                $item->setData('billingAddress', $item->getBillingAddress());
            }

            return $template->render([
                'item' => $item,
            ]);
        } catch (Exception $e) {
            $this->_logger->critical($e->getMessage());
        }

        return '';
    }

    /**
     * @param $headers
     * @param $authentication
     * @param $contentType
     * @param $url
     * @param $body
     * @param $method
     *
     * @return array
     */
    public function sendHttpRequest($headers, $authentication, $contentType, $url, $body, $method)
    {
        if (!$method) {
            $method = 'GET';
        }
        if ($headers && !is_array($headers)) {
            $headers = $this::jsonDecode($headers);
        }
        $headersConfig = [];

        foreach ($headers as $header) {
            $key             = $header['name'];
            $value           = $header['value'];
            $headersConfig[] = trim($key) . ': ' . trim($value);
        }

        if ($authentication) {
            $headersConfig[] = 'Authorization: ' . $authentication;
        }

        if ($contentType) {
            $headersConfig[] = 'Content-Type: ' . $contentType;
        }

        $curl = $this->curlFactory->create();
        $curl->write($method, $url, '1.1', $headersConfig, $body);

        $result = ['success' => false];

        try {
			$resultCurl = $curl->read();
			$result['response'] = $resultCurl;

			if (!empty($resultCurl)) {
				$httpResponse = explode("\r\n\r\n", $resultCurl, 2)[0]; // Isolando a parte do cabeçalho HTTP

				preg_match('/HTTP\/\d\.\d\s(\d+)/', $httpResponse, $matches);

				if (isset($matches[1])) {
					$statusCode = (int)$matches[1];

					if ($this->isSuccess($statusCode)) {
						$result['success'] = true;
					} else {
						$result['message'] = __('Cannot connect to server. Please try again later.');
					}
				}
			} else {
				$result['message'] = __('Cannot connect to server. Please try again later.');
			}
		} catch (Exception $e) {
			$result['message'] = $e->getMessage();
		}

		$curl->close();

        return $result;
    }

    /**
     * @param $url
     * @param $method
     * @param $username
     * @param $realm
     * @param $password
     * @param $nonce
     * @param $algorithm
     * @param $qop
     * @param $nonceCount
     * @param $clientNonce
     * @param $opaque
     *
     * @return string
     */
    public function getDigestAuthHeader(
        $url,
        $method,
        $username,
        $realm,
        $password,
        $nonce,
        $algorithm,
        $qop,
        $nonceCount,
        $clientNonce,
        $opaque
    ) {
        $uri          = parse_url($url)[2];
        $method       = $method ?: 'GET';
        $A1           = hash('md5', "{$username}:{$realm}:{$password}");
        $A2           = hash('md5', "{$method}:{$uri}");
        $response     = hash('md5', "{$A1}:{$nonce}:{$nonceCount}:{$clientNonce}:{$qop}:${A2}");
        $digestHeader = "Digest username=\"{$username}\", realm=\"{$realm}\", nonce=\"{$nonce}\", uri=\"{$uri}\", cnonce=\"{$clientNonce}\", nc={$nonceCount}, qop=\"{$qop}\", response=\"{$response}\", opaque=\"{$opaque}\", algorithm=\"{$algorithm}\"";

        return $digestHeader;
    }

    /**
     * @param $username
     * @param $password
     *
     * @return string
     */
    public function getBasicAuthHeader($username, $password)
    {
        return 'Basic ' . base64_encode("{$username}:{$password}");
    }

    /**
     * @param $item
     * @param $hookType
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function sendObserver($item, $hookType)
    {
        if (!$this->isEnabled()) {
            return;
        }

        /** @var Collection $hookCollection */
        $hookCollection = $this->hookFactory->create()->getCollection()
            ->addFieldToFilter('hook_type', $hookType)
            ->addFieldToFilter('status', 1)
            ->setOrder('priority', 'ASC');

        $isSendMail = $this->getConfigGeneral('alert_enabled');
        $sendTo     = explode(',', $this->getConfigGeneral('send_to'));

        foreach ($hookCollection as $hook) {
            try {
                $history = $this->historyFactory->create();
                $data    = [
                    'hook_id'     => $hook->getId(),
                    'hook_name'   => $hook->getName(),
                    'store_ids'   => $hook->getStoreIds(),
                    'hook_type'   => $hook->getHookType(),
                    'priority'    => $hook->getPriority(),
                    'payload_url' => $this->generateLiquidTemplate($item, $hook->getPayloadUrl()),
                    'body'        => $this->generateLiquidTemplate($item, $hook->getBody())
                ];
                $history->addData($data);
                try {
                    $result = $this->sendHttpRequestFromHook($hook, $item);
                    $history->setResponse(isset($result['response']) ? $result['response'] : '');
                } catch (Exception $e) {
                    $result = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
                if ($result['success'] === true) {
                    $history->setStatus(Status::SUCCESS);
                } else {
                    $history->setStatus(Status::ERROR)
                        ->setMessage($result['message']);
                    if ($isSendMail) {
                        $this->sendMail(
                            $sendTo,
                            __('Something went wrong while sending %1 hook', $hook->getName()),
                            $this->getConfigGeneral('email_template'),
                            $this->storeManager->getStore()->getId()
                        );
                    }
                }
                $history->save();
            } catch (Exception $e) {
                if ($isSendMail) {
                    $this->sendMail(
                        $sendTo,
                        __('Something went wrong while sending %1 hook', $hook->getName()),
                        $this->getConfigGeneral('email_template'),
                        $this->storeManager->getStore()->getId()
                    );
                }
            }
        }
    }

    /**
     * @param $sendTo
     * @param $mes
     * @param $emailTemplate
     * @param $storeId
     *
     * @return bool
     * @throws LocalizedException
     */
    public function sendMail($sendTo, $mes, $emailTemplate, $storeId)
    {
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($emailTemplate)
                ->setTemplateOptions([
                    'area'  => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'viewLogUrl' => $this->backendUrl->getUrl('mpwebhook/logs/'),
                    'mes'        => $mes
                ])
                ->setFrom('general')
                ->addTo($sendTo);
            $transport = $this->transportBuilder->getTransport();
            $transport->sendMessage();

            return true;
        } catch (MailException $e) {
            $this->_logger->critical($e->getLogMessage());
        }

        return false;
    }

    /**
     * @return int
     * @throws NoSuchEntityException
     */
    public function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * @param string $schedule
     * @param string $startTime
     *
     * @return string
     */
    public function getCronExpr($schedule, $startTime)
    {
        $ArTime        = explode(',', $startTime);
        $cronExprArray = [
            (int) $ArTime[1], // Minute
            (int) $ArTime[0], // Hour
            $schedule === Schedule::CRON_MONTHLY ? 1 : '*', // Day of the Month
            '*', // Month of the Year
            $schedule === Schedule::CRON_WEEKLY ? 0 : '*', // Day of the Week
        ];
        if ($schedule === Schedule::CRON_MINUTE) {
            return '* * * * *';
        }

        return implode(' ', $cronExprArray);
    }

    /**
     * @param null $field
     *
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function getCronSchedule($field = null)
    {
        $storeId = $this->getStoreId();
        if ($field === null) {
            return $this->getModuleConfig('cron/schedule', $storeId);
        }

        return $this->getModuleConfig('cron/' . $field, $storeId);
    }

    /**
     * @param $classPath
     *
     * @return mixed
     */
    public function getObjectClass($classPath)
    {
        return $this->objectManager->create($classPath);
    }

    /**
     * @param $code
     *
     * @return bool
     */
    public function isSuccess($code)
    {
        return (200 <= $code && 300 > $code);
    }
}
