<?php

declare(strict_types=1);

namespace App\Tests\Http\Request;

use App\Http\Headers\Headers;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpVersion;
use App\Http\Request\HttpRequest;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    public function testPathStripsQueryString(): void
    {
        $request = $this->requestWithTarget('/users/42?page=2');

        $this->assertSame('/users/42', $request->path());
        $this->assertSame(['page' => '2'], $request->query());
    }

    public function testPathWithoutQueryIsTheWholeTarget(): void
    {
        $request = $this->requestWithTarget('/hello');

        $this->assertSame('/hello', $request->path());
        $this->assertSame([], $request->query());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $headers = new Headers();
        $headers->set('Content-Type', 'text/plain');

        $request = new HttpRequest(
            method: HttpMethod::GET,
            target: '/',
            version: HttpVersion::HTTP_1_1,
            headers: $headers,
            body: '',
        );

        $this->assertSame('text/plain', $request->header('content-type'));
        $this->assertSame('text/plain', $request->header('Content-Type'));
        $this->assertNull($request->header('Missing'));
    }

    private function requestWithTarget(string $target): HttpRequest
    {
        return new HttpRequest(
            method: HttpMethod::GET,
            target: $target,
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );
    }
}