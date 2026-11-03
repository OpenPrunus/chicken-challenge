<?php

declare(strict_types=1);

namespace Chicken\Tests\Support;

use Chicken\Support\AtomicFile;
use Chicken\Support\Base64Url;
use Chicken\Support\CheckMode;
use Chicken\Support\Env;
use Chicken\Support\Nickname;
use Chicken\Support\Num;
use Chicken\Support\StdoutLogger;
use Chicken\Support\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ---- Nickname -----------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'simple' => ['Alice', 'Alice'],
            'espaces autour' => ['  Bob  ', 'Bob'],
            'accents conservés' => ['gnié', 'gnié'],
            'caractères permis' => ['a_b-c.d e', 'a_b-c.d e'],
            'html retiré' => ['<img src=x>', 'img srcx'],
            'script retiré' => ['<b>Bob</b>', 'bBobb'],
            'html + troncature' => ['<img src=x onerror=1>', 'img srcx onerro'],
            'html + troncature (serveur)' => ['<img src=x onerror=1>' . 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', 'img srcx onerro'],
            'emoji retirés' => ['Poule🐔', 'Poule'],
            'tronqué à 15' => [str_repeat('z', 40), str_repeat('z', 15)],
            'tronqué sans espace' => [str_repeat('a', 14) . ' bbb', str_repeat('a', 14)],
            'vide' => ['', ''],
            'que du symbole' => ['<>/\\', ''],
            'unicode multi-octet' => [str_repeat('é', 20), str_repeat('é', 15)],
        ];
    }

    #[DataProvider('names')]
    public function testCleanName(string $raw, string $expected): void
    {
        $this->assertSame($expected, Nickname::clean($raw));
    }

    public function testNicknameKeyIsCaseInsensitiveWithUnicode(): void
    {
        $this->assertSame(Nickname::key('GNIÉ'), Nickname::key('gnié'));
    }

    // ---- Env ----------------------------------------------------------------------------------------------------

    public function testEnvNumberUsesEnvironmentOnlyWhenPositive(): void
    {
        $k = 'CHICKEN_TEST_' . bin2hex(random_bytes(3));
        $this->assertSame(7.0, Env::number($k, 7));
        putenv("$k=2.5");
        $this->assertSame(2.5, Env::number($k, 7));
        putenv("$k=0");
        $this->assertSame(7.0, Env::number($k, 7), 'zéro ignoré');
        putenv("$k=-3");
        $this->assertSame(7.0, Env::number($k, 7), 'négatif ignoré');
        putenv("$k=abc");
        $this->assertSame(7.0, Env::number($k, 7), 'non numérique ignoré');
        putenv("$k=0");
        $this->assertSame(0.0, Env::number($k, 7, true), 'avec allowZero, 0 est une valeur valide');
        putenv("$k=-2");
        $this->assertSame(7.0, Env::number($k, 7, true), 'mais jamais un négatif');
        putenv("$k=abc");
        $this->assertSame(7.0, Env::number($k, 7, true));
        putenv("$k=1.5");
        $this->assertSame(1.5, Env::number($k, 7, true));
        putenv($k);
    }

    public function testEnvStringAndMode(): void
    {
        $k = 'CHICKEN_TEST_' . bin2hex(random_bytes(3));
        $this->assertSame('défaut', Env::string($k, 'défaut'));
        putenv("$k=valeur");
        $this->assertSame('valeur', Env::string($k, 'défaut'));
        putenv("$k=");
        $this->assertSame('défaut', Env::string($k, 'défaut'), 'vide = absent');
        foreach (['enforce' => CheckMode::Enforce, 'log' => CheckMode::Log, 'off' => CheckMode::Off] as $value => $mode) {
            putenv("$k=$value");
            $this->assertSame($mode, Env::mode($k));
        }
        putenv("$k=n'importe quoi");
        $this->assertSame(CheckMode::Enforce, Env::mode($k), 'valeur inconnue : le plus strict');
        $this->assertSame(CheckMode::Log, Env::mode($k, CheckMode::Log));
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

    public function testAtomicFileWritesAndReadsJson(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-atomic-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $file = "$dir/data.json";
            $this->assertNull(AtomicFile::readJson($file), 'absent');
            $this->assertTrue(AtomicFile::write($file, '{"a":1}'));
            $this->assertSame(['a' => 1], AtomicFile::readJson($file));
            $this->assertFileDoesNotExist("$file.tmp");
            file_put_contents($file, '{ cassé');
            $this->assertNull(AtomicFile::readJson($file), 'illisible');
            $this->assertFalse(@AtomicFile::write('/chemin/inexistant/f.json', '{}'));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testSystemClockGivesTheCurrentTime(): void
    {
        $clock = new SystemClock();
        $this->assertEqualsWithDelta(microtime(true), SystemClock::seconds($clock), 1.0);
    }

    public function testStdoutLoggerInterpolatesPlaceholders(): void
    {
        $out = fopen('php://memory', 'w+');
        (new StdoutLogger($out))->info('+ {name} (#{id}) {list}', ['name' => 'gnié', 'id' => 4, 'list' => [1, 2]]);
        rewind($out);
        $this->assertMatchesRegularExpression('/^\[\d\d:\d\d:\d\d\] \+ gnié \(#4\) \[1,2\]\n$/', (string) stream_get_contents($out));
    }
}
