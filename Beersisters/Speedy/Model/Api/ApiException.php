<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model\Api;

/**
 * A failed Speedy API call. The message never contains the credentials, so it is safe to log.
 */
class ApiException extends \RuntimeException
{
}
