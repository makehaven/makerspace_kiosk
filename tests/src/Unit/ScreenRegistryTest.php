<?php

namespace Drupal\Tests\makerspace_kiosk\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makerspace_kiosk\ScreenRegistry;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_kiosk\ScreenRegistry
 * @group makerspace_kiosk
 */
class ScreenRegistryTest extends UnitTestCase {

  /**
   * In-memory state store.
   */
  protected array $store = [];

  /**
   * Builds a registry over the in-memory store at a fixed time.
   */
  protected function registry(int $now): ScreenRegistry {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn($key, $default = NULL) => $this->store[$key] ?? $default);
    $state->method('set')->willReturnCallback(function ($key, $value) {
      $this->store[$key] = $value;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    return new ScreenRegistry($state, $time);
  }

  /**
   * @covers ::normalizeId
   */
  public function testNormalizeId(): void {
    $this->assertSame('3F9A2C', ScreenRegistry::normalizeId(' 3f9a2c '));
    $this->assertNull(ScreenRegistry::normalizeId('3F9A2'));
    $this->assertNull(ScreenRegistry::normalizeId('3F9A2G'));
    $this->assertNull(ScreenRegistry::normalizeId('../etc'));
  }

  /**
   * @covers ::isValidUrl
   */
  public function testIsValidUrl(): void {
    $this->assertTrue(ScreenRegistry::isValidUrl('/display/slideshow'));
    $this->assertTrue(ScreenRegistry::isValidUrl('https://example.org/board'));
    $this->assertFalse(ScreenRegistry::isValidUrl('//evil.example/x'));
    $this->assertFalse(ScreenRegistry::isValidUrl('http://example.org'));
    $this->assertFalse(ScreenRegistry::isValidUrl('javascript:alert(1)'));
    $this->assertFalse(ScreenRegistry::isValidUrl('/display/a b'));
    $this->assertFalse(ScreenRegistry::isValidUrl(''));
  }

  /**
   * @covers ::recordSeen
   */
  public function testRecordSeenThrottlesWrites(): void {
    $this->registry(1000)->recordSeen('3F9A2C', '0.1.0');
    $this->registry(1100)->recordSeen('3F9A2C', '0.1.0');
    $this->assertSame(1000, $this->store[ScreenRegistry::SEEN]['3F9A2C']['last']);
    // A new agent version is written straight away.
    $this->registry(1100)->recordSeen('3F9A2C', '0.2.0');
    $this->assertSame('0.2.0', $this->store[ScreenRegistry::SEEN]['3F9A2C']['version']);
    $this->assertSame(1000, $this->store[ScreenRegistry::SEEN]['3F9A2C']['first']);
  }

  /**
   * @covers ::recordSeen
   */
  public function testMadeUpIdsCannotGrowWithoutLimitOrEvictAssignedScreens(): void {
    $this->store[ScreenRegistry::ASSIGNMENTS] = [
      'AAAAAA' => [
        'label' => 'Main TV',
        'url' => '/display/slideshow',
        'rotation' => 0,
      ],
    ];
    $this->registry(1)->recordSeen('AAAAAA', '0.1.0');
    for ($i = 0; $i < ScreenRegistry::SEEN_LIMIT + 10; $i++) {
      $this->registry(100 + $i)->recordSeen(sprintf('%06X', $i), '0.1.0');
    }
    $seen = $this->store[ScreenRegistry::SEEN];
    $this->assertCount(ScreenRegistry::SEEN_LIMIT + 1, $seen);
    $this->assertArrayHasKey('AAAAAA', $seen);
    $this->assertArrayNotHasKey('000000', $seen);
  }

}
