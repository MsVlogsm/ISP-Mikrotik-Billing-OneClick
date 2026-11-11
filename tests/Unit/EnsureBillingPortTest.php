<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureBillingPort;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class EnsureBillingPortTest extends TestCase
{
    public function test_staging_https_port_is_not_exposed_even_when_environment_is_staging(): void
    {
        $this->app['env'] = 'staging';
        $request = Request::create('https://bill.xlinkbd.net:8084/dashboard');

        $this->expectException(NotFoundHttpException::class);
        (new EnsureBillingPort)->handle($request, fn () => response('unexpected'));
    }

    public function test_staging_port_is_not_allowed_in_production(): void
    {
        $this->app['env'] = 'production';
        $request = Request::create('https://bill.xlinkbd.net:8084/dashboard');

        try {
            (new EnsureBillingPort)->handle($request, fn () => response('unexpected'));
            $this->fail('Production must reject the Staging port.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_staging_port_is_not_allowed_over_plain_http(): void
    {
        $this->app['env'] = 'staging';
        $request = Request::create('http://bill.xlinkbd.net:8084/dashboard');

        try {
            (new EnsureBillingPort)->handle($request, fn () => response('unexpected'));
            $this->fail('Staging port must require HTTPS.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }
}
