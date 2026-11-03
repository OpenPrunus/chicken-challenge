<?php

declare(strict_types=1);

namespace Chicken\Tests\Support;

use Chicken\Support\Base64Url;
use Chicken\Support\CheckMode;
use Chicken\Support\Num;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SupportTest extends TestCase
{
    // ---- Num ----------------------------------------------------------------------------------------------------

    public function testNumClampsAndRejectsNonNumbers(): void
    {
        $this->assertSame(0.5, Num::clamped(0.5, -1, 1));
        $this->assertSame(1.0, Num::clamped(5, -1, 1));
        $this->assertSame(-1.0, Num::clamped(-99.9, -1, 1));
        $this->assertSame(1.9, Num::clamped(2, 0.3, 1.9), 'borne haute');
        $this->assertSame(0.3, Num::clamped(0, 0.3, 1.9), 'borne basse');
        foreach (['1', null, true, [], 'abc', new stdClass()] as $bad) {
            $this->assertNull(Num::clamped($bad, -1, 1), 'rejette ' . get_debug_type($bad));
        }
        foreach ([INF, -INF, NAN] as $bad) {
            $this->assertNull(Num::clamped($bad, -1, 1));
        }
    }

    public function testFiniteAcceptsAnyFiniteNumber(): void
    {
        $this->assertSame(3.0, Num::finite(3));
        $this->assertSame(-7.5, Num::finite(-7.5));
        foreach ([NAN, INF, '3', null, false] as $bad) {
            $this->assertNull(Num::finite($bad));
        }
    }

    public function testCheckModeFromEnvironment(): void
    {
        $k = 'CHICKEN_TEST_' . bin2hex(random_bytes(3));
        foreach (['enforce' => CheckMode::Enforce, 'log' => CheckMode::Log, 'off' => CheckMode::Off] as $value => $mode) {
            putenv("$k=$value");
            $this->assertSame($mode, CheckMode::fromEnv($k));
        }
        putenv("$k=n'importe quoi");
        $this->assertSame(CheckMode::Enforce, CheckMode::fromEnv($k), 'valeur inconnue : le plus strict');
        $this->assertSame(CheckMode::Log, CheckMode::fromEnv($k, CheckMode::Log));
        putenv($k);
    }

    public function testCheckModeSemantics(): void
    {
        $this->assertTrue(CheckMode::Enforce->blocks());
        $this->assertFalse(CheckMode::Log->blocks());
        $this->assertTrue(CheckMode::Log->isActive());
        $this->assertFalse(CheckMode::Off->isActive());
        $this->assertSame('REFUSED', CheckMode::Enforce->verdict());
        $this->assertSame('suspect', CheckMode::Log->verdict());
    }

    // ---- Base64Url / AtomicFile / horloge / logger -------------------------------------------------------------

    public function testBase64UrlRoundTripAndRejection(): void
    {
        foreach (['', 'a', "bin\x00\xff\xfe", str_repeat('é', 50)] as $s) {
            $this->assertSame($s, Base64Url::decode(Base64Url::encode($s)));
        }
        $this->assertFalse(Base64Url::decode('***'));
        $this->assertStringNotContainsString('=', Base64Url::encode('a'));
    }
}
