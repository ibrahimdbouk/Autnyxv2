<?php

namespace App\Http\Controllers;

/**
 * Base controller.
 *
 * Laravel 11/12's default skeleton no longer ships this class, but the app's
 * controllers (AnomalyReportController, ReportController, InboundEmailController)
 * all `extend Controller`. Without this file the router fails to resolve any of
 * those routes with "Class App\Http\Controllers\Controller not found" (a 500).
 * Keeping it abstract and minimal restores the conventional base for every
 * controller to extend.
 */
abstract class Controller
{
    //
}
