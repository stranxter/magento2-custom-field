<?php
namespace Beersisters\Deliverynote\Observer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\QuoteFactory;
use Psr\Log\LoggerInterface;

class PlaceOrder implements ObserverInterface
{
    /**
    * @var \Psr\Log\LoggerInterface
    */
    protected $_logger;

    /**
    * @var \Magento\Customer\Model\Session
    */
    protected $quoteFactory;

    /**
     * Constructor
     *
     * @param \Psr\Log\LoggerInterface $logger
     */

    public function __construct(LoggerInterface $logger,
        QuoteFactory $quoteFactory) {
        $this->_logger = $logger;
        $this->quoteFactory = $quoteFactory;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getOrder();
        $quoteId = $order->getQuoteId();
        $quote  = $this->quoteFactory->create()->load($quoteId);
        $order->setDelivery_note($quote->getDelivery_note());
        $order->save();
    }
}