<?php

declare(strict_types=1);

namespace App\Payroll\Application;

use RuntimeException;

final class ConcurrencyConflict extends RuntimeException {}
