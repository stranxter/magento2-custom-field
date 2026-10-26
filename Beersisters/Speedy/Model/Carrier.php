<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Speedy with two fixed-price methods: to an office/automat and to an address.
 *
 * Offline carrier: no API call here. Free shipping comes only from cart price rules (rule 2, ≥ €35),
 * honoured the same way Magento's flat rate does it.
 */
class Carrier extends AbstractCarrier implements CarrierInterface
{
    public const CODE = 'bsspeedy';
    public const METHOD_OFFICE = 'office';
    public const METHOD_ADDRESS = 'address';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var MethodFactory
     */
    private $rateMethodFactory;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $free = $this->isFreeShipping($request);
        $result = $this->rateResultFactory->create();
        foreach ([self::METHOD_OFFICE, self::METHOD_ADDRESS] as $code) {
            $price = $free ? 0.0 : (float)$this->getConfigData('price_' . $code);

            $method = $this->rateMethodFactory->create();
            $method->setCarrier(self::CODE);
            $method->setCarrierTitle($this->getConfigData('title'));
            $method->setMethod($code);
            $method->setMethodTitle($this->getConfigData('name_' . $code));
            $method->setPrice($price);
            $method->setCost($price);
            $result->append($method);
        }

        return $result;
    }

    public function getAllowedMethods()
    {
        return [
            self::METHOD_OFFICE => $this->getConfigData('name_office'),
            self::METHOD_ADDRESS => $this->getConfigData('name_address'),
        ];
    }

    /**
     * Free when the address-level flag is set, or when every shippable item carries the per-item flag
     * (cart rules with "Free Shipping: For matching items only"). Same logic as Magento's flat rate carrier.
     */
    private function isFreeShipping(RateRequest $request): bool
    {
        if ($request->getFreeShipping() === true) {
            return true;
        }
        $packageQty = (float)$request->getPackageQty();

        return $packageQty > 0 && $this->getFreeBoxesCount($request) >= $packageQty;
    }

    private function getFreeBoxesCount(RateRequest $request): float
    {
        $freeBoxes = 0.0;
        foreach ($request->getAllItems() ?: [] as $item) {
            if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                continue;
            }
            if ($item->getHasChildren() && $item->isShipSeparately()) {
                foreach ($item->getChildren() as $child) {
                    if ($child->getFreeShipping() && !$child->getProduct()->isVirtual()) {
                        $freeBoxes += (float)$item->getQty() * (float)$child->getQty();
                    }
                }
            } elseif ($item->getFreeShipping()) {
                $freeBoxes += (float)$item->getQty();
            }
        }

        return $freeBoxes;
    }
}
