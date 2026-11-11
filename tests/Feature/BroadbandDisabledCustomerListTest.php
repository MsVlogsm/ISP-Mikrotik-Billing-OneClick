<?php

namespace Tests\Feature;

use App\Http\Controllers\BroadbandController;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Tests\TestCase;

class BroadbandDisabledCustomerListTest extends TestCase
{
    public function test_disabled_broadband_list_redirects_to_the_primary_customer_list(): void
    {
        $request = Request::create('/broadband/customers?status=disabled', 'GET');
        $response = app(BroadbandController::class)->list($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            route('customers.index', ['dashboard_status' => 'disable']),
            $response->getTargetUrl(),
        );
    }
}
