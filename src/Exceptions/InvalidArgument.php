<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Exceptions;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgument;

final class InvalidArgument extends \InvalidArgumentException implements PsrInvalidArgument {}
