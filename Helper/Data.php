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
 * @package     Mageplaza_CustomerApproval
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\CustomerApproval\Helper;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Area;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Asset\Repository as AssetFile;
use Magento\Store\Model\StoreManagerInterface;
use Mageplaza\Core\Helper\AbstractData;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\ResourceModel\CustomerFactory;
use Mageplaza\CustomerApproval\Model\Config\Source\AttributeOptions;
use Magento\Framework\Message\ManagerInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;

/**
 * Class Data
 * @package Mageplaza\CustomerApproval\Helper
 */
class Data extends AbstractData
{
    const CONFIG_MODULE_PATH = 'mpcustomerapproval';
    const XML_PATH_EMAIL     = 'email';

    /**
     * @var HttpContext
     */
    protected $_httpContext;

    /**
     * @var AssetFile
     */
    protected $_assetRepo;

    /**
     * @var Http
     */
    protected $_requestHttp;

    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepositoryInterface;

    /**
     * @var TransportBuilder
     */
    protected $attributeMetadata;

    /**
     * @var Customer
     */
    protected $customer;

    /**
     * @var CustomerResource
     */
    protected $customerFactory;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var ManagerInterface
     */
    protected $customerCollectionFactory;

    /**
     * Data constructor.
     *
     * @param Context                       $context
     * @param ObjectManagerInterface        $objectManager
     * @param StoreManagerInterface         $storeManager
     * @param HttpContext                   $httpContext
     * @param AssetFile                     $assetRepo
     * @param Http                          $requestHttp
     * @param TransportBuilder              $transportBuilder
     * @param CustomerRepositoryInterface   $customerRepositoryInterface
     * @param AttributeMetadataDataProvider $attributeMetadata
     * @param Customer                      $customer
     * @param CustomerFactory               $customerFactory
     * @param ManagerInterface              $messageManager
     * @param CustomerCollectionFactory     $customerCollectionFactory
     */
    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager,
        HttpContext $httpContext,
        AssetFile $assetRepo,
        Http $requestHttp,
        TransportBuilder $transportBuilder,
        CustomerRepositoryInterface $customerRepositoryInterface,
        AttributeMetadataDataProvider $attributeMetadata,
        Customer $customer,
        CustomerFactory $customerFactory,
        ManagerInterface $messageManager,
        CustomerCollectionFactory $customerCollectionFactory
    )
    {
        $this->_httpContext                = $httpContext;
        $this->_assetRepo                  = $assetRepo;
        $this->_requestHttp                = $requestHttp;
        $this->transportBuilder            = $transportBuilder;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->attributeMetadata           = $attributeMetadata;
        $this->customer                    = $customer;
        $this->customerFactory             = $customerFactory;
        $this->messageManager              = $messageManager;
        $this->customerCollectionFactory   = $customerCollectionFactory;
        parent::__construct($context, $objectManager, $storeManager);
    }

    /**
     * @return bool
     */
    public function isCustomerLogedIn()
    {
        return $this->_httpContext->getValue(CustomerContext::CONTEXT_AUTH);
    }

    /**
     * @param $customerId
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCustomerById($customerId)
    {
        return $this->customerRepositoryInterface->getById($customerId);
    }

    /**
     * @param $CusEmail
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCustomerByEmail($CusEmail)
    {
        return $this->customerRepositoryInterface->get($CusEmail);
    }

    /**
     * @param $customerId
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getIsApproved($customerId)
    {
        $customer         = $this->getCustomerById($customerId);
        $isApprovedObject = $customer->getCustomAttribute('is_approved');
        if (!$isApprovedObject || $isApprovedObject == null) {
            return null;
        }
        $isApprovedObjectArray = $isApprovedObject->__toArray();
        $attributeCode         = $isApprovedObjectArray['attribute_code'];
        if ($attributeCode == 'is_approved') {
            $value = $isApprovedObjectArray['value'];
        }

        return $value;
    }

    /**
     * @param $customerId
     *
     * @throws \Exception
     */
    public function approvalCustomerById($customerId)
    {
        $customer     = $this->customer->load($customerId);
        $customerData = $customer->getDataModel();
        if ($customerData->getCustomAttribute('is_approved') != AttributeOptions::APPROVED) {
            $customerData->setId($customerId);
            $customerData->setCustomAttribute('is_approved', AttributeOptions::APPROVED);
            $customer->updateData($customerData);
            $customer->save();
        }
        $storeId  = $this->getStoreId();
        $sendTo   = $customer->getEmail();
        $sender   = $this->getSenderCustomer();
        $loginurl = $this->getLoginUrl();

        $enableSendEmail = $this->getEnabledApproveEmail();
        if ($enableSendEmail) {
            #send emailto customer
            try {
                $this->sendMail(
                    $sendTo,
                    $customer->getFirstname(),
                    $customer->getLastname(),
                    $customer->getEmail(),
                    $loginurl,
                    $this->getApproveTemplate(),
                    $storeId,
                    $sender);
            } catch (\Exception $e) {
                $this->messageManager->addException($e, __($e->getMessage()));
            }
        }
    }

    /**
     * @param $customerId
     *
     * @throws \Exception
     */
    public function notApprovalCustomerById($customerId)
    {
        $customer     = $this->customer->load($customerId);
        $customerData = $customer->getDataModel();
        if ($customerData->getCustomAttribute('is_approved') != AttributeOptions::NOTAPPROVE) {
            $customerData->setId($customerId);
            $customerData->setCustomAttribute('is_approved', AttributeOptions::NOTAPPROVE);
            $customer->updateData($customerData);
            $customer->save();
        }

        $storeId  = $this->getStoreId();
        $sendTo   = $customer->getEmail();
        $sender   = $this->getSenderCustomer();
        $loginurl = $this->getLoginUrl();

        $enableSendEmail = $this->getEnabledNotApproveEmail();
        if ($enableSendEmail) {
            #send emailto customer
            try {
                $this->sendMail(
                    $sendTo,
                    $customer->getFirstname(),
                    $customer->getLastname(),
                    $customer->getEmail(),
                    $loginurl,
                    $this->getNotApproveTemplate(),
                    $storeId,
                    $sender);
            } catch (\Exception $e) {
                $this->messageManager->addException($e, __($e->getMessage()));
            }
        }
    }

    /**
     * @param $customerId
     *
     * @throws \Exception
     */
    public function setApprovePendingById($customerId)
    {
        $customer     = $this->customer->load($customerId);
        $customerData = $customer->getDataModel();
        if ($customerData->getCustomAttribute('is_approved') == null) {
            $customerData->setId($customerId);
            $customerData->setCustomAttribute('is_approved', AttributeOptions::PENDING);
            $customer->updateData($customerData);
            $customer->save();
        }
    }

    /**
     * @return \Magento\Store\Api\Data\StoreInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStore()
    {
        return $this->storeManager->getStore();
    }

    /**
     * @return int
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreId()
    {
        return $this->getStore()->getId();
    }

    /**
     * @return bool
     */
    public function isCustomerApprovalEnabled()
    {
        return $this->isEnabled();
    }

    /**
     * @return mixed|null
     */
    public function getCustomerGroupId()
    {
        return $this->_httpContext->getValue(\Magento\Customer\Model\Context::CONTEXT_GROUP);
    }

    /**
     * @return string
     */
    public function getRouteName()
    {
        return $this->_requestHttp->getRouteName();
    }

    /**
     * @return string
     */
    public function getFullAction()
    {
        return $this->_requestHttp->getFullActionName();
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getEnabledNoticeAdmin($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/enabled', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getNoticeAdminTemplate($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/template', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getSenderAdmin($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/sender', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getRecipientsAdmin($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/sendto', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getSenderCustomer($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/sender', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getEnabledSuccessEmail($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/customer_success_email/enabled', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getSuccessTemplate($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/customer_success_email/template', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getEnabledApproveEmail($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/customer_approve_email/enabled', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getApproveTemplate($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/customer_approve_email/template', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getEnabledNotApproveEmail($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/customer_not_approve_email/enabled', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getNotApproveTemplate($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/customer_not_approve_email/template', $storeId);
    }

    /**
     * @return string
     */
    public function getLoginUrl()
    {
        return $this->_getUrl('customer/account/login');
    }

    /**
     * @param $sendTo
     * @param $firstname
     * @param $lastname
     * @param $email
     * @param $loginPath
     * @param $emailTemplate
     * @param $storeId
     * @param $sender
     *
     * @return bool
     */
    public function sendMail($sendTo, $firstname, $lastname, $email, $loginPath, $emailTemplate, $storeId, $sender)
    {
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($emailTemplate)
                ->setTemplateOptions([
                    'area'  => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'firstname' => $firstname,
                    'lastname'  => $lastname,
                    'email'     => $email,
                    'loginurl'  => $loginPath,
                ])
                ->setFrom($sender)
                ->addTo($sendTo);
            $transport = $this->transportBuilder->getTransport();
            $transport->sendMessage();

            return true;
        } catch (\Magento\Framework\Exception\MailException $e) {
            $this->_logger->critical($e->getLogMessage());
        }

        return false;
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getAutoApproveConfig($storeId = null)
    {
        return $this->getConfigGeneral('auto_approve', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getMessageAfterRegister($storeId = null)
    {
        return $this->getConfigGeneral('message_after_register', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getTypeNotApprove($storeId = null)
    {
        return $this->getConfigGeneral('type_not_approve', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getErrorMessage($storeId = null)
    {
        return $this->getConfigGeneral('error_message', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getCmsRedirectPage($storeId = null)
    {
        return $this->getConfigGeneral('redirect_cms_page', $storeId);
    }

    /**
     * @param $path
     * @param $param
     *
     * @return string
     */
    public function getUrl($path, $param)
    {
        return $this->_getUrl($path, $param);
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getBaseUrlDashboard()
    {
        return $this->storeManager->getStore()->getBaseUrl();
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getListCustomerApprove()
    {
        $customerCollection = $this->customerCollectionFactory->create();
        $customerApprove    = [];
        foreach ($customerCollection as $customer) {
            $customerId       = $customer->getId();
            $customer         = $this->getCustomerById($customerId);
            $isApprovedObject = $customer->getCustomAttribute('is_approved');
            if (!$isApprovedObject || $isApprovedObject == null) {
                continue;
            }
            $isApprovedObjectArray = $isApprovedObject->__toArray();
            if ($isApprovedObjectArray['attribute_code'] == 'is_approved') {
                if ($isApprovedObjectArray['value'] == AttributeOptions::APPROVED) {
                    $customerApprove[] = $customer->getEmail();
                }
            }
        }

        return $customerApprove;
    }

    /**
     * @param $stringCode
     *
     * @return mixed
     */
    public function getRequestParam($stringCode)
    {
        return $this->_request->getParam($stringCode);
    }

    /**
     * @return array
     */
    public function getFullRequestParams()
    {
        return $this->_request->getParams();
    }
}
