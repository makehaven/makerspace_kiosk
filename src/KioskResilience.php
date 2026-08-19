<?php

namespace Drupal\makerspace_kiosk;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Serialization\Json;

/**
 * Supplies the shared kiosk resilience runtime to display controllers.
 *
 * The four display modules emit raw HTML Responses rather than render arrays,
 * so they cannot attach a Drupal asset library. Rather than refactor four
 * kiosks at once, this service hands back the same assets as an inline blob.
 *
 * Inlining is also the right call on its own merits here: a wall-mounted sign
 * that had to fetch a separate JS file would lose its recovery logic exactly
 * when it needs it most - during the deploy or outage that made the page fail.
 * A self-contained page can heal itself with no further requests.
 */
class KioskResilience {

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Memoised asset contents, keyed by relative path.
   *
   * @var array<string, string|null>
   */
  protected array $assetCache = [];

  /**
   * Constructs a KioskResilience service.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ModuleExtensionList $module_extension_list, LoggerChannelFactoryInterface $logger_factory) {
    $this->moduleExtensionList = $module_extension_list;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Returns the inline <style> block for the status chip.
   *
   * @return string
   *   A style element, or an empty string if the asset could not be read.
   */
  public function styles(): string {
    $css = $this->asset('css/kiosk-status.css');
    return $css === NULL ? '' : '<style>' . $css . '</style>';
  }

  /**
   * Returns the inline <script> block: the runtime plus a start() call.
   *
   * Emit this immediately before </body>, after the markup the callbacks
   * target.
   *
   * @param array $options
   *   Options for MakerspaceKiosk.start(). At minimum 'feedUrl'. Callbacks
   *   ('onData', 'onError', 'onStale', 'onRecover') must be supplied as raw
   *   JavaScript function source via $callbacks, not here.
   * @param array $callbacks
   *   Map of callback name to raw JavaScript function expression, e.g.
   *   ['onData' => 'function (data) { render(data.items || []); }'].
   *
   * @return string
   *   A script element. Never throws: a display that cannot load its resilience
   *   runtime still gets a minimal probe-gated daily reload, because shipping a
   *   kiosk with no recovery at all is the situation this module exists to end.
   */
  public function script(array $options, array $callbacks = []): string {
    if (empty($options['feedUrl'])) {
      throw new \InvalidArgumentException('KioskResilience::script() requires a feedUrl option.');
    }

    $runtime = $this->asset('js/kiosk-resilience.js');
    if ($runtime === NULL) {
      return $this->minimalFallbackScript();
    }

    // Callbacks are raw JS source, so they are spliced in as placeholders after
    // the options array is JSON-encoded. The placeholder token is not a valid
    // JSON string body, so it cannot collide with real option values.
    $placeholders = [];
    foreach ($callbacks as $name => $source) {
      $token = '__MK_CB_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $name)) . '__';
      $options[$name] = $token;
      $placeholders['"' . $token . '"'] = $source;
    }

    $encoded = Json::encode($options);
    if ($placeholders) {
      $encoded = strtr($encoded, $placeholders);
    }

    return '<script>' . $runtime . "\n"
      . 'MakerspaceKiosk.start(' . $encoded . ');' . "\n"
      . '</script>';
  }

  /**
   * Last-resort recovery for a display whose runtime asset is unreadable.
   *
   * Probe-gated so it cannot strand the kiosk on a browser error page, matching
   * the rule the full runtime follows.
   *
   * @return string
   *   A minimal script element.
   */
  protected function minimalFallbackScript(): string {
    return '<script>(function(){setInterval(function(){'
      . 'fetch(location.href,{method:"HEAD",cache:"no-store"})'
      . '.then(function(r){if(r.ok){location.reload();}}).catch(function(){});'
      . '},86400000);})();</script>';
  }

  /**
   * Reads and memoises a module asset.
   *
   * @param string $relative_path
   *   Path relative to the module root.
   *
   * @return string|null
   *   File contents, or NULL if unreadable.
   */
  protected function asset(string $relative_path): ?string {
    if (array_key_exists($relative_path, $this->assetCache)) {
      return $this->assetCache[$relative_path];
    }

    $contents = NULL;
    try {
      $path = $this->moduleExtensionList->getPath('makerspace_kiosk') . '/' . $relative_path;
      if (is_readable($path)) {
        $read = file_get_contents($path);
        if ($read !== FALSE) {
          $contents = $read;
        }
      }
    }
    catch (\Exception $e) {
      // Fall through to the NULL path below.
    }

    if ($contents === NULL) {
      $this->loggerFactory->get('makerspace_kiosk')->error(
        'Kiosk resilience asset @path could not be read; displays fall back to minimal recovery.',
        ['@path' => $relative_path]
      );
    }

    $this->assetCache[$relative_path] = $contents;
    return $contents;
  }

}
