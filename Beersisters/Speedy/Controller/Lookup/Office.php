<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Controller\Lookup;

use Beersisters\Speedy\Model\Lookup;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /bsspeedy/lookup/office?site_id=<id>: offices and automats of one site, from the local table.
 */
class Office implements HttpGetActionInterface
{
    private const MAX_AGE = 3600;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var Lookup
     */
    private $lookup;

    public function __construct(RequestInterface $request, JsonFactory $jsonFactory, Lookup $lookup)
    {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->lookup = $lookup;
    }

    public function execute(): Json
    {
        $siteId = $this->request->getParam('site_id');
        $items = is_string($siteId) && ctype_digit($siteId) ? $this->lookup->findOffices((int)$siteId) : [];

        // Same answer for everyone and the data changes once a day: let Varnish absorb repeated lookups.
        return $this->jsonFactory->create()
            ->setHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE, true)
            ->setHeader('Pragma', 'cache', true)
            ->setData(['items' => $items]);
    }
}
