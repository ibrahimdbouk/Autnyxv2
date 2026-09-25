<?php

namespace App\Services\Pipeline;

/** Another detection run holds this tenant's lock. */
class DetectionBusy extends \RuntimeException
{
}
