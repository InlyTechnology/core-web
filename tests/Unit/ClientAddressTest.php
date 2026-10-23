<?php

namespace Inly\CoreWeb\Tests\Unit;

use Illuminate\Http\Request;
use Inly\CoreWeb\Support\ClientAddress;
use PHPUnit\Framework\TestCase;

class ClientAddressTest extends TestCase
{
    private static function key(string $ip): string
    {
        return ClientAddress::key(Request::create('/', server: ['REMOTE_ADDR' => $ip]));
    }

    public function test_an_ipv4_address_is_counted_whole(): void
    {
        $this->assertSame('203.0.113.7', self::key('203.0.113.7'));
        $this->assertSame('203.0.113.7', self::key('::ffff:203.0.113.7'));
    }

    public function test_an_ipv6_address_is_counted_by_its_64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', self::key('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame(self::key('2001:db8:1:2::1'), self::key('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(self::key('2001:db8:1:2::1'), self::key('2001:db8:1:3::1'));
    }
}
