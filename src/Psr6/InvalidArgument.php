<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Psr6;

use Psr\Cache\InvalidArgumentException;

final class InvalidArgument extends \InvalidArgumentException implements InvalidArgumentException
{
}
